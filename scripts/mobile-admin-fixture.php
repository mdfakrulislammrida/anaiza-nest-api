<?php

/*
 * Fills a THROWAWAY local database with made-up shop data, so the admin can be looked at on a phone-sized screen (scripts/mobile-admin-check.mjs).
 *
 * It refuses to run unless all of these hold, so it can never touch real data:
 *   - MOBILE_ADMIN_FIXTURE=1 is set,
 *   - the database is SQLite and the file is not the project's own database/database.sqlite,
 *   - the app is not in production,
 *   - the database is empty (no users, no orders).
 *
 * Nothing here is real: names, phones and addresses are invented, no courier or mail is called (MAIL_MAILER must be "array" or "log").
 *
 *   DB_CONNECTION=sqlite DB_DATABASE=/path/scratch.sqlite php artisan migrate --force
 *   MOBILE_ADMIN_FIXTURE=1 DB_CONNECTION=sqlite DB_DATABASE=/path/scratch.sqlite MAIL_MAILER=array php scripts/mobile-admin-fixture.php
 */

use App\Models\AbandonedCheckout;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CourierEvent;
use App\Models\CourierSetting;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\ShippingZone;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$refuse = function (string $why): never {
    fwrite(STDERR, "Refusing to fill this database: {$why}\n");
    exit(1);
};

if (getenv('MOBILE_ADMIN_FIXTURE') !== '1') {
    $refuse('MOBILE_ADMIN_FIXTURE=1 is not set.');
}
if (app()->environment('production')) {
    $refuse('the app is in production.');
}
if (config('database.default') !== 'sqlite') {
    $refuse('the database is not SQLite.');
}

$file = realpath((string) config('database.connections.sqlite.database')) ?: '';
if ($file === '' || $file === realpath(__DIR__.'/../database/database.sqlite')) {
    $refuse('that is the project\'s own database (or the file is missing).');
}
if (! in_array(config('mail.default'), ['array', 'log'], true)) {
    $refuse('MAIL_MAILER must be "array" or "log".');
}
if (User::query()->count() > 0 || Order::query()->count() > 0) {
    $refuse('the database is not empty.');
}

// ---- the admin used for the check (a made-up login for this scratch database only)
User::create(['name' => 'Phone Check', 'email' => 'phone-check@example.test', 'password' => 'phone-check-only-1', 'role_id' => null]);

// ---- pictures: tiny real PNGs on the public disk
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
foreach (range(1, 6) as $n) {
    Storage::disk('public')->put("products/fixture-{$n}.png", $png);
}

// ---- catalogue
$tea = Category::create(['name' => 'Tea sets', 'slug' => 'tea-sets']);
$cups = Category::create(['name' => 'Cups and mugs', 'slug' => 'cups-and-mugs']);
$products = [];
foreach ([
    ['Blue and white gongfu tea set for 8, with gaiwan, in a fitted case with a long name that keeps going', $tea, 12500, 4, 'TS-001'],
    ['Rose cup and saucer', $cups, 1450, 18, 'CP-001'],
    ['Morris ceramic coffee cup and saucer set', $cups, 2150, 0, 'CP-002'],
    ['Tea jar, small', $tea, 890, 25, 'TJ-001'],
    ['Travel tea set in a fitted case', $tea, 2320, 7, 'TS-002'],
    ['Coaster set of 6 with holder', $cups, 650, 40, 'CO-001'],
] as $i => [$name, $category, $price, $stock, $sku]) {
    $product = Product::create(['category_id' => $category->id, 'name' => $name, 'slug' => str($name)->slug()->limit(60, '')->toString(), 'price' => $price, 'stock_quantity' => $stock, 'sku' => $sku, 'is_active' => $i !== 5, 'is_featured' => $i < 2]);
    ProductImage::create(['product_id' => $product->id, 'url' => 'products/fixture-'.($i + 1).'.png', 'url_400' => 'products/fixture-'.($i + 1).'.png', 'sort_order' => 0]);
    $products[] = $product;
}
$variant = ProductVariant::create(['product_id' => $products[1]->id, 'name' => 'Colour', 'value' => 'Rose pink', 'price' => 1450, 'stock_quantity' => 9, 'sku' => 'CP-001-R']);

ShippingZone::create(['name' => 'Inside Dhaka', 'delivery_fee' => 80, 'estimated_days' => '1-2 days']);
ShippingZone::create(['name' => 'Outside Dhaka', 'delivery_fee' => 130, 'estimated_days' => '2-4 days']);
ShippingZone::create(['name' => 'Remote areas with a long zone name to see how it wraps on a small screen', 'delivery_fee' => 180, 'estimated_days' => '4-7 days']);

Coupon::create(['code' => 'EID10', 'discount_type' => 'percent', 'amount' => 10, 'is_active' => true, 'min_order' => 1000, 'valid_until' => now()->addMonth()]);
Coupon::create(['code' => 'WELCOME100', 'discount_type' => 'fixed', 'amount' => 100, 'is_active' => true, 'single_use' => true]);
Coupon::create(['code' => 'OLD50', 'discount_type' => 'fixed', 'amount' => 50, 'is_active' => false, 'valid_until' => now()->subMonth()]);

