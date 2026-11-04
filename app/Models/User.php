<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\MediaUrl;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role_id', 'landing_scripts_allowed', 'avatar'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** The folder on the public disk where profile photos live. Only files in it are ever deleted by the cleanup below. */
    public const AVATAR_DIRECTORY = 'avatars/';

    protected static function booted(): void
    {
        // Replacing or removing the photo deletes the old file, and so does deleting the user; whatever the screen did, no orphan stays on the disk.
        static::updating(function (User $user): void {
            if ($user->isDirty('avatar')) {
                self::forgetAvatarFile($user->getOriginal('avatar'), $user->avatar);
            }
        });

        static::deleted(function (User $user): void {
            self::forgetAvatarFile($user->avatar, null);
        });
    }

    private static function forgetAvatarFile(?string $old, ?string $current): void
    {
        if (blank($old) || $old === $current || ! str_starts_with($old, self::AVATAR_DIRECTORY) || str_contains($old, '..')) {
            return;
        }

        Storage::disk('public')->delete($old);
    }

    /** The photo's address for the top-right menu, or null (Filament then asks the panel's local initials provider; no outside service is called). */
    public function getFilamentAvatarUrl(): ?string
    {
        return MediaUrl::resolve($this->avatar);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * A user with no role assigned is an unrestricted super-admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role_id === null;
    }

    public function hasPermission(string $key): bool
    {
        return $this->isSuperAdmin() || ($this->role?->hasPermission($key) ?? false);
    }
}
