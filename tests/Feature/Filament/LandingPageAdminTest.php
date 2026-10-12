<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\LandingPageResource\Pages\CreateLandingPage;
use App\Filament\Resources\LandingPageResource\Pages\EditLandingPage;
use App\Filament\Resources\LandingPageResource\Pages\ListLandingPages;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\Landing\LandingBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\AdminRecords;
use Tests\TestCase;

class LandingPageAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SiteSetting::create(['site_name' => 'Anaiza Nest']);
        Storage::fake('public');
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role_id' => null], $attributes));
    }

    private function blocks(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $out[(string) Str::uuid()] = $item;
        }

        return $out;
    }

    public function test_a_page_is_created_with_blocks_and_noindex_is_on_by_default(): void
    {
        $this->actingAs($this->admin());
        $product = AdminRecords::product();

        Livewire::test(CreateLandingPage::class)
            ->fillForm([
                'title' => 'Eid offer',
                'slug' => 'eid-offer',
                'status' => 'published',
                'blocks' => $this->blocks([
                    ['type' => 'hero', 'data' => ['is_visible' => true, 'headline' => 'A gift for Eid', 'button_target' => 'order']],
                    ['type' => 'rich_text', 'data' => ['is_visible' => true, 'content' => '<h1>Big</h1><p>Hello</p>']],
                    ['type' => 'products', 'data' => ['is_visible' => true, 'source' => 'hand', 'product_ids' => [$product->id]]],
                    ['type' => 'order_form', 'data' => ['is_visible' => true]],
                ]),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $page = LandingPage::firstOrFail();

        $this->assertTrue($page->noindex);
        $this->assertSame('focus', $page->layout);
        $this->assertSame(['hero', 'rich_text', 'products', 'order_form'], array_column($page->blocks, 'type'));
        $this->assertSame('<h2>Big</h2><p>Hello</p>', $page->blocks[1]['data']['content_clean'], 'a pasted H1 never becomes a second H1');
        $this->assertNotEmpty($page->blocks[1]['data']['uid']);
    }

    public function test_the_address_must_be_unique_and_url_safe(): void
    {
        $this->actingAs($this->admin());
        LandingPage::create(['title' => 'First', 'slug' => 'eid-offer']);

        Livewire::test(CreateLandingPage::class)
            ->fillForm(['title' => 'Second', 'slug' => 'eid-offer', 'status' => 'draft'])
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);

        Livewire::test(CreateLandingPage::class)
            ->fillForm(['title' => 'Third', 'slug' => 'not url safe!', 'status' => 'draft'])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_the_closing_time_must_be_after_the_opening_time(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateLandingPage::class)
            ->fillForm(['title' => 'Offer', 'slug' => 'offer', 'status' => 'published', 'publish_from' => '2026-10-20 10:00:00', 'publish_until' => '2026-10-19 10:00:00'])
            ->call('create')
            ->assertHasFormErrors(['publish_until']);
    }

    public function test_an_uploaded_html_file_fills_the_code_box_and_is_cleaned_on_save(): void
    {
        $this->actingAs($this->admin());
        $key = (string) Str::uuid();
        $file = UploadedFile::fake()->createWithContent('landing.html', '<!doctype html><html><head><style>.a{color:red}</style></head><body onload="x()"><h2 class="a">From a file</h2><script>alert(1)</script></body></html>');

        $component = Livewire::test(CreateLandingPage::class)
            ->fillForm(['title' => 'From file', 'slug' => 'from-file', 'status' => 'draft', 'blocks' => [$key => ['type' => 'custom_html', 'data' => ['is_visible' => true]]]])
            ->set("data.blocks.{$key}.data.html_file", [$file]);

        $html = $component->get("data.blocks.{$key}.data.html");

        $this->assertStringContainsString('<h2 class="a">From a file</h2>', $html);
        $this->assertStringContainsString('<style>.a{color:red}</style>', $html);

        $component->call('create')->assertHasNoFormErrors();

        $block = LandingPage::firstOrFail()->blocks[0]['data'];
        $this->assertStringContainsString('<h2 class="a">From a file</h2>', $block['html_clean']);
        $this->assertStringNotContainsString('<script', $block['html_clean']);
        $this->assertStringContainsString('.lp-'.$block['uid'].' .a{color:red}', $block['html_clean']);
    }

    public function test_an_html_file_over_one_megabyte_or_of_the_wrong_kind_is_refused(): void
    {
        $this->actingAs($this->admin());
        $key = (string) Str::uuid();

        $big = UploadedFile::fake()->createWithContent('big.html', str_repeat('<p>x</p>', 200_000));
        $this->assertGreaterThan(1024 * 1024, $big->getSize());

        Livewire::test(CreateLandingPage::class)
            ->fillForm(['title' => 'Big', 'slug' => 'big', 'status' => 'draft', 'blocks' => [$key => ['type' => 'custom_html', 'data' => ['is_visible' => true, 'html' => '<p>x</p>']]]])
            ->set("data.blocks.{$key}.data.html_file", [$big])
            ->call('create')
            ->assertHasFormErrors();

        $wrong = UploadedFile::fake()->createWithContent('payload.php', '<?php echo 1;');
        $keyTwo = (string) Str::uuid();

        Livewire::test(CreateLandingPage::class)
            ->fillForm(['title' => 'Wrong', 'slug' => 'wrong', 'status' => 'draft', 'blocks' => [$keyTwo => ['type' => 'custom_html', 'data' => ['is_visible' => true, 'html' => '<p>x</p>']]]])
            ->set("data.blocks.{$keyTwo}.data.html_file", [$wrong])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, LandingPage::count());
    }

    public function test_pasted_html_over_the_size_limit_is_refused(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateLandingPage::class)
            ->fillForm(['title' => 'Huge', 'slug' => 'huge', 'status' => 'draft', 'blocks' => $this->blocks([
                ['type' => 'custom_html', 'data' => ['is_visible' => true, 'html' => str_repeat('a', 1_048_577)]],
            ])])
            ->call('create')
            ->assertHasFormErrors();
    }

    public function test_the_script_switch_is_off_for_everyone_and_enabled_only_with_a_grant(): void
    {
        $page = LandingPage::create(['title' => 'Page', 'slug' => 'page', 'blocks' => [['type' => 'custom_html', 'data' => ['html' => '<p>x</p>', 'allow_scripts' => false]]]]);

        // A super-admin with no grant: the switch is there but disabled.
        $this->actingAs($this->admin());
        $component = Livewire::test(EditLandingPage::class, ['record' => $page->getKey()]);
        $key = array_key_first($component->get('data.blocks'));
        $this->assertStringContainsString('do not have permission', json_encode($component->html()));
        $this->assertFalse(LandingBlocks::canAllowScripts(auth()->user()));

        // With the grant it can be used.
        $this->actingAs($this->admin(['landing_scripts_allowed' => true]));
        $this->assertTrue(LandingBlocks::canAllowScripts(auth()->user()));
        $this->assertNotNull($key);
    }

    public function test_only_a_super_admin_can_set_the_script_grant_on_a_person(): void
    {
        $limited = $this->admin(['role_id' => Role::create(['name' => 'Marketer', 'slug' => 'marketer', 'permissions' => ['landing_pages.manage']])->id]);
        $this->actingAs($limited)->get('/admin/users')->assertForbidden();

        $this->assertArrayHasKey('landing_pages.allow_scripts', Role::PERMISSIONS);
        $this->assertFalse($limited->hasPermission('landing_pages.allow_scripts'));
    }

    public function test_a_page_can_be_duplicated_as_a_draft_with_its_own_html_scope(): void
    {
        $this->actingAs($this->admin());
        $page = LandingPage::create([
            'title' => 'Eid offer', 'slug' => 'eid-offer', 'status' => 'published',
            'blocks' => [['type' => 'custom_html', 'data' => ['html' => '<style>.a{color:red}</style><p class="a">x</p>']]],
        ]);

        Livewire::test(ListLandingPages::class)->callTableAction('duplicate', $page);

        $copy = LandingPage::query()->where('slug', 'eid-offer-copy')->firstOrFail();
        $this->assertSame('draft', $copy->status);
        $this->assertSame('Eid offer (copy)', $copy->title);
        $this->assertNotSame($page->fresh()->blocks[0]['data']['uid'], $copy->blocks[0]['data']['uid']);
        $this->assertStringContainsString('.lp-'.$copy->blocks[0]['data']['uid'].' .a', $copy->blocks[0]['data']['html_clean']);

        // Again: a fresh address each time.
        Livewire::test(ListLandingPages::class)->callTableAction('duplicate', $page);
        $this->assertTrue(LandingPage::query()->where('slug', 'eid-offer-copy-2')->exists());
    }

    public function test_the_list_shows_views_orders_conversion_and_revenue(): void
    {
        $this->actingAs($this->admin());
        $page = LandingPage::create(['title' => 'Eid offer', 'slug' => 'eid-offer', 'status' => 'published']);
        $page->stats()->create(['day' => now('Asia/Dhaka')->toDateString(), 'views' => 50, 'add_to_carts' => 8]);
        $order = AdminRecords::order();
        $order->forceFill(['landing_page_id' => $page->id, 'landing_slug' => 'eid-offer', 'total' => 2500, 'status' => 'pending'])->save();

        Livewire::test(ListLandingPages::class)
            ->assertSuccessful()
            ->assertSee('Eid offer')
            ->assertSee('50')
            ->assertSee('2%')
            ->assertSee('৳2,500');
    }

    public function test_orders_show_their_landing_page_and_can_be_filtered_by_it(): void
    {
        $this->actingAs($this->admin());
        $from = AdminRecords::order();
        $from->forceFill(['landing_page_id' => null, 'landing_slug' => 'eid-offer'])->save();
        $other = AdminRecords::order();

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$from, $other])
            ->assertTableColumnFormattedStateSet('landing_slug', '/lp/eid-offer', $from)
            ->filterTable('landing_slug', 'eid-offer')
            ->assertCanSeeTableRecords([$from])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_a_staff_member_needs_the_landing_pages_permission(): void
    {
        $role = Role::create(['name' => 'Packer', 'slug' => 'packer', 'permissions' => ['orders.manage']]);
        $this->actingAs($this->admin(['role_id' => $role->id]))->get('/admin/landing-pages')->assertForbidden();

        $role = Role::create(['name' => 'Marketer', 'slug' => 'marketer', 'permissions' => ['landing_pages.manage']]);
        $this->actingAs($this->admin(['role_id' => $role->id]))->get('/admin/landing-pages')->assertOk();
    }

    public function test_the_product_picker_finds_active_products_by_name_or_sku(): void
    {
        $this->actingAs($this->admin());
        $category = Category::create(['name' => 'Mugs', 'slug' => 'mugs']);
        Product::create(['category_id' => $category->id, 'name' => 'Glazed mug', 'slug' => 'glazed-mug', 'price' => 600, 'stock_quantity' => 5, 'sku' => 'MUG-9', 'is_active' => true]);
        Product::create(['category_id' => $category->id, 'name' => 'Retired mug', 'slug' => 'retired-mug', 'price' => 600, 'stock_quantity' => 5, 'sku' => 'MUG-0', 'is_active' => false]);

        $component = Livewire::test(CreateLandingPage::class);
        $key = (string) Str::uuid();
        $component->fillForm(['blocks' => [$key => ['type' => 'products', 'data' => ['is_visible' => true, 'source' => 'hand']]]]);

        $field = $component->instance()->form->getComponent("data.blocks.{$key}.data.product_ids");

        $this->assertSame(['Glazed mug'], array_values($field->getSearchResults('mug')));
        $this->assertSame(['Glazed mug'], array_values($field->getSearchResults('MUG-9')));
    }
}
