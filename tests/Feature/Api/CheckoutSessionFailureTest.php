<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\CheckoutSessionController;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What can make POST /api/checkout-sessions answer something other than 204, and the proof that none of it can get in the way of a checkout.
 * (The investigation behind the report: the application itself can only answer 204, 422 for a malformed body, 429 when throttled, and 503 only
 * when the whole site is in maintenance mode. Any other 503 comes from the host in front of PHP.)
 */
class CheckoutSessionFailureTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Log::listen(function ($event): void {
            $this->logged[] = $event->level.': '.$event->message;
        });
    }

    private function report(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/checkout-sessions', array_merge([
            'session_token' => str_repeat('a', 32), 'stage' => 'contact_filled', 'email' => 'ann@example.test', 'phone' => '01712345678', 'name' => 'Ann',
            'items' => [['product_id' => 1, 'quantity' => 1, 'price' => 600]],
        ], $overrides));
    }

    public function test_a_good_report_answers_204(): void
    {
        $this->report()->assertNoContent();
    }

    public function test_a_database_failure_inside_the_report_still_answers_204_and_is_logged_with_its_reason(): void
    {
        Schema::drop('abandoned_checkouts');

        $this->report()->assertNoContent();

        $this->assertNotEmpty(array_filter($this->logged, fn (string $line) => str_starts_with($line, 'error:')), 'the failure is written to the log');
    }

    public function test_a_database_that_is_busy_is_waited_for_only_briefly(): void
    {
        $this->assertSame(1500, CheckoutSessionController::BUSY_TIMEOUT_MS);
        DB::statement('PRAGMA busy_timeout = 10000');   // what an order may wait

        $this->report()->assertNoContent();

        $this->assertSame(1500, (int) (DB::selectOne('PRAGMA busy_timeout')->timeout ?? 0), 'a held PHP worker is what makes a shared host answer 503');
    }

    public function test_a_malformed_report_is_a_422_not_a_server_error(): void
    {
        $this->report(['stage' => 'nonsense'])->assertStatus(422);
    }

    public function test_too_many_reports_from_one_address_are_a_429_with_a_retry_time_not_a_503(): void
    {
        for ($i = 0; $i < 90; $i++) {
            $this->report(['stage' => 'cart'])->assertNoContent();
        }

        $this->report(['stage' => 'cart'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_the_only_503_the_application_itself_gives_is_maintenance_mode(): void
    {
        Artisan::call('down');

        try {
            $this->report()->assertStatus(503);
            $this->getJson('/api/products')->assertStatus(503);
        } finally {
            Artisan::call('up');
        }

        $this->report()->assertNoContent();
    }

    public function test_a_broken_report_table_never_stops_an_order(): void
    {
        Schema::drop('abandoned_checkouts');
        $product = Product::create(['category_id' => Category::create(['name' => 'Mugs', 'slug' => 'mugs'])->id, 'name' => 'Mug', 'slug' => 'mug', 'price' => 600, 'stock_quantity' => 9, 'sku' => 'M-1', 'is_active' => true]);

        $this->postJson('/api/orders', [
            'customer_name' => 'Nusrat Jahan', 'customer_phone' => '01712345679', 'customer_address' => 'House 1', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani',
            'payment_method' => 'cod', 'checkout_session' => str_repeat('b', 32), 'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
    }
}
