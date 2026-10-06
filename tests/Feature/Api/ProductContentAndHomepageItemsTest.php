<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\HomepageSection;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductContentAndHomepageItemsTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'tea-sets'], ['name' => 'Tea Sets']);

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 1450,
            'stock_quantity' => 10,
            'sku' => 'TP-001',
            'is_active' => true,
        ], $attributes));
    }

    public function test_a_product_with_nothing_new_has_empty_content_fields(): void
    {
        $this->product();

        $this->getJson('/api/products/test-product')->assertOk()
            ->assertJsonPath('data.box_contents', [])
            ->assertJsonPath('data.care_instructions', null)
            ->assertJsonPath('data.gift_box_included', false);
    }

    public function test_the_content_fields_are_served_with_blanks_dropped(): void
    {
        $this->product([
            'box_contents' => ['Teapot', '  ', 'Two cups'],
            'care_instructions' => '  Wash by hand.  ',
            'gift_box_included' => true,
        ]);

        $this->getJson('/api/products/test-product')->assertOk()
            ->assertJsonPath('data.box_contents', ['Teapot', 'Two cups'])
            ->assertJsonPath('data.care_instructions', 'Wash by hand.')
            ->assertJsonPath('data.gift_box_included', true);
    }

    private function section(string $type, array $attributes = []): HomepageSection
    {
        return HomepageSection::create(array_merge(['type' => $type, 'position' => 20, 'is_enabled' => true], $attributes));
    }

    public function test_only_switched_on_tiles_with_a_destination_are_served(): void
    {
        Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);
        Page::create(['title' => 'Gift guide', 'slug' => 'gift-guide', 'content' => '<p>x</p>']);

        $this->section('occasions', ['tiles' => [
            ['label' => 'Eid-ul-Fitr', 'link_type' => 'category', 'link_category' => 'tea-sets', 'is_enabled' => true],
            ['label' => 'Wedding season', 'link_type' => 'page', 'link_page' => 'gift-guide', 'is_enabled' => true],
            ['label' => 'Gift finder', 'link_type' => 'gift_finder', 'is_enabled' => true],
            ['label' => 'Housewarming', 'link_type' => 'custom', 'custom_url' => '/shop', 'is_enabled' => false],
            ['label' => 'No link', 'link_type' => 'category', 'link_category' => '', 'is_enabled' => true],
            ['label' => 'Bad link', 'link_type' => 'custom', 'custom_url' => 'javascript:alert(1)', 'is_enabled' => true],
            ['label' => '', 'link_type' => 'custom', 'custom_url' => '/shop', 'is_enabled' => true],
        ]]);

        $section = collect($this->getJson('/api/homepage-sections')->assertOk()->json('data'))->firstWhere('type', 'occasions');

        $this->assertSame(
            [
                ['label' => 'Eid-ul-Fitr', 'image' => null, 'href' => '/category/tea-sets'],
                ['label' => 'Wedding season', 'image' => null, 'href' => '/pages/gift-guide'],
                ['label' => 'Gift finder', 'image' => null, 'href' => '/gift-finder'],
            ],
            $section['tiles'],
        );
        $this->assertNull($section['reasons']);
    }

    public function test_reasons_are_capped_at_five_and_blank_lines_are_dropped(): void
    {
        $this->section('why_us', ['reasons' => array_merge(
            [['title' => null, 'line' => '']],
            array_map(fn (int $i) => ['title' => $i === 1 ? 'Edited' : null, 'line' => "Line {$i}"], range(1, 7)),
        )]);

        $section = collect($this->getJson('/api/homepage-sections')->json('data'))->firstWhere('type', 'why_us');

        $this->assertCount(5, $section['reasons']);
        $this->assertSame(['title' => 'Edited', 'line' => 'Line 1'], $section['reasons'][0]);
        $this->assertNull($section['tiles']);
    }

    public function test_the_kit_occasions_are_added_switched_off_and_only_once(): void
    {
        $added = HomepageSection::addKitOccasions();

        $this->assertSame(array_keys(HomepageSection::KIT_OCCASIONS), $added);
        $section = HomepageSection::where('type', 'occasions')->sole();
        $this->assertCount(7, $section->tiles);
        $this->assertSame([false], array_values(array_unique(array_column($section->tiles, 'is_enabled'))));
        $this->assertSame([null], array_values(array_unique(array_column($section->tiles, 'image'))));

        // Nothing is live until the admin switches a tile on.
        $served = collect($this->getJson('/api/homepage-sections')->json('data'))->firstWhere('type', 'occasions');
        $this->assertSame([], $served['tiles']);

        $this->assertSame([], HomepageSection::addKitOccasions());
        $this->assertCount(7, HomepageSection::where('type', 'occasions')->sole()->tiles);
        $this->assertSame(1, HomepageSection::where('type', 'occasions')->count());
    }

    public function test_adding_kit_occasions_keeps_an_edited_tile(): void
    {
        $this->section('occasions', ['tiles' => [
            ['label' => 'Eid-ul-Fitr', 'link_type' => 'custom', 'custom_url' => '/shop', 'is_enabled' => true],
        ]]);

        $added = HomepageSection::addKitOccasions();

        $this->assertNotContains('Eid-ul-Fitr', $added);
        $tiles = HomepageSection::where('type', 'occasions')->sole()->tiles;
        $this->assertCount(7, $tiles);
        $this->assertTrue($tiles[0]['is_enabled']);
    }

    public function test_the_kit_lines_are_inserted_word_for_word_once(): void
    {
        $this->assertTrue(HomepageSection::insertKitLines());

        $reasons = HomepageSection::where('type', 'why_us')->sole()->reasons;
        $this->assertSame([
            'A small, edited range, so every piece has earned its place.',
            'Gift packaging is part of the product, not an extra.',
            'Packed and checked by hand before dispatch.',
            'Cash on delivery, and a real shop in Dhaka as well as the website.',
            'Part of Fast-Signs Group, in business in Bangladesh since 2003.',
        ], array_column($reasons, 'line'));

        $this->assertFalse(HomepageSection::insertKitLines());
        $this->assertSame(1, HomepageSection::where('type', 'why_us')->count());
    }

    public function test_a_section_with_lines_already_is_left_alone(): void
    {
        $this->section('why_us', ['reasons' => [['title' => null, 'line' => 'My own line']]]);

        $this->assertFalse(HomepageSection::insertKitLines());
        $this->assertSame([['title' => null, 'line' => 'My own line']], HomepageSection::where('type', 'why_us')->sole()->reasons);
    }
}
