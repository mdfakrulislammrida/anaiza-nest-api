<?php

namespace App\Support;

use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Page;
use App\Models\SlugRedirect;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place that knows what an address (slug) may be and where each kind of page lives on the storefront.
 *
 * A slug is lower-case letters, numbers and hyphens, never one the storefront already uses for something else, and not shared with another page of the same kind
 * (nor with an address another page used to have, which still redirects). The path is the same everywhere the API links to the page: the lists, the single
 * answers, the homepage tiles, the sitemap and the product feeds.
 */
final class Slugs
{
    /** type => the path in front of the slug on the storefront */
    public const PREFIXES = ['category' => '/category/', 'page' => '/pages/', 'landing_page' => '/lp/', 'product' => '/product/'];

    /** type => the model */
    private const MODELS = ['category' => Category::class, 'page' => Page::class, 'landing_page' => LandingPage::class];

    /**
     * The first part of every address the storefront (src/app) or the host already answers, and the words that would be confusing next to them. A slug that equals one
     * of these is refused.
     */
    public const RESERVED = [
        'account', 'admin', 'api', 'article', 'auth', 'blog', 'cart', 'category', 'checkout', 'contact', 'corporate-gifting', 'faq', 'favicon', 'gift-finder',
        'hot-deals', 'llms', 'lp', 'new', 'occasion', 'occasions', 'order-confirmation', 'page', 'pages', 'product', 'products', 'reset-password', 'robots', 'search',
        'shop', 'sitemap', 'special-prices', 'storage', 'track-order', 'wishlist',
    ];

    public static function path(string $type, string $slug): string
    {
        return (self::PREFIXES[$type] ?? '/').$slug;
    }

    /** Why a slug may not be used for this record, in words for the admin, or null. $ignoreId is the record being edited. */
    public static function problem(string $type, ?string $slug, ?int $ignoreId = null): ?string
    {
        $slug = (string) $slug;

        if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return 'Use lower-case letters, numbers and single hyphens only, for example eid-gifts. No spaces, capitals, accents, slashes or hyphens at the ends.';
        }

        if (in_array($slug, self::RESERVED, true)) {
            return "\"{$slug}\" is an address the shop already uses for something else. Choose another, for example {$slug}-gifts.";
        }

        $model = self::MODELS[$type] ?? null;

        if ($model !== null) {
            $taken = $model::query()->where('slug', $slug)->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();

            if ($taken) {
                return "Another page already has the address \"{$slug}\". Every address must be different.";
            }

            $used = SlugRedirect::query()->where('type', $type)->where('old_slug', $slug)->when($ignoreId !== null, fn ($q) => $q->where('model_id', '!=', $ignoreId))->exists();

            if ($used) {
                return "\"{$slug}\" is an old address of another page, and visitors are still sent from it to that page. Choose a different one.";
            }
        }

        return null;
    }

    /**
     * Finds a record by the address in a link: its current slug, or one it used to have.
     *
     * @return array{model: Model, redirected: bool}|null
     */
    public static function find(string $type, string $slug): ?array
    {
        $class = self::MODELS[$type];
        $found = $class::query()->where('slug', $slug)->first();

        if ($found !== null) {
            return ['model' => $found, 'redirected' => false];
        }

        $id = SlugRedirect::query()->where('type', $type)->where('old_slug', $slug)->value('model_id');
        $moved = $id === null ? null : $class::query()->find($id);

        return $moved === null ? null : ['model' => $moved, 'redirected' => true];
    }

    /** The slug a link should use today for a page whose slug may have changed since the link was written; the same slug when nothing is known. */
    public static function current(string $type, string $slug): string
    {
        $found = self::find($type, $slug);

        return $found === null ? $slug : (string) $found['model']->slug;
    }

    /**
     * The hint a single-page answer carries when the link used an old address.
     *
     * @return array{from: string, to: string, status: int}|null
     */
    public static function hint(string $type, string $asked, Model $model): ?array
    {
        return $asked === (string) $model->slug ? null : ['from' => self::path($type, $asked), 'to' => self::path($type, (string) $model->slug), 'status' => 301];
    }

    /**
     * Every old address and where it goes now, for the storefront to turn into redirects in one go.
     *
     * @return list<array{type: string, from: string, to: string}>
     */
    public static function redirects(): array
    {
        $out = [];

        foreach (SlugRedirect::query()->orderBy('id')->get() as $row) {
            $class = self::MODELS[$row->type] ?? null;
            $slug = $class === null ? null : $class::query()->whereKey($row->model_id)->value('slug');

            if ($slug !== null) {
                $out[] = ['type' => $row->type, 'from' => self::path($row->type, $row->old_slug), 'to' => self::path($row->type, (string) $slug)];
            }
        }

        return $out;
    }
}
