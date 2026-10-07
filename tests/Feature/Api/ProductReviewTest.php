<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Support\ConversionApiHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Customer $customer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);
        $this->product = $this->makeProduct('Porcelain tea set', 'porcelain-tea-set', $category);
        $this->customer = $this->makeCustomer('01712345678');
        $this->order = $this->makeOrder($this->customer, [$this->product]);
        RateLimiter::clear('product-reviews');
    }

    private function makeProduct(string $name, string $slug, ?Category $category = null): Product
    {
        return Product::create([
            'category_id' => ($category ?? Category::first())->id, 'name' => $name, 'slug' => $slug, 'price' => 1450,
            'stock_quantity' => 50, 'sku' => strtoupper($slug), 'is_active' => true,
        ]);
    }

    private function makeCustomer(string $phone): Customer
    {
        return Customer::create([
            'name' => 'Nusrat Jahan', 'email' => 'c'.uniqid().'@example.com', 'password' => 'secret-password', 'phone' => $phone,
            'address' => 'House 1', 'city' => 'Dhaka', 'postal_code' => '1212',
        ]);
    }

    /**
     * @param  list<Product>  $products
     */
    private function makeOrder(Customer $customer, array $products, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'customer_id' => $customer->id, 'status' => 'delivered', 'subtotal' => 1450, 'delivery_fee' => 80, 'total' => 1530, 'payment_method' => 'cod',
        ], $overrides));

        foreach ($products as $product) {
            $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'price' => 1450]);
        }

        return $order;
    }

    private function review(array $attributes = []): ProductReview
    {
        return ProductReview::create(array_merge([
            'product_id' => $this->product->id, 'name' => 'Rafi', 'rating' => 5, 'body' => 'Lovely set, packed beautifully.', 'status' => 'approved',
            'approved_at' => now(),
        ], $attributes));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'rating' => 5, 'title' => 'Lovely', 'body' => 'It arrived beautifully packed and we use it every day.', 'name' => 'Nusrat J.',
            'order_number' => (string) $this->order->id, 'phone' => '01712345678',
        ], $overrides);
    }

    private function submit(array $payload, ?Product $product = null)
    {
        return $this->postJson('/api/products/'.($product ?? $this->product)->slug.'/reviews', $payload);
    }

    // ---- reading

    public function test_with_no_reviews_there_is_no_rating_anywhere(): void
    {
        $this->getJson('/api/products/porcelain-tea-set/reviews')->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('summary.rating_count', 0)
            ->assertJsonPath('summary.rating_average', null);

        $this->getJson('/api/products/porcelain-tea-set')->assertJsonPath('data.rating_count', 0)->assertJsonPath('data.rating_average', null);
        $this->getJson('/api/products')->assertJsonPath('data.0.rating_count', 0)->assertJsonPath('data.0.rating_average', null);
    }

    public function test_only_approved_reviews_are_public_and_the_rating_counts_only_those(): void
    {
        $this->review(['rating' => 5, 'name' => 'A']);
        $this->review(['rating' => 4, 'name' => 'B']);
        $this->review(['rating' => 1, 'name' => 'Pending one', 'status' => 'pending', 'approved_at' => null]);
        $this->review(['rating' => 1, 'name' => 'Rejected one', 'status' => 'rejected', 'approved_at' => null]);

        $index = $this->getJson('/api/products/porcelain-tea-set/reviews')->assertOk();
        $this->assertEqualsCanonicalizing(['A', 'B'], array_column($index->json('data'), 'name'));
        $index->assertJsonPath('summary.rating_count', 2)->assertJsonPath('summary.rating_average', 4.5);

        $this->getJson('/api/products/porcelain-tea-set')->assertJsonPath('data.rating_count', 2)->assertJsonPath('data.rating_average', 4.5);
        $this->getJson('/api/products')->assertJsonPath('data.0.rating_count', 2)->assertJsonPath('data.0.rating_average', 4.5);
    }

    public function test_the_average_is_rounded_to_one_decimal(): void
    {
        foreach ([5, 4, 4] as $rating) {
            $this->review(['rating' => $rating]);
        }

        $this->getJson('/api/products/porcelain-tea-set')->assertJsonPath('data.rating_average', 4.3);
    }

    public function test_reviews_are_paginated_newest_first_and_leak_nothing_private(): void
    {
        foreach (range(1, 5) as $i) {
            $this->review(['name' => "Reviewer {$i}", 'approved_at' => now()->addMinutes($i), 'admin_reply' => $i === 5 ? 'Thank you, we are glad.' : null]);
        }

        $first = $this->getJson('/api/products/porcelain-tea-set/reviews?per_page=2')->assertOk();
        $this->assertSame(['Reviewer 5', 'Reviewer 4'], array_column($first->json('data'), 'name'));
        $first->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 5)->assertJsonPath('data.0.admin_reply', 'Thank you, we are glad.');
        $this->assertSame(['Reviewer 3', 'Reviewer 2'], array_column($this->getJson('/api/products/porcelain-tea-set/reviews?per_page=2&page=2')->json('data'), 'name'));

        $keys = array_keys($first->json('data.0'));
        sort($keys);
        $this->assertSame(['admin_reply', 'body', 'created_at', 'id', 'name', 'photos', 'rating', 'title', 'verified_purchase'], $keys);
    }

    public function test_an_inactive_product_has_no_reviews_page(): void
    {
        $this->product->update(['is_active' => false]);

        $this->getJson('/api/products/porcelain-tea-set/reviews')->assertNotFound();
        $this->submit($this->payload())->assertNotFound();
    }

    // ---- who may write one

    public function test_a_review_from_an_order_number_and_its_phone_is_saved_pending_and_verified(): void
    {
        $this->submit($this->payload())->assertCreated()->assertJsonPath('message', 'Thank you. Your review is with us and will appear once we have read it.');

        $review = ProductReview::sole();
        $this->assertSame('pending', $review->status);
        $this->assertTrue($review->verified_purchase);
        $this->assertSame($this->order->id, $review->order_id);
        $this->assertSame($this->customer->id, $review->customer_id);
        $this->assertSame($this->order->items->first()->id, $review->order_item_id);
        $this->assertSame('Nusrat J.', $review->name);
        $this->getJson('/api/products/porcelain-tea-set/reviews')->assertJsonPath('summary.rating_count', 0);
    }

    public function test_a_wrong_phone_number_is_refused(): void
    {
        $this->submit($this->payload(['phone' => '01799999999']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['order_number']);

        $this->assertSame(0, ProductReview::count());
    }

    public function test_the_phone_may_be_typed_in_any_usual_form(): void
    {
        foreach (['+8801712345678', '8801712345678', '01712-345 678'] as $i => $phone) {
            $order = $this->makeOrder($this->customer, [$this->product]);

            $this->submit($this->payload(['order_number' => (string) $order->id, 'phone' => $phone]))->assertCreated();
        }

        $this->assertSame(3, ProductReview::count());
    }

    public function test_a_wrong_missing_or_foreign_order_number_is_refused(): void
    {
        $this->submit($this->payload(['order_number' => '999999']))->assertStatus(422)->assertJsonValidationErrors(['order_number']);
        $this->submit($this->payload(['order_number' => 'abc']))->assertStatus(422)->assertJsonValidationErrors(['order_number']);
        $this->submit($this->payload(['order_number' => null, 'phone' => null]))->assertStatus(422)->assertJsonValidationErrors(['order_number']);

        $other = $this->makeCustomer('01811111111');
        $theirs = $this->makeOrder($other, [$this->product]);
        $this->submit($this->payload(['order_number' => (string) $theirs->id]))->assertStatus(422);
        $this->assertSame(0, ProductReview::count());
    }

    public function test_the_order_must_contain_the_product_and_must_not_be_cancelled_or_unpaid(): void
    {
        $other = $this->makeProduct('Ceramic mug', 'ceramic-mug');
        $this->submit($this->payload(), $other)->assertStatus(422);

        $cancelled = $this->makeOrder($this->customer, [$this->product], ['status' => 'cancelled']);
        $this->submit($this->payload(['order_number' => (string) $cancelled->id]))->assertStatus(422);

        $failed = $this->makeOrder($this->customer, [$this->product], ['payment_method' => 'bkash', 'payment_status' => 'failed']);
        $this->submit($this->payload(['order_number' => (string) $failed->id]))->assertStatus(422);

        $this->assertSame(0, ProductReview::count());
    }

    public function test_a_signed_in_customer_can_review_a_product_from_their_own_orders(): void
    {
        $token = $this->customer->createToken('storefront')->plainTextToken;

        $this->withToken($token)->postJson('/api/products/porcelain-tea-set/reviews', ['rating' => 4, 'body' => 'Really pleased with it.', 'name' => 'Nusrat'])
            ->assertCreated();

        $review = ProductReview::sole();
        $this->assertSame($this->customer->id, $review->customer_id);
        $this->assertSame($this->order->id, $review->order_id);
    }

    public function test_a_signed_in_customer_cannot_review_something_they_have_not_ordered_or_use_someone_elses_order(): void
    {
        $stranger = $this->makeCustomer('01877777777');
        $token = $stranger->createToken('storefront')->plainTextToken;

        $this->withToken($token)->postJson('/api/products/porcelain-tea-set/reviews', ['rating' => 5, 'body' => 'I never bought this one.', 'name' => 'Stranger'])
            ->assertStatus(422);
        $this->withToken($token)->postJson('/api/products/porcelain-tea-set/reviews', ['rating' => 5, 'body' => 'I never bought this one.', 'name' => 'Stranger', 'order_number' => (string) $this->order->id, 'phone' => '01712345678'])
            ->assertStatus(422);

        $this->assertSame(0, ProductReview::count());
    }

    public function test_there_is_one_review_per_order_line(): void
    {
        $this->submit($this->payload())->assertCreated();
        $this->submit($this->payload())->assertStatus(422)->assertJsonValidationErrors(['rating']);

        // A second line of the same product on the order (another colour, say) can be reviewed once more, and then no more.
        $this->order->items()->create(['product_id' => $this->product->id, 'product_name' => $this->product->name, 'quantity' => 1, 'price' => 1450]);
        $this->submit($this->payload())->assertCreated();
        $this->submit($this->payload())->assertStatus(422);

        $this->assertSame(2, ProductReview::count());
    }

    public function test_attempts_are_rate_limited(): void
    {
        foreach (range(1, 10) as $i) {
            $this->submit($this->payload(['phone' => '01700000000']))->assertStatus(422);
        }

        $this->submit($this->payload())->assertStatus(429);
        $this->assertSame(0, ProductReview::count());
    }

    // ---- what is asked for

    public function test_the_form_is_checked(): void
    {
        $this->submit($this->payload(['rating' => 0]))->assertJsonValidationErrors(['rating']);
        $this->submit($this->payload(['rating' => 6]))->assertJsonValidationErrors(['rating']);
        $this->submit($this->payload(['body' => 'Short']))->assertJsonValidationErrors(['body']);
        $this->submit($this->payload(['name' => '']))->assertJsonValidationErrors(['name']);
        $this->submit($this->payload(['title' => str_repeat('a', 121)]))->assertJsonValidationErrors(['title']);

        $errors = $this->submit($this->payload(['rating' => null]))->json('errors.rating.0');
        $this->assertSame('Please choose a rating from one to five stars.', $errors);
    }

    // ---- photos

    public function test_up_to_three_photos_go_through_the_webp_pipeline(): void
    {
        Storage::fake('public');

        $photos = [
            UploadedFile::fake()->image('one.jpg', 1600, 1200),
            UploadedFile::fake()->image('two.png', 800, 800),
            UploadedFile::fake()->image('three.webp', 300, 300),
        ];

        $this->post('/api/products/porcelain-tea-set/reviews', $this->payload(['photos' => $photos]), ['Accept' => 'application/json'])->assertCreated();

        $review = ProductReview::sole();
        $this->assertCount(3, $review->photos);
        foreach ($review->photos as $photo) {
            $this->assertStringEndsWith('.webp', $photo['url']);
            $this->assertStringEndsWith('-400.webp', $photo['thumb']);
            Storage::disk('public')->assertExists([$photo['url'], $photo['thumb']]);
        }
        $width = getimagesizefromstring(Storage::disk('public')->get($review->photos[0]['url']))[0];
        $this->assertLessThanOrEqual(1000, $width);
        $this->assertSame(6, count(Storage::disk('public')->allFiles('reviews/generated')), 'Only the generated files are kept, never the originals.');
    }

    public function test_a_fourth_photo_or_a_non_image_is_refused(): void
    {
        Storage::fake('public');

        $four = array_map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"), [1, 2, 3, 4]);
        $this->post('/api/products/porcelain-tea-set/reviews', $this->payload(['photos' => $four]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['photos']);

        $this->post('/api/products/porcelain-tea-set/reviews', $this->payload(['photos' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['photos.0']);

        $this->assertSame(0, ProductReview::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_approved_reviews_serve_photo_urls(): void
    {
        Storage::fake('public');
        $this->review(['photos' => [['url' => 'reviews/generated/a.webp', 'thumb' => 'reviews/generated/a-400.webp']]]);

        $photo = $this->getJson('/api/products/porcelain-tea-set/reviews')->json('data.0.photos.0');

        $this->assertStringEndsWith('reviews/generated/a.webp', $photo['url']);
        $this->assertStringEndsWith('reviews/generated/a-400.webp', $photo['thumb']);
    }

    // ---- hashing for Meta advanced matching (the browser applies the same rules)

    public function test_names_cities_and_countries_are_hashed_the_way_the_browser_hashes_them(): void
    {
        $this->assertSame(hash('sha256', 'nusrat'), ConversionApiHasher::fullName(' Nusrat  Jahan ')['first']);
        $this->assertSame(hash('sha256', 'jahan'), ConversionApiHasher::fullName(' Nusrat  Jahan ')['last']);
        $this->assertSame(hash('sha256', 'abdurrahman'), ConversionApiHasher::fullName('Abdur-Rahman Khan')['first']);
        $this->assertNull(ConversionApiHasher::fullName('Madonna')['last']);
        $this->assertSame(hash('sha256', 'coxsbazar'), ConversionApiHasher::text("Cox's Bazar"));
        $this->assertSame(hash('sha256', 'ঢাকা'), ConversionApiHasher::text('ঢাকা'), 'Bangla letters and their vowel signs are kept.');
        $this->assertNull(ConversionApiHasher::text('  '));
        $this->assertSame(hash('sha256', 'bd'), ConversionApiHasher::country());
    }
}
