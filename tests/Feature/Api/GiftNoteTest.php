<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminRecords;
use Tests\TestCase;

class GiftNoteTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $category = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 1450,
            'stock_quantity' => 10,
            'sku' => 'TP-001',
            'is_active' => true,
        ]);
    }

    private function payload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Fakrul Islam',
            'customer_phone' => '01710000000',
            'customer_address' => 'House 12, Road 5',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            'thana' => 'Banani',
            'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $overrides);
    }

    public function test_a_gift_order_stores_and_returns_the_message(): void
    {
        $response = $this->postJson('/api/orders', $this->payload($this->product(), [
            'is_gift' => true,
            'gift_message' => "  Happy Eid, Ammu.\nWith love  ",
        ]))->assertCreated();

        $response->assertJsonPath('data.is_gift', true)->assertJsonPath('data.gift_message', "Happy Eid, Ammu.\nWith love");
        $this->assertTrue(Order::sole()->is_gift);
    }

    public function test_a_bangla_message_of_exactly_200_code_points_is_accepted_and_201_is_not(): void
    {
        $product = $this->product();

        $this->postJson('/api/orders', $this->payload($product, ['is_gift' => true, 'gift_message' => str_repeat('শুভ', 66).'শু']))
            ->assertCreated();

        $this->postJson('/api/orders', $this->payload($product, ['is_gift' => true, 'gift_message' => str_repeat('শু', 100).'ভ']))
            ->assertStatus(422)->assertJsonValidationErrors(['gift_message']);
    }

    public function test_a_gift_without_a_message_is_fine(): void
    {
        $this->postJson('/api/orders', $this->payload($this->product(), ['is_gift' => true]))
            ->assertCreated()
            ->assertJsonPath('data.is_gift', true)
            ->assertJsonPath('data.gift_message', null);
    }

    public function test_a_message_is_dropped_when_the_gift_box_is_not_ticked(): void
    {
        $this->postJson('/api/orders', $this->payload($this->product(), ['is_gift' => false, 'gift_message' => 'Should not be kept']))
            ->assertCreated()
            ->assertJsonPath('data.is_gift', false)
            ->assertJsonPath('data.gift_message', null);
    }

    public function test_a_gift_never_changes_the_price(): void
    {
        $product = $this->product();

        $plain = $this->postJson('/api/orders', $this->payload($product))->assertCreated()->json('data');
        $gift = $this->postJson('/api/orders', $this->payload($product, ['is_gift' => true, 'gift_message' => 'Hello']))->assertCreated()->json('data');

        $this->assertSame([$plain['subtotal'], $plain['delivery_fee'], $plain['total']], [$gift['subtotal'], $gift['delivery_fee'], $gift['total']]);
    }

    public function test_the_order_notes_field_still_works_beside_the_gift_note(): void
    {
        $this->postJson('/api/orders', $this->payload($this->product(), ['gift_note' => 'Ring the bell twice', 'is_gift' => true, 'gift_message' => 'Hi']))
            ->assertCreated();

        $order = Order::sole();
        $this->assertSame('Ring the bell twice', $order->gift_note);
        $this->assertSame('Hi', $order->gift_message);
    }

    private function giftOrder(array $attributes = []): Order
    {
        $customer = AdminRecords::customer();

        return Order::create(array_merge([
            'customer_id' => $customer->id,
            'status' => 'pending',
            'subtotal' => 1450,
            'delivery_fee' => 80,
            'total' => 1530,
            'payment_method' => 'cod',
            'is_gift' => true,
            'gift_message' => 'Happy Eid, Ammu.',
        ], $attributes));
    }

    public function test_the_print_view_shows_the_message_and_sender_and_never_a_price(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $order = $this->giftOrder();

        $response = $this->get("/admin/orders/{$order->id}/gift-note")->assertOk();

        $response->assertSee('Happy Eid, Ammu.')->assertSee('From Fakrul Islam');
        foreach (['1450', '1,450', '1530', '1,530', '৳', 'Total', 'Subtotal'] as $forbidden) {
            $response->assertDontSee($forbidden, false);
        }
        $response->assertSee('size: A6', false);
    }

    public function test_the_print_view_escapes_the_message(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $order = $this->giftOrder(['gift_message' => '<script>alert(1)</script>']);

        $this->get("/admin/orders/{$order->id}/gift-note")->assertOk()->assertDontSee('<script>alert(1)', false);
    }

    public function test_the_print_view_needs_a_signed_in_admin_with_the_orders_permission(): void
    {
        $order = $this->giftOrder();

        $this->get("/admin/orders/{$order->id}/gift-note")->assertRedirect();

        $role = Role::create(['name' => 'Support', 'slug' => 'support', 'permissions' => ['products.manage']]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);
        $this->get("/admin/orders/{$order->id}/gift-note")->assertForbidden();

        $role->update(['permissions' => ['orders.manage']]);
        $this->actingAs($user->fresh());
        $this->get("/admin/orders/{$order->id}/gift-note")->assertOk();
    }

    public function test_an_order_that_is_not_a_gift_has_no_gift_note(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $order = $this->giftOrder(['is_gift' => false, 'gift_message' => null]);

        $this->get("/admin/orders/{$order->id}/gift-note")->assertNotFound();
    }
}
