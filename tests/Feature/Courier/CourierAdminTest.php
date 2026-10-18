<?php

namespace Tests\Feature\Courier;

use App\Filament\Pages\ManageCourierSettings;
use App\Filament\Pages\NetworkCheck;
use App\Filament\Resources\CourierStatusMapResource\Pages\ManageCourierStatusMaps;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\RelationManagers\CourierEventsRelationManager;
use App\Models\CourierEvent;
use App\Models\CourierSetting;
use App\Models\CourierStatusMap;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\AdminRecords;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

class CourierAdminTest extends TestCase
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
    }

    // ---- Settings > Courier

    public function test_the_settings_page_renders_and_never_prints_a_saved_secret(): void
    {
        $this->steadfast(['steadfast_webhook_token' => 'whk-token-ABCDEF123456']);
        $this->pathao();

        $html = $this->get(ManageCourierSettings::getUrl())->assertSuccessful()->assertSee('Saved. Leave blank to keep it.', false)->getContent();

        foreach (['sf-api-key-AAAA1111', 'sf-secret-key-BBBB2222', 'whk-token-ABCDEF123456', 'pa-client-secret-DDDD4444', 'pa-password-EEEE5555', 'pa-client-id-CCCC3333', 'merchant@example.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }

        // The webhook address to paste into the Steadfast panel is shown.
        $this->assertStringContainsString(url('/api/webhooks/steadfast'), $html);
    }

    public function test_leaving_a_secret_blank_keeps_it_and_typing_one_replaces_it_encrypted(): void
    {
        $this->steadfast(['steadfast_auth_failed_at' => now()]);

        Livewire::test(ManageCourierSettings::class)
            ->fillForm(['steadfast_api_key' => '', 'steadfast_secret_key' => '', 'default_weight' => 1.5, 'steadfast_enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = CourierSetting::current();
        $this->assertSame('sf-api-key-AAAA1111', $settings->steadfast_api_key, 'blank keeps what was saved');
        $this->assertSame(1.5, $settings->default_weight);
        $this->assertNotNull($settings->steadfast_auth_failed_at, 'the old keys are still the ones refused');

        Livewire::test(ManageCourierSettings::class)
            ->fillForm(['steadfast_api_key' => 'brand-new-key-123456789', 'steadfast_secret_key' => 'brand-new-secret-987654321'])
            ->call('save');

        $settings = CourierSetting::current();
        $this->assertSame('brand-new-key-123456789', $settings->steadfast_api_key);
        $this->assertNull($settings->steadfast_auth_failed_at, 'new keys get a clean start');
        $this->assertNotSame('brand-new-key-123456789', DB::table('courier_settings')->value('steadfast_api_key'), 'encrypted at rest');
    }

    public function test_after_saving_the_form_holds_no_secret(): void
    {
        $component = Livewire::test(ManageCourierSettings::class)
            ->fillForm(['steadfast_api_key' => 'typed-api-key-123456', 'steadfast_secret_key' => 'typed-secret-654321', 'pathao_password' => 'typed-password-1'])
            ->call('save');

        $state = json_encode($component->get('data'));
        foreach (['typed-api-key-123456', 'typed-secret-654321', 'typed-password-1'] as $secret) {
            $this->assertStringNotContainsString($secret, $state);
        }
        $this->assertSame('typed-api-key-123456', CourierSetting::current()->steadfast_api_key);
    }

    public function test_a_new_pathao_login_or_environment_throws_away_the_old_tokens(): void
    {
        $this->pathao(['pathao_access_token' => 'old-token', 'pathao_refresh_token' => 'old-refresh', 'pathao_token_expires_at' => now()->addDays(4), 'pathao_auth_failed_at' => now()]);

        Livewire::test(ManageCourierSettings::class)->fillForm(['pathao_sandbox' => false])->call('save');

        $settings = CourierSetting::current();
        $this->assertNull($settings->pathao_access_token);
        $this->assertNull($settings->pathao_refresh_token);
        $this->assertNull($settings->pathao_auth_failed_at);
        $this->assertFalse($settings->pathao_sandbox);
    }

    public function test_the_webhook_token_may_hold_only_letters_digits_and_a_few_marks(): void
    {
        Livewire::test(ManageCourierSettings::class)->fillForm(['steadfast_webhook_token' => 'bad token!'])->call('save')->assertHasFormErrors(['steadfast_webhook_token']);
        Livewire::test(ManageCourierSettings::class)->fillForm(['steadfast_webhook_token' => 'Good-Token_1.2=ok'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('Good-Token_1.2=ok', CourierSetting::current()->steadfast_webhook_token);
    }

    public function test_the_steadfast_test_only_pings_and_reads_the_balance_and_never_books(): void
    {
        $this->steadfast();
        $this->fakeHttp([
            self::STEADFAST.'/ping' => Http::response(['status' => 'ok']),
            self::STEADFAST.'/get_balance' => Http::response(['status' => 200, 'current_balance' => 1234.5]),
        ]);

        Livewire::test(ManageCourierSettings::class)->call('testSteadfast')->assertNotified('Steadfast is working');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/ping') && ! $request->hasHeader('Api-Key'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/get_balance') && $request->hasHeader('Api-Key'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'create_order'));
    }

    public function test_a_failed_steadfast_test_shows_the_exact_reason_calmly(): void
    {
        $this->steadfast();
        $this->fakeHttp([
            self::STEADFAST.'/ping' => Http::response(['status' => 'ok']),
            self::STEADFAST.'/get_balance' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        Livewire::test(ManageCourierSettings::class)->call('testSteadfast')->assertNotified('Steadfast did not connect');

        $this->assertNotNull(CourierSetting::current()->steadfast_auth_failed_at);
    }

    public function test_the_pathao_test_logs_in_lists_the_stores_and_names_the_environment(): void
    {
        $this->pathao();
        $this->fakeHttp([
            self::PATHAO.'/issue-token' => Http::response(['access_token' => 't', 'refresh_token' => 'r', 'expires_in' => 432000]),
            self::PATHAO.'/stores' => Http::response(['data' => ['data' => [['store_id' => 777, 'store_name' => 'Anaiza Nest, Banani']]]]),
        ]);

        Livewire::test(ManageCourierSettings::class)->call('testPathao')->assertNotified('Pathao is working (sandbox)');

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/orders'));
    }

    // ---- the sandbox banner

    public function test_the_admin_says_loudly_when_pathao_is_in_the_sandbox_and_stops_when_it_is_live(): void
    {
        $this->pathao(['pathao_sandbox' => true]);
        $this->get('/admin')->assertSuccessful()->assertSee('Pathao SANDBOX mode');

        $this->pathao(['pathao_sandbox' => false]);
        $this->get('/admin')->assertSuccessful()->assertDontSee('SANDBOX mode');

        $this->pathao(['pathao_sandbox' => true, 'pathao_enabled' => false]);
        $this->get('/admin')->assertDontSee('SANDBOX mode');
    }

    // ---- the orders screens

    public function test_send_to_courier_from_the_orders_list_books_and_reports(): void
    {
        $this->steadfast();
        $order = $this->codOrder();
        $this->fakeHttp([self::STEADFAST.'/create_order' => Http::response($this->steadfastBooked((string) $order->id))]);

        Livewire::test(ListOrders::class)
            ->assertTableActionVisible('sendToCourier', $order)
            ->callTableAction('sendToCourier', $order, ['courier' => 'steadfast', 'mark_shipped' => true])
            ->assertNotified('Sent to the courier');

        $order->refresh();
        $this->assertSame('steadfast', $order->courier);
        $this->assertSame('shipped', $order->status);
        $this->assertSame(auth()->id(), $order->courier_booked_by);

        // Booked: the button goes, so a second click cannot even be made.
        Livewire::test(ListOrders::class)->assertTableActionHidden('sendToCourier', $order);
    }

    public function test_a_refused_booking_from_the_list_says_why_and_offers_a_retry(): void
    {
        $this->steadfast();
        $order = $this->codOrder();
        $this->fakeHttp([self::STEADFAST.'/create_order' => Http::response(['status' => 400, 'errors' => ['recipient_phone' => ['Bad phone.']]])]);

        Livewire::test(ListOrders::class)
            ->callTableAction('sendToCourier', $order, ['courier' => 'steadfast', 'mark_shipped' => false])
            ->assertNotified('Not sent');

        $this->assertNull($order->fresh()->courier_booked_at);
        $this->assertStringContainsString('Bad phone', $order->fresh()->courier_last_error);
        Livewire::test(ListOrders::class)->assertTableActionVisible('sendToCourier', $order->fresh());
    }

    public function test_someone_without_the_permission_does_not_see_or_cannot_use_the_actions(): void
    {
        $this->steadfast();
        $order = $this->codOrder();
        Http::fake();

        $role = Role::create(['name' => 'Packer', 'slug' => 'packer', 'permissions' => ['orders.manage']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));

        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('sendToCourier', $order)
            ->assertTableBulkActionHidden('sendToCourierBulk');

        Http::assertNothingSent();

        $role->update(['permissions' => ['orders.manage', 'orders.send_to_courier']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));

        Livewire::test(ListOrders::class)->assertTableActionVisible('sendToCourier', $order);
    }

    public function test_the_bulk_action_sends_the_selected_orders_and_reports_a_line_each(): void
    {
        $this->steadfast();
        $good = $this->codOrder();
        $wallet = $this->codOrder(['payment_method' => 'bkash', 'payment_status' => 'awaiting_verification']);
        $this->fakeHttp(fn (Request $request) => Http::response($this->steadfastBooked($request['invoice'], '5001')));

        Livewire::test(ListOrders::class)
            ->callTableBulkAction('sendToCourierBulk', [$good, $wallet], ['courier' => 'steadfast', 'mark_shipped' => false])
            ->assertNotified('1 of 2 sent');

        $this->assertNotNull($good->fresh()->courier_booked_at);
        $this->assertNull($wallet->fresh()->courier_booked_at);
        Http::assertSentCount(1);
    }

    public function test_the_order_page_shows_the_booking_the_history_and_the_flag_for_a_returned_parcel(): void
    {
        $this->steadfast();
        $order = $this->booked($this->codOrder(['status' => 'shipped']), overrides: ['courier_returned_at' => now(), 'courier_last_error' => 'Status check: Steadfast is busy or down just now (HTTP 503).']);
        CourierEvent::create(['order_id' => $order->id, 'courier' => 'steadfast', 'source' => 'poll', 'event_type' => 'status', 'status' => 'cancelled', 'summary' => 'Steadfast: Cancelled by the courier.']);

        $this->get(OrderResource::getUrl('edit', ['record' => $order]))->assertSuccessful()
            ->assertSee('TRK1001')->assertSee('Returned or cancelled by the courier')->assertSee('not changed automatically');

        Livewire::test(CourierEventsRelationManager::class, ['ownerRecord' => $order, 'pageClass' => EditOrder::class])
            ->assertSuccessful()->assertSee('Steadfast: Cancelled by the courier.');
    }

    public function test_the_order_page_for_an_unbooked_order_shows_the_failure_and_the_send_action(): void
    {
        $this->steadfast();
        $order = $this->codOrder();
        $order->forceFill(['courier_last_error' => 'Steadfast refused the request', 'courier_last_error_at' => now()])->saveQuietly();

        $this->get(OrderResource::getUrl('edit', ['record' => $order]))->assertSuccessful()
            ->assertSee('Steadfast refused the request')->assertSee('Nothing was saved from it');

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])->assertActionVisible('sendToCourier');
    }

    public function test_the_list_filters_by_courier_state(): void
    {
        $this->steadfast();
        $sent = $this->booked($this->codOrder(['status' => 'shipped']));
        $notSent = $this->codOrder();

        Livewire::test(ListOrders::class)
            ->filterTable('courier_state', 'booked')->assertCanSeeTableRecords([$sent])->assertCanNotSeeTableRecords([$notSent])
            ->filterTable('courier_state', 'not_sent')->assertCanSeeTableRecords([$notSent])->assertCanNotSeeTableRecords([$sent]);
    }

    // ---- the status map

    public function test_the_status_map_screen_lists_the_statuses_and_lets_the_owner_change_one(): void
    {
        $row = CourierStatusMap::query()->where('courier', 'steadfast')->where('courier_status', 'hold')->firstOrFail();

        Livewire::test(ManageCourierStatusMaps::class)
            ->assertCanSeeTableRecords([$row])
            ->assertSee('delivered_approval_pending')
            ->callTableAction('edit', $row, ['courier' => 'steadfast', 'courier_status' => 'hold', 'stage' => 'Waiting at the hub', 'effect' => 'none', 'is_final' => false, 'marks_cod_collected' => false, 'rank' => 25])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Waiting at the hub', $row->fresh()->stage);
    }

    public function test_the_seeded_steadfast_map_follows_the_documented_rules(): void
    {
        $map = CourierStatusMap::query()->where('courier', 'steadfast')->get()->keyBy('courier_status');

        foreach (['pending', 'in_review', 'hold', 'delivered_approval_pending', 'partial_delivered_approval_pending', 'cancelled_approval_pending', 'unknown_approval_pending', 'delivered', 'partial_delivered', 'cancelled', 'exceptional', 'unknown'] as $status) {
            $this->assertTrue($map->has($status), $status);
        }

        // Only these three end a parcel, and only delivered collects the cash.
        $this->assertEqualsCanonicalizing(['delivered', 'partial_delivered', 'cancelled'], $map->where('is_final', true)->keys()->all());
        $this->assertSame(['delivered'], $map->where('marks_cod_collected', true)->keys()->all());

        // A rider's word never does anything.
        foreach ($map->filter(fn ($row) => str_ends_with($row->courier_status, '_approval_pending')) as $row) {
            $this->assertSame('none', $row->effect, $row->courier_status);
            $this->assertFalse($row->is_final);
            $this->assertFalse($row->marks_cod_collected);
        }

        // Steadfast's own spelling of the return statuses.
        $this->assertTrue($map->has('partial_delivered_return_proccessing'));
        $this->assertTrue($map->has('cancelled_return_rider_assigned'));
        $this->assertTrue($map->has('cancelled_return_received'));
    }

    // ---- the network page

    public function test_the_network_page_checks_the_courier_hosts_and_shows_which_cannot_be_reached(): void
    {
        $this->fakeHttp(function (Request $request) {
            if (str_contains($request->url(), 'sandbox')) {
                throw new ConnectionException('cURL error 28: Connection timed out');
            }

            return Http::response('', str_contains($request->url(), 'hermes') ? 404 : 200);
        });

        $page = Livewire::test(NetworkCheck::class)->call('checkCouriers');
        $checks = collect($page->get('courierChecks'))->keyBy('name');

        $this->assertCount(3, $checks);
        $this->assertTrue($checks['Steadfast']['ok']);
        $this->assertTrue($checks['Pathao (live)']['ok'], 'any answer means the way is open');
        $this->assertFalse($checks['Pathao (sandbox)']['ok']);
        $this->assertStringContainsString('timed out', $checks['Pathao (sandbox)']['detail']);

        $page->assertSee('portal.packzy.com')->assertSee('api-hermes.pathao.com')->assertSee('courier-api-sandbox.pathao.com');
        Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Api-Key') || $request->hasHeader('Authorization'));
    }

    // ---- the customer's view

    public function test_the_track_order_answer_carries_the_courier_the_code_the_link_and_plain_words_but_nothing_private(): void
    {
        $this->steadfast(['steadfast_tracking_url' => 'https://example.test/track/{code}']);
        $order = $this->booked($this->codOrder(['status' => 'shipped']), overrides: ['courier_tracking_url' => 'https://example.test/track/TRK1001', 'courier_last_error' => 'Status check: secret trouble']);

        $json = $this->getJson('/api/orders/lookup?order_id='.$order->id.'&phone=01712345678')->assertOk();

        $json->assertJsonPath('data.courier_name', 'Steadfast')
            ->assertJsonPath('data.tracking_number', 'TRK1001')
            ->assertJsonPath('data.tracking_url', 'https://example.test/track/TRK1001')
            ->assertJsonPath('data.delivery_update', 'With Steadfast, on its way to you.');

        $text = $json->getContent();
        foreach (['secret trouble', 'cod_amount', 'courier_consignment_id', 'courier_status', 'courier_invoice'] as $private) {
            $this->assertStringNotContainsString($private, $text);
        }

        // Plain words for each state, and nothing before the order is booked.
        $order->forceFill(['courier_status' => 'hold'])->saveQuietly();
        $this->getJson('/api/orders/lookup?order_id='.$order->id.'&phone=01712345678')->assertJsonPath('data.delivery_update', 'On hold with Steadfast. We are following it up.');

        $order->forceFill(['courier_returned_at' => now()])->saveQuietly();
        $this->getJson('/api/orders/lookup?order_id='.$order->id.'&phone=01712345678')->assertJsonPath('data.delivery_update', 'Steadfast could not complete this delivery. We will contact you.');

        $plain = $this->codOrder();
        $this->getJson('/api/orders/lookup?order_id='.$plain->id.'&phone=01712345678')->assertJsonPath('data.delivery_update', null)->assertJsonPath('data.tracking_url', null);
    }

    public function test_the_courier_permission_exists_and_super_admin_has_it(): void
    {
        $this->assertArrayHasKey('orders.send_to_courier', Role::PERMISSIONS);
        $this->assertTrue($this->admin()->hasPermission('orders.send_to_courier'));
        $this->assertFalse(User::factory()->create(['role_id' => AdminRecords::role(['permissions' => ['orders.manage']])->id])->hasPermission('orders.send_to_courier'));
    }
}
