<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\HomepageSectionResource\Pages\ListHomepageSections;
use App\Models\HomepageSection;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RestoreDefaultSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A migration seeds the five defaults; production has none, so start from none.
        HomepageSection::query()->delete();
    }

    private function signIn(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    /**
     * @return array<string, int>
     */
    private function positions(): array
    {
        return HomepageSection::query()->orderBy('position')->pluck('position', 'type')->all();
    }

    public function test_it_creates_all_five_defaults_in_order_on_an_empty_table(): void
    {
        $created = HomepageSection::restoreDefaults();

        $this->assertSame(HomepageSection::DEFAULT_ORDER, $created);
        $this->assertSame(
            ['hero_banner' => 0, 'hot_deals' => 1, 'bestsellers' => 2, 'new_arrivals' => 3, 'newsletter' => 4],
            $this->positions(),
        );
        $this->assertSame(5, HomepageSection::where('is_enabled', true)->count());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        HomepageSection::restoreDefaults();
        $before = HomepageSection::orderBy('id')->get()->map->only(['id', 'type', 'position', 'updated_at'])->all();

        $this->assertSame([], HomepageSection::restoreDefaults());

        $this->assertSame(5, HomepageSection::count());
        $this->assertEquals($before, HomepageSection::orderBy('id')->get()->map->only(['id', 'type', 'position', 'updated_at'])->all());
    }

    public function test_it_never_overwrites_an_edited_section(): void
    {
        HomepageSection::create([
            'type' => 'bestsellers',
            'position' => 9,
            'is_enabled' => false,
            'custom_title' => 'Loved this season',
            'custom_subtitle' => 'Chosen by hand.',
        ]);

        $created = HomepageSection::restoreDefaults();

        $this->assertSame(['hero_banner', 'hot_deals', 'new_arrivals', 'newsletter'], $created);
        $edited = HomepageSection::where('type', 'bestsellers')->sole();
        $this->assertSame(9, $edited->position);
        $this->assertFalse($edited->is_enabled);
        $this->assertSame('Loved this season', $edited->custom_title);
        $this->assertSame('Chosen by hand.', $edited->custom_subtitle);
        $this->assertSame(5, HomepageSection::count());
    }

    public function test_missing_sections_go_after_the_last_existing_position(): void
    {
        HomepageSection::create(['type' => 'hero_banner', 'position' => 0, 'is_enabled' => true]);
        HomepageSection::create(['type' => 'custom_html', 'position' => 7, 'is_enabled' => true, 'custom_html' => '<p>Hi</p>']);

        HomepageSection::restoreDefaults();

        $this->assertSame(
            ['hero_banner' => 0, 'custom_html' => 7, 'hot_deals' => 8, 'bestsellers' => 9, 'new_arrivals' => 10, 'newsletter' => 11],
            $this->positions(),
        );
    }

    public function test_the_header_action_restores_them_and_the_api_serves_them(): void
    {
        $this->signIn();
        $this->getJson('/api/homepage-sections')->assertOk()->assertExactJson(['data' => []]);

        Livewire::test(ListHomepageSections::class)
            ->callAction('restoreDefaultSections')
            ->assertNotified('5 sections restored');

        $this->assertSame(
            HomepageSection::DEFAULT_ORDER,
            array_column($this->getJson('/api/homepage-sections')->assertOk()->json('data'), 'type'),
        );
    }

    public function test_the_header_action_says_so_when_there_is_nothing_to_restore(): void
    {
        $this->signIn();
        HomepageSection::restoreDefaults();

        Livewire::test(ListHomepageSections::class)
            ->callAction('restoreDefaultSections')
            ->assertNotified('All five default sections already exist');

        $this->assertSame(5, HomepageSection::count());
    }

    public function test_the_list_page_renders_with_no_sections_at_all(): void
    {
        $this->signIn();
        $this->withoutExceptionHandling();

        $this->get('/admin/homepage-sections')->assertOk()->assertSee('Restore default sections');
    }
}
