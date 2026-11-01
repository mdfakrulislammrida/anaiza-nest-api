<?php

namespace App\Models\Concerns;

use App\Models\SlugRedirect;
use App\Support\Slugs;

/**
 * Remembers the addresses a record used to have, so changing a slug never breaks a link that is already out there (an email, an ad, a search result).
 * The model says what it is with slugType() (category, page, landing_page).
 */
trait RemembersSlugs
{
    abstract public static function slugType(): string;

    public static function bootRemembersSlugs(): void
    {
        static::updated(function ($model): void {
            if (! $model->wasChanged('slug')) {
                return;
            }

            $old = (string) $model->getOriginal('slug');
            $type = static::slugType();

            // Coming back to an address it had before: that old address is the current one again, not a redirect.
            SlugRedirect::query()->where('type', $type)->where('old_slug', $model->slug)->delete();

            if ($old !== '' && $old !== $model->slug) {
                SlugRedirect::query()->updateOrCreate(['type' => $type, 'old_slug' => $old], ['model_id' => $model->getKey()]);
            }
        });

        static::deleted(function ($model): void {
            SlugRedirect::query()->where('type', static::slugType())->where('model_id', $model->getKey())->delete();
        });
    }

    /** @return list<string> */
    public function previousSlugs(): array
    {
        return SlugRedirect::query()->where('type', static::slugType())->where('model_id', $this->getKey())->orderBy('id')->pluck('old_slug')->all();
    }

    /** The address on the storefront, from the current slug. */
    public function path(): string
    {
        return Slugs::path(static::slugType(), (string) $this->slug);
    }
}
