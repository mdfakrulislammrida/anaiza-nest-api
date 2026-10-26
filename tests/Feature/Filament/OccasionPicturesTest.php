<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\HomepageSectionResource\Pages\EditHomepageSection;
use App\Models\HomepageSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Shop by occasion" tiles: a picture added to a tile stays, whatever is saved before or after it, with the page kept open or reloaded.
 * (The cause of the bug these cover: after Save the form kept the path of the upload the observer had converted and removed, and the
 * next Save wrote that dead path over the tile's picture, so only the tile saved last kept one.)
 */
class OccasionPicturesTest extends TestCase
{
    use RefreshDatabase;

    private const SHOWN = ['Wedding season', 'Housewarming', 'Corporate gifting', 'For parents'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    /** Four shown tiles with a switched-off one among them, as on the live site. */
    private function section(): HomepageSection
    {
        $tile = fn (string $label, bool $on = true) => ['label' => $label, 'image' => null, 'link_type' => 'custom', 'custom_url' => '/shop', 'is_enabled' => $on];

        return HomepageSection::create(['type' => 'occasions', 'position' => 1, 'is_enabled' => true, 'tiles' => [
            $tile('Wedding season'), $tile('Housewarming'), $tile("Mother's Day", false), $tile('Corporate gifting'), $tile('For parents'),
        ]]);
    }

    private function open(HomepageSection $section): Testable
    {
        return Livewire::test(EditHomepageSection::class, ['record' => $section->getRouteKey()]);
    }

    /** The form's key for the tile with this label, as the form holds it right now (the keys change when the form is filled again). */
    private function key(Testable $page, string $label): string
    {
        foreach ($page->get('data.tiles') as $key => $tile) {
            if (($tile['label'] ?? null) === $label) {
                return $key;
            }
        }

        $this->fail("No tile called {$label} in the form.");
    }

    private function upload(Testable $page, string $label, int $width = 800): Testable
    {
        return $page->set('data.tiles.'.$this->key($page, $label).'.image', [UploadedFile::fake()->image(str($label)->slug().'.png', $width, 600)]);
    }

    /** @return array<string, ?string> label => stored picture */
    private function stored(HomepageSection $section): array
    {
        return collect($section->fresh()->tiles)->pluck('image', 'label')->all();
    }

    private function assertAllKept(HomepageSection $section, array $labels = self::SHOWN): void
    {
        $images = $this->stored($section);

        foreach ($labels as $label) {
            $this->assertNotNull($images[$label], "{$label} has a picture");
            $this->assertStringStartsWith('occasions/generated/', $images[$label], "{$label}'s picture is the converted one");
            Storage::disk('public')->assertExists($images[$label]);
        }

        $this->assertCount(count($labels), array_unique(array_filter($images)), 'each tile has its own picture');
    }

    public function test_four_pictures_added_one_after_another_with_a_save_each_time_all_stay(): void
    {
        $section = $this->section();
        $page = $this->open($section);

        foreach (self::SHOWN as $label) {
            $this->upload($page, $label)->call('save')->assertHasNoFormErrors();
        }

        $this->assertAllKept($section);
        $this->assertNull($this->stored($section)["Mother's Day"], 'the switched-off tile did not get anyone else\'s picture');
        $this->assertSame(['Wedding season', 'Housewarming', "Mother's Day", 'Corporate gifting', 'For parents'], array_keys($this->stored($section)), 'the order is unchanged');
    }

    public function test_the_form_shows_the_stored_pictures_after_each_save(): void
    {
        $section = $this->section();
        $page = $this->open($section);

        $this->upload($page, 'Wedding season')->call('save');
        $this->upload($page, 'Housewarming')->call('save');

        $formImages = collect($page->get('data.tiles'))->mapWithKeys(fn ($tile) => [$tile['label'] => array_values((array) ($tile['image'] ?? []))[0] ?? null])->all();

        $this->assertSame($this->stored($section)['Wedding season'], $formImages['Wedding season']);
        $this->assertSame($this->stored($section)['Housewarming'], $formImages['Housewarming']);
        $this->assertNull($formImages['Corporate gifting']);
    }

    public function test_four_pictures_added_and_saved_once_all_stay(): void
    {
        $section = $this->section();
        $page = $this->open($section);

        foreach (self::SHOWN as $label) {
            $this->upload($page, $label);
        }
        $page->call('save')->assertHasNoFormErrors();

        $this->assertAllKept($section);
    }

    public function test_reloading_the_page_after_each_upload_loses_nothing(): void
    {
        $section = $this->section();

        foreach (self::SHOWN as $label) {
            $page = $this->open($section);
            $this->upload($page, $label)->call('save')->assertHasNoFormErrors();
        }

        $this->assertAllKept($section);
    }

    public function test_saving_other_changes_keeps_every_picture_and_a_save_never_converts_twice(): void
    {
        $section = $this->section();
        $page = $this->open($section);

        foreach (self::SHOWN as $label) {
            $this->upload($page, $label);
        }
        $page->call('save');
        $before = $this->stored($section);

        $page->fillForm(['custom_title' => 'Shop by occasion'])->call('save')->assertHasNoFormErrors();
        $page->call('save');

        $this->assertSame($before, $this->stored($section), 'the same files, not new copies');
        $this->assertSame('Shop by occasion', $section->fresh()->custom_title);
    }

    public function test_one_removal_removes_only_that_tiles_picture(): void
    {
        $section = $this->section();
        $page = $this->open($section);

        foreach (self::SHOWN as $label) {
            $this->upload($page, $label);
        }
        $page->call('save');
        $before = $this->stored($section);

        $page->set('data.tiles.'.$this->key($page, 'Housewarming').'.image', [])->call('save')->assertHasNoFormErrors();
        $after = $this->stored($section);

        $this->assertNull($after['Housewarming']);
        foreach (['Wedding season', 'Corporate gifting', 'For parents'] as $label) {
            $this->assertSame($before[$label], $after[$label], "{$label} keeps its picture");
        }

        // And the page, reloaded, agrees.
        $this->assertNull(collect($this->open($section)->get('data.tiles'))->firstWhere('label', 'Housewarming')['image'] ?? null ?: null);
    }

    public function test_a_tile_added_after_the_pictures_does_not_disturb_them(): void
    {
        $section = $this->section();
        $page = $this->open($section);

        foreach (self::SHOWN as $label) {
            $this->upload($page, $label)->call('save');
        }
        $before = $this->stored($section);

        $page->set('data.tiles', array_merge($page->get('data.tiles'), ['new' => ['label' => 'Eid-ul-Fitr', 'is_enabled' => true, 'link_type' => 'custom', 'custom_url' => '/shop']]))->call('save')->assertHasNoFormErrors();

        $after = $this->stored($section);
        foreach (self::SHOWN as $label) {
            $this->assertSame($before[$label], $after[$label]);
        }
        $this->assertNull($after['Eid-ul-Fitr']);
    }

    public function test_the_form_says_a_picture_is_waiting_for_save_and_stops_saying_it_after(): void
    {
        $section = $this->section();
        $page = $this->open($section);

        $page->assertDontSee('Picture uploaded: press Save');
        $this->upload($page, 'Wedding season')->assertSee('Picture uploaded: press Save to keep it.');
        $page->call('save')->assertDontSee('Picture uploaded: press Save');
    }

    public function test_the_public_api_serves_all_four_pictures_under_the_image_field(): void
    {
        $section = $this->section();
        $page = $this->open($section);

        foreach (self::SHOWN as $label) {
            $this->upload($page, $label)->call('save');
        }

        $tiles = collect($this->getJson('/api/homepage-sections')->assertOk()->json('data'))->firstWhere('type', 'occasions')['tiles'];

        $this->assertSame(self::SHOWN, array_column($tiles, 'label'), 'the switched-off tile is left out, the shown ones stay in order');
        foreach ($tiles as $tile) {
            $this->assertSame(['label', 'image', 'href'], array_keys($tile));
            $this->assertStringContainsString('/storage/occasions/generated/', $tile['image']);
        }
        $this->assertCount(4, array_unique(array_column($tiles, 'image')));
    }

    public function test_a_picture_whose_file_is_gone_is_not_served_and_is_cleared_on_the_next_save(): void
    {
        $section = $this->section();
        $tiles = $section->tiles;
        $tiles[0]['image'] = 'occasions/01GONE.png';
        $tiles[1]['image'] = 'https://cdn.example.test/housewarming.webp';
        $section->forceFill(['tiles' => $tiles])->saveQuietly();

        $served = collect($this->getJson('/api/homepage-sections')->json('data'))->firstWhere('type', 'occasions')['tiles'];
        $this->assertNull($served[0]['image'], 'a dead path is an empty tile, not a broken picture');
        $this->assertSame('https://cdn.example.test/housewarming.webp', $served[1]['image'], 'an address is served as it is');

        $this->open($section)->call('save');
        $this->assertNull($this->stored($section)['Wedding season']);
    }
}
