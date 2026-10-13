<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\ArticleResource\Pages\EditArticle;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\PageResource\Pages\EditPage;
use App\Models\Article;
use App\Models\Category;
use App\Models\Page;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LongContentHtmlTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = '<table><thead><tr><th>Budget</th><th>Delivery</th></tr></thead><tbody><tr><td>Under ৳1,500</td><td>1 to 3 days</td></tr></tbody></table>';

    private const DIRTY = '<h2 class="lead" style="color:red">Gifts</h2><script>alert(1)</script><style>p{color:red}</style><p onclick="x()">Hi</p>'.self::TABLE;

    public function test_a_table_is_kept_and_scripts_styles_and_classes_are_stripped_when_content_is_saved(): void
    {
        $category = Category::create(['name' => 'Gifts', 'slug' => 'gifts', 'seo_description' => self::DIRTY]);
        $page = Page::create(['title' => 'Care', 'slug' => 'care', 'content' => self::DIRTY]);
        $article = Article::create(['title' => 'Guide', 'slug' => 'guide', 'content' => self::DIRTY, 'published_at' => now()->subDay()]);

        foreach ([$category->fresh()->seo_description, $page->fresh()->content, $article->fresh()->content] as $stored) {
            $this->assertSame('<h2>Gifts</h2><p>Hi</p>'.self::TABLE, $stored);
        }

        $this->assertSame('<h2>Gifts</h2><p>Hi</p>'.self::TABLE, $this->getJson('/api/categories/gifts')->assertOk()->json('data.seo_description'));
        $this->assertStringContainsString(self::TABLE, $this->getJson('/api/pages/care')->assertOk()->json('data.content'));
        $this->assertStringContainsString(self::TABLE, $this->getJson('/api/articles/guide')->assertOk()->json('data.content'));
    }

    public function test_content_already_stored_is_cleaned_on_the_way_out_and_left_alone_in_the_database(): void
    {
        $page = Page::create(['title' => 'Care', 'slug' => 'care', 'content' => '<p>x</p>']);
        Page::query()->whereKey($page->id)->update(['content' => '<h1 class="a">Title</h1><script>alert(1)</script>'.self::TABLE]);

        $served = $this->getJson('/api/pages/care')->assertOk()->json('data.content');

        $this->assertSame('<h2>Title</h2>'.self::TABLE, $served);
        $this->assertStringContainsString('<script>', Page::find($page->id)->content, 'Reading never rewrites the database.');
    }

    public function test_a_save_that_does_not_touch_the_content_changes_nothing_byte_for_byte(): void
    {
        $page = Page::create(['title' => 'Care', 'slug' => 'care', 'content' => '<p>x</p>']);
        $legacy = "<div class=\"old\">Legacy <span style=\"color:red\">markup</span></div>\n".self::TABLE;
        Page::query()->whereKey($page->id)->update(['content' => $legacy]);

        $page = Page::find($page->id);
        $page->update(['title' => 'Care guide']);

        $this->assertSame($legacy, Page::find($page->id)->content);
        $this->assertSame([], $page->contentHtmlReport());
    }

    public function test_content_that_needs_nothing_removed_is_stored_exactly_as_given(): void
    {
        $html = "<h2>Gifts</h2>\n<p>Packed &amp; wrapped.</p>\n".self::TABLE;
        $category = Category::create(['name' => 'Gifts', 'slug' => 'gifts', 'seo_description' => $html]);

        $this->assertSame($html, $category->fresh()->seo_description);
    }

    public function test_the_admin_form_keeps_a_table_and_says_what_it_took_out(): void
    {
        $this->actingAsAdmin();
        $page = Page::create(['title' => 'Care', 'slug' => 'care', 'content' => '<p>x</p>']);

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['content_mode' => 'html', 'content' => self::DIRTY])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame('<h2>Gifts</h2><p>Hi</p>'.self::TABLE, $page->fresh()->content);
    }

    public function test_content_with_a_table_opens_in_the_html_box_and_plain_content_in_the_visual_editor(): void
    {
        $this->actingAsAdmin();
        $withTable = Page::create(['title' => 'Care', 'slug' => 'care', 'content' => '<h2>Hi</h2>'.self::TABLE]);
        $plain = Page::create(['title' => 'About', 'slug' => 'about', 'content' => '<h2>Hi</h2><p>Plain</p>']);

        Livewire::test(EditPage::class, ['record' => $withTable->getRouteKey()])
            ->assertFormSet(['content_mode' => 'html']);

        Livewire::test(EditPage::class, ['record' => $plain->getRouteKey()])
            ->assertFormSet(['content_mode' => 'visual']);
    }

    public function test_saving_the_form_without_touching_a_table_leaves_the_stored_html_untouched(): void
    {
        $this->actingAsAdmin();
        $stored = '<h2>Gifts</h2><p>Hi</p>'.self::TABLE;
        $category = Category::create(['name' => 'Gifts', 'slug' => 'gifts', 'seo_description' => $stored]);
        $article = Article::create(['title' => 'Guide', 'slug' => 'guide', 'content' => $stored]);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->fillForm(['intro_text' => 'A new summary.'])
            ->call('save')
            ->assertHasNoFormErrors();
        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->fillForm(['title' => 'Guide to gifts'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($stored, $category->fresh()->seo_description);
        $this->assertSame($stored, $article->fresh()->content);
    }

    public function test_the_check_command_lists_what_would_be_cleaned_and_changes_nothing(): void
    {
        $page = Page::create(['title' => 'Care', 'slug' => 'care', 'content' => '<p>x</p>']);
        $stored = '<h2 class="a">Hi</h2><script>alert(1)</script>'.self::TABLE;
        Page::query()->whereKey($page->id)->update(['content' => $stored]);

        $this->artisan('content:check')
            ->expectsOutputToContain("Page #{$page->id} Care: has a table (kept) Removed 1 script tag.")
            ->assertSuccessful();

        $this->assertSame($stored, Page::find($page->id)->content);
    }

    private function actingAsAdmin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }
}
