<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReviewRequestSetting;
use App\Models\SystemEvent;
use App\Support\Reviews\ReviewRequests;
use App\Support\System\SystemEvents;
use App\Support\System\TaskBudget;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The host has one cron line, `schedule:run` every minute, and no queue worker. So every scheduled task must be safe to start on top of a slow earlier
 * run (an overlap lock that expires), a task that sends mail must stop on its own after a while, and nothing but the heartbeat may run every minute.
 */
class SchedulerSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return list<Event> */
    private function events(): array
    {
        Artisan::call('schedule:list');

        return array_values(app(Schedule::class)->events());
    }

    private function named(string $needle): Event
    {
        foreach ($this->events() as $event) {
            if (str_contains((string) ($event->command ?? ''), $needle) || $event->description === $needle) {
                return $event;
            }
        }

        $this->fail("No scheduled task called {$needle}.");
    }

    public function test_the_whole_schedule_is_these_ten_tasks_and_only_the_heartbeat_runs_every_minute(): void
    {
        $list = [];
        foreach ($this->events() as $event) {
            $list[] = trim(($event->description ?: preg_replace('/^.*artisan.\s*/', '', (string) $event->command)).' '.$event->expression);
        }
        sort($list);

        $this->assertSame([
            'backup:run 30 2 * * *',
            'checkouts:recover */5 * * * *',
            'checkouts:tidy 30 3 * * *',
            'conversions:retry */10 * * * *',
            'couriers:digest 30 9 * * *',
            'couriers:sync */15 * * * *',
            'reviews:request */5 * * * *',
            'scheduler-heartbeat * * * * *',
            'stock:digest 0 9 * * *',
            'system:tidy 45 3 * * *',
        ], $list);
    }

    public function test_every_task_has_an_overlap_lock_that_expires_within_two_hours(): void
    {
        foreach ($this->events() as $event) {
            $name = $event->description ?: (string) $event->command;

            $this->assertTrue($event->withoutOverlapping, "{$name} can start on top of itself");
            $this->assertGreaterThan(0, $event->expiresAt, $name);
            $this->assertLessThanOrEqual(120, $event->expiresAt, "{$name}: a crashed run must not block it for long");
        }
    }

    public function test_the_courier_sync_is_every_fifteen_minutes_and_its_lock_ends_before_the_next_run(): void
    {
        $sync = $this->named('couriers:sync');

        $this->assertSame('*/15 * * * *', $sync->expression);
        $this->assertLessThan(15, $sync->expiresAt);
        $this->assertLessThan(60, (int) config('couriers.sync.seconds'), 'the sync stops itself well inside a minute');
    }

    public function test_a_run_is_skipped_while_the_last_one_still_holds_the_lock(): void
    {
        $heartbeat = $this->named('scheduler-heartbeat');
        $key = $heartbeat->mutexName();
        $this->assertFalse($heartbeat->shouldSkipDueToOverlapping());

        // The last run is still going: its lock is in the cache.
        Cache::add($key, true, 120 * 60);
        $this->assertTrue($heartbeat->shouldSkipDueToOverlapping());

        Artisan::call('schedule:run');

        $this->assertStringNotContainsString('Running [scheduler-heartbeat]', Artisan::output());
        $this->assertNull(SystemEvents::latest('scheduler'), 'the overlapping run did not start');
    }

    public function test_a_finished_run_lets_go_of_its_lock_so_the_next_minute_runs(): void
    {
        $heartbeat = $this->named('scheduler-heartbeat');
        $outputs = [];

        foreach ([1, 2, 3] as $minute) {
            Artisan::call('schedule:run');
            $outputs[$minute] = Artisan::output();
        }

        foreach ($outputs as $minute => $output) {
            $this->assertStringContainsString('Running [scheduler-heartbeat]', $output, "run {$minute} went ahead");
        }
        $this->assertFalse($heartbeat->shouldSkipDueToOverlapping(), 'and nothing is left locked');
        $this->assertSame(1, SystemEvent::query()->where('type', 'scheduler')->count(), 'one heartbeat row, updated in place');
    }

    public function test_the_heartbeat_the_go_live_check_reads_is_written_by_the_every_minute_task(): void
    {
        Artisan::call('schedule:run');

        $beat = SystemEvents::latest('scheduler');

        $this->assertNotNull($beat);
        $this->assertSame('ran', $beat->message);
        $this->assertTrue($beat->updated_at->diffInSeconds(now(), true) < 5);
    }

    public function test_the_nightly_backup_is_kept_in_fourteen_copies(): void
    {
        $this->assertSame(14, (int) config('backup.keep_daily'));
        $backup = $this->named('backup:run');
        $this->assertSame(['30 2 * * *', 'Asia/Dhaka'], [$backup->expression, (string) $backup->timezone], '02:30 Dhaka time');
    }

    public function test_mail_waits_at_most_ten_seconds_for_a_server_that_does_not_answer(): void
    {
        $this->assertSame(10, config('mail.mailers.smtp.timeout'));
    }

    // ---- a task that sends mail stops by itself

    public function test_the_review_requests_stop_when_their_time_is_up_and_carry_on_next_run(): void
    {
        Mail::fake();
        ReviewRequestSetting::query()->create(['enabled' => true, 'email_enabled' => true, 'enabled_at' => now()->subYear(), 'days_after_delivery' => 1]);
        $product = Product::create([
            'category_id' => Category::create(['name' => 'Mugs', 'slug' => 'mugs'])->id,
            'name' => 'Red mug', 'slug' => 'red-mug', 'price' => 600, 'stock_quantity' => 20, 'sku' => 'MUG-RED', 'is_active' => true,
        ]);

        foreach (range(1, 3) as $n) {
            $customer = Customer::create(['name' => 'Priya', 'email' => "priya{$n}@example.test", 'phone' => '0171000000'.$n, 'password' => 'x']);
            $order = Order::create(['customer_id' => $customer->id, 'status' => 'delivered', 'subtotal' => 600, 'delivery_fee' => 80, 'total' => 680, 'payment_method' => 'cod', 'delivered_at' => now()->subDays(5)]);
            $order->items()->create(['product_id' => $product->id, 'product_name' => 'Red mug', 'quantity' => 1, 'price' => 600, 'sku' => 'MUG-RED']);
        }

        // The time is already up: nothing is sent, and nothing is lost.
        $this->assertSame(['sent' => 0, 'reminded' => 0, 'skipped' => 0], ReviewRequests::run(null, microtime(true) - 1));
        $this->assertSame(0, Order::query()->whereNotNull('review_request_sent_at')->count());

        // The next run has its own time and sends them all.
        $this->assertSame(3, ReviewRequests::run()['sent']);
        $this->assertSame(40, TaskBudget::SECONDS);
        $this->assertGreaterThan(microtime(true) + 30, TaskBudget::until());
    }
}
