<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\NetworkCheck;
use App\Filament\Resources\BlockedContactResource\Pages\ManageBlockedContacts;
use App\Filament\Resources\MediaItemResource\Pages\CreateMediaItem;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\BlockedContact;
use App\Models\Role;
use App\Models\ShippingZone;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\AdminRecords;
use Tests\TestCase;

class AdminHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role_id' => null], $attributes));
    }

    // ---- passwords and accounts

    public function test_a_new_admin_account_needs_a_strong_password(): void
    {
        $this->actingAs($this->admin());

        foreach (['short1', 'longpasswordbutnonumbers', '1234567890123456'] as $weak) {
            Livewire::test(CreateUser::class)
                ->fillForm(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => $weak])
                ->call('create')
                ->assertHasFormErrors(['password']);
        }

        $this->assertSame(1, User::count());

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => 'a-good-password-2026'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('a-good-password-2026', User::where('email', 'staff@example.com')->value('password')));
    }

    public function test_the_sample_admin_is_never_seeded_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        // Called directly: the seed command itself asks for confirmation in production.
        (new DatabaseSeeder)->run();

        $this->assertSame(0, User::where('email', 'test@example.com')->count());
        $this->assertSame(2, ShippingZone::count(), 'the shipping zones are still created');

        $this->app['env'] = 'local';
        User::query()->delete();
        (new DatabaseSeeder)->run();
        $this->assertSame(1, User::where('email', 'test@example.com')->count());
    }

    // ---- uploads

    public function test_the_media_library_refuses_scripts_and_svg(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());

        foreach ([
            UploadedFile::fake()->createWithContent('payload.php', '<?php echo 1;'),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'),
            UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
        ] as $file) {
            Livewire::test(CreateMediaItem::class)
                ->fillForm(['path' => $file])
                ->call('create')
                ->assertHasFormErrors(['path']);
        }
    }

    public function test_the_media_library_still_takes_a_picture(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());

        Livewire::test(CreateMediaItem::class)
            ->fillForm(['path' => UploadedFile::fake()->image('banner.png', 100, 100)])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    // ---- blocked buyers

    public function test_block_this_phone_adds_the_orders_number_to_the_blocklist(): void
    {
        $this->actingAs($this->admin());
        $order = AdminRecords::order();
        $order->forceFill(['ship_phone' => '01711234567'])->save();

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->callAction('blockPhone');

        $this->assertSame(1, BlockedContact::where('kind', BlockedContact::PHONE)->where('value', '01711234567')->count());
        $this->assertStringContainsString('#'.$order->id, (string) BlockedContact::first()->note);

        // Doing it twice does not add a second row.
        Livewire::test(EditOrder::class, ['record' => $order->getKey()])->callAction('blockPhone');
        $this->assertSame(1, BlockedContact::count());
    }

    public function test_a_blocked_buyer_is_added_and_validated_from_the_admin(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(ManageBlockedContacts::class)
            ->callAction('create', ['kind' => 'phone', 'value' => 'not a phone'])
            ->assertHasActionErrors(['value']);

        Livewire::test(ManageBlockedContacts::class)
            ->callAction('create', ['kind' => 'phone', 'value' => '+880 1711-234567', 'note' => 'Prank orders'])
            ->assertHasNoActionErrors();

        $block = BlockedContact::first();
        $this->assertSame('01711234567', $block->value);
        $this->assertNotNull($block->created_by);
    }

    public function test_blocked_buyers_need_their_own_permission(): void
    {
        $editor = $this->admin(['role_id' => Role::create([
            'name' => 'Packer', 'slug' => 'packer', 'permissions' => ['orders.manage'],
        ])->id]);
        $this->actingAs($editor)->get('/admin/blocked-contacts')->assertForbidden();

        $manager = $this->admin(['role_id' => Role::create([
            'name' => 'Safety', 'slug' => 'safety', 'permissions' => ['blocked_contacts.manage'],
        ])->id]);
        $this->actingAs($manager)->get('/admin/blocked-contacts')->assertOk();
    }

    // ---- order protection setting

    public function test_the_daily_order_limit_has_a_default_and_can_be_set(): void
    {
        $this->assertSame(5, SiteSetting::create(['site_name' => 'Anaiza Nest'])->fresh()->max_orders_per_phone_per_day);
    }

    // ---- network check

    public function test_the_network_check_page_shows_the_address_the_shop_sees(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(NetworkCheck::class)
            ->assertSuccessful()
            ->assertSee('Network check');

        $this->get('/admin/network-check')->assertOk()->assertSee('REMOTE_ADDR', false);
    }

    public function test_the_network_check_page_needs_the_integration_permission(): void
    {
        $role = Role::create(['name' => 'Packer', 'slug' => 'packer2', 'permissions' => ['orders.manage']]);
        $this->actingAs($this->admin(['role_id' => $role->id]))->get('/admin/network-check')->assertForbidden();
    }

    // ---- the server check command

    public function test_security_check_flags_a_default_password_and_a_sample_admin(): void
    {
        User::factory()->create(['email' => 'test@example.com', 'password' => Hash::make('password'), 'role_id' => null]);

        $this->artisan('security:check')
            ->expectsOutputToContain('No admin uses the password "password"')
            ->expectsOutputToContain('No sample admin account')
            ->assertFailed();
    }

    public function test_security_check_passes_the_account_checks_for_a_strong_admin(): void
    {
        User::factory()->create(['email' => 'owner@anaizanest.com', 'password' => Hash::make('a-long-unguessable-pass-9'), 'role_id' => null]);

        $this->artisan('security:check')
            ->expectsOutputToContain('[ OK ] No admin uses the password "password"')
            ->expectsOutputToContain('[ OK ] No sample admin account')
            ->run();
    }
}
