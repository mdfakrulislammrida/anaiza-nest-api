<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageCartRecovery;
use App\Filament\Pages\ManageReviewRequests;
use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\AbandonedCheckoutResource\Pages\ListAbandonedCheckouts;
use App\Filament\Resources\CouponResource\Pages\CreateCoupon;
use App\Filament\Resources\EmailTemplateResource\Pages\EditEmailTemplate;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Mail\TransactionalMail;
use App\Models\AbandonedCheckout;
use App\Models\CartRecoverySetting;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\EmailOptOut;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReviewRequestSetting;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\BrandVoice;
use App\Support\Email\EmailBuilder;
use App\Support\Email\SampleData;
use App\Support\Email\TemplateDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class RecoveryAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 06:00:00');
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function checkout(array $overrides = []): AbandonedCheckout
    {
        static $n = 0;
        $n++;

        return AbandonedCheckout::create(array_merge([
            'session_token' => 'sess-'.str_pad((string) $n, 20, 'x'), 'name' => 'Priya Rahman', 'email' => "priya{$n}@example.com", 'phone' => '0171111'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'stage' => 'contact_filled', 'cart_total' => 1450, 'first_activity_at' => now()->subHours(3), 'last_activity_at' => now()->subHours(2),
            'items' => [['product_id' => 1, 'name' => 'Tea set', 'price' => 1450, 'quantity' => 1, 'sku' => 'TS-1', 'slug' => 'tea-set']],
        ], $overrides));
    }

    // ---- WhatsApp settings

    public function test_the_whatsapp_number_must_be_international_and_is_saved_clean(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['whatsapp_number' => '01886004421'])
            ->call('save')
            ->assertHasFormErrors(['whatsapp_number']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm([
                'whatsapp_number' => '+880 1886-004421',
                'whatsapp_default_message' => 'Hello, I would like to ask about a gift.',
                'whatsapp_show_bubble' => false,
                'whatsapp_hidden_paths' => ['/checkout', '/cart'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $site = SiteSetting::first();
        $this->assertSame('+8801886004421', $site->whatsapp_number);
        $this->assertFalse($site->whatsapp_show_bubble);
        $this->assertSame(['/checkout', '/cart'], $site->whatsappHiddenPaths());
    }

    public function test_the_site_settings_api_carries_the_whatsapp_block_with_the_contact_phone_as_fallback(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'contact_phone' => '+880 1700-000000']);

        $this->getJson('/api/site-settings')->assertOk()
            ->assertJsonPath('data.whatsapp.number', '+880 1700-000000')
            ->assertJsonPath('data.whatsapp.show_bubble', true)
            ->assertJsonPath('data.whatsapp.message', SiteSetting::DEFAULT_WHATSAPP_MESSAGE)
            ->assertJsonPath('data.whatsapp.hidden_paths', []);

        SiteSetting::first()->update(['whatsapp_number' => '+8801886004421', 'whatsapp_hidden_paths' => ['checkout/', '/cart']]);

        $this->getJson('/api/site-settings')
            ->assertJsonPath('data.whatsapp.number', '+8801886004421')
            ->assertJsonPath('data.whatsapp.hidden_paths', ['/checkout', '/cart']);
    }

    // ---- settings pages

    public function test_switching_review_requests_on_records_the_moment_and_the_message_default_is_not_frozen(): void
    {
        Livewire::test(ManageReviewRequests::class)
            ->fillForm(['enabled' => true, 'days_after_delivery' => 4, 'reminder_enabled' => true, 'reminder_days' => 9])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = ReviewRequestSetting::first();
        $this->assertTrue($settings->enabled);
        $this->assertTrue($settings->enabled_at->equalTo(now()));
        $this->assertSame(4, $settings->days_after_delivery);
        $this->assertSame(9, $settings->reminder_days);
        $this->assertNull($settings->whatsapp_message, 'the untouched default stays blank so it keeps following the code');
    }

    public function test_cart_recovery_settings_save_in_minutes_and_record_when_they_were_switched_on(): void
    {
        Livewire::test(ManageCartRecovery::class)
            ->fillForm([
                'enabled' => true, 'step1_delay_hours' => 2, 'step2_delay_hours' => 24, 'step2_coupon_enabled' => true,
                'coupon_type' => 'fixed', 'coupon_amount' => 150, 'coupon_valid_hours' => 36, 'daily_cap' => 50,
                'quiet_start' => '23:00', 'quiet_end' => '07:30',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = CartRecoverySetting::first();
        $this->assertTrue($settings->enabled);
        $this->assertSame(120, $settings->step1_delay_minutes);
        $this->assertSame(1440, $settings->step2_delay_minutes);
        $this->assertSame('fixed', $settings->coupon_type);
        $this->assertSame(36, $settings->coupon_valid_hours);
        $this->assertSame('23:00', $settings->quiet_start);
        $this->assertTrue($settings->enabled_at->equalTo(now()));
    }

    // ---- coupons in the admin

    public function test_a_coupon_end_entered_in_dhaka_time_is_stored_as_that_exact_moment(): void
    {
        Livewire::test(CreateCoupon::class)
            ->fillForm([
                'code' => 'eid-gift', 'discount_type' => 'percent', 'amount' => 15, 'is_active' => true,
                'valid_from' => '2026-10-20 09:00:00', 'valid_until' => '2026-10-25 18:30:00',
                'bound_phone' => '+880 1711-111111',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $coupon = Coupon::where('code', 'EID-GIFT')->firstOrFail();
        $this->assertSame('2026-10-25 12:30:00', $coupon->valid_until->utc()->format('Y-m-d H:i:s'), '18:30 in Dhaka is 12:30 UTC');
        $this->assertSame('2026-10-20 03:00:00', $coupon->valid_from->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('01711111111', $coupon->bound_phone);
    }

    // ---- abandoned checkouts

    public function test_send_email_now_sends_the_reminder_records_it_and_respects_unsubscribes(): void
    {
        Mail::fake();
        $checkout = $this->checkout();

        Livewire::test(ListAbandonedCheckouts::class)
            ->callTableAction('sendEmail', $checkout);

        Mail::assertSent(TransactionalMail::class, fn (TransactionalMail $m) => $m->templateKey === TemplateDefinitions::CART_REMINDER);
        $this->assertCount(1, $checkout->fresh()->messages['manual']);
        $this->assertSame(0, $checkout->fresh()->messages_count, 'a manual send does not use up the automatic steps');

        EmailOptOut::record($checkout->email, null, 'test');
        Mail::fake();

        Livewire::test(ListAbandonedCheckouts::class)->callTableAction('sendEmail', $checkout);

        Mail::assertNothingSent();
    }

    public function test_create_coupon_makes_a_bound_code_and_the_next_email_is_the_offer(): void
    {
        Mail::fake();
        $checkout = $this->checkout();

        Livewire::test(ListAbandonedCheckouts::class)
            ->callTableAction('makeCoupon', $checkout, ['type' => 'percent', 'amount' => 12, 'min_order' => 1000, 'valid_hours' => 24]);

        $coupon = $checkout->fresh()->coupon;
        $this->assertNotNull($coupon);
        $this->assertSame($checkout->email, $coupon->bound_email);
        $this->assertTrue($coupon->single_use);
        $this->assertTrue($coupon->valid_until->equalTo(now()->addHours(24)));

        Livewire::test(ListAbandonedCheckouts::class)->callTableAction('sendEmail', $checkout->fresh());

        Mail::assertSent(TransactionalMail::class, fn (TransactionalMail $m) => $m->templateKey === TemplateDefinitions::CART_OFFER && str_contains($m->render(), $coupon->code));
    }

    public function test_mark_ignored_stops_reminders(): void
    {
        $checkout = $this->checkout();

        Livewire::test(ListAbandonedCheckouts::class)->callTableAction('ignore', $checkout);

        $this->assertSame('ignored', $checkout->fresh()->status);
    }

    public function test_the_checkout_whatsapp_link_is_only_for_those_who_said_yes_and_records_the_click(): void
    {
        $no = $this->checkout(['whatsapp_optin' => false]);
        $yes = $this->checkout(['whatsapp_optin' => true, 'phone' => '01711112222']);

        $this->get(route('checkouts.whatsapp', $no))->assertForbidden();

        $response = $this->get(route('checkouts.whatsapp', $yes));
        $response->assertRedirectContains('https://wa.me/8801711112222?text=');
        $this->assertStringContainsString(rawurlencode('/cart?recover='.$yes->recovery_token), $response->headers->get('Location'));
        $this->assertNotNull($yes->fresh()->whatsapp_clicked_at);
    }

    // ---- orders: WhatsApp review request and the "due" filter

    private function deliveredOrder(Carbon $deliveredAt, array $customer = []): Order
    {
        static $n = 0;
        $n++;

        $product = Product::query()->first() ?? Product::create([
            'category_id' => Category::create(['name' => 'Mugs', 'slug' => 'mugs'])->id,
            'name' => 'Red mug', 'slug' => 'red-mug', 'price' => 600, 'stock_quantity' => 5, 'sku' => 'MUG', 'is_active' => true,
        ]);
        $buyer = Customer::create(array_merge(['name' => 'Priya Rahman', 'email' => "buyer{$n}@example.com", 'phone' => '0172000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'password' => 'x'.$n], $customer));
        $order = Order::create(['customer_id' => $buyer->id, 'status' => 'delivered', 'subtotal' => 600, 'delivery_fee' => 80, 'total' => 680, 'payment_method' => 'cod', 'delivered_at' => $deliveredAt]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => 'Red mug', 'quantity' => 1, 'price' => 600, 'sku' => 'MUG']);

        return $order;
    }

    public function test_the_whatsapp_review_request_opens_the_chat_with_the_link_and_records_the_click(): void
    {
        ReviewRequestSetting::create(['enabled' => false, 'whatsapp_enabled' => true]);
        $order = $this->deliveredOrder(now()->subDays(6));

        $response = $this->get(route('orders.whatsapp-review', $order));

        $response->assertRedirectContains('https://wa.me/'.'8801720000001'.'?text=');
        $location = urldecode($response->headers->get('Location'));
        $this->assertStringContainsString('Hello Priya Rahman', $location);
        $this->assertStringContainsString('#'.$order->id, $location);
        $this->assertStringContainsString('/product/red-mug?review=', $location);
        $this->assertNotNull($order->fresh()->review_whatsapp_clicked_at);
    }

    public function test_the_due_filter_lists_delivered_orders_not_yet_asked(): void
    {
        ReviewRequestSetting::create(['enabled' => true, 'enabled_at' => now()->subMonth(), 'days_after_delivery' => 5]);
        $due = $this->deliveredOrder(now()->subDays(6));
        $tooSoon = $this->deliveredOrder(now()->subDays(2));
        $asked = $this->deliveredOrder(now()->subDays(8));
        $asked->forceFill(['review_request_sent_at' => now()->subDay(), 'review_request_status' => 'sent'])->save();
        $clicked = $this->deliveredOrder(now()->subDays(9));
        $clicked->forceFill(['review_whatsapp_clicked_at' => now()])->save();

        Livewire::test(ListOrders::class)
            ->filterTable('review_request_due', true)
            ->assertCanSeeTableRecords([$due])
            ->assertCanNotSeeTableRecords([$tooSoon, $asked, $clicked]);
    }

    // ---- email templates

    public function test_the_new_templates_are_listed_with_their_wording_and_preview_without_raw_placeholders(): void
    {
        EmailTemplate::ensureAll();

        foreach ([TemplateDefinitions::REVIEW_REQUEST, TemplateDefinitions::REVIEW_REMINDER, TemplateDefinitions::CART_REMINDER, TemplateDefinitions::CART_OFFER] as $key) {
            $this->assertNotNull(EmailTemplate::where('key', $key)->first(), $key);

            $built = EmailBuilder::build($key, SampleData::order(), SampleData::customer(), new SiteSetting, null, SampleData::context($key));

            $this->assertStringNotContainsString('{{', $built['subject'].$built['html'], "{$key} leaves no placeholder behind");
            $this->assertStringContainsString('Unsubscribe', $built['html']);
        }
    }

    public function test_the_cart_offer_says_the_real_end_time_and_closes_with_the_cta_line(): void
    {
        $built = EmailBuilder::build(TemplateDefinitions::CART_OFFER, null, null, new SiteSetting, null, SampleData::context(TemplateDefinitions::CART_OFFER));

        $this->assertStringContainsString('ANZ-7K3M9', $built['html']);
        $this->assertStringContainsString('ends on 16 October 2026, 12:00 pm', $built['html']);
        $this->assertStringContainsString('Order now, pay on delivery.', $built['html']);
    }

    public function test_the_built_in_wording_follows_the_brand_voice(): void
    {
        foreach (TemplateDefinitions::MARKETING as $key) {
            $defaults = TemplateDefinitions::defaults($key);
            $notes = BrandVoice::notes($defaults['subject'].' '.$defaults['intro_html'].' '.$defaults['closing_html']);

            $this->assertSame([], $notes, "{$key}: ".implode(' | ', $notes));
        }
    }

    public function test_a_template_preview_page_renders_for_a_cart_email(): void
    {
        EmailTemplate::ensureAll();
        $record = EmailTemplate::where('key', TemplateDefinitions::CART_OFFER)->firstOrFail();

        Livewire::test(EditEmailTemplate::class, ['record' => $record->getRouteKey()])->assertSuccessful();
    }
}
