<?php

namespace App\Models;

use App\Support\Email\TemplateDefinitions;
use Illuminate\Database\Eloquent\Model;

/**
 * The admin's wording for one transactional email. A row only ever holds the admin's own text; the built-in wording
 * (App\Support\Email\TemplateDefinitions) is what is used whenever a row is switched off, missing, or has a blank field.
 */
class EmailTemplate extends Model
{
    public const ORDER_CONFIRMATION = TemplateDefinitions::CONFIRMATION;

    protected $fillable = [
        'key',
        'subject',
        'intro_html',
        'closing_html',
        'footer_note',
        'use_custom_html',
        'custom_html',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'use_custom_html' => 'boolean',
        ];
    }

    /**
     * Makes sure every template has a row to list and edit. They start switched off, so nothing changes until the admin
     * chooses to use their own wording.
     */
    public static function ensureAll(): void
    {
        $have = static::query()->pluck('key')->all();

        foreach (array_diff(TemplateDefinitions::KEYS, $have) as $key) {
            static::query()->create(['key' => $key, 'is_active' => false]);
        }
    }

    public function label(): string
    {
        return TemplateDefinitions::label($this->key);
    }

    /**
     * What the admin form starts from: their text where they have written some, the built-in wording elsewhere.
     *
     * @return array<string, mixed>
     */
    public function formState(): array
    {
        $defaults = TemplateDefinitions::defaults($this->key);

        return [
            'is_active' => $this->is_active,
            'subject' => filled($this->subject) ? $this->subject : $defaults['subject'],
            'intro_html' => filled($this->intro_html) ? $this->intro_html : $defaults['intro_html'],
            'closing_html' => $this->closing_html ?? $defaults['closing_html'],
            'footer_note' => $this->footer_note ?? $defaults['footer_note'],
            'use_custom_html' => $this->use_custom_html,
            'custom_html' => $this->custom_html,
        ];
    }
}
