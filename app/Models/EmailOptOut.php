<?php

namespace App\Models;

use App\Support\Contact;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone who asked not to get cart or review emails. Kept by email and by phone, and honoured at once.
 */
class EmailOptOut extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['email', 'phone', 'source'];

    public static function record(?string $email, ?string $phone, string $source): void
    {
        $email = Contact::email($email);
        $phone = Contact::phone($phone);

        if ($email === null && $phone === null) {
            return;
        }

        if (! static::covers($email, $phone)) {
            static::query()->create(['email' => $email, 'phone' => $phone, 'source' => $source]);
        }
    }

    /** Whether this email or this phone has opted out. */
    public static function covers(?string $email, ?string $phone): bool
    {
        $email = Contact::email($email);
        $phone = Contact::phone($phone);

        if ($email === null && $phone === null) {
            return false;
        }

        return static::query()
            ->where(function ($query) use ($email, $phone) {
                if ($email !== null) {
                    $query->orWhere('email', $email);
                }
                if ($phone !== null) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->exists();
    }
}
