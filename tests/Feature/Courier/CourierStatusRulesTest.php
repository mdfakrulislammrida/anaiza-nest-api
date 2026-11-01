<?php

namespace Tests\Feature\Courier;

use App\Filament\Pages\ManageCourierSettings;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Mail\TransactionalMail;
use App\Models\CourierEvent;
use App\Models\CourierSetting;
use App\Models\CourierStatusMap;
use App\Models\Order;
use App\Support\Courier\CourierStatusApplier;
use App\Support\Courier\CourierSync;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

/**
 * The rules for what a courier's report may do to an order (OPS1-B): forward only, final is final, nothing a person decided is overruled,
 * every automatic change is written in the order's Courier history with where it came from, and the switch in Settings > Courier.
 * Every courier answer is faked; no test reaches Steadfast or Pathao.
 */
class CourierStatusRulesTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    private const TOKEN = 'whk_Token-123.ABC=xyz';

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop();
        $this->steadfast(['steadfast_webhook_token' => self::TOKEN]);
        Mail::fake();

        // Steadfast reports no "in transit" of its own; the owner maps one (or Pathao's) to "The order is shipped".
        CourierStatusMap::query()->create(['courier' => 'steadfast', 'courier_status' => 'in_transit', 'stage' => 'In transit', 'effect' => 'shipped', 'is_final' => false, 'marks_cod_collected' => false, 'rank' => 60]);
    }

    private function hook(Order $order, string $status, string $key): TestResponse
    {
        $raw = json_encode(['notification_type' => 'delivery_status', 'consignment_id' => 1001, 'invoice' => (string) $order->id, 'status' => $status]);
        $server = [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw, self::TOKEN), 'HTTP_IDEMPOTENCY_KEY' => $key,
        ];

        $response = $this->call('POST', '/api/webhooks/steadfast', [], [], [], $server, $raw);
        // The test client has already run what was queued for after the response; forget it so a later step does not run it twice.
        (new \ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);

        return $response;
    }

    private function says(string $status): void
    {
        $this->fakeHttp([self::STEADFAST.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => $status])]);
    }

    private function sent(string $key): int
    {
        return Mail::sent(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->templateKey === $key)->count();
    }

    /** @return list<string> */
    private function history(Order $order, ?string $type = null): array
    {
        return CourierEvent::query()->where('order_id', $order->id)->when($type, fn ($q) => $q->where('event_type', $type))->orderBy('id')->pluck('summary')->all();
    }

    // ---- forward only

    public function test_in_transit_by_webhook_moves_processing_to_shipped_once_and_writes_the_history(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'processing']));

        $this->hook($order, 'in_transit', 'evt-1')->assertOk();

        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame(1, $this->sent('order_shipped'));

        $line = $this->history($order, 'order_status');
        $this->assertCount(1, $line);
        $this->assertStringContainsString('Steadfast webhook', $line[0]);
        $this->assertStringContainsString('Processing to Shipped', $line[0]);
        $this->assertStringContainsString('in_transit', $line[0], "the courier's own word is kept");
        $this->assertSame('in_transit', CourierEvent::query()->where('event_type', 'order_status')->value('status'));
    }

    public function test_a_repeat_or_a_retry_changes_nothing_and_adds_nothing(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'processing']));

        $this->hook($order, 'in_transit', 'evt-1');

        $this->hook($order, 'in_transit', 'evt-1')->assertOk()->assertJson(['duplicate' => true]);
        $this->hook($order, 'in_transit', 'evt-2')->assertOk();

        $this->assertCount(1, $this->history($order, 'order_status'));
        $this->assertSame(1, $this->sent('order_shipped'));
    }

    public function test_a_late_in_transit_after_delivered_changes_nothing(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']));

        $this->hook($order, 'delivered', 'evt-1')->assertOk();
        $this->assertSame('delivered', $order->fresh()->status);

        $this->hook($order, 'in_transit', 'evt-2')->assertOk();

        $order->refresh();
        $this->assertSame('delivered', $order->status, 'never moved backwards');
        $this->assertSame('delivered', $order->courier_status);
        $this->assertSame(1, $this->sent('order_delivered'));
        $this->assertSame(0, $this->sent('order_shipped'));
        $this->assertStringContainsString('earlier than', implode(' ', $this->history($order, 'status_ignored')));
    }

    public function test_an_order_a_person_cancelled_is_never_changed_and_never_gets_cash_recorded(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'cancelled']));

        // The webhook says delivered, then the status check confirms it.
        $this->hook($order, 'delivered', 'evt-1')->assertOk();
        $this->says('delivered');
        CourierStatusApplier::apply($order->fresh(), 'steadfast', 'delivered', 'poll');
        $this->afterResponse();

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertNull($order->cod_collected_at, 'a cancelled order keeps its money as it is');
        $this->assertSame(0, $this->sent('order_delivered'));
        $this->assertStringContainsString('left as it is', implode(' ', $this->history($order, 'status_ignored')));
        $this->assertStringContainsString('no cash was recorded', implode(' ', $this->history($order, 'status_ignored')));
    }

    public function test_an_order_already_delivered_or_returned_by_a_person_is_left_alone_by_in_transit(): void
    {
        foreach (['delivered', 'returned', 'cancelled'] as $status) {
            $order = $this->booked($this->codOrder(['status' => $status]));

            CourierStatusApplier::apply($order, 'steadfast', 'in_transit', 'webhook');

            $this->assertSame($status, $order->fresh()->status);
        }
    }

    public function test_a_status_we_do_not_know_is_kept_and_shown_but_moves_nothing(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'processing']));

        $this->hook($order, 'lost_in_the_post', 'evt-1')->assertOk();

        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame('lost_in_the_post', $order->courier_status);
        $this->assertStringContainsString('Unknown status', $order->courier_status_text);
        $this->assertStringContainsString('lost_in_the_post', implode(' ', $this->history($order, 'status')));
    }

    // ---- where a change came from

    public function test_the_history_names_the_source_of_a_status_check_and_of_sync_now(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'processing']));
        $this->says('in_transit');
        CourierSync::run();
        $this->afterResponse();

        $line = $this->history($order, 'order_status');
        $this->assertCount(1, $line);
        $this->assertStringContainsString('couriers:sync', $line[0]);
        $this->assertSame('poll', CourierEvent::query()->where('event_type', 'order_status')->value('source'));

        $second = $this->booked($this->codOrder(['status' => 'processing']));
        $result = CourierSync::one($second);
        $this->afterResponse();

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Updated', $result['message']);
        $this->assertStringContainsString('Sync now', $this->history($second, 'order_status')[0]);
    }

    public function test_a_status_check_fills_in_what_a_missed_webhook_never_told_us(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']));

        // No webhook ever arrived. The first check delivers the order, the next one confirms it and records the cash.
        $this->says('delivered');
        CourierSync::run();
        $this->afterResponse();
        $this->assertSame('delivered', $order->fresh()->status);

        Order::query()->whereKey($order->id)->update(['courier_next_sync_at' => now()->subSecond()]);
        CourierSync::run();
        $this->afterResponse();

        $order->refresh();
        $this->assertNotNull($order->cod_collected_at);
        $this->assertNotNull($order->courier_final_at);
        $this->assertSame(1, $this->sent('order_delivered'));
    }

    // ---- the switch

    public function test_with_automatic_updates_off_the_report_is_kept_and_the_order_is_not_touched(): void
    {
        CourierSetting::current()->update(['status_sync_enabled' => false]);
        $order = $this->booked($this->codOrder(['status' => 'shipped']));

        $this->hook($order, 'delivered', 'evt-1')->assertOk();
        $this->says('delivered');
        CourierSync::run();
        $this->afterResponse();

        $order->refresh();
        $this->assertSame('shipped', $order->status);
        $this->assertSame('delivered', $order->courier_status, 'the courier\'s word is still shown');
        $this->assertNull($order->cod_collected_at);
        $this->assertNull($order->courier_final_at);
        $this->assertSame(0, $this->sent('order_delivered'));
        $this->assertNotEmpty($this->history($order, 'status_recorded'));
    }

    public function test_the_switch_is_on_by_default_and_the_settings_page_shows_the_webhook_headers_but_never_the_token(): void
    {
        $this->assertTrue((bool) CourierSetting::current()->fresh()->status_sync_enabled);

        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());

        $html = $this->get(ManageCourierSettings::getUrl())->assertSuccessful()->getContent();

        $this->assertStringContainsString('Update order status from courier automatically', $html);
        $this->assertStringContainsString('Authorization: Bearer', $html);
        $this->assertStringContainsString('X-Signature', $html);
        $this->assertStringContainsString(url('/api/webhooks/steadfast'), $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);

        Livewire::test(ManageCourierSettings::class)->fillForm(['status_sync_enabled' => false])->call('save')->assertHasNoFormErrors();
        $this->assertFalse((bool) CourierSetting::current()->fresh()->status_sync_enabled);
    }

    // ---- the polling fallback

    public function test_a_parcel_is_followed_for_thirty_days_after_booking_and_not_longer(): void
    {
        $old = $this->booked($this->codOrder(['status' => 'shipped']), 'steadfast', ['courier_booked_at' => now()->subDays(31)]);
        $recent = $this->booked($this->codOrder(['status' => 'shipped']), 'steadfast', ['courier_booked_at' => now()->subDays(29)]);
        $this->says('hold');

        $summary = CourierSync::run();

        $this->assertSame(1, $summary['checked']);
        $this->assertSame('hold', $recent->fresh()->courier_status);
        $this->assertSame('in_review', $old->fresh()->courier_status, 'left alone');
    }

    public function test_one_parcel_failing_for_any_reason_does_not_stop_the_others(): void
    {
        $a = $this->booked($this->codOrder(['status' => 'shipped']), 'steadfast', ['courier_invoice' => 'INV-A']);
        $b = $this->booked($this->codOrder(['status' => 'shipped']), 'steadfast', ['courier_invoice' => 'INV-B']);

        $this->fakeHttp([
            self::STEADFAST.'/status_by_invoice/INV-A' => fn () => throw new \RuntimeException('something unexpected'),
            self::STEADFAST.'/status_by_invoice/INV-B' => Http::response(['status' => 200, 'delivery_status' => 'hold']),
        ]);

        $summary = CourierSync::run();

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['checked']);
        $this->assertSame('hold', $b->fresh()->courier_status);
        $this->assertSame('in_review', $a->fresh()->courier_status);
    }

    public function test_the_command_answers_to_both_names_and_stays_on_the_scheduler(): void
    {
        $this->says('hold');

        $this->artisan('courier:sync')->assertSuccessful();
        $this->artisan('couriers:sync')->assertSuccessful();

        $names = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode(' ');
        $this->assertStringContainsString('couriers:sync', $names);
    }

    // ---- Sync now

    public function test_sync_now_says_plainly_when_it_cannot_or_need_not_check(): void
    {
        $this->assertFalse(CourierSync::one($this->codOrder(['status' => 'processing']))['ok'], 'not sent to a courier');

        $final = $this->booked($this->codOrder(['status' => 'delivered']), 'steadfast', ['courier_final_at' => now()]);
        $result = CourierSync::one($final);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('already final', $result['message']);

        $this->steadfast(['steadfast_enabled' => false]);
        $this->assertStringContainsString('switched off', CourierSync::one($this->booked($this->codOrder(['status' => 'shipped'])))['message']);
    }

    public function test_sync_now_reports_a_courier_failure_without_a_secret_and_backs_the_parcel_off(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']));
        $this->fakeHttp([self::STEADFAST.'/status_by_invoice/*' => Http::response('boom', 500)]);

        $result = CourierSync::one($order);

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('sf-api-key', $result['message']);
        $this->assertStringNotContainsString('sf-secret-key', $result['message']);
        $this->assertSame(1, $order->fresh()->courier_sync_failures);
    }

    public function test_the_sync_now_button_is_on_the_order_page_and_runs_the_same_check(): void
    {
        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());
        $order = $this->booked($this->codOrder(['status' => 'processing']));
        $this->says('in_transit');

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('syncCourierNow')
            ->callAction('syncCourierNow')
            ->assertNotified('Checked with the courier');

        $this->afterResponse();
        $this->assertSame('shipped', $order->fresh()->status);

        $unsent = $this->codOrder(['status' => 'processing']);
        Livewire::test(EditOrder::class, ['record' => $unsent->getRouteKey()])->assertActionHidden('syncCourierNow');
    }

    // ---- a failed email never breaks the webhook

    public function test_a_failing_email_does_not_make_the_webhook_fail_or_undo_the_change(): void
    {
        $order = $this->booked($this->codOrder(['status' => 'shipped']));
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp is down'));

        try {
            $this->hook($order, 'delivered', 'evt-1')->assertOk()->assertJson(['ok' => true]);
        } finally {
            Mail::clearResolvedInstances();
            $this->app->forgetInstance('mail.manager');
        }

        $this->assertSame('delivered', $order->fresh()->status, 'the order still moved');
        $this->assertSame('delivered', $order->fresh()->courier_status);
    }
}
