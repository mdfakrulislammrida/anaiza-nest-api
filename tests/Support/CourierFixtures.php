<?php

namespace Tests\Support;

use App\Models\CourierSetting;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Settings, orders and courier answers for the courier tests. Every courier answer here is a fake: no test calls a real courier.
 */
trait CourierFixtures
{
    protected const STEADFAST = 'portal.packzy.com/api/v1';

    protected const PATHAO = 'courier-api-sandbox.pathao.com/aladdin/api/v1';

    protected function steadfast(array $overrides = []): CourierSetting
    {
        return tap(CourierSetting::current())->update($overrides + [
            'steadfast_enabled' => true,
            'steadfast_api_key' => 'sf-api-key-AAAA1111',
            'steadfast_secret_key' => 'sf-secret-key-BBBB2222',
            'default_courier' => 'steadfast',
        ]);
    }

    protected function pathao(array $overrides = []): CourierSetting
    {
        return tap(CourierSetting::current())->update($overrides + [
            'pathao_enabled' => true,
            'pathao_sandbox' => true,
            'pathao_client_id' => 'pa-client-id-CCCC3333',
            'pathao_client_secret' => 'pa-client-secret-DDDD4444',
            'pathao_username' => 'merchant@example.com',
            'pathao_password' => 'pa-password-EEEE5555',
            'pathao_store_id' => '777',
        ]);
    }

    protected function shop(): SiteSetting
    {
        return SiteSetting::query()->first() ?? SiteSetting::create(['site_name' => 'Anaiza Nest', 'alert_email' => 'owner@example.com', 'contact_email' => 'owner@example.com']);
    }

    /** A cash on delivery order a courier can take. */
    protected function codOrder(array $overrides = []): Order
    {
        $customer = AdminRecords::customer(['phone' => '01712345678', 'name' => 'Nusrat Jahan', 'address' => 'House 12, Road 5']);

        return AdminRecords::order($overrides + [
            'customer_id' => $customer->id,
            'status' => 'processing',
            'payment_status' => 'cod',
            'ship_name' => 'Nusrat Jahan',
            'ship_phone' => '01712345678',
            'ship_address' => 'House 12, Road 5',
            'ship_thana' => 'Banani',
            'ship_district' => 'Dhaka',
            'ship_division' => 'Dhaka',
        ]);
    }

    /** Runs what was queued "after the response" and forgets it, so a test with several steps runs each piece once. */
    protected function afterResponse(): void
    {
        $this->app->terminate();
        (new \ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);
    }

    /** A fresh set of fake courier answers (Http::fake() alone adds to the earlier ones, and the first one wins). */
    protected function fakeHttp(array|callable $answers = []): void
    {
        Http::swap(new Factory);
        Http::fake($answers);
    }

    protected function admin(): User
    {
        return User::factory()->create(['role_id' => null]);
    }

    /** @return array<string, mixed> */
    protected function steadfastBooked(string $invoice, string $consignment = '1001', string $code = 'TRK1001', string $status = 'in_review'): array
    {
        return [
            'status' => 200,
            'message' => 'Consignment has been created successfully.',
            'consignment' => ['consignment_id' => (int) $consignment, 'invoice' => $invoice, 'tracking_code' => $code, 'status' => $status],
        ];
    }

    /** Marks an order as booked with a courier, as a booking would have left it. */
    protected function booked(Order $order, string $courier = 'steadfast', array $overrides = []): Order
    {
        $order->forceFill($overrides + [
            'courier' => $courier,
            'courier_name' => ucfirst($courier),
            'courier_invoice' => (string) $order->id,
            'courier_consignment_id' => '1001',
            'courier_tracking_code' => 'TRK1001',
            'tracking_number' => 'TRK1001',
            'courier_booked_at' => now()->subHours(3),
            'cod_amount_sent' => (int) $order->total,
            'courier_status' => 'in_review',
            'courier_status_text' => 'In review',
            'courier_status_rank' => 20,
            'courier_next_sync_at' => now()->subMinute(),
        ])->saveQuietly();

        return $order->fresh();
    }
}
