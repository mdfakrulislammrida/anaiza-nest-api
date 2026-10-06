<?php

namespace App\Models;

use App\Support\WalletPayments;
use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    protected $fillable = [
        'bkash_number',
        'nagad_number',
        'rocket_number',
        'cod_enabled',
        'bkash_instructions',
        'nagad_instructions',
        'rocket_instructions',
        'bkash_logo',
        'nagad_logo',
        'rocket_logo',
        'payment_notify_email',
    ];

    /**
     * The merchant number a customer is asked to pay for this method; null when the admin has set none.
     */
    public function numberFor(string $method): ?string
    {
        $number = trim((string) $this->{"{$method}_number"});

        return $number === '' ? null : $number;
    }

    /**
     * The admin's steps for this method, or the built-in wording when they have not written any.
     */
    public function instructionsFor(string $method): string
    {
        $text = (string) $this->{"{$method}_instructions"};

        return trim(strip_tags($text)) === '' ? WalletPayments::defaultInstructions($method) : $text;
    }

    /**
     * Eloquent does not re-read a row after inserting it, so a settings row created on first read
     * (the API does that) would otherwise report cash on delivery as null instead of on.
     */
    protected $attributes = [
        'cod_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'cod_enabled' => 'boolean',
        ];
    }
}
