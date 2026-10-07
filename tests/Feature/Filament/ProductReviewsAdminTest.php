<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductReviewResource;
use App\Filament\Resources\ProductReviewResource\Pages\EditProductReview;
use App\Filament\Resources\ProductReviewResource\Pages\ListProductReviews;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductReviewsAdminTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $this->product = $this->product('Porcelain tea set', 'porcelain-tea-set');
    }

    private function product(string $name, string $slug): Product
    {
        return Product::create([
            'category_id' => (Category::first() ?? Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']))->id,
            'name' => $name, 'slug' => $slug, 'price' => 1450, 'stock_quantity' => 5, 'sku' => strtoupper($slug), 'is_active' => true,
        ]);
    }

    private function review(array $attributes = []): ProductReview
    {
        return ProductReview::create(array_merge([
            'product_id' => $this->product->id, 'name' => 'Rafi', 'rating' => 5, 'body' => 'Lovely set, packed beautifully.',
        ], $attributes));
    }

    public function test_approving_and_rejecting_decide_what_is_public(): void
    {
        $review = $this->review();

        Livewire::test(ListProductReviews::class)
            ->callTableAction('approve', $review)
            ->assertNotified('Review approved');
        $this->assertSame('approved', $review->fresh()->status);
        $this->assertNotNull($review->fresh()->approved_at);
        $this->getJson('/api/products/porcelain-tea-set/reviews')->assertJsonPath('summary.rating_count', 1);

        Livewire::test(ListProductReviews::class)
            ->callTableAction('reject', $review)
            ->assertNotified('Review rejected');
        $this->assertSame('rejected', $review->fresh()->status);
        $this->getJson('/api/products/porcelain-tea-set/reviews')->assertJsonPath('summary.rating_count', 0);
    }

    public function test_bulk_actions_approve_and_reject_many(): void
    {
        $reviews = collect([$this->review(), $this->review(), $this->review()]);

        Livewire::test(ListProductReviews::class)->callTableBulkAction('approveSelected', $reviews);
        $this->assertSame(['approved', 'approved', 'approved'], $reviews->map(fn ($review) => $review->fresh()->status)->all());

        Livewire::test(ListProductReviews::class)->callTableBulkAction('rejectSelected', $reviews->take(2));
        $this->assertSame(['rejected', 'rejected', 'approved'], $reviews->map(fn ($review) => $review->fresh()->status)->all());
    }

    public function test_the_list_filters_by_status_rating_and_product(): void
    {
        $mug = $this->product('Ceramic mug', 'ceramic-mug');
        $pending = $this->review(['rating' => 2]);
        $approved = $this->review(['status' => 'approved', 'rating' => 5]);
        $other = $this->review(['product_id' => $mug->id, 'status' => 'approved', 'rating' => 5]);

        Livewire::test(ListProductReviews::class)
            ->assertCanSeeTableRecords([$pending, $approved, $other])
            ->filterTable('status', 'pending')->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$approved, $other])
            ->removeTableFilter('status')
            ->filterTable('rating', 5)->assertCanSeeTableRecords([$approved, $other])->assertCanNotSeeTableRecords([$pending])
            ->removeTableFilter('rating')
            ->filterTable('product_id', $mug->id)->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$pending, $approved]);

        Livewire::test(ListProductReviews::class)->set('activeTab', 'pending')->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$approved]);
    }

    public function test_the_sidebar_badge_counts_reviews_waiting_to_be_read(): void
    {
        $this->assertNull(ProductReviewResource::getNavigationBadge());

        $this->review();
        $this->review();
        $this->review(['status' => 'approved']);

        $this->assertSame('2', ProductReviewResource::getNavigationBadge());
        $this->assertSame('Reviews waiting to be read', ProductReviewResource::getNavigationBadgeTooltip());
    }

    public function test_the_admin_can_reply_and_nothing_else_about_a_review_can_change(): void
    {
        $review = $this->review(['status' => 'approved']);

        Livewire::test(EditProductReview::class, ['record' => $review->getRouteKey()])
            ->fillForm(['admin_reply' => 'Thank you, we are so glad it arrived well.', 'body' => 'Rewritten by the admin', 'rating' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        $review->refresh();
        $this->assertSame('Thank you, we are so glad it arrived well.', $review->admin_reply);
        $this->assertNotNull($review->admin_replied_at);
        $this->assertSame('Lovely set, packed beautifully.', $review->body);
        $this->assertSame(5, $review->rating);
        $this->getJson('/api/products/porcelain-tea-set/reviews')->assertJsonPath('data.0.admin_reply', 'Thank you, we are so glad it arrived well.');
    }

    public function test_clearing_the_reply_removes_it(): void
    {
        $review = $this->review(['status' => 'approved', 'admin_reply' => 'Thanks', 'admin_replied_at' => now()]);

        Livewire::test(EditProductReview::class, ['record' => $review->getRouteKey()])->fillForm(['admin_reply' => ''])->call('save');

        $this->assertNull($review->fresh()->admin_reply);
        $this->assertNull($review->fresh()->admin_replied_at);
    }

    public function test_the_admin_cannot_write_a_customer_review(): void
    {
        $this->assertFalse(ProductReviewResource::canCreate());
        $this->get('/admin/product-reviews/create')->assertNotFound();
    }

    public function test_the_review_page_shows_what_the_customer_wrote(): void
    {
        $review = $this->review(['title' => 'A keeper', 'order_id' => null]);

        $this->get("/admin/product-reviews/{$review->id}/edit")->assertOk()
            ->assertSee('Lovely set, packed beautifully.')
            ->assertSee('A keeper')
            ->assertSee('Approve');
    }
}
