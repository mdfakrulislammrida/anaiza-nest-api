<?php

namespace Tests\Feature\Filament;

use App\Models\ProductAttribute;
use App\Models\ProductLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTaxonomyResourceTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role_id' => null]);
    }

    public function test_brand_resource_pages_render(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/admin/brands')->assertOk();
        $this->get('/admin/brands/create')->assertOk();
    }

    public function test_product_tag_resource_pages_render(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/admin/product-tags')->assertOk();
        $this->get('/admin/product-tags/create')->assertOk();
    }

    public function test_product_label_resource_pages_render_including_edit_with_color_column(): void
    {
        $this->actingAs($this->superAdmin());

        $label = ProductLabel::create(['name' => 'Hot', 'badge_color' => '#661E29']);

        $this->get('/admin/product-labels')->assertOk();
        $this->get('/admin/product-labels/create')->assertOk();
        $this->get("/admin/product-labels/{$label->id}/edit")->assertOk();
    }

    public function test_product_attribute_resource_pages_render_including_values_relation_manager(): void
    {
        $this->actingAs($this->superAdmin());

        $attribute = ProductAttribute::create(['name' => 'Size', 'slug' => 'size']);

        $this->get('/admin/product-attributes')->assertOk();
        $this->get('/admin/product-attributes/create')->assertOk();
        $this->get("/admin/product-attributes/{$attribute->id}/edit")->assertOk();
    }

    public function test_product_resource_edit_page_renders_with_new_merchandising_fields(): void
    {
        $this->actingAs($this->superAdmin());

        $category = \App\Models\Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);
        $product = \App\Models\Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 1000,
            'stock_quantity' => 5,
            'sku' => 'TP-001',
            'is_active' => true,
        ]);

        $this->get("/admin/products/{$product->id}/edit")->assertOk();
    }
}
