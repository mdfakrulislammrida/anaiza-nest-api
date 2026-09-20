<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductLabel;
use App\Models\ProductTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(): Product
    {
        $category = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 1000,
            'stock_quantity' => 5,
            'sku' => 'TP-001',
            'is_active' => true,
        ]);
    }

    public function test_brands_index_returns_only_public_fields(): void
    {
        Brand::create(['name' => 'Acme', 'slug' => 'acme', 'logo_url' => 'https://example.com/logo.png']);

        $response = $this->getJson('/api/brands');

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Acme', 'slug' => 'acme']);
    }

    public function test_tags_index_returns_tags(): void
    {
        ProductTag::create(['name' => 'Gift', 'slug' => 'gift']);

        $response = $this->getJson('/api/tags');

        $response->assertOk()->assertJsonFragment(['name' => 'Gift']);
    }

    public function test_attributes_index_returns_attributes_with_values(): void
    {
        $attribute = ProductAttribute::create(['name' => 'Size', 'slug' => 'size']);
        ProductAttributeValue::create(['product_attribute_id' => $attribute->id, 'value' => 'Large']);

        $response = $this->getJson('/api/attributes');

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Size'])
            ->assertJsonFragment(['value' => 'Large']);
    }

    public function test_product_detail_includes_brand_tags_labels_and_attribute_values(): void
    {
        $product = $this->makeProduct();

        $brand = Brand::create(['name' => 'Acme', 'slug' => 'acme']);
        $tag = ProductTag::create(['name' => 'Gift', 'slug' => 'gift']);
        $label = ProductLabel::create(['name' => 'Hot', 'badge_color' => '#661E29']);
        $attribute = ProductAttribute::create(['name' => 'Size', 'slug' => 'size']);
        $value = ProductAttributeValue::create(['product_attribute_id' => $attribute->id, 'value' => 'Large']);

        $product->update(['brand_id' => $brand->id]);
        $product->tags()->attach($tag);
        $product->labels()->attach($label);
        $product->attributeValues()->attach($value);

        $response = $this->getJson("/api/products/{$product->slug}");

        $response->assertOk()
            ->assertJsonPath('data.brand.slug', 'acme')
            ->assertJsonFragment(['name' => 'Gift'])
            ->assertJsonFragment(['name' => 'Hot', 'badge_color' => '#661E29'])
            ->assertJsonFragment(['attribute_name' => 'Size', 'value' => 'Large']);
    }

    public function test_products_index_can_filter_by_brand_and_tag(): void
    {
        $product = $this->makeProduct();
        $brand = Brand::create(['name' => 'Acme', 'slug' => 'acme']);
        $tag = ProductTag::create(['name' => 'Gift', 'slug' => 'gift']);
        $product->update(['brand_id' => $brand->id]);
        $product->tags()->attach($tag);

        $otherCategory = Category::create(['name' => 'Other', 'slug' => 'other']);
        Product::create([
            'category_id' => $otherCategory->id,
            'name' => 'Other Product',
            'slug' => 'other-product',
            'price' => 500,
            'stock_quantity' => 5,
            'sku' => 'OP-001',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/products?brand=acme');
        $response->assertOk()->assertJsonCount(1, 'data');

        $response = $this->getJson('/api/products?tag=gift');
        $response->assertOk()->assertJsonCount(1, 'data');
    }
}
