<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Support\InitialsAvatarProvider;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Batch ADM1, U1: an admin user can add, replace and remove their own photo in My profile; it shows in the top-right menu, initials stand in without calling
 * any outside service, and nobody can change another user's photo.
 */
class AdminProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['name' => 'Fakrul Islam', 'email' => 'fakrul@example.test'], $attributes));
    }

    private function photo(string $name = 'me.png', int $size = 600): UploadedFile
    {
        return UploadedFile::fake()->image($name, $size, $size);
    }

    private function profile(User $user)
    {
        $this->actingAs($user);

        return Livewire::test(EditProfile::class);
    }

    public function test_my_profile_is_in_the_menu_and_opens_for_the_signed_in_user(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $html = $this->get('/admin')->assertOk()->getContent();
        $page = $this->get('/admin/profile')->assertOk()->getContent();

        $this->assertStringContainsString('/admin/profile', $html);
        $this->assertStringContainsString('Photo', $page);
        $this->assertStringContainsString('fakrul@example.test', $page);
    }

    public function test_an_upload_is_saved_in_the_avatars_folder_and_shows_in_the_menu(): void
    {
        $user = $this->user();

        $this->profile($user)
            ->fillForm(['avatar' => $this->photo()])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $user->fresh()->avatar;

        $this->assertStringStartsWith('avatars/', $stored);
        Storage::disk('public')->assertExists($stored);

        $html = $this->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString($user->fresh()->getFilamentAvatarUrl(), $html);
        $this->assertStringContainsString($stored, $html);
        $this->assertStringNotContainsString('ui-avatars.com', $html);
    }

    public function test_replacing_the_photo_deletes_the_old_file(): void
    {
        Storage::disk('public')->put('avatars/old.png', $this->photo()->getContent());
        $user = $this->user(['avatar' => 'avatars/old.png']);

        // As the screen does it: the old picture is taken out of the field, the new one put in, then Save.
        $this->profile($user)
            ->set('data.avatar', [])
            ->fillForm(['avatar' => $this->photo('new.png')])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $user->fresh()->avatar;

        $this->assertNotSame('avatars/old.png', $stored);
        Storage::disk('public')->assertExists($stored);
        Storage::disk('public')->assertMissing('avatars/old.png');
    }

    public function test_removing_the_photo_deletes_the_file_and_falls_back_to_initials_with_no_outside_request(): void
    {
        Storage::disk('public')->put('avatars/old.png', $this->photo()->getContent());
        $user = $this->user(['avatar' => 'avatars/old.png']);

        $this->profile($user)
            ->fillForm(['avatar' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($user->fresh()->avatar);
        Storage::disk('public')->assertMissing('avatars/old.png');

        $html = $this->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        $this->assertStringNotContainsString('ui-avatars.com', $html);
        Http::assertNothingSent();
    }

    public function test_a_user_with_no_photo_shows_their_initials_drawn_locally(): void
    {
        $user = $this->user();

        $this->assertNull($user->getFilamentAvatarUrl());

        $url = filament()->getUserAvatarUrl($user);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
        $svg = base64_decode(substr($url, strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('>FI<', $svg);
        $this->assertStringNotContainsString('http', str_replace('http://www.w3.org/2000/svg', '', $svg));
    }

    public function test_which_initials_are_drawn(): void
    {
        $this->assertSame('FI', InitialsAvatarProvider::initials('Fakrul Islam'));
        $this->assertSame('R', InitialsAvatarProvider::initials('Rafi'));
        $this->assertSame('MA', InitialsAvatarProvider::initials('  mohammad abdul alim '));
        $this->assertSame('?', InitialsAvatarProvider::initials(''));
        $this->assertSame('?', InitialsAvatarProvider::initials('   '));
        $this->assertSame('<', '<'); // the letters are escaped in the picture, see the next line
        $this->assertStringNotContainsString('<script', base64_decode(substr(InitialsAvatarProvider::dataUri('<script>'), 26)));
    }

    public function test_only_jpg_png_and_webp_up_to_2_mb_are_accepted(): void
    {
        $user = $this->user();
        $component = $this->profile($user);
        $field = $component->instance()->form->getComponent('data.avatar');

        $this->assertSame(['image/jpeg', 'image/png', 'image/webp'], $field->getAcceptedFileTypes());
        $this->assertSame(2048, $field->getMaxSize());
        $this->assertSame('1:1', $field->getImageCropAspectRatio());

        $component->fillForm(['avatar' => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf')])->call('save')->assertHasFormErrors(['avatar']);
        $component->fillForm(['avatar' => UploadedFile::fake()->image('huge.png', 800, 800)->size(3000)])->call('save')->assertHasFormErrors(['avatar']);
        $component->fillForm(['avatar' => UploadedFile::fake()->image('anim.gif', 400, 400)])->call('save')->assertHasFormErrors(['avatar']);

        $this->assertNull($user->fresh()->avatar);
    }

    public function test_one_user_cannot_change_another_users_photo(): void
    {
        Storage::disk('public')->put('avatars/theirs.png', $this->photo()->getContent());
        $other = $this->user(['email' => 'other@example.test', 'avatar' => 'avatars/theirs.png']);
        $me = $this->user(['email' => 'me@example.test']);

        // My profile only ever edits the signed-in user.
        $this->profile($me)->fillForm(['avatar' => $this->photo('mine.png')])->call('save')->assertHasNoFormErrors();

        $this->assertSame('avatars/theirs.png', $other->fresh()->avatar);
        Storage::disk('public')->assertExists('avatars/theirs.png');
        $this->assertStringStartsWith('avatars/', $me->fresh()->avatar);

        // The Users screen has no photo field, and one sent anyway changes nothing.
        $this->actingAs($me);
        $edit = Livewire::test(EditUser::class, ['record' => $other->getKey()]);
        $this->assertNull($edit->instance()->form->getComponent('data.avatar'));

        $edit->fillForm(['avatar' => 'avatars/evil.png', 'name' => 'Renamed'])->call('save');
        $this->assertSame('avatars/theirs.png', $other->fresh()->avatar);
    }

    public function test_deleting_a_user_removes_their_photo_file_and_nothing_outside_the_avatars_folder(): void
    {
        Storage::disk('public')->put('avatars/gone.png', $this->photo()->getContent());
        Storage::disk('public')->put('products/keep.png', $this->photo()->getContent());
        $user = $this->user(['avatar' => 'avatars/gone.png']);
        $stray = $this->user(['email' => 'stray@example.test', 'avatar' => 'products/keep.png']);

        $user->delete();
        $stray->delete();

        Storage::disk('public')->assertMissing('avatars/gone.png');
        Storage::disk('public')->assertExists('products/keep.png');
    }

    public function test_the_users_list_has_a_small_round_picture_per_user(): void
    {
        Storage::disk('public')->put('avatars/a.png', $this->photo()->getContent());
        $with = $this->user(['avatar' => 'avatars/a.png']);
        $without = $this->user(['email' => 'plain@example.test', 'name' => 'Rafi Khan']);
        $this->actingAs($with);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$with, $without])
            ->assertTableColumnStateSet('photo', $with->getFilamentAvatarUrl(), $with)
            ->assertTableColumnStateSet('photo', filament()->getUserAvatarUrl($without), $without);
    }

    public function test_the_migration_is_safe_on_existing_rows_and_reversible(): void
    {
        $user = $this->user();
        $migration = require database_path('migrations/2026_11_04_100000_add_avatar_to_users_table.php');

        $this->assertTrue(Schema::hasColumn('users', 'avatar'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'avatar'));
        $this->assertSame(1, DB::table('users')->where('email', 'fakrul@example.test')->count(), 'the row is untouched');

        $migration->up();
        $this->assertTrue(Schema::hasColumn('users', 'avatar'));
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('avatar'), 'an existing row starts with no photo');
    }
}
