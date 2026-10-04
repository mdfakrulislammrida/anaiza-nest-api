<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BrandResource\Pages\ListBrands;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Filament\Resources\CouponResource\Pages\ListCoupons;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\ProductAttributeResource\Pages\ListProductAttributes;
use App\Filament\Resources\ProductLabelResource\Pages\ListProductLabels;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductTagResource\Pages\ListProductTags;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Resources\ShippingZoneResource\Pages\EditShippingZone;
use App\Filament\Resources\ShippingZoneResource\Pages\ListShippingZones;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductLabel;
use App\Models\ProductTag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\AdminRecords;
use Tests\TestCase;

/**
 * Deleting something the database refuses to orphan must produce a message, not a 500,
 * and a bulk delete must skip the blocked rows and say which ones.
 */
class DeleteGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notifications(): array
    {
        return session()->get('filament.notifications', []);
    }

    private function lastNotification(): array
    {
        $all = $this->notifications();

        return end($all) ?: [];
    }

    // --- categories -----------------------------------------------------

    public function test_a_category_with_products_cannot_be_deleted_from_the_list_and_says_why(): void
    {
        $category = AdminRecords::category();
        AdminRecords::product(['category_id' => $category->id]);
        AdminRecords::product(['category_id' => $category->id]);

        Livewire::test(ListCategories::class)->callTableAction('delete', $category);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertSame(2, Product::count());
        $note = $this->lastNotification();
        $this->assertStringContainsString('Can\'t delete "Tea Sets"', $note['title']);
        $this->assertStringContainsString('2 products', $note['body']);
    }

    public function test_a_category_with_products_cannot_be_deleted_from_its_edit_page(): void
    {
        $category = AdminRecords::category();
        AdminRecords::product(['category_id' => $category->id]);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])->callAction('delete');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertStringContainsString('1 product', $this->lastNotification()['body']);
    }

    public function test_an_empty_category_still_deletes(): void
    {
        $category = AdminRecords::category();

        Livewire::test(ListCategories::class)->callTableAction('delete', $category);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_bulk_deleting_categories_skips_the_ones_with_products_and_names_them(): void
    {
        $blocked = AdminRecords::category(['name' => 'Has Products', 'slug' => 'has-products']);
        AdminRecords::product(['category_id' => $blocked->id]);
        $free = AdminRecords::category(['name' => 'Empty One', 'slug' => 'empty-one']);
        $alsoFree = AdminRecords::category(['name' => 'Empty Two', 'slug' => 'empty-two']);

        Livewire::test(ListCategories::class)->callTableBulkAction('delete', [$blocked, $free, $alsoFree]);

        $this->assertDatabaseHas('categories', ['id' => $blocked->id]);
        $this->assertDatabaseMissing('categories', ['id' => $free->id]);
        $this->assertDatabaseMissing('categories', ['id' => $alsoFree->id]);

        $titles = array_column($this->notifications(), 'title');
        $this->assertContains('2 records deleted', $titles);
        $skipped = $this->lastNotification();
        $this->assertSame('1 record was skipped', $skipped['title']);
        $this->assertStringContainsString('"Has Products": It still has 1 product.', $skipped['body']);
    }

    // --- customers ------------------------------------------------------

    public function test_bulk_deleting_customers_skips_those_with_orders(): void
    {
        $withOrders = AdminRecords::customer(['name' => 'Has Orders']);
        AdminRecords::order(['customer_id' => $withOrders->id]);
        $without = AdminRecords::customer(['name' => 'No Orders']);

        Livewire::test(ListCustomers::class)->callTableBulkAction('delete', [$withOrders, $without]);

        $this->assertDatabaseHas('customers', ['id' => $withOrders->id]);
        $this->assertDatabaseMissing('customers', ['id' => $without->id]);
        $this->assertSame(1, Order::count());
        $skipped = $this->lastNotification();
        $this->assertSame('1 record was skipped', $skipped['title']);
        $this->assertStringContainsString('"Has Orders": It still has 1 order.', $skipped['body']);
    }

    public function test_bulk_deleting_only_blocked_customers_deletes_nothing_and_does_not_crash(): void
    {
        $customer = AdminRecords::customer();
        AdminRecords::order(['customer_id' => $customer->id]);
        AdminRecords::order(['customer_id' => $customer->id]);

        Livewire::test(ListCustomers::class)->callTableBulkAction('delete', [$customer]);

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertSame(2, Order::count());
        $this->assertContains('1 record was skipped', array_column($this->notifications(), 'title'));
        $this->assertNotContains('1 record deleted', array_column($this->notifications(), 'title'));
    }

    // --- shipping zones -------------------------------------------------

    public function test_a_shipping_zone_used_by_orders_cannot_be_deleted(): void
    {
        $zone = AdminRecords::shippingZone();
        AdminRecords::order(['shipping_zone_id' => $zone->id]);

        Livewire::test(ListShippingZones::class)->callTableAction('delete', $zone);
        $this->assertDatabaseHas('shipping_zones', ['id' => $zone->id]);
        $this->assertStringContainsString('1 order', $this->lastNotification()['body']);

        Livewire::test(EditShippingZone::class, ['record' => $zone->getRouteKey()])->callAction('delete');
        $this->assertDatabaseHas('shipping_zones', ['id' => $zone->id]);
        $this->assertSame(1, Order::where('shipping_zone_id', $zone->id)->count());
    }

    public function test_an_unused_shipping_zone_still_deletes(): void
    {
        $zone = AdminRecords::shippingZone();

        Livewire::test(ListShippingZones::class)->callTableAction('delete', $zone);

        $this->assertDatabaseMissing('shipping_zones', ['id' => $zone->id]);
    }

    public function test_bulk_deleting_shipping_zones_skips_used_ones(): void
    {
        $used = AdminRecords::shippingZone(['name' => 'Used Zone']);
        AdminRecords::order(['shipping_zone_id' => $used->id]);
        $unused = AdminRecords::shippingZone(['name' => 'Spare Zone']);

        Livewire::test(ListShippingZones::class)->callTableBulkAction('delete', [$used, $unused]);

        $this->assertDatabaseHas('shipping_zones', ['id' => $used->id]);
        $this->assertDatabaseMissing('shipping_zones', ['id' => $unused->id]);
        $this->assertStringContainsString('"Used Zone"', $this->lastNotification()['body']);
    }

    // --- roles (the database would silently null users.role_id, i.e. grant full access) ---

    public function test_a_role_with_staff_cannot_be_deleted_and_the_staff_keep_their_role(): void
    {
        $role = AdminRecords::role(['name' => 'Editor']);
        $staff = User::factory()->create(['role_id' => $role->id]);

        Livewire::test(ListRoles::class)->callTableAction('delete', $role);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertSame($role->id, $staff->fresh()->role_id);
        $this->assertStringContainsString('1 staff account', $this->lastNotification()['body']);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])->callAction('delete');
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertSame($role->id, $staff->fresh()->role_id);
    }

    public function test_bulk_deleting_roles_skips_those_with_staff(): void
    {
        $inUse = AdminRecords::role(['name' => 'In Use']);
        User::factory()->create(['role_id' => $inUse->id]);
        $spare = AdminRecords::role(['name' => 'Spare']);

        Livewire::test(ListRoles::class)->callTableBulkAction('delete', [$inUse, $spare]);

        $this->assertDatabaseHas('roles', ['id' => $inUse->id]);
        $this->assertDatabaseMissing('roles', ['id' => $spare->id]);
        $this->assertStringContainsString('"In Use"', $this->lastNotification()['body']);
    }

    public function test_an_unused_role_still_deletes(): void
    {
        $role = AdminRecords::role();

        Livewire::test(ListRoles::class)->callTableAction('delete', $role);

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    // --- everything else: no restrict key, so deletes must keep working -----------------

    public function test_a_product_with_order_items_variants_and_images_deletes_and_keeps_the_order(): void
    {
        $product = AdminRecords::product();
        $product->variants()->create(['name' => 'Colour', 'value' => 'Red', 'stock_quantity' => 3]);
        $product->images()->create(['url' => 'products/a.jpg', 'sort_order' => 0]);
        $order = AdminRecords::order();
        $order->items()->first()->update(['product_id' => $product->id]);

        Livewire::test(ListProducts::class)->callTableAction('delete', $product);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_variants', ['product_id' => $product->id]);
        $this->assertSame(1, $order->items()->count(), 'the order line survives, with its name snapshot');
        $this->assertNull($order->items()->first()->product_id);
    }

    public function test_brands_tags_labels_attributes_and_coupons_delete_even_when_in_use(): void
    {
        $brand = Brand::create(['name' => 'Anaiza', 'slug' => 'anaiza']);
        $product = AdminRecords::product(['brand_id' => $brand->id]);
        $tag = ProductTag::create(['name' => 'Gift', 'slug' => 'gift']);
        $label = ProductLabel::create(['name' => 'Hot', 'badge_color' => '#661E29']);
        $attribute = ProductAttribute::create(['name' => 'Size', 'slug' => 'size']);
        $value = $attribute->values()->create(['value' => 'Large']);
        $product->tags()->attach($tag);
        $product->labels()->attach($label);
        $product->attributeValues()->attach($value);
        $coupon = Coupon::create(['code' => 'WELCOME10', 'discount_type' => 'percent', 'amount' => 10]);

        Livewire::test(ListBrands::class)->callTableAction('delete', $brand);
        Livewire::test(ListProductTags::class)->callTableAction('delete', $tag);
        Livewire::test(ListProductLabels::class)->callTableAction('delete', $label);
        Livewire::test(ListProductAttributes::class)->callTableAction('delete', $attribute);
        Livewire::test(ListCoupons::class)->callTableAction('delete', $coupon);

        foreach (['brands' => $brand, 'product_tags' => $tag, 'product_labels' => $label, 'product_attributes' => $attribute, 'coupons' => $coupon] as $table => $model) {
            $this->assertDatabaseMissing($table, ['id' => $model->id]);
        }
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertNull($product->fresh()->brand_id);
    }

    public function test_an_order_with_items_deletes_with_its_items_and_leaves_the_customer(): void
    {
        $order = AdminRecords::order();

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])->callAction('delete');

        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('order_items', ['order_id' => $order->id]);
        $this->assertSame(1, Customer::count());
    }
}
