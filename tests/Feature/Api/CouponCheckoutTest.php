<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Support\Coupons\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CouponCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 06:00:00');
        Bus::fake();

        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets'])->id,
            'name' => 'Tea set', 'slug' => 'tea-set', 'price' => 1000, 'stock_quantity' => 50, 'sku' => 'TS-1', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function coupon(array $overrides = []): Coupon
    {
        static $n = 0;
        $n++;

        return Coupon::create(array_merge([
            'code' => "TEST-{$n}", 'discount_type' => 'percent', 'amount' => 10, 'is_active' => true,
        ], $overrides));
    }

    private function order(array $overrides = [], int $quantity = 1)
    {
        static $p = 0;
        $p++;

        return $this->postJson('/api/orders', array_merge([
            'customer_name' => 'Nusrat Jahan', 'customer_phone' => '0171000'.str_pad((string) $p, 4, '0', STR_PAD_LEFT), 'customer_email' => "buyer{$p}@example.com",
            'customer_address' => 'House 1', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
        ], $overrides));
    }

    public function test_a_percent_coupon_takes_its_share_off_the_subtotal_and_is_recorded(): void
    {
        $coupon = $this->coupon(['code' => 'TEN', 'amount' => 10]);

        $response = $this->order(['coupon_code' => ' ten '], 1)->assertCreated();

        $order = Order::findOrFail($response->json('data.id'));
        $this->assertSame(1000, $order->subtotal);
        $this->assertSame(100, $order->discount_amount);
        $this->assertSame('TEN', $order->coupon_code);
        $this->assertSame(1000 - 100 + 80, $order->total, 'subtotal - discount + delivery');
        $this->assertSame(100, $response->json('data.discount_amount'));

        $coupon->refresh();
        $this->assertSame(1, $coupon->used_count);
        $this->assertSame($order->id, $coupon->used_order_id);
    }

    public function test_a_fixed_coupon_never_takes_off_more_than_the_cart(): void
    {
        $this->coupon(['code' => 'BIG', 'discount_type' => 'fixed', 'amount' => 5000]);

        $order = Order::findOrFail($this->order(['coupon_code' => 'BIG'])->assertCreated()->json('data.id'));

        $this->assertSame(1000, $order->discount_amount);
        $this->assertSame(80, $order->total, 'only delivery is left to pay');
    }

    public function test_a_discount_never_costs_the_customer_free_delivery(): void
    {
        $this->coupon(['code' => 'TWOK', 'discount_type' => 'fixed', 'amount' => 500]);

        // 3 x 1000 = 3000 > 2000 threshold; the coupon brings the payable subtotal to 2500, still above, so free either way.
        // 2100 is the case that matters: above the threshold before the coupon, below it after.
        $this->product->update(['price' => 2100]);

        $order = Order::findOrFail($this->order(['coupon_code' => 'TWOK'])->assertCreated()->json('data.id'));

        $this->assertSame(0, $order->delivery_fee);
        $this->assertSame(2100 - 500, $order->total);
    }

    public function test_an_ended_or_not_yet_started_coupon_is_refused_with_its_real_time(): void
    {
        $this->coupon(['code' => 'ENDED', 'valid_until' => now()->subMinute()]);
        $this->coupon(['code' => 'SOON', 'valid_from' => now()->addHour()]);

        $this->order(['coupon_code' => 'ENDED'])->assertStatus(422)->assertJsonValidationErrors('coupon_code');
        $this->order(['coupon_code' => 'SOON'])->assertStatus(422)->assertJsonValidationErrors('coupon_code');
        $this->assertSame(0, Order::count(), 'a refused code places no order');
    }

    public function test_the_end_is_exact_to_the_minute_in_dhaka_time(): void
    {
        // 18:00 on 14 Oct in Dhaka is 12:00 UTC. At 11:59 UTC it works; at 12:01 it has ended.
        $this->coupon(['code' => 'SIX', 'valid_until' => Carbon::parse('2026-10-14 18:00:00', 'Asia/Dhaka')]);

        Carbon::setTestNow('2026-10-14 11:59:00');
        $this->order(['coupon_code' => 'SIX'])->assertCreated();

        $this->coupon(['code' => 'SIX2', 'valid_until' => Carbon::parse('2026-10-14 18:00:00', 'Asia/Dhaka')]);
        Carbon::setTestNow('2026-10-14 12:01:00');
        $this->order(['coupon_code' => 'SIX2'])->assertStatus(422);
    }

    public function test_a_single_use_coupon_works_once(): void
    {
        $this->coupon(['code' => 'ONCE', 'single_use' => true]);

        $this->order(['coupon_code' => 'ONCE'])->assertCreated();
        $this->order(['coupon_code' => 'ONCE'])->assertStatus(422)->assertJsonValidationErrors('coupon_code');

        $this->assertSame(1, Order::count());
    }

    public function test_a_coupon_that_is_not_single_use_can_be_used_again(): void
    {
        $this->coupon(['code' => 'MANY']);

        $this->order(['coupon_code' => 'MANY'])->assertCreated();
        $this->order(['coupon_code' => 'MANY'])->assertCreated();

        $this->assertSame(2, Coupon::where('code', 'MANY')->value('used_count'));
    }

    public function test_a_bound_coupon_works_only_for_its_email_or_phone(): void
    {
        $this->coupon(['code' => 'MINE', 'bound_email' => 'priya@example.com', 'bound_phone' => '01711111111', 'single_use' => true]);

        $this->order(['coupon_code' => 'MINE', 'customer_email' => 'someone.else@example.com', 'customer_phone' => '01799999999'])
            ->assertStatus(422)->assertJsonValidationErrors('coupon_code');

        // The right email, typed in capitals, is the same email.
        $this->order(['coupon_code' => 'MINE', 'customer_email' => 'Priya@Example.com', 'customer_phone' => '01799999999'])->assertCreated();
    }

    public function test_a_bound_coupon_also_works_for_its_phone_however_it_is_typed(): void
    {
        $this->coupon(['code' => 'PHONE', 'bound_phone' => '01711111111']);

        $this->order(['coupon_code' => 'PHONE', 'customer_email' => 'other@example.com', 'customer_phone' => '+880 1711-111111'])->assertCreated();
    }

    public function test_the_minimum_order_is_enforced_on_the_cart(): void
    {
        $this->coupon(['code' => 'MIN', 'min_order' => 1500]);

        $this->order(['coupon_code' => 'MIN'], 1)->assertStatus(422)->assertJsonValidationErrors('coupon_code');
        $this->order(['coupon_code' => 'MIN'], 2)->assertCreated();
    }

    public function test_two_codes_are_not_combined_and_an_off_coupon_is_refused(): void
    {
        $this->coupon(['code' => 'ONE']);
        $this->coupon(['code' => 'TWO']);
        $this->coupon(['code' => 'OFF', 'is_active' => false]);

        $this->order(['coupon_code' => 'ONE,TWO'])->assertStatus(422);
        $this->order(['coupon_code' => 'OFF'])->assertStatus(422);

        // One code, one discount: the order carries a single coupon and its single discount.
        $order = Order::findOrFail($this->order(['coupon_code' => 'ONE'])->assertCreated()->json('data.id'));
        $this->assertSame('ONE', $order->coupon_code);
        $this->assertSame(100, $order->discount_amount);
    }

    public function test_the_preview_endpoint_agrees_with_the_order_and_refuses_in_the_same_words(): void
    {
        $this->coupon(['code' => 'PREVIEW', 'amount' => 20]);
        $items = [['product_id' => $this->product->id, 'quantity' => 2]];

        $this->postJson('/api/coupons/validate', ['code' => 'preview', 'items' => $items])
            ->assertOk()
            ->assertJson(['valid' => true, 'code' => 'PREVIEW', 'discount' => 400, 'subtotal' => 2000, 'value_label' => '20%']);

        $this->postJson('/api/coupons/validate', ['code' => 'NOPE', 'items' => $items])
            ->assertStatus(422)->assertJson(['valid' => false]);
    }

    public function test_a_preview_that_was_fine_is_checked_again_when_the_order_is_placed(): void
    {
        $coupon = $this->coupon(['code' => 'LATE', 'valid_until' => now()->addMinutes(5)]);
        $items = [['product_id' => $this->product->id, 'quantity' => 1]];

        $this->postJson('/api/coupons/validate', ['code' => 'LATE', 'items' => $items])->assertOk();

        Carbon::setTestNow(now()->addMinutes(10));
        $this->order(['coupon_code' => 'LATE'])->assertStatus(422);

        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_a_generated_code_is_readable_unique_and_bound(): void
    {
        $coupon = CouponService::createFor('Priya@Example.com', '+8801711111111', 'percent', 15, 1200, 48);

        $this->assertMatchesRegularExpression('/^ANZ-[2-9A-HJKMNP-Z]{5}$/', $coupon->code);
        $this->assertSame('priya@example.com', $coupon->bound_email);
        $this->assertSame('01711111111', $coupon->bound_phone);
        $this->assertTrue($coupon->single_use);
        $this->assertSame(1200, $coupon->min_order);
        $this->assertTrue($coupon->valid_until->equalTo(now()->addHours(48)));
        $this->assertSame('recovery', $coupon->source);
    }
}
