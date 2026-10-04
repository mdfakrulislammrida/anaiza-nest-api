<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\ArticleResource\Pages\CreateArticle;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\DeliveryFeeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContentAndPolicyFormsTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 1000,
            'stock_quantity' => 5,
            'sku' => 'TP-1',
            'is_active' => true,
            'specifications' => [['label' => 'Material', 'value' => 'Porcelain']],
        ]);
    }

    public function test_the_new_forms_render(): void
    {
        $this->signIn();
        $product = $this->product();
        $category = Category::first();
        $article = Article::create(['title' => 'A', 'slug' => 'a']);
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        $this->get("/admin/products/{$product->id}/edit")->assertOk()->assertSee('Specifications');
        $this->get('/admin/products/create')->assertOk();
        $this->get("/admin/categories/{$category->id}/edit")->assertOk();
        $this->get('/admin/articles/create')->assertOk()->assertSee('Author name');
        $this->get("/admin/articles/{$article->id}/edit")->assertOk();
        $this->get('/admin/site-settings')->assertOk()->assertSee('Delivery &amp; returns', false);
    }

    public function test_product_summary_barcode_and_specifications_save(): void
    {
        $this->signIn();
        $product = $this->product();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'summary' => 'Anaiza Nest Test Product is a test set for tea lovers.',
                'gtin' => '1234567890123',
                'mpn' => 'MPN-9',
                'specifications' => [
                    ['label' => 'Material', 'value' => 'Porcelain'],
                    ['label' => 'Capacity', 'value' => '250 ml'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame('1234567890123', $product->gtin);
        $this->assertSame('MPN-9', $product->mpn);
        $this->assertSame('Anaiza Nest Test Product is a test set for tea lovers.', $product->summary);
        $this->assertSame('Capacity', $product->specifications[1]['label']);
    }

    public function test_product_form_rejects_a_bad_barcode_a_long_summary_and_too_many_spec_rows(): void
    {
        $this->signIn();
        $product = $this->product();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['gtin' => '12345', 'summary' => str_repeat('a', 601)])
            ->call('save')
            ->assertHasFormErrors(['gtin', 'summary']);

        $tooMany = array_map(fn ($i) => ['label' => "Row {$i}", 'value' => 'x'], range(1, 21));
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['specifications' => $tooMany])
            ->call('save')
            ->assertHasFormErrors(['specifications']);
    }

    public function test_blank_gtin_and_mpn_are_allowed(): void
    {
        $this->signIn();
        $product = $this->product();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['gtin' => '', 'mpn' => ''])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_category_intro_allows_600_characters_but_not_601(): void
    {
        $this->signIn();
        $category = Category::create(['name' => 'Long', 'slug' => 'long']);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->fillForm(['intro_text' => str_repeat('a', 600)])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->fillForm(['intro_text' => str_repeat('a', 601)])
            ->call('save')
            ->assertHasFormErrors(['intro_text']);
    }

    public function test_article_author_fields_save(): void
    {
        $this->signIn();

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'title' => 'Care guide',
                'slug' => 'care-guide',
                'author_name' => 'A. Writer',
                'author_bio' => 'Writes about tea.',
                'content' => '<p>Body</p>',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $article = Article::where('slug', 'care-guide')->firstOrFail();
        $this->assertSame('A. Writer', $article->author_name);
        $this->assertSame('Writes about tea.', $article->author_bio);
    }

    public function test_saving_site_settings_with_edited_policy_changes_checkout_fees(): void
    {
        $this->signIn();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm([
                'free_delivery_threshold' => 3000,
                'delivery_fee_dhaka' => 95,
                'delivery_fee_outside_dhaka' => 160,
                'delivery_days_dhaka_min' => 2,
                'delivery_days_dhaka_max' => 4,
                'delivery_days_outside_min' => 4,
                'delivery_days_outside_max' => 6,
                'return_window_days' => 14,
                'brand_description' => 'Anaiza Nest sells ceramic tea sets in Bangladesh.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = SiteSetting::first();
        $this->assertSame(3000, $settings->free_delivery_threshold);
        $this->assertSame(14, $settings->return_window_days);
        $this->assertSame('Anaiza Nest sells ceramic tea sets in Bangladesh.', $settings->brand_description);
        $this->assertSame(95, DeliveryFeeCalculator::forDivision('Dhaka', 2500));
        $this->assertSame(0, DeliveryFeeCalculator::forDivision('Dhaka', 3001));
        $this->assertSame(160, DeliveryFeeCalculator::forDivision('Sylhet', 100));
    }

    public function test_an_untouched_default_promo_text_is_stored_blank_so_it_keeps_following_the_threshold(): void
    {
        $this->signIn();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        // The form pre-fills the generated default; saving it unchanged must not freeze it.
        Livewire::test(ManageSiteSettings::class)->call('save')->assertHasNoFormErrors();
        $this->assertNull(SiteSetting::first()->promo_text);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['free_delivery_threshold' => 4500])
            ->call('save');
        $this->assertStringContainsString('4,500', $this->getJson('/api/site-settings')->json('data.promo_text'));
    }

    public function test_a_custom_promo_text_is_kept(): void
    {
        $this->signIn();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['promo_text' => 'Eid sale is on'])
            ->call('save');

        $this->assertSame('Eid sale is on', SiteSetting::first()->promo_text);
    }

    public function test_delivery_time_upper_bound_cannot_be_below_the_lower_bound(): void
    {
        $this->signIn();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['delivery_days_dhaka_min' => 5, 'delivery_days_dhaka_max' => 2])
            ->call('save')
            ->assertHasFormErrors(['delivery_days_dhaka_max']);
    }
}
