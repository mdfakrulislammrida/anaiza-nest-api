<?php

namespace Tests\Feature;

use App\Models\HomepageSection;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A shop with nothing in it yet (demo data removed on purpose) is a valid state: the API answers 200
 * with an empty list, the way the storefront build expects, and the brand-kit migration copes with
 * empty tables.
 */
class EmptyCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const BRAND_KIT_MIGRATION = 'database/migrations/2026_10_07_100000_add_brand_kit_fields.php';

    // --- the API ------------------------------------------------------------

    public function test_every_list_endpoint_answers_200_with_a_valid_empty_list(): void
    {
        HomepageSection::query()->delete();

        foreach (['/api/categories', '/api/pages', '/api/homepage-sections'] as $url) {
            $this->getJson($url)->assertOk()->assertExactJson(['data' => []]);
        }

        foreach (['/api/products', '/api/articles'] as $url) {
            $json = $this->getJson($url)->assertOk()->json();
            $this->assertSame([], $json['data'], $url);
            $this->assertSame(1, $json['meta']['last_page'], "{$url}: the build's pagination loop needs last_page >= 1");
            $this->assertSame(0, $json['meta']['total'], $url);
        }
    }

    public function test_filtered_product_lists_are_empty_not_errors(): void
    {
        foreach (['?on_sale=1', '?is_featured=1', '?sort=newest&per_page=12', '?category=nope', '?search=tea&min_price=1&max_price=9'] as $query) {
            $json = $this->getJson('/api/products'.$query)->assertOk()->json();
            $this->assertSame([], $json['data'], $query);
        }
    }

    public function test_every_settings_endpoint_answers_200_even_when_its_row_has_to_be_created(): void
    {
        DB::table('site_settings')->delete();
        DB::table('payment_settings')->delete();
        DB::table('marketing_settings')->delete();
        DB::table('popup_settings')->delete();

        foreach (['/api/site-settings', '/api/payment-settings', '/api/marketing-settings', '/api/popup-settings'] as $url) {
            $this->getJson($url)->assertStatus(200);
        }
    }

    public function test_a_payment_settings_row_created_on_first_read_has_cash_on_delivery_on(): void
    {
        DB::table('payment_settings')->delete();

        $this->assertTrue($this->getJson('/api/payment-settings')->json('data.cod_enabled'));
    }

    public function test_site_settings_work_with_no_settings_row_at_all(): void
    {
        SiteSetting::query()->delete();

        $json = $this->getJson('/api/site-settings')->assertOk()->json('data');

        $this->assertSame('Anaiza Nest', $json['site_name']);
        $this->assertSame('Gifted, beautifully.', $json['tagline']);
        $this->assertNotEmpty($json['footer_about']);
        $this->assertNotEmpty($json['brand_description']);
        $this->assertNotEmpty($json['nav_links']);
        $this->assertTrue($json['cod_enabled']);
    }

    // --- the brand-kit migration on empty tables ---------------------------------

    public function test_the_brand_kit_migration_rolls_back_and_runs_again_on_empty_tables(): void
    {
        DB::table('site_settings')->delete();
        DB::table('homepage_sections')->delete();

        $this->artisan('migrate:rollback', ['--path' => self::BRAND_KIT_MIGRATION, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasColumn('site_settings', 'tagline'));
        $this->assertFalse(Schema::hasColumn('homepage_sections', 'custom_subtitle'));

        $this->artisan('migrate', ['--path' => self::BRAND_KIT_MIGRATION, '--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('site_settings', 'tagline'));
        $this->assertTrue(Schema::hasColumn('homepage_sections', 'custom_subtitle'));
        $this->assertSame(0, DB::table('site_settings')->count());
        $this->assertSame(0, DB::table('homepage_sections')->count());
    }

    public function test_the_relabel_step_ignores_every_odd_shape_a_row_can_have(): void
    {
        $migration = require base_path(self::BRAND_KIT_MIGRATION);
        $relabel = new ReflectionMethod($migration, 'relabel');
        $map = ['/hot-deals' => ['Hot Deals', 'Special prices']];

        DB::table('site_settings')->delete();

        // No rows: nothing to do, nothing to fail.
        $relabel->invoke($migration, 'nav_links', $map);

        $now = now();
        $rows = [
            'null links' => null,
            'not an array' => json_encode('just a string'),
            'empty list' => json_encode([]),
            'no url keys' => json_encode([['label' => 'Hot Deals']]),
            'url but no label' => json_encode([['url' => '/hot-deals']]),
            'renamed by the admin' => json_encode([['label' => 'Sale', 'url' => '/hot-deals']]),
            'unmapped url' => json_encode([['label' => 'Hot Deals', 'url' => '/elsewhere']]),
        ];
        $ids = [];
        foreach ($rows as $name => $links) {
            $ids[$name] = DB::table('site_settings')->insertGetId(['site_name' => $name, 'nav_links' => $links, 'created_at' => $now, 'updated_at' => $now]);
        }
        $ids['old default'] = DB::table('site_settings')->insertGetId([
            'site_name' => 'old default',
            'nav_links' => json_encode([['label' => 'Home', 'url' => '/'], ['label' => 'Hot Deals', 'url' => '/hot-deals']]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $relabel->invoke($migration, 'nav_links', $map);

        foreach ($rows as $name => $links) {
            $this->assertSame($links, DB::table('site_settings')->where('id', $ids[$name])->value('nav_links'), "'{$name}' must be left alone");
        }
        $this->assertSame(
            ['Home', 'Special prices'],
            array_column(json_decode(DB::table('site_settings')->where('id', $ids['old default'])->value('nav_links'), true), 'label'),
        );
    }
}
