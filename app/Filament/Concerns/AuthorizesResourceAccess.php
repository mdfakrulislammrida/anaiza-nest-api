<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Gates an entire Filament resource behind a single permission key from
 * Role::PERMISSIONS, so staff accounts can be limited to only the parts
 * of the admin panel their role grants them.
 */
trait AuthorizesResourceAccess
{
    public static function getPermissionKey(): string
    {
        return static::$permissionKey;
    }

    public static function can(string $action, ?Model $record = null): bool
    {
        return Auth::user()?->hasPermission(static::getPermissionKey()) ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::can('viewAny');
    }

    public static function canCreate(): bool
    {
        return static::can('create');
    }

    public static function canEdit(Model $record): bool
    {
        return static::can('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::can('delete', $record);
    }

    public static function canDeleteAny(): bool
    {
        return static::can('deleteAny');
    }
}
