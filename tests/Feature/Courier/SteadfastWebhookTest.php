<?php

namespace Tests\Feature\Courier;

use App\Mail\ShopAlertMail;
use App\Mail\TransactionalMail;
use App\Models\CourierEvent;
use App\Models\CourierSetting;
use App\Models\Order;
use App\Support\Courier\CourierSync;
use App\Support\Courier\SteadfastWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

class SteadfastWebhookTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    private const TOKEN = 'whk_Token-123.ABC=xyz';

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop();
        $this->steadfast(['steadfast_webhook_token' => self::TOKEN]);
        $this->order = $this->booked($this->codOrder(['status' => 'shipped']));
        Mail::fake();
    }

    /**
     * Posts exactly as Steadfast does: JSON, a Bearer token, and the hex HMAC-SHA256 of the raw body keyed with the token.
     *
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, string>  $headers
     */
    private function hook(array|string $payload, array $headers = [], ?string $token = self::TOKEN, bool $sign = true): TestResponse
    {
        $raw = is_array($payload) ? json_encode($payload) : $payload;
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_USER_AGENT' => 'Steadfast-Webhook/1.0'];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
            if ($sign) {
                $server['HTTP_X_SIGNATURE'] = hash_hmac('sha256', $raw, $token);
            }
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $response = $this->call('POST', '/api/webhooks/steadfast', [], [], [], $server, $raw);
        $this->forgetTerminating();

        return $response;
    }

    private function forgetTerminating(): void
    {
        (new \ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);
    }

    private function delivery(string $status, array $extra = []): array
    {
        return array_merge(['notification_type' => 'delivery_status', 'consignment_id' => 1001, 'invoice' => (string) $this->order->id, 'cod_amount' => 1580, 'status' => $status, 'delivery_charge' => 80, 'tracking_message' => 'Update', 'updated_at' => '2026-10-22 10:00:00'], $extra);
    }

    private function delivered(): int
    {
        return Mail::sent(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->templateKey === 'order_delivered')->count();
    }

    // ---- who may call

    public function test_a_correctly_signed_delivery_status_updates_the_order_after_the_answer(): void
    {
        $this->hook($this->delivery('hold'), ['Idempotency-Key' => 'evt-1'])->assertOk()->assertJson(['ok' => true]);

        $order = $this->order->fresh();
        $this->assertSame('hold', $order->courier_status);
        $this->assertSame('On hold', $order->courier_status_text);
        $this->assertNotNull(CourierSetting::current()->steadfast_webhook_last_at);

        $event = CourierEvent::query()->where('idempotency_key', 'steadfast:evt-1')->firstOrFail();
        $this->assertSame($order->id, $event->order_id);
        $this->assertSame('webhook', $event->source);
        $this->assertSame('delivery_status', $event->event_type);
    }

    public function test_a_wrong_signature_is_refused_with_401_and_changes_nothing(): void
    {
        $raw = json_encode($this->delivery('delivered'));

        $this->call('POST', '/api/webhooks/steadfast', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw.' ', self::TOKEN),
        ], $raw)->assertUnauthorized();

        // A signature made with another key.
        $this->call('POST', '/api/webhooks/steadfast', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw, 'another-token'),
        ], $raw)->assertUnauthorized();

        $this->assertSame(0, CourierEvent::query()->where('source', 'webhook')->count());
        $this->assertSame('in_review', $this->order->fresh()->courier_status);
    }

    public function test_a_wrong_bearer_token_is_refused_even_with_a_good_signature(): void
    {
        $raw = json_encode($this->delivery('delivered'));

        $this->call('POST', '/api/webhooks/steadfast', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer wrong-token', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw, self::TOKEN),
        ], $raw)->assertUnauthorized();

        $this->hook($this->delivery('delivered'), token: self::TOKEN, headers: ['Authorization' => 'Basic '.self::TOKEN])->assertUnauthorized();
        $this->assertSame('in_review', $this->order->fresh()->courier_status);
    }

    public function test_a_missing_signature_or_a_missing_bearer_is_refused(): void
    {
        $this->hook($this->delivery('delivered'), sign: false)->assertUnauthorized();
        $this->hook($this->delivery('delivered'), token: null)->assertUnauthorized();
        $this->assertSame(0, CourierEvent::query()->where('source', 'webhook')->count());
    }

    public function test_the_signature_is_checked_on_the_raw_body_not_on_re_encoded_json(): void
    {
        // The same JSON written with different spacing and escapes: signed as sent, it passes; signed as re-encoded, it fails.
        $raw = '{ "notification_type":"delivery_status",  "invoice":"'.$this->order->id.'", "status":"hold", "consignment_id":1001 }';

        $this->hook($raw)->assertOk();

        $reencoded = json_encode(json_decode($raw, true));
        $this->call('POST', '/api/webhooks/steadfast', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $reencoded, self::TOKEN),
        ], $raw)->assertUnauthorized();
    }

    public function test_no_token_saved_means_the_webhook_is_off_not_open(): void
    {
        CourierSetting::current()->update(['steadfast_webhook_token' => null]);

        // Even an empty token "signed" with an empty key gets nowhere.
        $this->call('POST', '/api/webhooks/steadfast', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', '{}', '')], '{}')->assertNotFound();
        $this->hook($this->delivery('delivered'), token: self::TOKEN)->assertNotFound();

        CourierSetting::current()->update(['steadfast_webhook_token' => self::TOKEN, 'steadfast_enabled' => false]);
        $this->hook($this->delivery('delivered'))->assertNotFound();

        $this->assertSame(0, CourierEvent::query()->where('source', 'webhook')->count());
        $this->assertSame('in_review', $this->order->fresh()->courier_status);
    }

    public function test_a_body_that_is_not_json_is_a_400_and_a_bad_signature_is_never_logged_with_the_token(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $this->hook('not json')->assertStatus(400);
        $this->hook($this->delivery('delivered'), token: 'wrong-token-value')->assertUnauthorized();
        $this->hook($this->delivery('delivered'))->assertOk();

        $this->assertNotEmpty($logged);
        foreach ($logged as $line) {
            $this->assertStringNotContainsString(self::TOKEN, $line);
            $this->assertStringNotContainsString('wrong-token-value', $line);
            $this->assertStringNotContainsString('tracking_message', $line, 'the body is not logged');
        }
    }

    public function test_the_route_is_rate_limited(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/webhooks/steadfast', 'POST'));

        $this->assertContains('throttle:courier-webhook', $route->gatherMiddleware());
    }

    // ---- replays and mismatches

    public function test_a_retried_event_with_the_same_idempotency_key_is_answered_and_applied_once(): void
    {
        $payload = $this->delivery('delivered');

        $this->hook($payload, ['Idempotency-Key' => 'evt-77'])->assertOk()->assertJsonMissing(['duplicate' => true]);
        $this->hook($payload, ['Idempotency-Key' => 'evt-77'])->assertOk()->assertJson(['duplicate' => true]);
        $this->hook($payload, ['Idempotency-Key' => 'evt-77'])->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, CourierEvent::query()->where('idempotency_key', 'steadfast:evt-77')->count());
        $this->assertSame(1, CourierEvent::query()->where('order_id', $this->order->id)->where('event_type', 'status')->count());
        $this->assertSame(1, $this->delivered());
    }

    public function test_without_an_idempotency_key_the_body_itself_identifies_a_repeat(): void
    {
        $payload = $this->delivery('hold');

        $this->hook($payload)->assertOk()->assertJsonMissing(['duplicate' => true]);
        $this->hook($payload)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, CourierEvent::query()->where('source', 'webhook')->whereNotNull('idempotency_key')->count());
    }

    public function test_an_event_for_an_order_we_do_not_have_is_kept_and_ignored(): void
    {
        $this->hook(['notification_type' => 'delivery_status', 'invoice' => '99999', 'consignment_id' => 5, 'status' => 'delivered'], ['Idempotency-Key' => 'evt-x'])->assertOk();

        $event = CourierEvent::query()->where('idempotency_key', 'steadfast:evt-x')->firstOrFail();
        $this->assertNull($event->order_id);
        $this->assertStringContainsString('no order of ours matches', $event->summary);
        $this->assertSame('in_review', $this->order->fresh()->courier_status);
        $this->assertSame(0, $this->delivered());
    }

    public function test_a_consignment_that_is_not_the_one_we_booked_is_ignored(): void
    {
        $this->hook($this->delivery('delivered', ['consignment_id' => 555]), ['Idempotency-Key' => 'evt-m'])->assertOk();

        $this->assertSame('in_review', $this->order->fresh()->courier_status);
        $this->assertSame(1, CourierEvent::query()->where('order_id', $this->order->id)->where('event_type', 'webhook_mismatch')->count());
    }

    public function test_the_order_is_found_by_consignment_when_the_invoice_is_missing(): void
    {
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => 1001, 'status' => 'hold'])->assertOk();

        $this->assertSame('hold', $this->order->fresh()->courier_status);
    }

    // ---- what a status does

    public function test_delivered_by_webhook_delivers_the_order_but_cash_waits_for_a_status_check(): void
    {
        $this->hook($this->delivery('delivered'), ['Idempotency-Key' => 'evt-d'])->assertOk();

        $order = $this->order->fresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame(1, $this->delivered());
        $this->assertNull($order->cod_collected_at, 'never marked collected from a webhook alone');
        $this->assertNull($order->courier_final_at, 'not final until a check confirms it');

        // The confirming status check is due within minutes, not a quarter of an hour.
        $this->assertTrue($order->courier_next_sync_at->lt(now()->addMinutes(3)));
        $this->assertSame(0, CourierSync::run()['checked'], 'not before it is due');
        Order::query()->whereKey($order->id)->update(['courier_next_sync_at' => now()->subSecond()]);

        // The status check confirms it: now the cash is recorded and the checking stops.
        $this->fakeHttp([self::STEADFAST.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'delivered'])]);
        $this->assertSame(1, CourierSync::run()['checked']);

        $order->refresh();
        $this->assertNotNull($order->cod_collected_at);
        $this->assertSame(1580, $order->cod_collected_amount);
        $this->assertNotNull($order->courier_final_at);
        $this->assertSame(1, $this->delivered(), 'no second email');
    }

    public function test_a_riders_unconfirmed_word_by_webhook_delivers_nothing(): void
    {
        foreach (['delivered_approval_pending', 'partial_delivered_approval_pending', 'cancelled_approval_pending'] as $i => $status) {
            $this->hook($this->delivery($status), ['Idempotency-Key' => "evt-a{$i}"])->assertOk();
        }

        $order = $this->order->fresh();
        $this->assertSame('shipped', $order->status);
        $this->assertNull($order->cod_collected_at);
        $this->assertNull($order->courier_returned_at);
        $this->assertSame(0, $this->delivered());
    }

    public function test_cancelled_and_partial_by_webhook_flag_and_tell_the_owner_without_touching_money_or_stock(): void
    {
        $this->hook($this->delivery('cancelled'), ['Idempotency-Key' => 'evt-c'])->assertOk();

        $order = $this->order->fresh();
        $this->assertNotNull($order->courier_returned_at);
        $this->assertSame('shipped', $order->status);
        $this->assertNull($order->cod_collected_at);
        Mail::assertSent(ShopAlertMail::class, 1);
    }

    public function test_a_late_earlier_status_never_moves_the_parcel_backwards(): void
    {
        $this->hook($this->delivery('hold'), ['Idempotency-Key' => 'e1'])->assertOk();
        $this->hook($this->delivery('pending'), ['Idempotency-Key' => 'e2'])->assertOk();
        $this->hook($this->delivery('delivered_approval_pending'), ['Idempotency-Key' => 'e3'])->assertOk();
        $this->hook($this->delivery('in_review'), ['Idempotency-Key' => 'e4'])->assertOk();

        $this->assertSame('delivered_approval_pending', $this->order->fresh()->courier_status);
        $this->assertSame(2, CourierEvent::query()->where('order_id', $this->order->id)->where('event_type', 'status_ignored')->count());
    }

    public function test_a_status_we_have_not_seen_is_stored_as_unknown(): void
    {
        $this->hook($this->delivery('brand_new_status'), ['Idempotency-Key' => 'evt-u'])->assertOk();

        $this->assertSame('brand_new_status', $this->order->fresh()->courier_status);
        $this->assertSame('Unknown status', $this->order->fresh()->courier_status_text);
        $this->assertDatabaseHas('courier_status_maps', ['courier' => 'steadfast', 'courier_status' => 'brand_new_status', 'effect' => 'none']);
    }

    public function test_a_delivery_status_with_no_type_is_treated_as_one(): void
    {
        $payload = $this->delivery('hold');
        unset($payload['notification_type']);

        $this->hook($payload)->assertOk();

        $this->assertSame('hold', $this->order->fresh()->courier_status);
    }

    // ---- every other event is stored and nothing more

    public function test_every_other_event_type_is_stored_and_acts_on_nothing(): void
    {
        $before = $this->order->only(['status', 'courier_status', 'courier_status_text', 'courier_final_at', 'courier_returned_at', 'cod_collected_at']);

        foreach (array_diff(SteadfastWebhook::EVENTS, ['delivery_status']) as $i => $type) {
            $this->hook([
                'notification_type' => $type, 'consignment_id' => 1001, 'invoice' => (string) $this->order->id, 'status' => 'delivered',
                'message' => 'Something happened', 'recipient_phone' => '01712345678', 'recipient_name' => 'Nusrat', 'address' => 'House 12',
                'contact_number' => '01800000000', 'nested' => ['email' => 'a@example.com', 'note' => 'ok'],
            ], ['Idempotency-Key' => "evt-t{$i}"])->assertOk();

            $event = CourierEvent::query()->where('idempotency_key', "steadfast:evt-t{$i}")->firstOrFail();
            $this->assertSame($type, $event->event_type);
            $this->assertSame($this->order->id, $event->order_id);

            // Nothing that could identify a person is kept.
            $kept = json_encode($event->payload);
            foreach (['01712345678', 'Nusrat', 'House 12', '01800000000', 'a@example.com'] as $private) {
                $this->assertStringNotContainsString($private, $kept, $type);
            }
            $this->assertStringContainsString('Something happened', $kept);
        }

        $this->assertSame($before, $this->order->fresh()->only(['status', 'courier_status', 'courier_status_text', 'courier_final_at', 'courier_returned_at', 'cod_collected_at']));
        $this->assertSame(0, $this->delivered());
        Mail::assertNothingSent();
        $this->assertSame(8, CourierEvent::query()->where('source', 'webhook')->count());
    }

    public function test_an_event_type_we_have_never_heard_of_is_kept_as_other(): void
    {
        $this->hook(['notification_type' => 'brand_new_event', 'invoice' => (string) $this->order->id], ['Idempotency-Key' => 'evt-o'])->assertOk();

        $this->assertSame('other', CourierEvent::query()->where('idempotency_key', 'steadfast:evt-o')->value('event_type'));
        $this->assertSame('in_review', $this->order->fresh()->courier_status);
    }

    public function test_polling_and_the_webhook_can_run_side_by_side_without_doing_anything_twice(): void
    {
        $this->fakeHttp([self::STEADFAST.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'hold'])]);

        CourierSync::run();
        $this->hook($this->delivery('hold'), ['Idempotency-Key' => 'evt-s1'])->assertOk();

        $this->assertSame(1, CourierEvent::query()->where('order_id', $this->order->id)->where('event_type', 'status')->count(), 'the same status is not recorded twice');
        $this->assertSame('hold', $this->order->fresh()->courier_status);
    }
}
