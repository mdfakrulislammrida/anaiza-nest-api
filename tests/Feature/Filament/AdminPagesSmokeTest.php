<?php

namespace Tests\Feature\Filament;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminRecords;
use Tests\TestCase;

/**
 * Renders every admin screen as a signed-in admin with real records behind it, so a
 * column or form that only breaks once data exists (like the Roles list did) fails here
 * instead of showing up as a 500 in production.
 *
 * Resources and settings pages are discovered from the app directory, so a new one is
 * covered automatically -- and fails with an unhandled match until AdminRecords knows
 * how to build a record for it.
 */
class AdminPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string}>
     */
    public static function resources(): array
    {
        return self::discover('Resources', 'Resource');
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function settingsPages(): array
    {
        return self::discover('Pages');
    }

    /**
     * @return array<string, array{class-string}>
     */
    private static function discover(string $folder, string $suffix = ''): array
    {
        $cases = [];

        foreach (glob(__DIR__."/../../../app/Filament/{$folder}/*{$suffix}.php") as $file) {
            $name = basename($file, '.php');
            $cases[$name] = ['App\\Filament\\'.$folder.'\\'.$name];
        }

        return $cases;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Surface the real exception instead of a generic 500 page.
        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    public function test_discovery_finds_the_resources_and_pages(): void
    {
        $this->assertGreaterThanOrEqual(20, count(self::resources()));
        $this->assertGreaterThanOrEqual(6, count(self::settingsPages()));
        $this->assertSame(
            count(Filament::getPanel('admin')->getResources()),
            count(self::resources()),
            'A resource is registered but not found on disk (or the other way round).',
        );
    }

    #[DataProvider('resources')]
    public function test_every_page_of_a_resource_renders_with_a_record_present(string $resource): void
    {
        $record = AdminRecords::for($resource::getModel());

        // Give the screens that list related rows something to list.
        if ($record instanceof Customer) {
            AdminRecords::order(['customer_id' => $record->id]);
        }
        if ($record instanceof Product) {
            $record->variants()->create(['name' => 'Colour', 'value' => 'Red', 'stock_quantity' => 3]);
        }

        $pages = array_keys($resource::getPages());
        $this->assertContains('index', $pages);

        foreach ($pages as $page) {
            $url = $resource::getUrl($page, in_array($page, ['edit', 'view'], true) ? ['record' => $record] : []);

            $this->get($url)->assertSuccessful("{$resource} '{$page}' page ({$url}) did not render.");
        }
    }

    #[DataProvider('settingsPages')]
    public function test_every_settings_page_renders(string $page): void
    {
        $this->get($page::getUrl())->assertSuccessful("{$page} did not render.");
    }

    public function test_the_dashboard_renders(): void
    {
        $this->get('/admin')->assertSuccessful();
    }
}
