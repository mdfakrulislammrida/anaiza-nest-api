<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AbandonedCheckoutResource\Pages\ListAbandonedCheckouts;
use App\Filament\Resources\CouponResource\Pages\EditCoupon;
use App\Filament\Resources\CouponResource\Pages\ListCoupons;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\ProductReviewResource\Pages\ListProductReviews;
use App\Models\AbandonedCheckout;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\ProductReview;
use App\Support\Dhaka;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AdminRecords;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

/**
 * Batch ADM1, T1 and T2: the admin reads times in Dhaka (the database stays UTC), the Orders list has a Placed column and a Placed filter whose days are
 * Dhaka days, the phone card and the order page show the time.
 */
class PlacedAndDhakaTimeTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->shop();
        $this->actingAs($this->admin());
        // 15:00 on Wednesday 04 Nov 2026 in Dhaka (09:00 UTC).
        Carbon::setTestNow('2026-11-04 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** An order whose stored created_at is exactly this UTC time. */
    private function orderAt(string $utc, array $overrides = []): Order
    {
        $order = AdminRecords::order($overrides);
        $order->forceFill(['created_at' => $utc, 'updated_at' => $utc])->saveQuietly();

        return $order->fresh();
    }

    // --- the helper --------------------------------------------------------

    public function test_a_dhaka_day_is_turned_into_the_database_time_before_it_is_asked(): void
    {
        $this->assertSame('2026-11-03 18:00:00', Dhaka::dayStart('2026-11-04')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-04 17:59:59', Dhaka::dayEnd('2026-11-04')->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', Dhaka::dayStart('2026-11-04')->timezoneName);
    }

    public function test_the_presets_are_dhaka_calendar_days(): void
    {
        $now = Carbon::parse('2026-11-04 09:00:00');

        $this->assertSame(['2026-11-04', '2026-11-04'], Dhaka::preset('today', $now));
        $this->assertSame(['2026-11-03', '2026-11-03'], Dhaka::preset('yesterday', $now));
        $this->assertSame(['2026-10-29', '2026-11-04'], Dhaka::preset('last_7_days', $now));
        $this->assertSame(['2026-11-01', '2026-11-04'], Dhaka::preset('this_month', $now));
        $this->assertSame(['2026-10-01', '2026-10-31'], Dhaka::preset('last_month', $now));
        $this->assertNull(Dhaka::preset('nonsense', $now));
        // Late on 03 Nov in UTC is already 04 Nov in Dhaka.
        $this->assertSame(['2026-11-04', '2026-11-04'], Dhaka::preset('today', Carbon::parse('2026-11-03 20:00:00')));
    }

    public function test_a_time_reads_like_this_in_dhaka(): void
    {
        $this->assertSame('04 Nov 2026, 05:30 AM', Dhaka::format(Carbon::parse('2026-11-03 23:30:00')));
        $this->assertSame('04 Nov 2026, 11:14 AM', Dhaka::format('2026-11-04 05:14:00'));
        $this->assertSame('—', Dhaka::format(null));
    }

    // --- the Placed filter --------------------------------------------------

    public function test_an_order_late_the_day_before_in_utc_is_today_in_dhaka(): void
    {
        $morning = $this->orderAt('2026-11-03 23:30:00');
        $lastNight = $this->orderAt('2026-11-03 17:59:59');

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['preset' => 'today'])
            ->assertCanSeeTableRecords([$morning])
            ->assertCanNotSeeTableRecords([$lastNight]);
    }

    public function test_the_dhaka_midnight_boundaries_are_exact(): void
    {
        $lastSecondOfYesterday = $this->orderAt('2026-11-03 17:59:59');
        $midnight = $this->orderAt('2026-11-03 18:00:00');
        $lastSecondOfToday = $this->orderAt('2026-11-04 17:59:59');
        $nextMidnight = $this->orderAt('2026-11-04 18:00:00');

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['preset' => 'today'])
            ->assertCanSeeTableRecords([$midnight, $lastSecondOfToday])
            ->assertCanNotSeeTableRecords([$lastSecondOfYesterday, $nextMidnight]);

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['preset' => 'yesterday'])
            ->assertCanSeeTableRecords([$lastSecondOfYesterday])
            ->assertCanNotSeeTableRecords([$midnight, $lastSecondOfToday, $nextMidnight]);
    }

    public function test_the_other_presets(): void
    {
        $today = $this->orderAt('2026-11-04 05:00:00');
        $sixDaysAgo = $this->orderAt('2026-10-29 05:00:00');
        $sevenDaysAgo = $this->orderAt('2026-10-28 05:00:00');
        $firstOfMonth = $this->orderAt('2026-10-31 18:00:00');
        $lastOfLastMonth = $this->orderAt('2026-10-31 17:59:59');
        $firstOfLastMonth = $this->orderAt('2026-09-30 18:00:00');

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['preset' => 'last_7_days'])
            ->assertCanSeeTableRecords([$today, $sixDaysAgo, $firstOfMonth, $lastOfLastMonth])
            ->assertCanNotSeeTableRecords([$sevenDaysAgo, $firstOfLastMonth]);

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['preset' => 'this_month'])
            ->assertCanSeeTableRecords([$today, $firstOfMonth])
            ->assertCanNotSeeTableRecords([$lastOfLastMonth, $sixDaysAgo, $sevenDaysAgo]);

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['preset' => 'last_month'])
            ->assertCanSeeTableRecords([$lastOfLastMonth, $sixDaysAgo, $sevenDaysAgo, $firstOfLastMonth])
            ->assertCanNotSeeTableRecords([$today, $firstOfMonth]);
    }

    public function test_typed_dates_are_dhaka_days_and_either_end_can_be_left_out(): void
    {
        $inside = $this->orderAt('2026-11-02 20:00:00');   // 03 Nov 02:00 in Dhaka
        $before = $this->orderAt('2026-11-02 17:59:59');   // 02 Nov 23:59:59 in Dhaka
        $after = $this->orderAt('2026-11-03 18:00:00');    // 04 Nov 00:00 in Dhaka

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['from' => '2026-11-03', 'until' => '2026-11-03'])
            ->assertCanSeeTableRecords([$inside])
            ->assertCanNotSeeTableRecords([$before, $after]);

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['from' => '2026-11-03'])
            ->assertCanSeeTableRecords([$inside, $after])
            ->assertCanNotSeeTableRecords([$before]);

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['until' => '2026-11-03'])
            ->assertCanSeeTableRecords([$inside, $before])
            ->assertCanNotSeeTableRecords([$after]);
    }

    public function test_a_quick_range_wins_over_typed_dates(): void
    {
        $today = $this->orderAt('2026-11-04 05:00:00');
        $old = $this->orderAt('2026-10-01 05:00:00');

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['preset' => 'today', 'from' => '2026-10-01', 'until' => '2026-10-01'])
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$old]);
    }

    public function test_the_filter_stacks_with_the_preorders_tab_and_with_search(): void
    {
        $preorderToday = $this->orderAt('2026-11-04 05:00:00', ['status' => 'preorder', 'ship_name' => 'Nusrat Jahan']);
        $pendingToday = $this->orderAt('2026-11-04 05:00:00', ['ship_name' => 'Rafi Khan']);
        $preorderLastWeek = $this->orderAt('2026-10-20 05:00:00', ['status' => 'preorder', 'ship_name' => 'Nusrat Jahan']);

        Livewire::test(ListOrders::class)
            ->set('activeTab', 'preorders')
            ->filterTable('placed', ['preset' => 'today'])
            ->assertCanSeeTableRecords([$preorderToday])
            ->assertCanNotSeeTableRecords([$pendingToday, $preorderLastWeek]);

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['preset' => 'today'])
            ->searchTable('Nusrat')
            ->assertCanSeeTableRecords([$preorderToday])
            ->assertCanNotSeeTableRecords([$pendingToday, $preorderLastWeek]);

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['preset' => 'today'])
            ->filterTable('status', 'pending')
            ->assertCanSeeTableRecords([$pendingToday])
            ->assertCanNotSeeTableRecords([$preorderToday]);
    }

    public function test_an_active_filter_shows_a_chip_that_can_be_removed(): void
    {
        $component = Livewire::test(ListOrders::class)->filterTable('placed', ['preset' => 'last_7_days']);

        $component->assertSee('Placed: Last 7 days');

        $component->removeTableFilter('placed', 'preset')->assertDontSee('Placed: Last 7 days');

        Livewire::test(ListOrders::class)
            ->filterTable('placed', ['from' => '2026-11-01', 'until' => '2026-11-03'])
            ->assertSee('Placed from 01 Nov 2026')
            ->assertSee('Placed until 03 Nov 2026');
    }

    // --- the list, the card, the page ----------------------------------------

    public function test_the_placed_column_is_shown_by_default_in_dhaka_time_and_sorts(): void
    {
        $older = $this->orderAt('2026-11-03 23:30:00');
        $newer = $this->orderAt('2026-11-04 05:14:00');

        $table = Livewire::test(ListOrders::class)
            ->assertTableColumnVisible('created_at')
            ->assertTableColumnFormattedStateSet('created_at', '04 Nov 2026, 05:30 AM', $older)
            ->assertTableColumnFormattedStateSet('created_at', '04 Nov 2026, 11:14 AM', $newer);

        $table->sortTable('created_at')->assertCanSeeTableRecords([$older, $newer], inOrder: true);
        $table->sortTable('created_at', 'desc')->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }

    public function test_the_columns_keep_their_order_with_placed_beside_the_order_number(): void
    {
        $names = collect(Livewire::test(ListOrders::class)->instance()->getTable()->getColumns())
            ->map(fn ($column) => $column->getName())
            ->reject(fn (string $name) => $name === 'phone_card')
            ->values()
            ->take(3)
            ->all();

        $this->assertSame(['id', 'created_at', 'ship_name'], $names);
    }

    public function test_the_phone_card_carries_the_date_and_time(): void
    {
        $order = $this->orderAt('2026-11-03 23:30:00');

        $rows = OrderResource::phoneCard($order);

        $this->assertSame('04 Nov 2026, 05:30 AM', $rows[0]['left'][1]['text'] ?? null);
        Livewire::test(ListOrders::class)->assertSee('04 Nov 2026, 05:30 AM');
    }

    public function test_the_order_page_says_when_it_was_placed_and_only_the_times_that_exist(): void
    {
        $order = $this->orderAt('2026-11-03 23:30:00', ['delivered_at' => '2026-11-05 07:00:00']);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertSee('Placed on')
            ->assertSee('04 Nov 2026, 05:30 AM (Dhaka time)')
            ->assertSee('Delivered')
            ->assertSee('05 Nov 2026, 01:00 PM')
            ->assertDontSee('Payment verified')
            ->assertDontSee('Sent to courier')
            ->assertDontSee('Cash collected');
    }

    // --- the other lists ------------------------------------------------------

    public function test_customers_reviews_coupons_and_abandoned_checkouts_filter_by_dhaka_day_too(): void
    {
        // 23:30 UTC the day before is this morning in Dhaka; 17:59:59 is last night.
        $joinedToday = AdminRecords::customer(['email' => 'today@example.com']);
        $joinedToday->forceFill(['created_at' => '2026-11-03 23:30:00'])->saveQuietly();
        $joinedBefore = AdminRecords::customer(['email' => 'before@example.com']);
        $joinedBefore->forceFill(['created_at' => '2026-11-03 17:59:59'])->saveQuietly();

        Livewire::test(ListCustomers::class)
            ->assertTableColumnVisible('created_at')
            ->assertTableColumnFormattedStateSet('created_at', '04 Nov 2026, 05:30 AM', $joinedToday)
            ->filterTable('joined', ['preset' => 'today'])
            ->assertCanSeeTableRecords([$joinedToday])
            ->assertCanNotSeeTableRecords([$joinedBefore]);

        $reviewToday = AdminRecords::for(ProductReview::class);
        $reviewToday->forceFill(['created_at' => '2026-11-03 23:30:00'])->saveQuietly();
        $reviewBefore = ProductReview::create(['product_id' => $reviewToday->product_id, 'name' => 'Old', 'rating' => 4, 'body' => 'An older review of the set.']);
        $reviewBefore->forceFill(['created_at' => '2026-11-03 17:59:59'])->saveQuietly();

        Livewire::test(ListProductReviews::class)
            ->assertTableColumnFormattedStateSet('created_at', '04 Nov 2026, 05:30 AM', $reviewToday)
            ->filterTable('received', ['preset' => 'today'])
            ->assertCanSeeTableRecords([$reviewToday])
            ->assertCanNotSeeTableRecords([$reviewBefore]);

        $starting = Coupon::create(['code' => 'EIDSTART', 'discount_type' => 'percent', 'amount' => 10, 'valid_from' => '2026-11-03 23:30:00', 'valid_until' => '2026-11-10 05:00:00']);
        $earlier = Coupon::create(['code' => 'OLDSTART', 'discount_type' => 'percent', 'amount' => 10, 'valid_from' => '2026-11-03 17:59:59', 'valid_until' => '2026-11-04 05:00:00']);

        Livewire::test(ListCoupons::class)
            ->filterTable('starts', ['preset' => 'today'])
            ->assertCanSeeTableRecords([$starting])
            ->assertCanNotSeeTableRecords([$earlier]);

        Livewire::test(ListCoupons::class)
            ->filterTable('ends', ['preset' => 'today'])
            ->assertCanSeeTableRecords([$earlier])
            ->assertCanNotSeeTableRecords([$starting]);

        $active = AdminRecords::for(AbandonedCheckout::class);
        $active->forceFill(['last_activity_at' => '2026-11-03 23:30:00'])->saveQuietly();
        $quiet = AbandonedCheckout::create(['session_token' => 'sess-quiet-0000000002', 'name' => 'Old cart', 'stage' => 'contact_filled', 'cart_total' => 900, 'first_activity_at' => '2026-11-03 10:00:00', 'last_activity_at' => '2026-11-03 17:59:59', 'items' => [['product_id' => 1, 'name' => 'Cup', 'price' => 900, 'quantity' => 1]]]);

        Livewire::test(ListAbandonedCheckouts::class)
            ->filterTable('last_activity', ['from' => '2026-11-04', 'until' => '2026-11-04'])
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$quiet]);
    }

    public function test_a_time_picker_shows_dhaka_time_and_saves_back_the_same_instant(): void
    {
        $coupon = Coupon::create(['code' => 'PICKER', 'discount_type' => 'percent', 'amount' => 10, 'valid_from' => '2026-11-03 23:30:00']);

        Livewire::test(EditCoupon::class, ['record' => $coupon->getKey()])
            ->assertFormSet(['valid_from' => '2026-11-04 05:30'])
            ->fillForm(['valid_from' => '2026-11-04 05:30'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2026-11-03 23:30:00', DB::table('coupons')->where('id', $coupon->id)->value('valid_from'));
    }

    public function test_nothing_stored_is_changed_by_showing_or_filtering_times(): void
    {
        $order = $this->orderAt('2026-11-03 23:30:00');

        Livewire::test(ListOrders::class)->filterTable('placed', ['preset' => 'today'])->assertCanSeeTableRecords([$order]);
        Livewire::test(EditOrder::class, ['record' => $order->getKey()])->assertOk();

        $this->assertSame('2026-11-03 23:30:00', DB::table('orders')->where('id', $order->id)->value('created_at'));
        $this->assertSame('UTC', config('app.timezone'));
    }
}
