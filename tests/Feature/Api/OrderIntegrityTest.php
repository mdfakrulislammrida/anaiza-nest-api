<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\OrderController;
use App\Mail\OrderConfirmationMail;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Support\CustomerLookup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class OrderIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'Mugs', 'slug' => 'mugs'])->id,
            'name' => 'Glazed mug', 'slug' => 'glazed-mug', 'price' => 600, 'stock_quantity' => 50, 'sku' => 'MUG', 'is_active' => true,
        ]);
    }

    private function forgetTerminating(): void
    {
        (new ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);
    }

    private function order(array $overrides = [])
    {
        $this->forgetTerminating();

        return $this->postJson('/api/orders', array_merge([
            'customer_name' => 'Nusrat Jahan', 'customer_phone' => '01711234567', 'customer_email' => 'nusrat@example.com',
            'customer_address' => 'House 12, Road 5', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ], $overrides));
    }

    // ---- a guest checkout never rewrites an account

    public function test_an_order_with_someone_elses_phone_cannot_change_their_account_email(): void
    {
        $owner = Customer::create(['name' => 'Priya Rahman', 'email' => 'priya@example.com', 'phone' => '01711111111', 'address' => 'Her home', 'password' => 'her-own-password']);

        $this->order(['customer_name' => 'Someone Else', 'customer_phone' => '01711111111', 'customer_email' => 'attacker@example.com', 'customer_address' => 'Their street'])
            ->assertCreated();

        $owner->refresh();
        $this->assertSame('priya@example.com', $owner->email, 'the account email is never changed by a checkout');
        $this->assertSame('Priya Rahman', $owner->name);
        $this->assertSame('Her home', $owner->address);
        $this->assertTrue(Hash::check('her-own-password', $owner->password));

        // So the attacker's address cannot be used to reset her password.
        $this->assertSame([], CustomerLookup::byLogin('attacker@example.com')->pluck('id')->all());
    }

    public function test_the_order_keeps_what_the_buyer_typed(): void
    {
        Customer::create(['name' => 'Priya Rahman', 'email' => 'priya@example.com', 'phone' => '01711111111', 'password' => 'her-own-password']);

        $id = $this->order(['customer_name' => 'Rafi Ahmed', 'customer_phone' => '+880 1711-111111', 'customer_email' => 'RAFI@example.com', 'customer_address' => 'Rafi house', 'thana' => 'Gulshan'])
            ->assertCreated()->json('data.id');

        $order = Order::findOrFail($id);
        $this->assertSame('Rafi Ahmed', $order->ship_name);
        $this->assertSame('01711111111', $order->ship_phone, 'stored as a plain mobile number');
        $this->assertSame('rafi@example.com', $order->ship_email);
        $this->assertSame('Rafi house', $order->ship_address);
        $this->assertSame('Gulshan', $order->ship_thana);
    }

    public function test_a_later_order_from_the_same_phone_does_not_change_an_earlier_one(): void
    {
        $first = $this->order(['customer_address' => 'First address'])->assertCreated()->json('data.id');
        $second = $this->order(['customer_address' => 'SECOND address'])->assertCreated()->json('data.id');

        $this->assertSame(1, Customer::count(), 'the same phone is the same customer');
        $this->assertSame('First address', Order::findOrFail($first)->recipient()['address']);
        $this->assertSame('SECOND address', Order::findOrFail($second)->recipient()['address']);

        $this->postJson('/api/orders/lookup?order_id='.$first.'&phone=01711234567')->assertStatus(405);
        $this->getJson("/api/orders/lookup?order_id={$first}&phone=01711234567")->assertOk()->assertJsonPath('data.customer.address', 'First address');
    }

    // ---- email already used by someone else

    public function test_a_returning_customer_with_a_new_phone_and_a_known_email_can_still_order(): void
    {
        Mail::fake();
        Customer::create(['name' => 'Rafi', 'email' => 'rafi@example.com', 'phone' => '01722222222', 'password' => 'rafi-password-1']);

        $id = $this->order(['customer_name' => 'Rafi', 'customer_phone' => '01733333333', 'customer_email' => 'rafi@example.com'])
            ->assertCreated()->json('data.id');

        $order = Order::findOrFail($id);
        $this->assertSame('rafi@example.com', $order->ship_email, 'the confirmation still goes to what they typed');
        $this->assertNotSame(Customer::where('phone', '01722222222')->value('id'), $order->customer_id, 'the other account is not touched');
        $this->assertNull($order->customer->email, 'the email stays with its first customer');

        Mail::assertSent(OrderConfirmationMail::class, fn (OrderConfirmationMail $mail) => $mail->hasTo('rafi@example.com'));
    }

    public function test_a_clash_is_never_reported_as_a_transaction_id_problem(): void
    {
        $response = $this->order(['customer_phone' => '01733333333', 'customer_email' => 'new@example.com'])->assertCreated();

        $this->assertNotNull($response->json('data.id'));
        $this->order(['customer_phone' => '01744444444', 'customer_email' => 'new@example.com'])->assertCreated();
    }

    public function test_a_wallet_transaction_id_used_twice_is_still_reported_as_such(): void
    {
        PaymentSetting::create(['bkash_number' => '01711000000']);
        $payload = ['payment_method' => 'bkash', 'payment_sender_number' => '01755555555', 'payment_trx_id' => 'ABC123XYZ9'];

        $this->order($payload)->assertCreated();
        $this->order($payload + ['customer_phone' => '01766666666'])->assertStatus(422)->assertJsonValidationErrors('payment_trx_id');
    }

    // ---- phone and size validation

    public function test_the_phone_must_be_a_mobile_number_we_can_call(): void
    {
        $this->order(['customer_phone' => '12345'])->assertStatus(422)
            ->assertJsonPath('errors.customer_phone.0', 'Please enter a Bangladeshi mobile number, like 01712 345678, so our courier can reach you.');
        $this->order(['customer_phone' => 'not a number'])->assertStatus(422);
        $this->order(['customer_phone' => '01211111111'])->assertStatus(422);

        foreach (['01712345678', '+8801712345678', '8801712345678', '01712 345678', '01712-345678'] as $phone) {
            $this->order(['customer_phone' => $phone])->assertCreated();
        }
    }

    public function test_an_order_cannot_carry_an_absurd_number_of_pieces(): void
    {
        $this->order(['items' => [['product_id' => $this->product->id, 'quantity' => 100]]])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
        $this->order(['items' => array_fill(0, 31, ['product_id' => $this->product->id, 'quantity' => 1])])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    // ---- tracking

    public function test_tracking_matches_the_phone_as_a_number(): void
    {
        $id = $this->order(['customer_phone' => '01711234567'])->assertCreated()->json('data.id');

        foreach (['01711234567', '+8801711234567', '+880 1711-234567'] as $phone) {
            $this->getJson('/api/orders/lookup?'.http_build_query(['order_id' => $id, 'phone' => $phone]))->assertOk();
        }

        $this->getJson('/api/orders/lookup?'.http_build_query(['order_id' => $id, 'phone' => '01799999999']))->assertNotFound();
    }

    public function test_an_order_made_before_the_snapshot_still_reads_from_its_customer(): void
    {
        $customer = Customer::create(['name' => 'Old Customer', 'email' => 'old@example.com', 'phone' => '01788888888', 'address' => 'Old address', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Mirpur', 'password' => 'x-old-pass1']);
        $order = Order::create(['customer_id' => $customer->id, 'status' => 'delivered', 'subtotal' => 600, 'delivery_fee' => 80, 'total' => 680, 'payment_method' => 'cod']);

        $this->assertNull($order->ship_name);
        $this->assertSame('Old Customer', $order->recipient()['name']);
        $this->assertSame('Old address, Mirpur, Dhaka, Dhaka', $order->recipientAddressLine());
        $this->getJson("/api/orders/lookup?order_id={$order->id}&phone=01788888888")->assertOk()->assertJsonPath('data.customer.name', 'Old Customer');
    }

    // ---- a busy database

    public function test_only_lock_errors_are_treated_as_busy(): void
    {
        $busy = new ReflectionMethod(OrderController::class, 'isBusy');
        $wrap = fn (string $message) => new QueryException('sqlite', 'select 1', [], new \PDOException($message));

        $this->assertTrue($busy->invoke(null, $wrap('SQLSTATE[HY000]: General error: 5 database is locked')));
        $this->assertTrue($busy->invoke(null, $wrap('SQLSTATE[40001]: Deadlock found when trying to get lock')));
        $this->assertFalse($busy->invoke(null, $wrap('SQLSTATE[HY000]: General error: 1 no such table: orders')));
    }

    public function test_sqlite_is_set_up_to_queue_writers_instead_of_failing_them(): void
    {
        $config = config('database.connections.sqlite');

        $this->assertSame('IMMEDIATE', $config['transaction_mode']);
        $this->assertSame('WAL', $config['journal_mode']);
        $this->assertGreaterThanOrEqual(5000, $config['busy_timeout']);
        $this->assertTrue((bool) $config['foreign_key_constraints']);
    }
}
