<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The picture shown for an admin user who has not uploaded one: their initials in a round tile, drawn right here as a small SVG in the address itself.
 * Filament's own default asks ui-avatars.com for it on every page; this makes no request to anyone.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        return self::dataUri(self::initials((string) Filament::getNameForDefaultAvatar($record)));
    }

    /** "Fakrul Islam" is "FI", "Rafi" is "R", nothing at all is "?". At most two letters, in capitals. */
    public static function initials(string $name): string
    {
        $letters = collect(preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->filter(fn (string $letter): bool => preg_match('/[\pL\pN]/u', $letter) === 1)
            ->values();

        $initials = $letters->count() > 1 ? $letters->first().$letters->last() : (string) $letters->first();

        return $initials !== '' ? $initials : '?';
    }

    public static function dataUri(string $initials): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">'
            .'<rect width="64" height="64" rx="32" fill="#44403c"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-size="'.(mb_strlen($initials) > 1 ? 26 : 30).'" font-weight="600" fill="#ffffff">'.e($initials).'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
