<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Tests\TestCase;

/**
 * The shop's demo data was removed on purpose, so every admin screen must also render with nothing in
 * the database. (AdminPagesSmokeTest covers the same screens with a record present.)
 */
class AdminEmptyStateSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));

        // Empty every content table; keep the signed-in user. (Settings rows are created on demand.)
        foreach (['products', 'categories', 'pages', 'articles', 'banners', 'brands', 'coupons', 'customers', 'faqs', 'homepage_sections', 'media_items', 'newsletter_subscribers', 'orders', 'product_attributes', 'product_labels', 'product_tags', 'shipping_zones', 'testimonials', 'contact_submissions', 'site_settings', 'payment_settings', 'marketing_settings', 'popup_settings', 'integration_settings'] as $table) {
            DB::table($table)->delete();
        }
    }

    #[DataProviderExternal(AdminPagesSmokeTest::class, 'resources')]
    public function test_the_list_and_create_pages_render_with_no_records(string $resource): void
    {
        $pages = array_keys($resource::getPages());
        $this->assertContains('index', $pages);

        foreach (array_intersect($pages, ['index', 'create']) as $page) {
            $url = $resource::getUrl($page);
            $this->get($url)->assertSuccessful("{$resource} '{$page}' ({$url}) did not render on an empty database.");
        }
    }

    #[DataProviderExternal(AdminPagesSmokeTest::class, 'settingsPages')]
    public function test_every_settings_page_renders_with_no_settings_rows(string $page): void
    {
        $this->get($page::getUrl())->assertSuccessful("{$page} did not render on an empty database.");
    }

    public function test_the_dashboard_renders_with_nothing_in_the_shop(): void
    {
        $this->get('/admin')->assertSuccessful();
    }
}
