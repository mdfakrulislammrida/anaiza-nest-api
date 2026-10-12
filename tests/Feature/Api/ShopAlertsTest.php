<?php

namespace Tests\Feature\Api;

use App\Mail\ShopAlertMail;
use App\Mail\WalletPaymentReceivedMail;
use App\Models\Category;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Support\System\ShopAlerts;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use Tests\TestCase;

class ShopAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->category = Category::create(['name' => 'Mugs', 'slug' => 'mugs']);
        $this->product = $this->product('Glazed mug', 'MUG', 50);
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'contact_email' => 'contact@example.com', 'low_stock_threshold' => 5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function product(string $name, string $sku, int $stock, bool $active = true): Product
    {
        return Product::create([
            'category_id' => $this->category->id, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)),
            'price' => 600, 'stock_quantity' => $stock, 'sku' => $sku, 'is_active' => $active,
        ]);
    }

    private function order(array $overrides = [])
    {
        (new ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);

        return $this->postJson('/api/orders', array_merge([
            'customer_name' => 'Nusrat Jahan', 'customer_phone' => '01711234567', 'customer_email' => 'nusrat@example.com',
            'customer_address' => 'House 12, Road 5', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ], $overrides));
    }

    // ---- a new order

    public function test_a_cash_on_delivery_order_tells_the_shop_with_a_link_to_confirm_on_whatsapp(): void
    {
        SiteSetting::query()->update(['alert_email' => 'owner@example.com']);

        $id = $this->order()->assertCreated()->json('data.id');

        Mail::assertSent(ShopAlertMail::class, function (ShopAlertMail $mail) use ($id): bool {
            $body = implode("\n", $mail->lines);

            return $mail->hasTo('owner@example.com')
                && str_starts_with($mail->alertSubject, "New order #{$id}: ৳")
                && str_contains($body, 'Nusrat Jahan, 01711234567')
                && str_contains($body, '2 x Glazed mug')
                && str_contains($body, 'House 12, Road 5, Banani, Dhaka, Dhaka')
                && str_starts_with($mail->links['Confirm with the customer on WhatsApp'], 'https://wa.me/8801711234567?text=')
                && str_contains(urldecode($mail->links['Confirm with the customer on WhatsApp']), "order #{$id}")
                && str_ends_with($mail->links['Open the order'], "/admin/orders/{$id}/edit");
        });
    }

    public function test_the_alert_falls_back_to_the_payment_address_then_the_contact_email(): void
    {
        $this->order()->assertCreated();
        Mail::assertSent(ShopAlertMail::class, fn ($mail) => $mail->hasTo('contact@example.com'));

        PaymentSetting::query()->firstOrCreate([])->update(['payment_notify_email' => 'payments@example.com']);
        $this->order(['customer_phone' => '01811111111'])->assertCreated();
        Mail::assertSent(ShopAlertMail::class, fn ($mail) => $mail->hasTo('payments@example.com'));
    }

    public function test_it_can_be_switched_off_and_stays_quiet_with_nowhere_to_send(): void
    {
        SiteSetting::query()->update(['alert_new_orders' => false]);
        $this->order()->assertCreated();
        Mail::assertNotSent(ShopAlertMail::class);

        SiteSetting::query()->update(['alert_new_orders' => true, 'contact_email' => null]);
        $this->order(['customer_phone' => '01811111111'])->assertCreated();
        Mail::assertNotSent(ShopAlertMail::class);
    }

    public function test_a_wallet_order_keeps_its_own_note_and_gets_no_second_one(): void
    {
        PaymentSetting::query()->firstOrCreate([])->update(['bkash_number' => '01711111111']);

        $this->order(['payment_method' => 'bkash', 'payment_trx_id' => 'TRX12345', 'payment_sender_number' => '01711234567'])->assertCreated();

        Mail::assertSent(WalletPaymentReceivedMail::class, 1);
        Mail::assertNotSent(ShopAlertMail::class);
    }

    public function test_a_mail_problem_cannot_fail_the_order_and_is_noted_for_the_health_page(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP password rejected'));

        $this->order()->assertCreated();

        $this->assertDatabaseHas('system_events', ['type' => 'mail_failed']);
    }

    // ---- the morning stock note

    public function test_the_stock_note_lists_what_is_finished_and_what_is_running_low(): void
    {
        $this->product('Linen napkin', 'NAP', 0);
        $this->product('Brass tray', 'TRAY', 3);
        $this->product('Old teapot', 'POT', 0, active: false);
        $variant = ProductVariant::create(['product_id' => $this->product->id, 'name' => 'Colour', 'value' => 'Blue', 'stock_quantity' => 2, 'sku' => 'MUG-BLU']);

        $this->artisan('stock:digest')->expectsOutputToContain('Sent the stock note (3 products) to contact@example.com')->assertSuccessful();

        Mail::assertSent(ShopAlertMail::class, function (ShopAlertMail $mail): bool {
            $body = implode("\n", $mail->lines);

            return $mail->alertSubject === 'Stock note: 3 products need a look'
                && str_contains($body, "Finished:\n  Linen napkin (NAP): none left")
                && str_contains($body, 'Brass tray (TRAY): 3 left')
                && str_contains($body, 'Glazed mug - Colour: Blue (MUG-BLU): 2 left')
                && ! str_contains($body, 'Old teapot')
                && str_contains($body, 'turn it off under Settings > Site settings > Shop alerts');
        });
        $this->assertNotNull($variant);
    }

    public function test_nothing_is_sent_when_everything_is_comfortably_stocked(): void
    {
        $this->artisan('stock:digest')->expectsOutputToContain('nothing to send')->assertSuccessful();

        Mail::assertNotSent(ShopAlertMail::class);
    }

    public function test_the_stock_note_goes_once_a_day(): void
    {
        $this->product('Linen napkin', 'NAP', 0);

        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'Asia/Dhaka'));
        $this->artisan('stock:digest')->assertSuccessful();
        $this->artisan('stock:digest')->expectsOutputToContain('already sent')->assertSuccessful();
        Mail::assertSent(ShopAlertMail::class, 1);

        $this->artisan('stock:digest', ['--force' => true])->assertSuccessful();
        Mail::assertSent(ShopAlertMail::class, 2);

        Carbon::setTestNow(Carbon::parse('2026-10-15 09:00:00', 'Asia/Dhaka'));
        $this->artisan('stock:digest')->assertSuccessful();
        Mail::assertSent(ShopAlertMail::class, 3);
    }

    public function test_the_stock_note_respects_the_switch_and_the_address(): void
    {
        $this->product('Linen napkin', 'NAP', 0);

        SiteSetting::query()->update(['alert_low_stock' => false]);
        $this->artisan('stock:digest')->expectsOutputToContain('switched off')->assertSuccessful();

        SiteSetting::query()->update(['alert_low_stock' => true, 'contact_email' => null]);
        $this->artisan('stock:digest')->expectsOutputToContain('No alert email address')->assertSuccessful();

        Mail::assertNotSent(ShopAlertMail::class);
    }

    public function test_a_long_list_is_cut_with_a_count_of_the_rest(): void
    {
        for ($i = 1; $i <= 45; $i++) {
            $this->product("Napkin {$i}", "N{$i}", 0);
        }

        $digest = ShopAlerts::stockDigest();

        $this->assertCount(40, $digest['out']);
        $this->assertSame(5, $digest['more']);
    }

    public function test_the_morning_jobs_are_on_the_schedule(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) $event->command)
            ->implode("\n");

        foreach (['backup:run', 'stock:digest', 'system:tidy'] as $name) {
            $this->assertStringContainsString($name, $commands);
        }

        $names = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all();
        $this->assertContains('scheduler-heartbeat', $names);
    }
}