// ---- customers and orders
$names = ['Nusrat Jahan', 'Md. Abdullah Al Mamun Chowdhury', 'Tasnim Rahman', 'Fahim Ahmed', 'Sadia Islam'];
$customers = [];
foreach ($names as $i => $name) {
    $customers[] = Customer::create(['name' => $name, 'email' => 'buyer'.$i.'@example.test', 'password' => 'not-a-real-password-'.$i, 'phone' => '0171234567'.$i, 'address' => 'House 12, Road 5', 'city' => 'Dhaka', 'postal_code' => '1212']);
}

$long = 'Flat 4B, House 12/A, Road 5, Block C, Bashundhara Residential Area, near the big mosque and the pharmacy, behind the school gate, Dhaka 1229';
$setups = [
    ['pending', 'cod', 'cod', null],
    ['processing', 'bkash', 'awaiting_verification', null],
    ['processing', 'cod', 'cod', null],
    ['shipped', 'cod', 'cod', 'steadfast'],
    ['shipped', 'cod', 'cod', 'pathao'],
    ['delivered', 'cod', 'cod', 'steadfast'],
    ['delivered', 'nagad', 'verified', null],
    ['cancelled', 'cod', 'cod', null],
];

foreach ($setups as $i => [$status, $method, $payment, $courier]) {
    $customer = $customers[$i % count($customers)];
    $order = Order::create([
        'customer_id' => $customer->id, 'status' => $status, 'subtotal' => 3900, 'delivery_fee' => 80, 'total' => 3980, 'payment_method' => $method, 'payment_status' => $payment,
        'ship_name' => $customer->name, 'ship_phone' => $customer->phone, 'ship_address' => $long, 'ship_division' => 'Dhaka', 'ship_district' => 'Dhaka', 'ship_thana' => 'Banani',
        'ship_email' => $customer->email, 'is_gift' => $i % 3 === 0, 'gift_message' => $i % 3 === 0 ? 'Happy birthday, with all our love and a very long wish that goes on for several lines so the wrapping can be seen.' : null,
        'payment_trx_id' => $method === 'cod' ? null : 'TRX9A8B7C6D5E4F3G2H1I0J'.$i, 'payment_sender_number' => $method === 'cod' ? null : '01712345678',
    ]);
    $order->items()->create(['product_id' => $products[0]->id, 'product_name' => $products[0]->name, 'quantity' => 1, 'price' => 2500, 'sku' => 'TS-001']);
    $order->items()->create(['product_id' => $products[1]->id, 'product_name' => $products[1]->name, 'variant_name' => 'Colour', 'variant_value' => 'Rose pink', 'quantity' => 2, 'price' => 700, 'sku' => 'CP-001-R']);

    if ($courier !== null) {
        $order->forceFill([
            'courier' => $courier, 'courier_name' => ucfirst($courier), 'courier_invoice' => (string) $order->id, 'courier_consignment_id' => '1000000000'.$order->id.'ABCDEFGHIJKLMNOP',
            'courier_tracking_code' => 'TRK-'.$order->id.'-LONGTRACKINGCODE', 'courier_booked_at' => now()->subDays(2), 'courier_status' => 'in_review', 'courier_status_text' => 'In review',
            'courier_status_rank' => 20, 'courier_next_sync_at' => now()->addMinutes(10), 'cod_amount_sent' => 3980,
        ])->saveQuietly();
        CourierEvent::create(['order_id' => $order->id, 'courier' => $courier, 'source' => 'poll', 'event_type' => 'status', 'status' => 'in_review', 'summary' => ucfirst($courier).' reported "in_review" (In review). Then a rather long sentence so the history row has to wrap on a phone.']);
        CourierEvent::create(['order_id' => $order->id, 'courier' => $courier, 'source' => 'admin', 'event_type' => 'booked', 'summary' => 'Sent to '.ucfirst($courier).'.']);
    }
}

// ---- abandoned checkouts, reviews
foreach ([['Rubina Akter', '01811111111', 'cart', 4200], ['Zahid Hasan', '01922222222', 'checkout', 2320], ['Mehjabin', null, 'cart', 890]] as $i => [$name, $phone, $stage, $total]) {
    AbandonedCheckout::create([
        'session_token' => 'fixture-session-'.$i, 'recovery_token' => 'fixture-recovery-'.$i, 'name' => $name, 'phone' => $phone, 'email' => 'abandoned'.$i.'@example.test', 'stage' => $stage,
        'items' => [['product_id' => $products[$i]->id, 'name' => $products[$i]->name, 'quantity' => 1, 'price' => $total]], 'cart_total' => $total,
        'first_activity_at' => now()->subHours(30), 'last_activity_at' => now()->subHours(26 - $i), 'status' => 'open',
    ]);
}
foreach ([['Nusrat J.', 5, 'approved', 'Lovely set, packed with care.'], ['Anonymous buyer', 3, 'pending', 'It came a little late but the cups are beautiful and exactly as shown in the photos, I would order again.'], ['Tasnim', 4, 'approved', 'Good.']] as $i => [$name, $rating, $status, $body]) {
    ProductReview::create(['product_id' => $products[$i]->id, 'name' => $name, 'rating' => $rating, 'title' => 'Review '.($i + 1), 'body' => $body, 'status' => $status, 'verified_purchase' => $i !== 1]);
}

CourierSetting::current();

echo "Filled the scratch database: login phone-check@example.test / phone-check-only-1 (this scratch database only).\n";
