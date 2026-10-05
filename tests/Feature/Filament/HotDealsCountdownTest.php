<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\HomepageSectionResource\Pages\CreateHomepageSection;
use App\Models\HomepageSection;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HotDealsCountdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A migration seeds the default homepage sections; start from an empty table.
        HomepageSection::query()->delete();
    }

    private function section(string $type, ?string $endsAt = null): HomepageSection
    {
        return HomepageSection::create([
            'type' => $type,
            'position' => HomepageSection::count() + 1,
            'is_enabled' => true,
            'deal_ends_at' => $endsAt,
        ]);
    }

    public function test_the_api_has_no_end_time_unless_one_is_set(): void
    {
        $this->section('hot_deals');

        $json = $this->getJson('/api/homepage-sections')->assertOk()->json('data');

        $this->assertNull($json[0]['deal_ends_at']);
    }

    public function test_the_api_returns_the_end_time_as_iso_8601_for_the_hot_deals_section(): void
    {
        $this->section('hot_deals', '2026-12-01 14:00:00');

        $json = $this->getJson('/api/homepage-sections')->json('data');

        $this->assertSame('2026-12-01T14:00:00+00:00', $json[0]['deal_ends_at']);
    }

    public function test_other_section_types_never_expose_an_end_time(): void
    {
        $this->section('bestsellers', '2026-12-01 14:00:00');

        $this->assertNull($this->getJson('/api/homepage-sections')->json('data.0.deal_ends_at'));
    }

    public function test_the_admin_form_reads_the_end_time_in_bangladesh_time_and_stores_utc(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));

        Livewire::test(CreateHomepageSection::class)
            ->fillForm(['type' => 'hot_deals', 'position' => 1, 'is_enabled' => true, 'deal_ends_at' => '2026-12-01 20:00:00'])
            ->call('create')
            ->assertHasNoFormErrors();

        // 20:00 in Dhaka (UTC+6) is 14:00 UTC.
        $this->assertSame('2026-12-01 14:00:00', HomepageSection::first()->deal_ends_at->utc()->format('Y-m-d H:i:s'));
    }
}
