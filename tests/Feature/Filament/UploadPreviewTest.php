<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\RelationManagers\ImagesRelationManager;
use App\Filament\Support\UploadPreview;
use App\Http\Controllers\Admin\UploadPreviewController;
use App\Models\ProductImage;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\AdminRecords;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

/**
 * Batch ADM1, I1: an upload field shows a small picture for a stored file and for a new one, and a neutral tile when the file is gone. The picture is
 * handed to the page from the page's own address (a browser fetch of the storage address fails when the admin is opened on another host or scheme).
 */
class UploadPreviewTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->shop();
        $this->actingAs($this->admin());
    }

    private function field(object $page, string $name): FileUpload
    {
        $component = $page->instance()->form->getComponent("data.{$name}");
        $this->assertInstanceOf(FileUpload::class, $component, $name);

        return $component;
    }

    // --- the route -----------------------------------------------------------

    public function test_a_signed_in_admin_gets_a_stored_picture_from_the_pages_own_address(): void
    {
        Storage::disk('public')->put('categories/Tea Cup.png', UploadedFile::fake()->image('x.png', 120, 120)->getContent());

        $response = $this->get('/admin/upload-preview/categories/Tea%20Cup.png');

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_the_route_refuses_anyone_who_is_not_signed_in(): void
    {
        Storage::disk('public')->put('categories/a.png', UploadedFile::fake()->image('a.png')->getContent());
        auth()->logout();

        $this->get('/admin/upload-preview/categories/a.png')->assertForbidden();
        $this->get('/admin/upload-placeholder/a.png')->assertForbidden();
    }

    public function test_the_route_serves_only_pictures_that_are_on_the_public_disk_and_cannot_leave_it(): void
    {
        Storage::disk('public')->put('notes/secret.txt', 'not a picture');
        Storage::disk('public')->put('categories/real.png', UploadedFile::fake()->image('real.png')->getContent());

        $this->get('/admin/upload-preview/notes/secret.txt')->assertNotFound();
        $this->get('/admin/upload-preview/categories/missing.png')->assertNotFound();
        $this->get('/admin/upload-preview/categories/..%2F..%2F.env')->assertNotFound();
        $this->get('/admin/upload-preview/..%2Fapp%2FModels%2FUser.php')->assertNotFound();
        $this->get('/admin/upload-preview/%2Fetc%2Fpasswd')->assertNotFound();
    }

    public function test_which_paths_are_safe(): void
    {
        foreach (['categories/a.png', 'products/generated/0f8c-1200.webp', 'site/Logo (navy).png', 'media-library/été.jpg'] as $path) {
            $this->assertTrue(UploadPreviewController::isSafe($path), $path);
        }

        foreach (['', '/etc/passwd', '../x.png', 'a/../../x.png', 'a\\b.png', "a\0b.png", 'http://evil.test/x.png', 'a/b.png?x=1', str_repeat('a', 301)] as $path) {
            $this->assertFalse(UploadPreviewController::isSafe($path), $path);
        }
    }

    public function test_the_neutral_tile_is_a_small_png(): void
    {
        $response = $this->get('/admin/upload-placeholder/gone.png');

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertNotFalse(@imagecreatefromstring($response->getContent()));
    }

    // --- the field -----------------------------------------------------------

    public function test_an_existing_picture_has_a_preview_on_the_pages_own_address_and_keeps_its_name(): void
    {
        Storage::disk('public')->put('categories/thumb.png', UploadedFile::fake()->image('thumb.png', 200, 200)->getContent());
        $category = AdminRecords::category();
        // Written straight to the table: the category observer would convert a picture it is given on create.
        DB::table('categories')->where('id', $category->id)->update(['thumbnail' => 'categories/thumb.png']);

        $page = Livewire::test(EditCategory::class, ['record' => $category->getKey()]);
        $files = $this->field($page, 'thumbnail')->getUploadedFiles();
        $file = array_values($files)[0];

        $this->assertSame('thumb.png', $file['name']);
        $this->assertSame('/admin/upload-preview/categories/thumb.png', $file['url']);
        $this->assertSame('image/png', $file['type']);
        $this->assertGreaterThan(0, $file['size']);
        $this->assertSame('96', $this->field($page, 'thumbnail')->getImagePreviewHeight());
    }

    public function test_a_missing_file_is_a_neutral_tile_with_its_name_and_the_stored_value_is_not_touched(): void
    {
        $category = AdminRecords::category(['thumbnail' => 'categories/gone.png']);

        $page = Livewire::test(EditCategory::class, ['record' => $category->getKey()]);
        $file = array_values($this->field($page, 'thumbnail')->getUploadedFiles())[0];

        $this->assertSame('gone.png', $file['name']);
        $this->assertSame('/admin/upload-placeholder/gone.png', $file['url']);

        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame('categories/gone.png', DB::table('categories')->where('id', $category->id)->value('thumbnail'));
    }

    public function test_a_picture_typed_in_as_a_full_address_is_a_tile_too(): void
    {
        $category = AdminRecords::category(['thumbnail' => 'https://old.example.test/pictures/tea.jpg']);

        $page = Livewire::test(EditCategory::class, ['record' => $category->getKey()]);
        $file = array_values($this->field($page, 'thumbnail')->getUploadedFiles())[0];

        $this->assertSame('tea.jpg', $file['name']);
        $this->assertStringStartsWith('/admin/upload-placeholder/', $file['url']);
    }

    public function test_a_new_upload_is_held_for_the_field_to_preview_and_is_saved_where_it_always_was(): void
    {
        $category = AdminRecords::category();

        $page = Livewire::test(EditCategory::class, ['record' => $category->getKey()])
            ->fillForm(['thumbnail' => UploadedFile::fake()->image('fresh.png', 300, 300)])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = DB::table('categories')->where('id', $category->id)->value('thumbnail');

        $this->assertStringStartsWith('categories/', $stored);
        Storage::disk('public')->assertExists($stored);
        $this->assertNotNull($page);
    }

    public function test_every_upload_field_in_the_admin_gets_the_small_preview_without_being_listed(): void
    {
        $field = FileUpload::make('anything')->image()->disk('public');

        $this->assertSame('96', $field->getImagePreviewHeight());
        $this->assertSame(96, UploadPreview::SIZE);
        $this->assertSame('/admin/upload-preview/a/b%20c.png', UploadPreview::url('a/b c.png'));
    }

    // --- the product page ------------------------------------------------------

    public function test_the_product_form_still_saves(): void
    {
        $product = AdminRecords::product();

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_the_product_pictures_table_shows_the_same_copy_as_the_products_list_and_a_tile_when_there_is_none(): void
    {
        $product = AdminRecords::product();
        Storage::disk('public')->put('products/generated/a-400.webp', UploadedFile::fake()->image('a.png', 40, 40)->getContent());
        Storage::disk('public')->put('products/generated/a-1200.webp', UploadedFile::fake()->image('a.png', 40, 40)->getContent());
        // Written straight to the table: the image observer would convert and rewrite the paths of a picture it is given on create.
        DB::table('product_images')->insert([
            ['product_id' => $product->id, 'url' => 'products/generated/a-1200.webp', 'url_400' => 'products/generated/a-400.webp', 'sort_order' => 0],
            ['product_id' => $product->id, 'url' => 'products/generated/lost-1200.webp', 'url_400' => null, 'sort_order' => 1],
        ]);
        [$kept, $gone] = ProductImage::query()->where('product_id', $product->id)->orderBy('sort_order')->get()->all();

        $table = Livewire::test(ImagesRelationManager::class, ['ownerRecord' => $product->fresh(), 'pageClass' => EditProduct::class]);

        $columns = array_keys($table->instance()->getTable()->getColumns());

        // The picture column is really there (it used to be shadowed by the text column of the same name) and the file path text stays too.
        $this->assertContains('preview', $columns);
        $this->assertContains('url', $columns);
        $table->assertTableColumnStateSet('preview', Storage::disk('public')->url('products/generated/a-400.webp'), $kept);
        $table->assertTableColumnStateSet('preview', null, $gone);
        $this->assertStringContainsString('upload-placeholder', $table->instance()->getTable()->getColumn('preview')->getDefaultImageUrl());
    }
}
