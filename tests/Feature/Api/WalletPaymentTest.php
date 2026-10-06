<?php

namespace Tests\Feature\Api;

use App\Mail\OrderConfirmationMail;
use App\Mail\WalletPaymentReceivedMail;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Support\WalletPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AdminRecords;
use Tests\TestCase;

class WalletPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PaymentSetting::create(['bkash_number' => '01711111111', 'nagad_number' => '01722222222', 'rocket_number' => null]);
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 1450,
            'stock_quantity' => 20,
            'sku' => 'TP-001',
            'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Fakrul Islam',
            'customer_email' => 'fakrul@example.com',
            'customer_phone' => '01710000000',
            'customer_address' => 'House 12, Road 5',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            'thana' => 'Banani',
            'payment_method' => 'cod',
            'items' => [['product_id' => Product::first()?->id ?? $this->product()->id, 'quantity' => 1]],
        ], $overrides);
    }

    private function wallet(array $overrides = []): array
    {
        return $this->payload(array_merge([
            'payment_method' => 'bkash',
            'payment_sender_number' => '01733333333',
            'payment_trx_id' => '9AB7C2D1XY',
        ], $overrides));
    }

    public function test_cash_on_delivery_needs_no_wallet_fields_and_stays_cod(): void
    {
        $this->postJson('/api/orders', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'cod');

        $order = Order::sole();
        $this->assertNull($order->payment_trx_id);
        $this->assertNull($order->payment_sender_number);
    }

    public function test_cash_on_delivery_ignores_wallet_fields_if_they_are_sent(): void
    {
        $this->postJson('/api/orders', $this->payload(['payment_sender_number' => 'rubbish', 'payment_trx_id' => '!!']))
            ->assertCreated();

        $this->assertNull(Order::sole()->payment_trx_id);
    }

    public function test_a_wallet_order_waits_for_verification_and_keeps_its_details(): void
    {
        $this->postJson('/api/orders', $this->wallet())
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'awaiting_verification')
            ->assertJsonMissingPath('data.payment_trx_id')
            ->assertJsonMissingPath('data.payment_sender_number');

        $order = Order::sole();
        $this->assertSame('9AB7C2D1XY', $order->payment_trx_id);
        $this->assertSame('01733333333', $order->payment_sender_number);
    }

    public function test_a_wallet_order_needs_both_fields(): void
    {
        foreach (['bkash', 'nagad'] as $method) {
            $this->postJson('/api/orders', $this->payload(['payment_method' => $method]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['payment_sender_number', 'payment_trx_id']);
        }

        $this->assertSame(0, Order::count());
    }

    public function test_the_sender_number_must_be_a_bangladeshi_mobile_number_and_is_tidied(): void
    {
        foreach (['0171111', '02123456789', 'abcdefghijk', '01011111111'] as $bad) {
            $this->postJson('/api/orders', $this->wallet(['payment_sender_number' => $bad, 'payment_trx_id' => 'TRX'.random_int(100000, 999999)]))
                ->assertJsonValidationErrors(['payment_sender_number']);
        }

        foreach (['+8801733333333', '8801733333333', '01733-333 333'] as $i => $good) {
            $this->postJson('/api/orders', $this->wallet(['payment_sender_number' => $good, 'payment_trx_id' => "OK{$i}ABCDEF"]))->assertCreated();
        }

        $this->assertSame(['01733333333'], Order::pluck('payment_sender_number')->unique()->values()->all());
    }

    public function test_the_transaction_id_must_be_six_to_twenty_letters_and_digits(): void
    {
        foreach (['ABC12', str_repeat('A', 21), 'ABC 123', 'ABC-123', 'টাকা১২৩৪৫'] as $bad) {
            $this->postJson('/api/orders', $this->wallet(['payment_trx_id' => $bad]))->assertJsonValidationErrors(['payment_trx_id']);
        }

        $this->postJson('/api/orders', $this->wallet(['payment_trx_id' => 'abc123']))->assertCreated();
        $this->postJson('/api/orders', $this->wallet(['payment_trx_id' => str_repeat('9', 20)]))->assertCreated();
    }

    public function test_a_transaction_id_can_back_only_one_order_whatever_its_case(): void
    {
        $this->postJson('/api/orders', $this->wallet(['payment_trx_id' => 'abc123xyz']))->assertCreated();

        $response = $this->postJson('/api/orders', $this->wallet(['payment_trx_id' => 'ABC123XYZ', 'payment_method' => 'nagad']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_trx_id']);

        $this->assertStringContainsString('already been used on another order', $response->json('errors.payment_trx_id.0'));
        $this->assertSame(1, Order::count());
        $this->assertSame('ABC123XYZ', Order::sole()->payment_trx_id);
    }

    public function test_a_refused_duplicate_does_not_use_up_stock(): void
    {
        $product = $this->product();
        $this->postJson('/api/orders', $this->wallet())->assertCreated();
        $before = $product->fresh()->stock_quantity;

        $this->postJson('/api/orders', $this->wallet())->assertStatus(422);

        $this->assertSame($before, $product->fresh()->stock_quantity);
    }

    public function test_a_wallet_without_a_published_number_is_not_accepted(): void
    {
        $this->postJson('/api/orders', $this->wallet(['payment_method' => 'rocket']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_card_is_no_longer_accepted_for_new_orders(): void
    {
        $this->postJson('/api/orders', $this->payload(['payment_method' => 'card']))->assertJsonValidationErrors(['payment_method']);
    }

    public function test_the_shop_is_emailed_about_a_wallet_order_and_not_about_cash_on_delivery(): void
    {
        Mail::fake();
        PaymentSetting::query()->update(['payment_notify_email' => 'owner@example.com']);

        $this->postJson('/api/orders', $this->payload())->assertCreated();
        Mail::assertNotSent(WalletPaymentReceivedMail::class);

        $this->postJson('/api/orders', $this->wallet())->assertCreated();
        Mail::assertSent(WalletPaymentReceivedMail::class, fn (WalletPaymentReceivedMail $mail) => $mail->hasTo('owner@example.com')
            && str_contains($mail->render(), '9AB7C2D1XY')
            && str_contains($mail->render(), '01733333333'));
    }

    public function test_the_notice_falls_back_to_the_contact_email_and_a_failure_is_only_logged(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'contact_email' => 'hello@example.com']);
        Mail::shouldReceive('to')->with('fakrul@example.com')->andReturnSelf();
        Mail::shouldReceive('send')->once();
        Mail::shouldReceive('to')->with('hello@example.com')->andThrow(new \RuntimeException('SMTP down'));
        Log::spy();

        $this->postJson('/api/orders', $this->wallet())->assertCreated();

        Log::shouldHaveReceived('warning')->once();
        $this->assertSame(1, Order::count());
    }

    public function test_the_customer_email_says_where_the_payment_stands(): void
    {
        $this->postJson('/api/orders', $this->wallet())->assertCreated();
        $order = Order::with(['customer', 'items'])->sole();

        $html = (new OrderConfirmationMail($order, SiteSetting::first()))->render();
        $this->assertStringContainsString('We are checking your payment, we will confirm it shortly.', $html);

        $order->forceFill(['payment_status' => 'verified'])->save();
        $this->assertStringContainsString('Payment received.', (new OrderConfirmationMail($order->fresh(['customer', 'items']), SiteSetting::first()))->render());

        $order->forceFill(['payment_status' => 'failed'])->save();
        $this->assertStringContainsString('We could not match this payment, please contact us.', (new OrderConfirmationMail($order->fresh(['customer', 'items']), SiteSetting::first()))->render());
    }

    public function test_track_order_returns_the_payment_state_but_not_the_private_details(): void
    {
        $created = $this->postJson('/api/orders', $this->wallet())->assertCreated();

        $this->getJson('/api/orders/lookup?order_id='.$created->json('data.id').'&phone=01710000000')
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'awaiting_verification')
            ->assertJsonMissingPath('data.payment_trx_id')
            ->assertJsonMissingPath('data.payment_note');
    }

    public function test_orders_made_in_the_admin_get_a_matching_payment_state(): void
    {
        $customer = AdminRecords::customer();
        $base = ['customer_id' => $customer->id, 'status' => 'pending', 'subtotal' => 100, 'delivery_fee' => 80, 'total' => 180];

        $this->assertSame('cod', Order::create($base + ['payment_method' => 'cod'])->payment_status);
        $this->assertSame('awaiting_verification', Order::create($base + ['payment_method' => 'nagad'])->payment_status);
    }

    public function test_the_payment_settings_api_serves_numbers_steps_and_logos(): void
    {
        $data = $this->getJson('/api/payment-settings')->assertOk()->json('data');

        $this->assertSame('01711111111', $data['bkash_number']);
        $this->assertStringContainsString('{{amount}}', $data['bkash_instructions']);
        $this->assertStringContainsString('{{number}}', $data['nagad_instructions']);
        $this->assertNull($data['bkash_logo']);

        PaymentSetting::query()->update(['bkash_instructions' => '<ol><li>My own step.</li></ol>']);
        $this->assertSame('<ol><li>My own step.</li></ol>', $this->getJson('/api/payment-settings')->json('data.bkash_instructions'));
    }

    public function test_number_and_id_tidying(): void
    {
        $this->assertSame('01712345678', WalletPayments::normalizeNumber(' +88 0171-234 5678 '));
        $this->assertTrue(WalletPayments::isValidNumber('01912345678'));
        $this->assertFalse(WalletPayments::isValidNumber('01212345678'));
        $this->assertSame('ABC123', WalletPayments::normalizeTrxId(' abc123 '));
        $this->assertNull(WalletPayments::message('cod'));
    }
}
