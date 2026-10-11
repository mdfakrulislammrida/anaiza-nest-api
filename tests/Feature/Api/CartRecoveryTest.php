<?php

namespace Tests\Feature\Api;

use App\Mail\TransactionalMail;
use App\Models\AbandonedCheckout;
use App\Models\CartRecoverySetting;
use App\Models\Category;
use App\Models\CookieConsentSetting;
use App\Models\Coupon;
use App\Models\EmailOptOut;
use App\Models\Order;
use App\Models\Product;
use App\Support\Recovery\CartRecovery;
use App\Support\Recovery\Unsubscribe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CartRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    /** 14 Oct 2026, 12:00 in Dhaka. */
    private const NOON = '2026-10-14 06:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOON);
        Mail::fake();
        Bus::fake();

        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets'])->id,
            'name' => 'Tea set', 'slug' => 'tea-set', 'price' => 1450, 'stock_quantity' => 10, 'sku' => 'TS-1', 'is_active' => true,
        ]);

        $this->settings(['enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function settings(array $values = []): CartRecoverySetting
    {
        $row = CartRecoverySetting::query()->first() ?? new CartRecoverySetting;
        $row->fill($values)->save();

        return $row;
    }

    private function report(array $overrides = []): void
    {
        $this->postJson('/api/checkout-sessions', array_merge([
            'session_token' => 'sess-'.str_repeat('a', 20),
            'stage' => 'contact_filled',
            'name' => 'Priya Rahman',
            'email' => 'priya@example.com',
            'phone' => '01711111111',
            'marketing_consent' => true,
            'items' => [['product_id' => $this->product->id, 'sku' => 'TS-1', 'name' => 'Tea set', 'price' => 1450, 'quantity' => 1, 'slug' => 'tea-set']],
        ], $overrides))->assertNoContent();
    }

    private function checkout(): AbandonedCheckout
    {
        return AbandonedCheckout::query()->firstOrFail();
    }

    private function sentTemplates(): array
    {
        return Mail::sent(TransactionalMail::class)->map(fn (TransactionalMail $mail) => $mail->templateKey)->values()->all();
    }

    // ---- storing

    public function test_a_visitor_with_nothing_to_contact_them_by_is_counted_never_stored(): void
    {
        $this->report(['email' => null, 'phone' => null, 'name' => 'Nobody', 'count_only' => true, 'stage' => 'checkout_started']);
        $this->report(['email' => 'not-an-email', 'phone' => '123', 'count_only' => true, 'stage' => 'checkout_started']);

        $this->assertSame(0, AbandonedCheckout::count());
        $this->assertSame(2, (int) DB::table('checkout_daily_counts')->where('stage', 'checkout_started')->value('visitors'));
        $this->assertSame('2026-10-14', DB::table('checkout_daily_counts')->value('day'));
    }

    public function test_an_anonymous_report_without_the_count_flag_changes_nothing(): void
    {
        $this->report(['email' => null, 'phone' => null]);

        $this->assertSame(0, AbandonedCheckout::count());
        $this->assertSame(0, DB::table('checkout_daily_counts')->count());
    }

    public function test_notice_mode_stores_an_identified_cart(): void
    {
        CookieConsentSetting::create(['enabled' => true, 'mode' => 'notice']);

        $this->report(['marketing_consent' => false]);

        $checkout = $this->checkout();
        $this->assertSame('priya@example.com', $checkout->email);
        $this->assertSame('01711111111', $checkout->phone);
        $this->assertSame(1450, $checkout->cart_total);
    }

    public function test_opt_in_mode_stores_nothing_without_marketing_consent(): void
    {
        CookieConsentSetting::create(['enabled' => true, 'mode' => 'opt_in']);

        $this->report(['marketing_consent' => false]);
        $this->assertSame(0, AbandonedCheckout::count());

        $this->report(['marketing_consent' => true]);
        $this->assertSame(1, AbandonedCheckout::count());
    }

    public function test_the_stage_only_moves_forward_and_the_last_five_viewed_products_are_kept(): void
    {
        $this->report(['stage' => 'contact_filled', 'viewed_products' => [1, 2, 3]]);
        $this->report(['stage' => 'cart', 'viewed_products' => [4, 5, 6, 7]]);

        $checkout = $this->checkout();
        $this->assertSame('contact_filled', $checkout->stage);
        $this->assertSame([3, 4, 5, 6, 7], $checkout->viewed_products);
        $this->assertSame(1, AbandonedCheckout::count(), 'one row per session');
    }

    // ---- an order stops everything

    public function test_an_order_closes_the_checkout_and_no_message_follows(): void
    {
        $this->report();
        $this->postJson('/api/orders', [
            'customer_name' => 'Priya Rahman', 'customer_phone' => '01711111111', 'customer_email' => 'priya@example.com',
            'customer_address' => 'House 1', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'checkout_session' => 'sess-'.str_repeat('a', 20),
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertCreated();

        $checkout = $this->checkout();
        $this->assertSame('ordered', $checkout->stage);
        $this->assertNotNull($checkout->order_id);
        $this->assertSame('ordered', $checkout->status);

        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(0, CartRecovery::run()['sent']);
        $this->assertSame([], array_diff($this->sentTemplates(), ['order_confirmation']));
    }

    public function test_an_order_is_matched_by_email_or_phone_when_the_session_is_not_known(): void
    {
        $this->report(['phone' => null]);

        $this->postJson('/api/orders', [
            'customer_name' => 'Priya', 'customer_phone' => '01799999999', 'customer_email' => 'PRIYA@example.com',
            'customer_address' => 'House 1', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame('ordered', $this->checkout()->stage);
    }

    public function test_an_order_after_a_reminder_counts_as_recovered_and_carries_the_recovery_source(): void
    {
        $this->report();
        $checkout = $this->checkout();
        $checkout->forceFill(['messages' => ['step1' => now()->toIso8601String()], 'messages_count' => 1])->save();

        $id = $this->postJson('/api/orders', [
            'customer_name' => 'Priya', 'customer_phone' => '01711111111', 'customer_email' => 'priya@example.com',
            'customer_address' => 'House 1', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'recovery_token' => $checkout->recovery_token, 'utm_source' => 'facebook',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        $this->assertSame('recovered', $checkout->fresh()->status);
        $order = Order::findOrFail($id);
        $this->assertSame('cart_recovery', $order->utm_source);
        $this->assertSame('email', $order->utm_medium);
    }

    // ---- timing

    public function test_step_one_goes_after_an_hour_and_only_once(): void
    {
        $this->report();

        Carbon::setTestNow(now()->addMinutes(59));
        $this->assertSame(0, CartRecovery::run()['sent']);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertSame(1, CartRecovery::run()['sent']);
        $this->assertSame(0, CartRecovery::run()['sent'], 'a second run finds the step already sent');

        $this->assertSame(['cart_reminder'], $this->sentTemplates());
        $this->assertSame(1, $this->checkout()->messages_count);
    }

    public function test_step_two_goes_after_forty_eight_hours_with_the_offer_and_a_bound_coupon(): void
    {
        $this->settings(['step2_coupon_enabled' => true, 'coupon_type' => 'percent', 'coupon_amount' => 10, 'coupon_min_order' => 1000, 'coupon_valid_hours' => 48]);
        $this->report();

        Carbon::setTestNow(now()->addMinutes(61));
        CartRecovery::run();

        // Closer to the end of the window, still waiting for step two: the quiet hours are not in the way at noon.
        Carbon::setTestNow(Carbon::parse(self::NOON)->addHours(47));
        $this->assertSame(0, CartRecovery::run()['sent']);

        Carbon::setTestNow(Carbon::parse(self::NOON)->addHours(49));
        // 49 hours after noon is 13:00 in Dhaka two days later, and the cart is 49 hours old (inside the 7 day window).
        $this->assertSame(1, CartRecovery::run()['sent']);

        $this->assertSame(['cart_reminder', 'cart_offer'], $this->sentTemplates());

        $coupon = Coupon::query()->firstOrFail();
        $this->assertTrue($coupon->single_use);
        $this->assertSame('priya@example.com', $coupon->bound_email);
        $this->assertSame('01711111111', $coupon->bound_phone);
        $this->assertSame(1000, $coupon->min_order);
        $this->assertTrue($coupon->valid_until->equalTo(now()->addHours(48)), 'exactly the stated hours from the send');
        $this->assertSame($coupon->id, $this->checkout()->coupon_id);

        Mail::assertSent(TransactionalMail::class, fn (TransactionalMail $mail) => $mail->templateKey === 'cart_offer'
            && str_contains($mail->render(), $coupon->code)
            && str_contains($mail->render(), '10%'));
    }

    public function test_step_two_without_the_coupon_switch_is_a_plain_reminder_and_makes_no_coupon(): void
    {
        $this->report();

        Carbon::setTestNow(now()->addMinutes(61));
        CartRecovery::run();
        Carbon::setTestNow(Carbon::parse(self::NOON)->addHours(49));
        CartRecovery::run();

        $this->assertSame(['cart_reminder', 'cart_reminder'], $this->sentTemplates());
        $this->assertSame(0, Coupon::count());
    }

    public function test_the_maximum_number_of_messages_is_respected(): void
    {
        $this->settings(['max_messages' => 1]);
        $this->report();

        Carbon::setTestNow(now()->addMinutes(61));
        CartRecovery::run();
        Carbon::setTestNow(Carbon::parse(self::NOON)->addHours(49));

        $this->assertSame(0, CartRecovery::run()['sent']);
    }

    // ---- quiet hours and the daily cap

    public function test_nothing_is_sent_in_quiet_hours_and_it_waits_until_morning(): void
    {
        // Left at 21:30 Dhaka (15:30 UTC); the hour is up at 22:30, which is quiet.
        Carbon::setTestNow('2026-10-14 15:30:00');
        $this->report();

        Carbon::setTestNow('2026-10-14 17:00:00'); // 23:00 Dhaka
        $result = CartRecovery::run();
        $this->assertSame(0, $result['sent']);
        $this->assertSame('quiet_hours', $result['waiting']);

        Carbon::setTestNow('2026-10-14 01:59:00'); // earlier than the send: nothing either
        Carbon::setTestNow('2026-10-14 22:00:00'); // 04:00 next day in Dhaka, still quiet
        $this->assertSame('quiet_hours', CartRecovery::run()['waiting']);

        Carbon::setTestNow('2026-10-15 02:30:00'); // 08:30 Dhaka
        $this->assertSame(1, CartRecovery::run()['sent'], 'it waited, and now goes');
    }

    public function test_the_daily_cap_holds_back_the_rest_until_tomorrow(): void
    {
        $this->settings(['daily_cap' => 1]);
        $this->report(['session_token' => 'sess-'.str_repeat('a', 20), 'email' => 'one@example.com']);
        $this->report(['session_token' => 'sess-'.str_repeat('b', 20), 'email' => 'two@example.com', 'phone' => '01722222222']);

        Carbon::setTestNow(now()->addMinutes(61));
        $first = CartRecovery::run();
        $this->assertSame(1, $first['sent']);
        $this->assertSame('daily_cap', $first['waiting']);
        $this->assertSame(0, CartRecovery::run()['sent']);

        // The next morning (Dhaka day changes at 18:00 UTC), inside the cart's 7 day window.
        Carbon::setTestNow('2026-10-15 05:00:00');
        $this->assertSame(1, CartRecovery::run()['sent']);
    }

    // ---- who is never written to

    public function test_off_means_nothing_is_sent(): void
    {
        $this->settings(['enabled' => false]);
        $this->report();

        Carbon::setTestNow(now()->addDay());

        $this->assertSame('off', CartRecovery::run()['waiting']);
        Mail::assertNothingSent();
    }

    public function test_a_small_cart_is_left_alone(): void
    {
        $this->settings(['min_cart_value' => 2000]);
        $this->report();

        Carbon::setTestNow(now()->addHours(2));

        $this->assertSame(0, CartRecovery::run()['sent']);
    }

    public function test_a_phone_only_checkout_gets_no_email(): void
    {
        $this->report(['email' => null]);

        Carbon::setTestNow(now()->addHours(2));

        $this->assertSame(0, CartRecovery::run()['sent']);
        $this->assertSame('open', $this->checkout()->status);
    }

    public function test_a_cart_older_than_the_window_is_not_written_to(): void
    {
        $this->report();

        Carbon::setTestNow(now()->addDays(8));

        $this->assertSame(0, CartRecovery::run()['sent']);
    }

    // ---- unsubscribe

    public function test_the_unsubscribe_link_is_honoured_at_once_by_email_and_phone(): void
    {
        $this->report();
        $url = Unsubscribe::url('priya@example.com', '01711111111');

        $this->get($url)->assertOk()->assertSee('You are unsubscribed');

        $this->assertTrue(EmailOptOut::covers('PRIYA@example.com', null));
        $this->assertTrue(EmailOptOut::covers(null, '+8801711111111'));
        $this->assertSame('unsubscribed', $this->checkout()->status);

        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(0, CartRecovery::run()['sent']);
    }

    public function test_a_mail_clients_one_click_post_works_without_a_token_from_the_browser(): void
    {
        $this->report();

        $this->post(Unsubscribe::url('priya@example.com', null))->assertOk();

        $this->assertTrue(EmailOptOut::covers('priya@example.com', null));
    }

    public function test_a_forged_unsubscribe_link_changes_nothing(): void
    {
        $this->get('/unsubscribe/'.base64_encode('{"e":"priya@example.com"}').'.deadbeef')->assertNotFound();

        $this->assertSame(0, EmailOptOut::count());
    }

    public function test_every_cart_email_carries_the_unsubscribe_link_and_header(): void
    {
        $this->report();

        Carbon::setTestNow(now()->addMinutes(61));
        CartRecovery::run();

        Mail::assertSent(TransactionalMail::class, function (TransactionalMail $mail) {
            $html = $mail->render();

            return str_contains($html, '/unsubscribe/') && str_contains($html, 'Order now, pay on delivery.');
        });
    }

    // ---- the recovery link

    public function test_the_recovery_link_restores_the_cart_with_stock_and_price_changes_called_out(): void
    {
        $other = Product::create([
            'category_id' => $this->product->category_id, 'name' => 'Glass teapot', 'slug' => 'glass-teapot', 'price' => 900,
            'stock_quantity' => 0, 'sku' => 'GT-1', 'is_active' => true,
        ]);
        $this->report(['items' => [
            ['product_id' => $this->product->id, 'sku' => 'TS-1', 'name' => 'Tea set', 'price' => 1450, 'quantity' => 3, 'slug' => 'tea-set'],
            ['product_id' => $other->id, 'sku' => 'GT-1', 'name' => 'Glass teapot', 'price' => 900, 'quantity' => 1, 'slug' => 'glass-teapot'],
        ]]);

        // Since then: the tea set went up in price and only two are left; the teapot is gone.
        $this->product->update(['price' => 1600, 'stock_quantity' => 2]);

        $response = $this->getJson('/api/recover/'.$this->checkout()->recovery_token)->assertOk();

        $items = collect($response->json('items'))->keyBy('slug');
        $this->assertSame('reduced', $items['tea-set']['status']);
        $this->assertSame(2, $items['tea-set']['quantity']);
        $this->assertSame(1600, $items['tea-set']['price']);
        $this->assertSame(1450, $items['tea-set']['was_price']);
        $this->assertSame('out_of_stock', $items['glass-teapot']['status']);
        $this->assertSame(0, $items['glass-teapot']['quantity']);
        $this->assertSame('priya@example.com', $response->json('email'));
    }

    public function test_the_recovery_link_applies_a_coupon_that_can_still_be_used_and_not_one_that_cannot(): void
    {
        $this->report();
        $checkout = $this->checkout();
        $coupon = Coupon::create([
            'code' => 'ANZ-ABCDE', 'discount_type' => 'percent', 'amount' => 10, 'is_active' => true, 'single_use' => true,
            'valid_until' => now()->addHours(10), 'bound_email' => 'priya@example.com',
        ]);
        $checkout->forceFill(['coupon_id' => $coupon->id])->save();

        $this->getJson('/api/recover/'.$checkout->recovery_token)->assertOk()
            ->assertJsonPath('coupon.code', 'ANZ-ABCDE')->assertJsonPath('coupon_ended', false);

        $coupon->forceFill(['used_count' => 1])->save();

        $this->getJson('/api/recover/'.$checkout->recovery_token)->assertOk()
            ->assertJsonPath('coupon', null)->assertJsonPath('coupon_ended', true);
    }

    public function test_an_unknown_recovery_link_is_a_calm_not_found(): void
    {
        $this->getJson('/api/recover/does-not-exist')->assertNotFound()->assertJson(['found' => false]);
    }

    // ---- housekeeping

    public function test_old_rows_expire_and_are_deleted_after_the_retention_period(): void
    {
        $this->report();

        Carbon::setTestNow(now()->addDays(8));
        CartRecovery::tidy();
        $this->assertSame('expired', $this->checkout()->status);

        Carbon::setTestNow(Carbon::parse(self::NOON)->addDays(91));
        $result = CartRecovery::tidy();
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(0, AbandonedCheckout::count());
    }
}
