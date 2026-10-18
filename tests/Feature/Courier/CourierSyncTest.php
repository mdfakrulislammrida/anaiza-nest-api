<?php

namespace Tests\Feature\Courier;

use App\Mail\ShopAlertMail;
use App\Mail\TransactionalMail;
use App\Models\CourierEvent;
use App\Models\CourierSetting;
use App\Models\CourierStatusMap;
use App\Models\Order;
use App\Support\Courier\CourierAlerts;
use App\Support\Courier\CourierStatusApplier;
use App\Support\Courier\CourierSync;
use App\Support\System\HealthChecks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

class CourierSyncTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop();
        $this->steadfast();
        Mail::fake();
    }

    private function says(string $status): void
    {
        $this->fakeHttp([self::STEADFAST.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => $status])]);
    }

    private function sent(string $key): int
    {
        return Mail::sent(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->templateKey === $key)->count();
    }

    // ---- what a status means

    public function test_a_riders_word_is_shown_but_never_delivers_collects_cash_or_ends_the_checking(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']));

        foreach (['delivered_approval_pending', 'partial_delivered_approval_pending', 'cancelled_approval_pending', 'unknown_approval_pending'] as $status) {
            $this->says($status);
            CourierSync::run();
            Order::query()->whereKey($order->id)->update(['courier_next_sync_at' => now()->subMinute()]);
        }

        $order->refresh();
        $this->assertSame('shipped', $order->status);
        $this->assertNull($order->cod_collected_at);
        $this->assertNull($order->courier_final_at);
        $this->assertNull($order->courier_returned_at);
        $this->assertSame('unknown_approval_pending', $order->courier_status);
        $this->assertSame(0, $this->sent('order_delivered'));
    }

    public function test_delivered_delivers_the_order_once_records_the_cash_and_stops_the_checking(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']));
        $this->says('delivered');

        $summary = CourierSync::run();
        $this->afterResponse();

        $order->refresh();
        $this->assertSame(1, $summary['changed']);
        $this->assertSame('delivered', $order->status);
        $this->assertNotNull($order->delivered_at, 'the review-request timer starts');
        $this->assertSame(1580, $order->cod_collected_amount);
        $this->assertNotNull($order->cod_collected_at);
        $this->assertNotNull($order->courier_final_at);
        $this->assertSame('Delivered', $order->courier_status_text);
        $this->assertSame(1, $this->sent('order_delivered'));
        $this->assertSame(1, CourierEvent::query()->where('order_id', $order->id)->where('event_type', 'cod_collected')->count());

        // Final is final: not checked again, so no second email and no second cash record.
        $this->says('delivered');
        $this->assertSame(0, CourierSync::run()['checked']);
        $this->afterResponse();
        $this->assertSame(1, $this->sent('order_delivered'));
    }

    public function test_a_verified_wallet_order_that_is_delivered_records_no_cash(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped', 'payment_method' => 'bkash', 'payment_status' => 'verified']), overrides: ['cod_amount_sent' => 0]);
        $this->says('delivered');

        CourierSync::run();

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertNull($order->fresh()->cod_collected_at);
    }

    public function test_partly_delivered_is_final_collects_no_cash_changes_no_status_and_tells_the_owner_once(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']));
        $this->says('partial_delivered');

        CourierSync::run();
        $this->afterResponse();

        $order->refresh();
        $this->assertSame('shipped', $order->status);
        $this->assertNull($order->cod_collected_at);
        $this->assertNotNull($order->courier_final_at);
        Mail::assertSent(ShopAlertMail::class, 1);
    }

    public function test_cancelled_by_the_courier_flags_the_order_tells_the_owner_and_touches_neither_stock_nor_money(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']));
        $before = $order->only(['status', 'total', 'payment_status', 'subtotal']);
        $this->says('cancelled');

        CourierSync::run();
        $this->afterResponse();

        $order->refresh();
        $this->assertNotNull($order->courier_returned_at);
        $this->assertNotNull($order->courier_final_at);
        $this->assertSame($before, $order->only(['status', 'total', 'payment_status', 'subtotal']));
        $this->assertNull($order->cod_collected_at);
        Mail::assertSent(ShopAlertMail::class, fn (ShopAlertMail $mail): bool => str_contains($mail->alertSubject, 'Cancelled by the courier') && str_contains(implode(' ', $mail->lines), 'refund'));
        Mail::assertSent(ShopAlertMail::class, 1);
        // No customer email for a courier return.
        $this->assertSame(0, $this->sent('order_cancelled'));
    }

    public function test_a_status_never_seen_before_is_stored_as_unknown_and_added_to_the_map_to_be_mapped(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']));
        $this->says('out_for_something_new');

        CourierSync::run();

        $order->refresh();
        $this->assertSame('out_for_something_new', $order->courier_status);
        $this->assertSame('Unknown status', $order->courier_status_text);
        $this->assertSame('shipped', $order->status);

        $row = CourierStatusMap::query()->where('courier', 'steadfast')->where('courier_status', 'out_for_something_new')->firstOrFail();
        $this->assertSame('none', $row->effect);
        $this->assertFalse($row->is_final);
        $this->assertSame(1, $row->seen_count);

        // Once the owner maps it, the same status means what they said.
        $row->update(['stage' => 'Out for delivery', 'rank' => 60]);
        $second = $this->booked($this->codOrder(['status' => 'shipped']));
        CourierSync::run();
        $this->assertSame('Out for delivery', $second->fresh()->courier_status_text);
        $this->assertSame(2, $row->fresh()->seen_count);
    }

    public function test_a_parcel_never_goes_backwards_and_a_final_parcel_never_changes(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']), overrides: ['courier_status' => 'hold', 'courier_status_text' => 'On hold', 'courier_status_rank' => 25]);
        $this->says('pending');

        CourierSync::run();

        $order->refresh();
        $this->assertSame('hold', $order->courier_status, 'pending (10) is earlier than hold (25)');
        $this->assertSame(1, CourierEvent::query()->where('order_id', $order->id)->where('event_type', 'status_ignored')->count());

        // Delivered, then a late "in review": ignored.
        $this->says('delivered');
        Order::query()->whereKey($order->id)->update(['courier_next_sync_at' => now()->subMinute()]);
        CourierSync::run();
        $this->assertSame('delivered', $order->fresh()->status);

        $this->assertSame('ignored_final', CourierStatusApplier::apply($order->fresh(), 'steadfast', 'in_review', 'webhook'));
        $this->assertSame('delivered', $order->fresh()->courier_status);
    }

    public function test_the_order_status_never_moves_backwards_either(): void
    {
        // Delivered by hand already: the courier saying "delivered" later changes nothing and sends nothing new.
        $order = $this->booked($this->codOrder(['status' => 'delivered', 'payment_status' => 'cod']));
        $this->says('delivered');

        CourierSync::run();
        $this->afterResponse();

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(0, $this->sent('order_delivered'));

        // A cancelled order is not moved to delivered.
        $cancelled = $this->booked($this->codOrder(['status' => 'cancelled']));
        $this->assertSame('applied', CourierStatusApplier::apply($cancelled, 'steadfast', 'delivered', 'poll'));
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    // ---- the polling itself is light

    public function test_it_checks_newest_first_and_at_most_the_limit(): void
    {
        $orders = collect(range(1, 5))->map(fn () => $this->booked($this->codOrder(['status' => 'shipped'])));
        $this->says('in_review');

        $summary = CourierSync::run(2);

        $this->assertSame(2, $summary['checked']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/status_by_invoice/'.$orders[4]->id));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/status_by_invoice/'.$orders[3]->id));
    }

    public function test_a_parcel_checked_a_moment_ago_waits_and_finished_ones_are_left_alone(): void
    {
        $recent = $this->booked($this->codOrder(['status' => 'shipped']), overrides: ['courier_next_sync_at' => now()->addMinutes(10)]);
        $this->booked($this->codOrder(['status' => 'delivered', 'payment_method' => 'bkash', 'payment_status' => 'verified']));
        $this->booked($this->codOrder(['status' => 'cancelled']));
        $this->booked($this->codOrder(['status' => 'shipped']), overrides: ['courier_final_at' => now()]);
        $this->booked($this->codOrder(['status' => 'shipped']), overrides: ['courier_returned_at' => now()]);
        $this->codOrder(['status' => 'shipped']); // never booked
        $this->says('in_review');

        $this->assertSame(0, CourierSync::run()['checked']);
        Http::assertNothingSent();

        Order::query()->whereKey($recent->id)->update(['courier_next_sync_at' => now()->subSecond()]);
        $this->assertSame(1, CourierSync::run()['checked']);
    }

    public function test_a_good_check_waits_fifteen_minutes_before_the_next(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']));
        $this->says('in_review');

        CourierSync::run();

        $next = $order->fresh()->courier_next_sync_at;
        $this->assertTrue($next->between(now()->addMinutes(14), now()->addMinutes(16)));
        $this->assertSame(0, CourierSync::run()['checked']);
    }

    public function test_a_failure_backs_off_further_each_time_and_a_run_gives_up_after_three_in_a_row(): void
    {
        $orders = collect(range(1, 6))->map(fn () => $this->booked($this->codOrder(['status' => 'shipped'])));
        $this->fakeHttp([self::STEADFAST.'/status_by_invoice/*' => Http::response('down', 503)]);

        $summary = CourierSync::run();

        $this->assertSame(3, $summary['failed'], 'an outage costs three calls, not six');
        Http::assertSentCount(3);
        $this->assertContains('steadfast: failing', $summary['stopped']);

        $first = $orders[5]->fresh();
        $this->assertSame(1, $first->courier_sync_failures);
        $this->assertTrue($first->courier_next_sync_at->between(now()->addMinutes(29), now()->addMinutes(31)), 'doubled to 30 minutes');
        $this->assertStringStartsWith('Status check:', $first->courier_last_error);

        // The second failure backs off further still.
        Order::query()->whereKey($first->id)->update(['courier_next_sync_at' => now()->subSecond()]);
        CourierSync::run(1);
        $this->assertTrue($first->fresh()->courier_next_sync_at->between(now()->addMinutes(59), now()->addMinutes(61)));

        // Once it works again, the error and the count clear.
        $this->says('in_review');
        Order::query()->whereKey($first->id)->update(['courier_next_sync_at' => now()->subSecond()]);
        CourierSync::run(1);
        $this->assertSame(0, $first->fresh()->courier_sync_failures);
        $this->assertNull($first->fresh()->courier_last_error);
    }

    public function test_the_daily_cap_stops_a_courier_for_the_day(): void
    {
        config(['couriers.sync.daily_cap' => 2]);
        collect(range(1, 5))->each(fn () => $this->booked($this->codOrder(['status' => 'shipped'])));
        $this->says('in_review');

        $summary = CourierSync::run();

        $this->assertSame(2, $summary['checked']);
        $this->assertContains('steadfast: daily cap', $summary['stopped']);
    }

    public function test_a_refused_key_stops_the_courier_for_good(): void
    {
        collect(range(1, 4))->each(fn () => $this->booked($this->codOrder(['status' => 'shipped'])));
        $this->fakeHttp([self::STEADFAST.'/status_by_invoice/*' => Http::response(['message' => 'Unauthorized'], 401)]);

        $summary = CourierSync::run();

        Http::assertSentCount(1);
        $this->assertContains('steadfast: keys refused', $summary['stopped']);
        $this->assertNotNull(CourierSetting::current()->steadfast_auth_failed_at);

        // And the next run does not knock at all.
        $this->assertSame(0, CourierSync::run()['checked']);
        Http::assertSentCount(1);
    }

    public function test_a_courier_that_is_switched_off_is_not_called(): void
    {
        $this->booked($this->codOrder(['status' => 'shipped']));
        CourierSetting::current()->update(['steadfast_enabled' => false]);
        $this->says('delivered');

        $this->assertSame(0, CourierSync::run()['checked']);
        Http::assertNothingSent();
    }

    public function test_the_command_runs_and_reports(): void
    {
        $this->booked($this->codOrder(['status' => 'shipped']));
        $this->says('in_review');

        $this->artisan('couriers:sync')->expectsOutputToContain('1 checked')->assertSuccessful();
    }

    public function test_it_is_on_the_scheduler_every_fifteen_minutes(): void
    {
        $events = collect(app(Schedule::class)->events());

        $sync = $events->first(fn ($event) => str_contains((string) $event->command, 'couriers:sync'));
        $digest = $events->first(fn ($event) => str_contains((string) $event->command, 'couriers:digest'));

        $this->assertNotNull($sync);
        $this->assertSame('*/15 * * * *', $sync->expression);
        $this->assertNotNull($digest);
    }

    // ---- Pathao

    public function test_pathao_is_checked_by_consignment_and_its_statuses_are_mapped_by_the_owner(): void
    {
        $this->pathao();
        $order = $this->booked($this->codOrder(['status' => 'shipped']), 'pathao', ['courier_consignment_id' => 'DL240101ABCD', 'courier_status' => 'Pending', 'courier_status_text' => null, 'courier_status_rank' => null]);
        $this->fakeHttp([
            self::PATHAO.'/issue-token' => Http::response(['access_token' => 't', 'refresh_token' => 'r', 'expires_in' => 432000]),
            self::PATHAO.'/orders/DL240101ABCD/info' => Http::response(['data' => ['consignment_id' => 'DL240101ABCD', 'order_status' => 'Delivered', 'order_status_slug' => 'Delivered', 'updated_at' => '2026-10-22 10:00:00']]),
        ]);

        // Pathao's docs list no statuses, so a first one is only stored.
        CourierSync::run();
        $this->afterResponse();
        $this->assertSame('shipped', $order->fresh()->status);
        $row = CourierStatusMap::query()->where('courier', 'pathao')->where('courier_status', 'Delivered')->firstOrFail();
        $this->assertSame('Unknown status', $row->stage);

        // The owner maps it; the next check does what they said.
        $row->update(['stage' => 'Delivered', 'effect' => 'delivered', 'is_final' => true, 'marks_cod_collected' => true, 'rank' => 100]);
        Order::query()->whereKey($order->id)->update(['courier_next_sync_at' => now()->subSecond(), 'courier_status' => 'Pending']);
        CourierSync::run();
        $this->afterResponse();

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame(1580, $order->cod_collected_amount);
        $this->assertNotNull($order->courier_final_at);
        $this->assertSame(1, $this->sent('order_delivered'));
    }

    // ---- the morning note and System health

    public function test_the_morning_note_lists_failed_bookings_and_parcels_gone_quiet_and_is_silent_otherwise(): void
    {
        $this->assertSame(0, CourierAlerts::digest());
        Mail::assertNothingSent();

        $failed = $this->codOrder();
        $failed->forceFill(['courier_last_error' => 'Steadfast refused the request', 'courier_last_error_at' => now()->subHour()])->saveQuietly();
        $quiet = $this->booked($this->codOrder(['status' => 'shipped']), overrides: ['courier_synced_at' => now()->subDays(4)]);
        $fresh = $this->booked($this->codOrder(['status' => 'shipped']), overrides: ['courier_synced_at' => now()->subHour()]);

        $this->assertSame(2, CourierAlerts::digest());

        Mail::assertSent(ShopAlertMail::class, function (ShopAlertMail $mail) use ($failed, $quiet, $fresh): bool {
            $body = implode("\n", $mail->lines);

            return str_contains($body, "#{$failed->id}: Steadfast refused the request") && str_contains($body, "#{$quiet->id}:") && ! str_contains($body, "#{$fresh->id}:");
        });
    }

    public function test_system_health_shows_the_couriers_only_when_one_is_in_use_and_flags_a_refused_login(): void
    {
        CourierSetting::current()->update(['steadfast_enabled' => false]);
        $this->assertSame([], HealthChecks::couriers());

        CourierSetting::current()->update(['steadfast_enabled' => true]);
        $rows = collect(HealthChecks::couriers())->keyBy('key');
        $this->assertSame('warn', $rows['courier_steadfast']['status']);
        $this->assertSame('ok', $rows['courier_unsynced']['status']);

        $this->booked($this->codOrder(['status' => 'shipped']), overrides: ['courier_synced_at' => now()->subHours(5)]);
        $this->assertSame('warn', collect(HealthChecks::couriers())->keyBy('key')['courier_unsynced']['status']);

        CourierSetting::current()->update(['steadfast_auth_failed_at' => now()]);
        $this->assertSame('fail', collect(HealthChecks::couriers())->keyBy('key')['courier_steadfast']['status']);

        CourierSetting::current()->update(['steadfast_auth_failed_at' => null, 'steadfast_webhook_token' => 'abc-123_TOKEN.x=']);
        $this->assertArrayHasKey('steadfast_webhook', collect(HealthChecks::couriers())->keyBy('key'));
    }
}
