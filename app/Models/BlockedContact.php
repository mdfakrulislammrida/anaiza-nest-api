<?php

namespace App\Models;

use App\Support\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone number, email address or piece of an address the shop will not take online orders from. A blocked buyer is told,
 * plainly and without saying why, to message the shop instead.
 */
class BlockedContact extends Model
{
    public const PHONE = 'phone';

    public const EMAIL = 'email';

    public const ADDRESS = 'address';

    public const KINDS = [
        self::PHONE => 'Phone number',
        self::EMAIL => 'Email address',
        self::ADDRESS => 'Part of an address',
    ];

    protected $fillable = ['kind', 'value', 'note', 'created_by'];

    /** What is stored: a phone as 01XXXXXXXXX, an email lower case, an address fragment lower case. */
    public static function normalise(string $kind, string $value): string
    {
        $value = trim($value);

        return match ($kind) {
            self::PHONE => Contact::phone($value) ?? $value,
            self::EMAIL => Contact::email($value) ?? mb_strtolower($value),
            default => mb_strtolower($value),
        };
    }

    /**
     * The block that applies to this buyer, if any.
     *
     * @param  array{phone?: ?string, email?: ?string, address?: ?string}  $buyer
     */
    public static function matching(array $buyer): ?self
    {
        $phone = isset($buyer['phone']) ? Contact::phone($buyer['phone']) : null;
        $email = isset($buyer['email']) ? Contact::email($buyer['email']) : null;
        $address = mb_strtolower(trim((string) ($buyer['address'] ?? '')));

        $blocks = static::query()->get();

        foreach ($blocks as $block) {
            $hit = match ($block->kind) {
                self::PHONE => $phone !== null && $phone === $block->value,
                self::EMAIL => $email !== null && $email === $block->value,
                self::ADDRESS => $address !== '' && $block->value !== '' && str_contains($address, $block->value),
                default => false,
            };

            if ($hit) {
                return $block;
            }
        }

        return null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        // Stored in the one form it is compared in, whatever way it was typed.
        static::saving(function (BlockedContact $block): void {
            $block->value = self::normalise($block->kind, (string) $block->value);
        });
    }
}
