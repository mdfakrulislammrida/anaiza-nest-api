<?php

namespace Tests\Feature\Courier;

use App\Filament\Resources\CourierStatusMapResource\Pages\ManageCourierStatusMaps;
use App\Mail\TransactionalMail;
use App\Models\CourierEvent;
use App\Models\CourierSetting;
use App\Models\CourierStatusMap;
use App\Models\Order;
use App\Support\Courier\CourierEffectPreview;
use App\Support\Courier\CourierStatusApplier;
use App\Support\Courier\CourierSync;
use App\Support\Courier\PathaoStatusPreset;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

/**
 * The ready-made Pathao status rows (OPS1-B follow-up): they show their meaning but change nothing until they are armed, the preview lists what
 * arming would do, and a catch-up of old news never writes to a customer. Every courier answer is faked.
 */
class PathaoStatusArmingTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop();
        $this->pathao();
        Mail::fake();
    }

    private function row(string $status): CourierStatusMap
    {
        return CourierStatusMap::lookup('pathao', $status) ?? $this->fail("no row for {$status}");
    }

    private function arm(): void
    {
        CourierStatusMap::query()->where('courier', 'pathao')->update(['armed' => true]);
    }

    private function parcel(string $courierStatus, string $orderStatus = 'shipped', array $overrides = []): Order
    {
        return $this->booked($this->codOrder(['status' => $orderStatus]), 'pathao', $overrides + [
            'courier_consignment_id' => 'DL'.random_int(100000, 999999), 'courier_status' => $courierStatus, 'courier_status_text' => null, 'courier_status_rank' => null,
        ]);
    }

    private function pathaoSays(string $slug): void
    {
        $this->fakeHttp([
            self::PATHAO.'/issue-token' => Http::response(['access_token' => 't', 'refresh_token' => 'r', 'expires_in' => 432000]),
            self::PATHAO.'/orders/*/info' => Http::response(['data' => ['order_status' => $slug, 'order_status_slug' => $slug]]),
        ]);
    }

    private function sent(string $key): int
    {
        return Mail::sent(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->templateKey === $key)->count();
    }

    /** The status was first stored this long ago (the order's own history line for it). */
    private function storedAgo(Order $order, string $status, int $hours): void
    {
        DB::table('courier_events')->insert([
            'order_id' => $order->id, 'courier' => 'pathao', 'source' => 'poll', 'event_type' => 'status', 'status' => $status,
            'summary' => 'seen', 'created_at' => now()->subHours($hours),
        ]);
    }

    // ---- the rows

    public function test_the_nine_seen_statuses_are_there_mapped_and_not_armed(): void
    {
        $expected = [
            'pending' => ['Booked, waiting for pickup', 'none'], 'Pickup_Requested' => ['Booked, waiting for pickup', 'none'], 'Assigned_for_Pickup' => ['Booked, waiting for pickup', 'none'],
            'At_the_Sorting_HUB' => ['In transit', 'shipped'], 'On_the_way_to_Sorting HUB' => ['In transit', 'shipped'], 'In_Transit' => ['In transit', 'shipped'],
            'Received_at_Last_Mile_HUB' => ['In transit', 'shipped'], 'Assigned_for_Delivery' => ['Out for delivery', 'none'], 'Delivered' => ['Delivered', 'delivered'],
        ];

        foreach ($expected as $status => [$stage, $effect]) {
            $row = $this->row($status);
            $this->assertSame($stage, $row->stage, $status);
            $this->assertSame($effect, $row->effect, $status);
            $this->assertFalse($row->armed, "{$status} starts not armed");
            $this->assertNotNull($row->rank, $status);
        }

        $delivered = $this->row('Delivered');
        $this->assertTrue($delivered->is_final);
        $this->assertTrue($delivered->marks_cod_collected);
        $this->assertSame(100, $delivered->rank);
        $this->assertSame(CourierStatusMap::query()->where('courier', 'steadfast')->where('courier_status', 'delivered')->value('rank'), $delivered->rank, 'the same place as Steadfast');
        $this->assertFalse($this->row('In_Transit')->is_final);
        $this->assertTrue(CourierStatusMap::query()->where('courier', 'steadfast')->where('armed', false)->doesntExist(), 'Steadfast rows keep acting');
    }

    public function test_spelling_variants_land_on_one_row(): void
    {
        $this->assertSame(1, CourierStatusMap::query()->where('courier', 'pathao')->where('status_key', 'on_the_way_to_sorting_hub')->count());

        foreach (['On_the_way_to_Sorting HUB', 'On_the_way_to_Sorting_HUB', 'on the way to sorting hub', 'ON-THE-WAY-TO-SORTING-HUB'] as $variant) {
            $this->assertSame($this->row('On_the_way_to_Sorting HUB')->id, CourierStatusMap::noteSeen('pathao', $variant)->id, $variant);
        }

        $this->assertSame(1, CourierStatusMap::query()->where('courier', 'pathao')->where('status_key', 'on_the_way_to_sorting_hub')->count());
        $this->assertSame($this->row('pending')->id, CourierStatusMap::lookup('pathao', 'Pending')->id);
    }

    public function test_seeding_changes_no_order_and_can_be_repeated(): void
    {
        // As the owner's database is: every Pathao status seen, all "Unknown status", and parcels holding them.
        CourierStatusMap::query()->where('courier', 'pathao')->delete();
        foreach (PathaoStatusPreset::rows() as $preset) {
            CourierStatusMap::query()->create(['courier' => 'pathao', 'courier_status' => $preset['status'], 'stage' => 'Unknown status', 'effect' => 'none', 'is_final' => false, 'marks_cod_collected' => false, 'rank' => null]);
        }
        $delivered = $this->parcel('Delivered');
        $transit = $this->parcel('In_Transit', 'processing');
        $before = Order::query()->orderBy('id')->get()->map->getAttributes()->all();
        $events = CourierEvent::query()->count();

        $first = PathaoStatusPreset::seed();
        $second = PathaoStatusPreset::seed();

        $this->assertSame(9, $first['filled']);
        $this->assertSame(0, $second['filled'] + $second['added'], 'a second run changes nothing');
        $this->assertSame($before, Order::query()->orderBy('id')->get()->map->getAttributes()->all(), 'not one order changed');
        $this->assertSame($events, CourierEvent::query()->count());
        $this->assertSame(9, CourierStatusMap::query()->where('courier', 'pathao')->count());
        $this->assertSame('shipped', $delivered->fresh()->status);
        $this->assertSame('processing', $transit->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_a_row_the_owner_edited_is_not_overwritten(): void
    {
        $this->row('Delivered')->update(['stage' => 'Handed over', 'effect' => 'none', 'is_final' => false, 'marks_cod_collected' => false, 'armed' => true]);
        $this->row('In_Transit')->update(['rank' => 77]);

        PathaoStatusPreset::seed();

        $delivered = $this->row('Delivered');
        $this->assertSame('Handed over', $delivered->stage);
        $this->assertSame('none', $delivered->effect);
        $this->assertTrue($delivered->armed);
        $this->assertSame(77, $this->row('In_Transit')->rank);
        $this->assertSame(1, CourierStatusMap::query()->where('courier', 'pathao')->where('status_key', 'delivered')->count());
    }

    // ---- not armed, then armed

    public function test_an_unarmed_row_shows_its_meaning_and_changes_nothing(): void
    {
        $order = $this->parcel('pending');
        $this->pathaoSays('Delivered');

        CourierSync::run();
        $this->afterResponse();

        $order->refresh();
        $this->assertSame('Delivered', $order->courier_status);
        $this->assertSame('Delivered', $order->courier_status_text, 'the meaning is shown');
        $this->assertSame('shipped', $order->status);
        $this->assertNull($order->cod_collected_at);
        $this->assertNull($order->courier_final_at, 'and checking goes on');
        $this->assertNull($order->courier_effect_status);
        $this->assertSame(0, $this->sent('order_delivered'));
        $this->assertStringContainsString('not switched on', CourierEvent::query()->where('order_id', $order->id)->where('event_type', 'status_recorded')->value('summary'));
    }

    public function test_after_arming_delivered_marks_delivered_and_the_cash_the_same_way_as_steadfast(): void
    {
        $order = $this->parcel('pending');
        $this->pathaoSays('Delivered');
        CourierSync::run();
        $this->afterResponse();
        $this->assertSame('shipped', $order->fresh()->status);

        $this->arm();
        Order::query()->whereKey($order->id)->update(['courier_next_sync_at' => now()->subSecond()]);
        CourierSync::run();
        $this->afterResponse();

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame(1580, $order->cod_collected_amount);
        $this->assertNotNull($order->courier_final_at);
        $this->assertSame(1, $this->sent('order_delivered'), 'fresh news: the existing delivered email, once');
        $this->assertStringContainsString('couriers:sync', CourierEvent::query()->where('order_id', $order->id)->where('event_type', 'order_status')->value('summary'));
    }

    public function test_in_transit_moves_pending_or_processing_to_shipped_and_never_backwards(): void
    {
        $this->arm();
        $this->pathaoSays('In_Transit');

        foreach (['pending', 'processing'] as $status) {
            $order = $this->parcel('Pickup_Requested', $status);
            CourierSync::run();
            $this->afterResponse();
            $this->assertSame('shipped', $order->fresh()->status, $status);
        }

        // Delivered by a person, or by the courier: a later In_Transit moves nothing.
        $done = $this->parcel('Delivered', 'delivered', ['courier_status_rank' => 100]);
        $this->pathaoSays('In_Transit');
        CourierSync::run();
        $this->afterResponse();
        $this->assertSame('delivered', $done->fresh()->status);
        $this->assertSame('Delivered', $done->fresh()->courier_status, 'the earlier report was ignored');
    }

    public function test_a_repeated_or_out_of_order_report_changes_nothing(): void
    {
        $this->arm();
        $order = $this->parcel('In_Transit', 'shipped', ['courier_status_rank' => 30]);

        $this->pathaoSays('Delivered');
        CourierSync::run();
        $this->afterResponse();
        CourierStatusApplier::apply($order->fresh(), 'pathao', 'Delivered', 'poll');
        CourierStatusApplier::apply($order->fresh(), 'pathao', 'Delivered', 'poll');
        $afterDelivered = CourierEvent::query()->where('order_id', $order->id)->count();

        CourierStatusApplier::apply($order->fresh(), 'pathao', 'At_the_Sorting_HUB', 'poll');
        CourierStatusApplier::apply($order->fresh(), 'pathao', 'delivered', 'poll');

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame('Delivered', $order->courier_status);
        $this->assertSame(1, $this->sent('order_delivered'));
        $this->assertSame(1, CourierEvent::query()->where('order_id', $order->id)->where('event_type', 'cod_collected')->count());
        $this->assertLessThanOrEqual($afterDelivered + 1, CourierEvent::query()->where('order_id', $order->id)->count(), 'at most one note that a late report was ignored');
    }

    // ---- the preview and --apply

    private function catchUp(int $count = 3): array
    {
        // Parcels whose Delivered the shop has known for three days, never acted on: the owner's 222.
        $orders = [];
        foreach (range(1, $count) as $n) {
            $order = $this->parcel('Delivered', 'shipped', ['courier_booked_at' => now()->subDays(9)]);
            $this->storedAgo($order, 'Delivered', 72);
            $orders[] = $order;
        }

        return $orders;
    }

    public function test_the_preview_lists_what_would_change_and_changes_nothing(): void
    {
        $orders = $this->catchUp();
        $this->parcel('pending'); // would change nothing: not listed
        $before = Order::query()->orderBy('id')->get()->map->getAttributes()->all();

        $rows = CourierEffectPreview::rows();

        $this->assertCount(3, $rows);
        $this->assertSame('delivered', $rows[0]['new_status']);
        $this->assertSame('shipped', $rows[0]['status']);
        $this->assertTrue($rows[0]['cash']);
        $this->assertSame('no (old news)', $rows[0]['email']);
        $this->assertStringContainsString('no SMS', $rows[0]['sms']);
        $this->assertFalse($rows[0]['armed']);

        $this->artisan('couriers:preview-effects')
            ->expectsOutputToContain('3 order(s) would change.')
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();

        $this->assertSame($before, Order::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame('shipped', $orders[0]->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_apply_changes_them_after_asking_and_sends_no_email_for_old_news(): void
    {
        $orders = $this->catchUp();

        $this->artisan('couriers:preview-effects --apply')
            ->expectsConfirmation('Change these 3 order(s) now?', 'no')
            ->assertSuccessful();
        $this->assertSame('shipped', $orders[0]->fresh()->status, 'declining changes nothing');

        $this->artisan('couriers:preview-effects --apply')
            ->expectsConfirmation('Change these 3 order(s) now?', 'yes')
            ->expectsOutputToContain('3 order(s) changed.')
            ->assertSuccessful();
        $this->afterResponse();

        foreach ($orders as $order) {
            $order->refresh();
            $this->assertSame('delivered', $order->status);
            $this->assertSame(1580, $order->cod_collected_amount);
            $this->assertNotNull($order->courier_final_at);
            $this->assertSame('catch_up', $order->review_request_status, 'no review request out of the blue');
            $this->assertStringContainsString('couriers:preview-effects', CourierEvent::query()->where('order_id', $order->id)->where('event_type', 'order_status')->value('summary'));
        }

        Mail::assertNothingSent();
        $this->assertSame([], CourierEffectPreview::rows(), 'nothing left to do');
    }

    public function test_notify_sends_the_old_emails_only_when_the_owner_asks(): void
    {
        $orders = $this->catchUp(2);

        $this->artisan('couriers:preview-effects --apply --notify')
            ->expectsConfirmation('Change these 2 order(s) now?', 'yes')
            ->assertSuccessful();
        $this->afterResponse();

        $this->assertSame(2, $this->sent('order_delivered'));
        $this->assertNull($orders[0]->fresh()->review_request_status);
    }

    public function test_armed_catch_up_in_the_normal_sync_sends_no_email_either(): void
    {
        $orders = $this->catchUp(2);
        $this->arm();
        $this->pathaoSays('Delivered');

        CourierSync::run();
        $this->afterResponse();

        foreach ($orders as $order) {
            $this->assertSame('delivered', $order->fresh()->status);
        }
        $this->assertSame(0, $this->sent('order_delivered'), 'old news never reaches a customer by itself');
    }

    public function test_the_preview_is_empty_when_automatic_updates_are_off_or_nothing_is_stored(): void
    {
        $this->catchUp();
        CourierSetting::current()->update(['status_sync_enabled' => false]);

        $this->assertSame([], CourierEffectPreview::rows());
        $this->artisan('couriers:preview-effects')->expectsOutputToContain('No order would change')->assertSuccessful();
    }

    // ---- arming in the admin

    public function test_the_arm_action_arms_only_the_pathao_rows_and_then_goes_away(): void
    {
        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());
        $this->catchUp(2);
        CourierStatusMap::query()->where('courier', 'steadfast')->where('courier_status', 'pending')->update(['armed' => false]);

        Livewire::test(ManageCourierStatusMaps::class)
            ->assertActionVisible('armPathao')
            ->callAction('armPathao')
            ->assertNotified('Pathao statuses armed')
            ->assertActionHidden('armPathao');

        $this->assertTrue(CourierStatusMap::query()->where('courier', 'pathao')->where('armed', false)->doesntExist());
        $this->assertFalse((bool) CourierStatusMap::query()->where('courier', 'steadfast')->where('courier_status', 'pending')->value('armed'), 'only Pathao is touched');
        $this->assertSame('shipped', Order::query()->whereNotNull('courier')->first()->status, 'arming alone changes no order');
    }
}
