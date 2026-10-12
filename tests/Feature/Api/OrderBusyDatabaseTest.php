<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\QueryException;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Uses real commits and rollbacks (not the wrapping transaction the other tests run in), because what matters here is
 * that a failed attempt leaves no order and no stock taken.
 */
class OrderBusyDatabaseTest extends TestCase
{
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh database that really commits and rolls back: no wrapping transaction, and nothing left behind (the
        // in-memory database goes away with the process).
        $this->artisan('migrate:fresh');

        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'Mugs', 'slug' => 'mugs'])->id,
            'name' => 'Glazed mug', 'slug' => 'glazed-mug', 'price' => 600, 'stock_quantity' => 50, 'sku' => 'MUG', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Leave an empty schema for whatever test class runs next in this process.
        $this->artisan('migrate:fresh');

        parent::tearDown();
    }

    private function order()
    {
        (new ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);

        return $this->postJson('/api/orders', [
            'customer_name' => 'Nusrat Jahan', 'customer_phone' => '01711234567', 'customer_address' => 'House 12, Road 5',
            'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);
    }

    private static function locked(): QueryException
    {
        return new QueryException('sqlite', 'insert into "orders"', [], new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
    }

    public function test_a_briefly_locked_database_is_retried_and_the_order_goes_through_with_stock_taken_once(): void
    {
        $failures = 2;
        Order::creating(function () use (&$failures): void {
            if ($failures-- > 0) {
                throw self::locked();
            }
        });

        $this->order()->assertCreated();

        $this->assertSame(1, Order::count());
        $this->assertSame(49, $this->product->fresh()->stock_quantity, 'two failed attempts took nothing; the third took one');
    }

    public function test_a_database_that_stays_locked_gets_a_calm_message_and_nothing_is_taken(): void
    {
        Order::creating(function (): void {
            throw self::locked();
        });

        $this->order()->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'We are a little busy just now and could not place your order. Nothing has been taken. Please press the button again in a moment.');

        $this->assertSame(0, Order::count());
        $this->assertSame(50, $this->product->fresh()->stock_quantity);
    }
}
