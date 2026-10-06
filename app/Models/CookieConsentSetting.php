<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cookie banner. In "notice" mode tracking runs and the banner informs; in "opt_in" mode analytics and
 * marketing wait until the visitor chooses. The wording is editable and starts as short, plain defaults.
 */
class CookieConsentSetting extends Model
{
    public const MODES = [
        'notice' => 'Notice only: tracking runs and the banner informs',
        'opt_in' => 'Opt-in required: analytics and marketing wait for consent',
    ];

    protected $fillable = [
        'enabled',
        'mode',
        'banner_text',
        'privacy_page_id',
        'privacy_link_label',
        'accept_label',
        'reject_label',
        'customize_label',
        'save_label',
    ];

    /**
     * Eloquent does not re-read a row after inserting it, so a row created on first read would otherwise
     * report these as null.
     */
    protected $attributes = [
        'enabled' => true,
        'mode' => 'notice',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * The settings row, or an unsaved one with the defaults when none exists yet.
     */
    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    public function privacyPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'privacy_page_id');
    }

    /**
     * True only while the banner is on and set to opt-in: that is the one case where marketing waits for consent.
     */
    public function requiresOptIn(): bool
    {
        return $this->enabled && $this->mode === 'opt_in';
    }

    public function bannerText(): string
    {
        return filled($this->banner_text) ? trim($this->banner_text) : self::defaultBannerText();
    }

    public function label(string $key): string
    {
        $value = $this->{$key === 'privacy' ? 'privacy_link_label' : "{$key}_label"};

        return filled($value) ? trim($value) : self::defaultLabels()[$key];
    }

    public static function defaultBannerText(): string
    {
        return 'We use cookies to keep the shop working and to see how it is used. You choose what else we may use.';
    }

    /**
     * @return array{accept: string, reject: string, customize: string, save: string, privacy: string}
     */
    public static function defaultLabels(): array
    {
        return [
            'accept' => 'Accept all',
            'reject' => 'Reject non-essential',
            'customize' => 'Customize',
            'save' => 'Save choices',
            'privacy' => 'Privacy policy',
        ];
    }
}
