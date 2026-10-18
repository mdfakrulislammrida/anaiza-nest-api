<?php

namespace Tests\Feature\Courier;

use App\Mail\TransactionalMail;
use App\Models\BlockedContact;
use App\Models\CourierEvent;
use App\Models\CourierSetting;
use App\Models\Order;
use App\Models\User;
use App\Support\Courier\CourierBooking;
use App\Support\Courier\CourierText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AdminRecords;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

class CourierBookingTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop();
    }

    // ---- what is owed, and what stops an order

    public function test_the_cash_to_collect_is_worked_out_on_the_server_from_the_payment(): void
    {
        $this->steadfast();

        $cod = $this->codOrder();
        $this->assertSame(1580, CourierBooking::codAmount($cod));

        // A verified wallet payment is paid: nothing to collect.
        $paid = $this->codOrder(['payment_method' => 'bkash', 'payment_status' => 'verified']);
        $this->assertSame(0, CourierBooking::codAmount($paid));
        $this->assertSame(0, CourierBooking::preview($paid, 'steadfast')['payload']['cod_amount']);

        // Undecided or failed: not ready to send.
        foreach (['awaiting_verification', 'failed'] as $status) {
            $order = $this->codOrder(['payment_method' => 'nagad', 'payment_status' => $status]);
            $this->assertNull(CourierBooking::codAmount($order));
            $this->assertNotEmpty(CourierBooking::preview($order, 'steadfast')['blocks'], $status);
        }
    }

    public function test_an_order_with_no_phone_no_address_or_a_final_status_is_blocked_and_nothing_is_sent(): void
    {
        $this->steadfast();
        Http::fake();

        $noPhone = $this->codOrder(['ship_phone' => 'abc']);
        $noPhone->customer->update(['phone' => null]);
        $noAddress = $this->codOrder(['ship_address' => '']);
        $noAddress->customer->update(['address' => null]);
        $delivered = $this->codOrder(['status' => 'delivered']);

        foreach ([$noPhone, $noAddress, $delivered] as $order) {
            $result = CourierBooking::book($order, 'steadfast', $this->admin());
            $this->assertFalse($result['ok']);
            $this->assertNull($order->fresh()->courier_booked_at);
        }

        Http::assertNothingSent();
        $this->assertStringContainsString('phone', CourierBooking::preview($noPhone->fresh(), 'steadfast')['blocks'][0]);
        $this->assertStringContainsString('address', implode(' ', CourierBooking::preview($noAddress->fresh(), 'steadfast')['blocks']));
    }

    public function test_a_courier_that_is_off_or_has_no_details_cannot_take_a_booking(): void
    {
        Http::fake();
        $order = $this->codOrder();

        $this->assertFalse(CourierBooking::book($order, 'steadfast', $this->admin())['ok']);

        $this->steadfast(['steadfast_enabled' => false]);
        $this->assertFalse(CourierBooking::book($order, 'steadfast', $this->admin())['ok']);
        $this->assertFalse(CourierBooking::book($order, 'dhl', $this->admin())['ok']);

        Http::assertNothingSent();
    }

    // ---- Steadfast

    public function test_steadfast_books_once_with_clean_text_and_records_everything_it_should(): void
    {
        $this->steadfast();
        $order = $this->codOrder(['ship_name' => 'Nusrat {Jahan}; <b>$', 'gift_note' => str_repeat('Leave at the gate. ', 40)]);
        Http::fake([self::STEADFAST.'/create_order' => Http::response($this->steadfastBooked((string) $order->id))]);
        Mail::fake();

        $result = CourierBooking::book($order, 'steadfast', $this->admin(), markShipped: true);

        $this->assertTrue($result['ok'], $result['message']);

        Http::assertSent(function (Request $request) use ($order): bool {
            $body = $request->data();

            return $request->url() === 'https://'.self::STEADFAST.'/create_order'
                && $request->hasHeader('Api-Key', 'sf-api-key-AAAA1111')
                && $request->hasHeader('Secret-Key', 'sf-secret-key-BBBB2222')
                && $body['invoice'] === (string) $order->id
                && $body['cod_amount'] === 1580
                && $body['recipient_phone'] === '01712345678'
                // { } ; < > $ are gone, and the text is cut to Steadfast's limits.
                && $body['recipient_name'] === 'Nusrat Jahan b'
                && mb_strlen($body['note']) <= 480
                && ! array_key_exists('recipient_email', $body);
        });

        $order->refresh();
        $this->assertSame('steadfast', $order->courier);
        $this->assertSame('Steadfast', $order->courier_name);
        $this->assertSame('1001', $order->courier_consignment_id);
        $this->assertSame('TRK1001', $order->tracking_number);
        $this->assertSame('TRK1001', $order->courier_tracking_code);
        $this->assertSame(1580, $order->cod_amount_sent);
        $this->assertNotNull($order->courier_booked_at);
        $this->assertSame('shipped', $order->status);
        $this->assertNull($order->courier_last_error);

        // The audit trail: who, what, never the recipient's phone or address.
        $event = CourierEvent::query()->where('order_id', $order->id)->where('event_type', 'booked')->firstOrFail();
        $this->assertSame('booking', $event->source);
        $this->assertStringContainsString('consignment 1001', $event->summary);
        $this->assertStringNotContainsString('01712345678', json_encode($event->toArray()));
        $this->assertStringNotContainsString('Road 5', json_encode($event->toArray()));

        // The shipped email goes out once, through the existing placeholders.
        $this->app->terminate();
        Mail::assertSent(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->templateKey === 'order_shipped');
        $this->assertSame(1, Mail::sent(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->templateKey === 'order_shipped')->count());
    }

    public function test_a_second_click_shows_the_booking_and_sends_nothing(): void
    {
        $this->steadfast();
        $order = $this->codOrder();
        Http::fake([self::STEADFAST.'/create_order' => Http::response($this->steadfastBooked((string) $order->id))]);

        $this->assertTrue(CourierBooking::book($order, 'steadfast', $this->admin())['ok']);
        $again = CourierBooking::book($order->fresh(), 'steadfast', $this->admin());

        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('Already booked', $again['message']);
        $this->assertStringContainsString('1001', $again['message']);
        Http::assertSentCount(1);
    }

    public function test_a_booking_already_under_way_is_not_started_twice(): void
    {
        $this->steadfast();
        Http::fake();
        $order = $this->codOrder();
        Order::query()->whereKey($order->id)->update(['courier_booking_started_at' => now()]);

        $result = CourierBooking::book($order, 'steadfast', $this->admin());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('being booked', $result['message']);
        Http::assertNothingSent();

        // An abandoned claim (older than three minutes) can be taken.
        Order::query()->whereKey($order->id)->update(['courier_booking_started_at' => now()->subMinutes(5)]);
        $this->fakeHttp([self::STEADFAST.'/create_order' => Http::response($this->steadfastBooked((string) $order->id))]);
        $this->assertTrue(CourierBooking::book($order->fresh(), 'steadfast', $this->admin())['ok']);
    }

    public function test_a_200_that_hides_a_refusal_is_a_failure_that_keeps_the_order_unbooked_and_can_be_retried(): void
    {
        $this->steadfast();
        $order = $this->codOrder();
        Http::fake([self::STEADFAST.'/create_order' => Http::response(['status' => 400, 'errors' => ['recipient_phone' => ['The recipient phone must be 11 digits.']]], 200)]);

        $result = CourierBooking::book($order, 'steadfast', $this->admin(), markShipped: true);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('must be 11 digits', $result['message']);

        $order->refresh();
        $this->assertNull($order->courier_booked_at);
        $this->assertNull($order->courier_consignment_id);
        $this->assertSame('processing', $order->status, 'nothing half-saved: the status did not move');
        $this->assertStringContainsString('must be 11 digits', $order->courier_last_error);
        $this->assertNull($order->courier_booking_started_at, 'the claim is released');
        $this->assertSame('booking_failed', CourierEvent::query()->where('order_id', $order->id)->value('event_type'));

        // Retry works, and clears the error.
        $this->fakeHttp([self::STEADFAST.'/create_order' => Http::response($this->steadfastBooked((string) $order->id))]);
        $this->assertTrue(CourierBooking::book($order->fresh(), 'steadfast', $this->admin())['ok']);
        $this->assertNull($order->fresh()->courier_last_error);
    }

    public function test_a_lost_connection_is_retried_once_and_a_duplicate_invoice_then_means_the_first_try_got_through(): void
    {
        $this->steadfast();
        $order = $this->codOrder();
        $calls = 0;

        Http::fake(function (Request $request) use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['status' => 400, 'errors' => ['invoice' => ['The invoice has already been taken.']]], 200);
        });

        $result = CourierBooking::book($order, 'steadfast', $this->admin());

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(2, $calls, 'one retry, no more');

        $order->refresh();
        $this->assertNotNull($order->courier_booked_at, 'booked: Steadfast already had the invoice');
        $this->assertNull($order->courier_consignment_id);
        $this->assertStringContainsString('consignment id never reached us', $order->courier_status_text);
        // It is followed by invoice, so it can still be checked.
        $this->assertSame((string) $order->id, $order->courier_invoice);
    }

    public function test_two_lost_connections_fail_cleanly_and_book_nothing(): void
    {
        $this->steadfast();
        $order = $this->codOrder();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $result = CourierBooking::book($order, 'steadfast', $this->admin());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Could not reach Steadfast', $result['message']);
        $this->assertNull($order->fresh()->courier_booked_at);
    }

    public function test_a_refused_key_is_never_retried_and_stops_everything_until_the_keys_are_saved_again(): void
    {
        $this->steadfast();
        $first = $this->codOrder();
        $second = $this->codOrder();
        Http::fake([self::STEADFAST.'/create_order' => Http::response(['message' => 'Unauthorized'], 401)]);

        $result = CourierBooking::book($first, 'steadfast', $this->admin());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('refused the keys', $result['message']);
        Http::assertSentCount(1);
        $this->assertNotNull(CourierSetting::current()->steadfast_auth_failed_at);

        // The next booking does not even knock.
        $this->assertFalse(CourierBooking::book($second, 'steadfast', $this->admin())['ok']);
        Http::assertSentCount(1);
    }

    public function test_a_server_error_is_reported_as_the_courier_being_down_and_nothing_is_saved(): void
    {
        $this->steadfast();
        $order = $this->codOrder();
        Http::fake([self::STEADFAST.'/create_order' => Http::response('Bad gateway', 502)]);

        $result = CourierBooking::book($order, 'steadfast', $this->admin());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('busy or down', $result['message']);
        $this->assertNull($order->fresh()->courier_booked_at);
    }

    public function test_an_error_message_never_carries_a_key_even_if_the_courier_echoes_it_back(): void
    {
        $this->steadfast();
        $order = $this->codOrder();
        Http::fake([self::STEADFAST.'/create_order' => Http::response(['message' => 'Bad key sf-secret-key-BBBB2222 for sf-api-key-AAAA1111'], 400)]);

        $result = CourierBooking::book($order, 'steadfast', $this->admin());

        foreach ([$result['message'], $order->fresh()->courier_last_error, json_encode(CourierEvent::all()->toArray())] as $text) {
            $this->assertStringNotContainsString('sf-secret-key-BBBB2222', $text);
            $this->assertStringNotContainsString('sf-api-key-AAAA1111', $text);
        }
    }

    public function test_text_is_cleaned_and_cut_before_it_is_sent(): void
    {
        $this->assertSame('a b c d e f', CourierText::clean('a{b}c;d<e>f$', 100));
        $this->assertSame('one two', CourierText::clean("  one \n\t two  ", 100));
        $this->assertSame(100, mb_strlen(CourierText::clean(str_repeat('é', 300), 100)));
    }

    // ---- Pathao

    public function test_pathao_logs_in_once_books_with_the_address_alone_and_sends_no_null(): void
    {
        $this->pathao();
        $order = $this->codOrder(['gift_note' => null]);
        Http::fake([
            self::PATHAO.'/issue-token' => Http::response(['token_type' => 'Bearer', 'expires_in' => 432000, 'access_token' => 'tok-ACCESS-123456', 'refresh_token' => 'tok-REFRESH-123456']),
            self::PATHAO.'/orders' => Http::response(['type' => 'success', 'code' => 200, 'data' => ['consignment_id' => 'DL240101ABCD', 'order_status' => 'Pending']]),
        ]);

        $result = CourierBooking::book($order, 'pathao', $this->admin());

        $this->assertTrue($result['ok'], $result['message']);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issue-token')
            && $request['grant_type'] === 'password' && $request['username'] === 'merchant@example.com' && $request['client_id'] === 'pa-client-id-CCCC3333');

        Http::assertSent(function (Request $request) use ($order): bool {
            if (! str_ends_with($request->url(), '/aladdin/api/v1/orders')) {
                return false;
            }
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer tok-ACCESS-123456')
                && $body['store_id'] === 777
                && $body['merchant_order_id'] === (string) $order->id
                && $body['recipient_phone'] === '01712345678'
                // The address alone, as "address, thana, district": Pathao finds the city, zone and area itself.
                && $body['recipient_address'] === 'House 12, Road 5, Banani, Dhaka'
                && $body['delivery_type'] === 48 && $body['item_type'] === 2
                && $body['item_weight'] === 0.5 && $body['item_quantity'] === 1
                && $body['amount_to_collect'] === 1580
                && ! array_key_exists('recipient_city', $body) && ! array_key_exists('recipient_zone', $body) && ! array_key_exists('recipient_area', $body)
                && ! in_array(null, $body, true);
        });

        $order->refresh();
        $this->assertSame('pathao', $order->courier);
        $this->assertSame('DL240101ABCD', $order->courier_consignment_id);
        $this->assertSame('DL240101ABCD', $order->tracking_number);
        $this->assertSame('Pathao', $order->courier_name);

        // The login is kept, encrypted at rest.
        $raw = DB::table('courier_settings')->value('pathao_access_token');
        $this->assertNotSame('tok-ACCESS-123456', $raw);
        $this->assertSame('tok-ACCESS-123456', CourierSetting::current()->pathao_access_token);
        $this->assertTrue(CourierSetting::current()->pathao_token_expires_at->gt(now()->addDays(4)));

        // The next booking reuses the token.
        $second = $this->codOrder();
        $this->assertTrue(CourierBooking::book($second, 'pathao', $this->admin())['ok']);
        Http::assertSentCount(3);
    }

    public function test_pathao_live_and_sandbox_use_their_own_hosts(): void
    {
        $this->pathao(['pathao_sandbox' => false]);
        Http::fake([
            'api-hermes.pathao.com/aladdin/api/v1/issue-token' => Http::response(['access_token' => 't', 'refresh_token' => 'r', 'expires_in' => 432000]),
            'api-hermes.pathao.com/aladdin/api/v1/orders' => Http::response(['data' => ['consignment_id' => 'LIVE1']]),
        ]);

        $this->assertTrue(CourierBooking::book($this->codOrder(), 'pathao', $this->admin())['ok']);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api-hermes.pathao.com/'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sandbox'));
    }

    public function test_a_pathao_refusal_is_reported_and_keeps_the_order_unbooked(): void
    {
        $this->pathao();
        $order = $this->codOrder();
        Http::fake([
            self::PATHAO.'/issue-token' => Http::response(['access_token' => 't', 'refresh_token' => 'r', 'expires_in' => 432000]),
            self::PATHAO.'/orders' => Http::response(['message' => 'Validation failed', 'errors' => ['recipient_address' => ['The recipient address must be at least 10 characters.']]], 422),
        ]);

        $result = CourierBooking::book($order, 'pathao', $this->admin());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('at least 10 characters', $result['message']);
        $this->assertNull($order->fresh()->courier_booked_at);
    }

    public function test_pathao_is_not_retried_on_a_lost_connection_because_a_repeat_could_book_twice(): void
    {
        $this->pathao();
        $order = $this->codOrder();
        $orderCalls = 0;
        Http::fake(function (Request $request) use (&$orderCalls) {
            if (str_ends_with($request->url(), '/issue-token')) {
                return Http::response(['access_token' => 't', 'refresh_token' => 'r', 'expires_in' => 432000]);
            }
            $orderCalls++;

            throw new ConnectionException('timed out');
        });

        $result = CourierBooking::book($order, 'pathao', $this->admin());

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $orderCalls);
        $this->assertStringContainsString('Check the Pathao panel before booking again', $result['message']);
    }

    public function test_a_refused_pathao_login_stops_pathao_and_never_sends_null_credentials(): void
    {
        $this->pathao();
        Http::fake([self::PATHAO.'/issue-token' => Http::response(['message' => 'invalid_client'], 401)]);

        $first = CourierBooking::book($this->codOrder(), 'pathao', $this->admin());
        $second = CourierBooking::book($this->codOrder(), 'pathao', $this->admin());

        $this->assertFalse($first['ok']);
        $this->assertStringContainsString('refused the login', $first['message']);
        $this->assertFalse($second['ok']);
        Http::assertSentCount(1);
        $this->assertNotNull(CourierSetting::current()->pathao_auth_failed_at);
    }

    // ---- bulk, permission, automatic

    public function test_a_bulk_booking_gives_one_line_per_order_and_one_refusal_does_not_spoil_the_rest(): void
    {
        $this->steadfast();
        $good = $this->codOrder();
        $unverified = $this->codOrder(['payment_method' => 'bkash', 'payment_status' => 'awaiting_verification']);
        $good2 = $this->codOrder();
        Http::fake(fn (Request $request) => Http::response($this->steadfastBooked($request['invoice'], (string) (2000 + (int) $request['invoice']), 'T'.$request['invoice'])));

        $results = CourierBooking::bulk(Order::query()->whereKey([$good->id, $unverified->id, $good2->id])->orderBy('id')->get(), 'steadfast', $this->admin());

        $this->assertCount(3, $results);
        $this->assertSame([true, false, true], array_column($results, 'ok'));
        $this->assertStringContainsString('not been verified', $results[1]['message']);
        Http::assertSentCount(2);
        $this->assertNull($unverified->fresh()->courier_booked_at);
        $this->assertNotNull($good->fresh()->courier_booked_at);
    }

    public function test_a_bulk_booking_takes_fifty_at_most(): void
    {
        $this->steadfast();
        Http::fake(fn (Request $request) => Http::response($this->steadfastBooked($request['invoice'], (string) (3000 + (int) $request['invoice']))));

        $orders = collect(range(1, 52))->map(fn () => $this->codOrder());
        $results = CourierBooking::bulk($orders, 'steadfast', $this->admin());

        $this->assertCount(52, $results);
        $this->assertSame(50, count(array_filter($results, fn (array $line): bool => $line['ok'])));
        $this->assertStringContainsString('at most 50', $results[51]['message']);
    }

    public function test_only_someone_with_the_courier_permission_can_book(): void
    {
        $this->steadfast();
        $order = $this->codOrder();
        Http::fake();

        $plain = User::factory()->create(['role_id' => AdminRecords::role(['permissions' => ['orders.manage']])->id]);
        $result = CourierBooking::book($order, 'steadfast', $plain);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('permission', $result['message']);
        Http::assertNothingSent();

        $this->fakeHttp([self::STEADFAST.'/create_order' => Http::response($this->steadfastBooked((string) $order->id))]);
        $allowed = User::factory()->create(['role_id' => AdminRecords::role(['permissions' => ['orders.send_to_courier']])->id]);
        $this->assertTrue(CourierBooking::book($order, 'steadfast', $allowed)['ok']);
    }

    public function test_automatic_booking_is_off_by_default_and_never_books_a_risky_or_unready_order(): void
    {
        $this->steadfast();
        Http::fake(fn (Request $request) => Http::response($this->steadfastBooked($request['invoice'])));

        // Off: reaching Processing books nothing.
        $off = $this->codOrder(['status' => 'pending']);
        $off->update(['status' => 'processing']);
        $this->afterResponse();
        $this->assertNull($off->fresh()->courier_booked_at);
        Http::assertNothingSent();

        CourierSetting::current()->update(['auto_book' => true]);

        // On: a clean cash on delivery order is booked after the response.
        $clean = $this->codOrder(['status' => 'pending']);
        $clean->update(['status' => 'processing']);
        $this->afterResponse();
        $this->assertNotNull($clean->fresh()->courier_booked_at);
        $this->assertSame('processing', $clean->fresh()->status, 'automatic booking does not set Shipped');
        $this->assertSame('automatic', CourierEvent::query()->where('order_id', $clean->id)->value('payload')['source']);

        // A blocked buyer, an unverified wallet, a missing address: each waits for a person.
        BlockedContact::create(['kind' => 'phone', 'value' => '01799999999']);
        $risky = $this->codOrder(['status' => 'pending', 'ship_phone' => '01799999999']);
        $wallet = $this->codOrder(['status' => 'pending', 'payment_method' => 'bkash', 'payment_status' => 'awaiting_verification']);
        $noAddress = $this->codOrder(['status' => 'pending', 'ship_address' => '']);
        $noAddress->customer->update(['address' => null]);

        foreach ([$risky, $wallet, $noAddress] as $order) {
            $order->update(['status' => 'processing']);
        }
        $this->afterResponse();

        foreach ([$risky, $wallet, $noAddress] as $order) {
            $this->assertNull($order->fresh()->courier_booked_at, 'order #'.$order->id);
        }
        Http::assertSentCount(1);
    }

    public function test_the_settings_never_expose_a_secret(): void
    {
        $settings = $this->steadfast(['steadfast_webhook_token' => 'whk-token-ABCDEF123456']);
        $this->pathao();
        $settings = CourierSetting::current();

        $dump = json_encode($settings->toArray()).json_encode($settings->toJson());

        foreach (['sf-api-key-AAAA1111', 'sf-secret-key-BBBB2222', 'whk-token-ABCDEF123456', 'pa-client-secret-DDDD4444', 'pa-password-EEEE5555', 'merchant@example.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }

        // At rest they are encrypted.
        $row = (array) DB::table('courier_settings')->first();
        foreach (CourierSetting::SECRETS as $field) {
            $this->assertNotSame(CourierSetting::current()->{$field}, $row[$field], $field);
        }
    }
}
