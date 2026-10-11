<?php

namespace Tests\Feature\Api;

use App\Mail\TransactionalMail;
use App\Models\Category;
use App\Models\Customer;
use App\Models\EmailOptOut;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ReviewRequestSetting;
use App\Support\Recovery\Unsubscribe;
use App\Support\Reviews\ReviewLinks;
use App\Support\Reviews\ReviewRequests;
use App\Support\WhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReviewRequestsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private const START = '2026-10-01 06:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::START);
        Mail::fake();

        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'Mugs', 'slug' => 'mugs'])->id,
            'name' => 'Red mug', 'slug' => 'red-mug', 'price' => 600, 'stock_quantity' => 20, 'sku' => 'MUG-RED', 'is_active' => true,
        ]);

        ReviewRequestSetting::query()->create(['enabled' => true, 'enabled_at' => now()->subDay(), 'days_after_delivery' => 5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function delivered(array $overrides = [], ?Carbon $at = null): Order
    {
        static $n = 0;
        $n++;

        $customer = Customer::create(array_merge([
            'name' => 'Priya Rahman', 'email' => "priya{$n}@example.com", 'phone' => '0171000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'password' => 'x-'.$n,
        ], $overrides['customer'] ?? []));

        $order = Order::create([
            'customer_id' => $customer->id, 'status' => 'delivered', 'subtotal' => 600, 'delivery_fee' => 80, 'total' => 680,
            'payment_method' => 'cod', 'delivered_at' => $at ?? now(),
        ]);
        $order->items()->create([
            'product_id' => $this->product->id, 'product_name' => 'Red mug', 'quantity' => 1, 'price' => 600, 'sku' => 'MUG-RED',
        ]);

        return $order->fresh(['items', 'customer']);
    }

    private function templates(): array
    {
        return Mail::sent(TransactionalMail::class)->map(fn (TransactionalMail $m) => $m->templateKey)->values()->all();
    }

    // ---- the scheduled run

    public function test_a_request_goes_after_the_set_days_and_only_once(): void
    {
        $order = $this->delivered();

        Carbon::setTestNow(now()->addDays(4)->addHours(23));
        $this->assertSame(0, ReviewRequests::run()['sent']);

        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(1, ReviewRequests::run()['sent']);
        $this->assertSame(0, ReviewRequests::run()['sent']);

        $this->assertSame(['review_request'], $this->templates());
        $this->assertNotNull($order->fresh()->review_request_sent_at);
        $this->assertSame('sent', $order->fresh()->review_request_status);
    }

    public function test_the_email_names_the_order_and_carries_a_working_signed_link_per_piece(): void
    {
        $order = $this->delivered();
        Carbon::setTestNow(now()->addDays(6));
        ReviewRequests::run();

        Mail::assertSent(TransactionalMail::class, function (TransactionalMail $mail) use ($order) {
            $html = $mail->render();

            return str_contains($html, '#'.$order->id)
                && preg_match('#/product/red-mug\?review=([A-Za-z0-9_\-.]+)\#reviews#', $html, $m)
                && ReviewLinks::resolve($m[1])?->id === $order->items->first()->id
                && str_contains($html, '/unsubscribe/');
        });
    }

    public function test_it_is_off_until_switched_on_and_never_reaches_orders_delivered_before_that(): void
    {
        $before = $this->delivered(at: now()->subDays(30));
        ReviewRequestSetting::query()->first()->update(['enabled_at' => now()]);
        $after = $this->delivered(at: now()->addHour());

        Carbon::setTestNow(now()->addDays(10));
        ReviewRequests::run();

        $this->assertNull($before->fresh()->review_request_sent_at, 'old orders are not mailed in bulk');
        $this->assertNotNull($after->fresh()->review_request_sent_at);

        ReviewRequestSetting::query()->first()->update(['enabled' => false]);
        $late = $this->delivered(at: now()->subDays(6));
        ReviewRequests::run();
        $this->assertNull($late->fresh()->review_request_sent_at);
    }

    public function test_someone_who_already_reviewed_is_skipped(): void
    {
        $order = $this->delivered();
        ProductReview::create([
            'product_id' => $this->product->id, 'order_id' => $order->id, 'order_item_id' => $order->items->first()->id,
            'name' => 'Priya', 'rating' => 5, 'body' => 'Lovely mug, we use it every morning.', 'status' => 'approved',
        ]);

        Carbon::setTestNow(now()->addDays(6));
        ReviewRequests::run();

        Mail::assertNothingSent();
        $this->assertSame('reviewed', $order->fresh()->review_request_status);
    }

    public function test_an_unsubscribed_customer_and_one_without_email_are_skipped(): void
    {
        $opted = $this->delivered();
        $none = $this->delivered(['customer' => ['email' => null]]);
        EmailOptOut::record($opted->customer->email, null, 'test');

        Carbon::setTestNow(now()->addDays(6));
        ReviewRequests::run();

        Mail::assertNothingSent();
        $this->assertSame('opted_out', $opted->fresh()->review_request_status);
        $this->assertSame('no_email', $none->fresh()->review_request_status);
    }

    public function test_the_single_reminder_goes_after_its_days_and_only_when_switched_on(): void
    {
        $this->delivered();
        Carbon::setTestNow(now()->addDays(6));
        ReviewRequests::run();

        Carbon::setTestNow(now()->addDays(8));
        $this->assertSame(0, ReviewRequests::run()['reminded'], 'off by default');

        ReviewRequestSetting::query()->first()->update(['reminder_enabled' => true, 'reminder_days' => 7]);
        $this->assertSame(1, ReviewRequests::run()['reminded']);
        $this->assertSame(0, ReviewRequests::run()['reminded']);

        $this->assertSame(['review_request', 'review_request_reminder'], $this->templates());
    }

    public function test_marking_an_order_delivered_records_the_day_and_keeps_the_first(): void
    {
        $order = Order::create([
            'customer_id' => Customer::create(['name' => 'A', 'email' => 'a@example.com', 'phone' => '01700000001', 'password' => 'x'])->id,
            'status' => 'shipped', 'subtotal' => 1, 'delivery_fee' => 0, 'total' => 1, 'payment_method' => 'cod',
        ]);

        $order->update(['status' => 'delivered']);
        $first = $order->fresh()->delivered_at;
        $this->assertNotNull($first);

        Carbon::setTestNow(now()->addDays(2));
        $order->update(['status' => 'shipped']);
        $order->update(['status' => 'delivered']);

        $this->assertTrue($order->fresh()->delivered_at->equalTo($first));
    }

    // ---- the link

    public function test_a_link_stands_for_one_line_and_ends_after_sixty_days(): void
    {
        $order = $this->delivered();
        $line = $order->items->first();
        $order->forceFill(['review_request_sent_at' => now()])->save();
        $line->refresh();

        $token = ReviewLinks::make($line);

        $this->assertSame($line->id, ReviewLinks::resolve($token)?->id);

        Carbon::setTestNow(now()->addDays(59));
        $this->assertNotNull(ReviewLinks::resolve($token));

        Carbon::setTestNow(now()->addDays(2));
        $this->assertNull(ReviewLinks::resolve($token), 'ended after 60 days');
    }

    public function test_a_forged_or_changed_link_does_not_resolve(): void
    {
        $line = $this->delivered()->items->first();
        $token = ReviewLinks::make($line);
        [$payload, $signature] = explode('.', $token);

        $this->assertNull(ReviewLinks::resolve($payload.'.'.str_repeat('0', 32)));
        $this->assertNull(ReviewLinks::resolve(rtrim(strtr(base64_encode('999.'.(time() + 100000)), '+/', '-_'), '=').'.'.$signature));
        $this->assertNull(ReviewLinks::resolve('garbage'));
        $this->assertNull(ReviewLinks::resolve(null));
    }

    public function test_a_review_through_the_link_needs_no_order_number_or_phone_and_uses_the_link_up(): void
    {
        $line = $this->delivered()->items->first();
        $token = ReviewLinks::make($line);

        $this->getJson("/api/products/red-mug/review-link?token={$token}")->assertOk()->assertJson(['valid' => true, 'name' => 'Priya Rahman']);

        $this->postJson('/api/products/red-mug/reviews', ['review_token' => $token, 'rating' => 5, 'name' => 'Priya', 'body' => 'We use it every single morning.'])
            ->assertCreated();

        $review = ProductReview::query()->firstOrFail();
        $this->assertSame($line->id, $review->order_item_id);
        $this->assertSame('pending', $review->status, 'the admin still approves every review');
        $this->assertTrue($review->verified_purchase);

        // Single use: the same link cannot review the same line again.
        $this->postJson('/api/products/red-mug/reviews', ['review_token' => $token, 'rating' => 4, 'name' => 'Priya', 'body' => 'A second go with the same link.'])
            ->assertStatus(422);
        $this->getJson("/api/products/red-mug/review-link?token={$token}")->assertOk()->assertJson(['valid' => false, 'reason' => 'used']);
    }

    public function test_a_link_for_another_piece_or_a_bad_link_is_refused_and_the_manual_way_still_works(): void
    {
        Product::create([
            'category_id' => $this->product->category_id, 'name' => 'Blue mug', 'slug' => 'blue-mug', 'price' => 600, 'stock_quantity' => 5, 'sku' => 'MUG-BLUE', 'is_active' => true,
        ]);
        $order = $this->delivered();
        $token = ReviewLinks::make($order->items->first());

        $this->postJson('/api/products/blue-mug/reviews', ['review_token' => $token, 'rating' => 5, 'name' => 'Priya', 'body' => 'Wrong piece for this link.'])->assertStatus(422);
        $this->postJson('/api/products/red-mug/reviews', ['review_token' => 'nope.nope', 'rating' => 5, 'name' => 'Priya', 'body' => 'Not a real link at all.'])->assertStatus(422);

        // Order number and phone still prove a purchase, as before.
        $this->postJson('/api/products/red-mug/reviews', [
            'order_number' => (string) $order->id, 'phone' => $order->customer->phone, 'rating' => 5, 'name' => 'Priya', 'body' => 'The manual way still works fine.',
        ])->assertCreated();

    }

    // ---- wa.me

    public function test_a_whatsapp_link_is_digits_only_with_the_message_encoded(): void
    {
        $this->assertSame('https://wa.me/8801886004421', WhatsApp::link('+880 1886-004421'));
        $this->assertSame('https://wa.me/8801886004421', WhatsApp::link('01886004421'));
        $this->assertSame('https://wa.me/8801886004421?text=Hello%20Priya%2C%20your%20order%20%2342%3A%20https%3A%2F%2Fx.test%2Fa%3Fb%3D1%26c%3D2', WhatsApp::link('+8801886004421', 'Hello Priya, your order #42: https://x.test/a?b=1&c=2'));
        $this->assertSame('https://wa.me/8801886004421', WhatsApp::link('+8801886004421', '   '));
        $this->assertNull(WhatsApp::link(null, 'Hello'));
        $this->assertNull(WhatsApp::link('abc', 'Hello'));
    }

    public function test_the_international_format_check(): void
    {
        $this->assertTrue(WhatsApp::isInternational('+8801886004421'));
        $this->assertFalse(WhatsApp::isInternational('01886004421'));
        $this->assertFalse(WhatsApp::isInternational('+88 018'));
        $this->assertFalse(WhatsApp::isInternational('8801886004421'));
    }

    public function test_message_placeholders_are_filled(): void
    {
        $this->assertSame('Hello Priya, order #42: https://x.test/r', WhatsApp::fill('Hello {name}, order #{order}: {link}', ['name' => 'Priya', 'order' => 42, 'link' => 'https://x.test/r']));
    }

    public function test_the_unsubscribe_token_round_trips_and_rejects_changes(): void
    {
        $token = Unsubscribe::token('Priya@Example.com', '+8801711111111');

        $this->assertSame(['email' => 'priya@example.com', 'phone' => '01711111111'], Unsubscribe::resolve($token));
        $this->assertNull(Unsubscribe::resolve($token.'x'));
        $this->assertNull(Unsubscribe::resolve('a.b'));
    }
}
