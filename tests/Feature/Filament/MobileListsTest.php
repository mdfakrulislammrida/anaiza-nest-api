<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AbandonedCheckoutResource\Pages\ListAbandonedCheckouts;
use App\Filament\Resources\CouponResource\Pages\ListCoupons;
use App\Filament\Resources\CourierStatusMapResource\Pages\ManageCourierStatusMaps;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductReviewResource\Pages\ListProductReviews;
use App\Filament\Resources\ShippingZoneResource\Pages\ListShippingZones;
use App\Models\AbandonedCheckout;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\ShippingZone;
use Filament\Facades\Filament;
use Filament\Tables\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

/**
 * The other lists on a phone (Batch OPS2-B, Phase B): each keeps every column for a desktop and adds one card column for a phone, and its row actions are
 * one menu on a phone. Same idea and same helper as the Orders list (MobileAdminTest).
 */
class MobileListsTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->shop();
        $this->actingAs($this->admin());
    }

    /** @return array<string, array{0: class-string, 1: list<string>}> the page, and columns the list is known to have had before the cards */
    public static function lists(): array
    {
        return [
            'products' => [ListProducts::class, ['category.name', 'name', 'sku', 'price', 'sale_price', 'stock_quantity', 'is_new', 'is_featured', 'is_active']],
            'customers' => [ListCustomers::class, ['name', 'email', 'phone', 'city', 'orders_count']],
            'abandoned checkouts' => [ListAbandonedCheckouts::class, ['stage', 'name', 'cart_total', 'last_activity_at', 'messages_count', 'status']],
            'reviews' => [ListProductReviews::class, ['status']],
            'coupons' => [ListCoupons::class, ['code', 'amount', 'status', 'valid_until', 'used_count']],
            'shipping zones' => [ListShippingZones::class, ['name']],
            'courier statuses' => [ManageCourierStatusMaps::class, ['courier', 'courier_status', 'stage', 'effect', 'is_final', 'marks_cod_collected', 'armed', 'rank', 'seen_count']],
        ];
    }

    /**
     * @param  list<string>  $known
     */
    #[DataProvider('lists')]
    public function test_each_list_keeps_its_columns_for_a_desktop_and_has_one_card_for_a_phone(string $page, array $known): void
    {
        $columns = Livewire::test($page)->instance()->getTable()->getColumns();
        $names = array_keys($columns);

        $this->assertSame('phone_card', end($names), 'the card is the last column');
        $this->assertSame('md', $columns['phone_card']->getHiddenFrom());

        foreach ($known as $name) {
            $this->assertArrayHasKey($name, $columns, "{$name} is still a column");
        }

        foreach ($columns as $name => $column) {
            if ($name !== 'phone_card') {
                $this->assertSame('md', $column->getVisibleFrom(), "{$name} shows from md up");
            }
        }
    }

    #[DataProvider('lists')]
    public function test_each_lists_row_actions_are_in_a_line_and_in_one_phone_menu(string $page): void
    {
        $actions = Livewire::test($page)->instance()->getTable()->getActions();
        $groups = array_values(array_filter($actions, fn ($action): bool => $action instanceof ActionGroup && str_contains($action->getExtraAttributes()['class'] ?? '', 'am-phone-only')));
        $inline = array_filter($actions, fn ($action): bool => ! $action instanceof ActionGroup);

        $this->assertCount(1, $groups, 'one menu');
        $this->assertNotEmpty($inline);

        foreach ($inline as $action) {
            $this->assertStringContainsString('am-desktop-only', $action->getExtraAttributes()['class'] ?? '');
        }

        $this->assertCount(count($inline), $groups[0]->getActions(), 'the menu holds the same actions as the line');
    }

    public function test_the_cards_show_the_facts_that_matter(): void
    {
        $category = Category::create(['name' => 'Tea sets', 'slug' => 'tea-sets']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Rose cup', 'slug' => 'rose-cup', 'price' => 1450, 'stock_quantity' => 0, 'sku' => 'RC-1', 'is_active' => true]);
        // The picture's file must be on the disk: a picture that is gone is skipped (App\Support\ProductThumbnail).
        Storage::fake('public')->put('products/rc-400.webp', 'x');
        ProductImage::create(['product_id' => $product->id, 'url' => 'products/rc.webp', 'url_400' => 'products/rc-400.webp', 'sort_order' => 0]);
        $customer = Customer::create(['name' => 'Nusrat Jahan', 'email' => 'n@example.test', 'password' => 'secret-pass-1', 'phone' => '01712345678', 'address' => 'x', 'city' => 'Dhaka', 'postal_code' => '1212']);
        ShippingZone::create(['name' => 'Inside Dhaka', 'delivery_fee' => 80, 'estimated_days' => '1-2 days']);
        Coupon::create(['code' => 'EID10', 'discount_type' => 'percent', 'amount' => 10, 'is_active' => true]);
        ProductReview::create(['product_id' => $product->id, 'name' => 'Tasnim', 'rating' => 4, 'title' => 'Good', 'body' => 'Lovely cups, packed with care.', 'status' => 'approved']);
        AbandonedCheckout::create([
            'session_token' => 's1', 'recovery_token' => 'r1', 'name' => 'Rubina Akter', 'phone' => '01811111111', 'email' => 'r@example.test', 'stage' => 'cart',
            'items' => [['product_id' => $product->id, 'name' => 'Rose cup', 'quantity' => 1, 'price' => 1450]], 'cart_total' => 1450, 'first_activity_at' => now(), 'last_activity_at' => now(), 'status' => 'open',
        ]);

        $show = fn (string $page, string $text) => $this->assertStringContainsString($text, Livewire::test($page)->html());

        $show(ListProducts::class, 'Out of stock');
        $show(ListProducts::class, '৳1,450');
        $show(ListProducts::class, 'products/rc-400.webp');
        $show(ListCustomers::class, '01712345678');
        $show(ListCustomers::class, '0 orders');
        $show(ListShippingZones::class, '৳80');
        $show(ListShippingZones::class, '1-2 days');
        $show(ListCoupons::class, 'EID10');
        $show(ListProductReviews::class, 'Lovely cups');
        $show(ListAbandonedCheckouts::class, 'Rubina Akter');
        $show(ListAbandonedCheckouts::class, '৳1,450 · 1 pcs');
        $show(ManageCourierStatusMaps::class, 'Acts on orders');
        $this->assertNotNull($customer);
    }
}
