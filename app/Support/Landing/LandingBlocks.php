<?php

namespace App\Support\Landing;

use App\Http\Resources\ProductResource;
use App\Http\Resources\ReviewResource;
use App\Models\Faq;
use App\Models\LandingPage;
use App\Models\MediaItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Support\HtmlSanitizer;
use App\Support\MediaUrl;
use Dom\HTMLDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What a landing page's blocks mean: cleaning the HTML ones when the page is saved, and turning every block into the
 * plain data the storefront draws (products, reviews and FAQs already resolved, hidden blocks left out).
 */
final class LandingBlocks
{
    /** The permission a role can hold, and the switch a super-admin can set on a person. Both are off for everyone by default. */
    public const SCRIPTS_PERMISSION = 'landing_pages.allow_scripts';

    public const MAX_HTML_BYTES = 1048576;

    public static function canAllowScripts(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        // Not hasPermission(): that is true for every permission when the person has no role (a super-admin). Scripts
        // are only ever allowed by an explicit grant.
        return (bool) $user->landing_scripts_allowed || ($user->role?->hasPermission(self::SCRIPTS_PERMISSION) ?? false);
    }

    // ---- saving

    /**
     * Cleans the HTML blocks of $page in place and records what was removed in $page->notes.
     */
    public static function process(LandingPage $page, ?User $user): void
    {
        $blocks = array_values($page->blocks ?? []);
        $notes = [];
        $mayAllowScripts = self::canAllowScripts($user);

        // The order form takes payment details: scripts are never allowed on a focus-mode page that shows it.
        $focusOrderForm = $page->layout === LandingPage::FOCUS
            && collect($blocks)->contains(fn ($block) => ($block['type'] ?? null) === 'order_form' && ($block['data']['is_visible'] ?? true));

        foreach ($blocks as $index => $block) {
            $type = $block['type'] ?? null;
            $data = $block['data'] ?? [];
            $label = (LandingPage::BLOCK_TYPES[$type] ?? (string) $type).' (block '.($index + 1).')';

            if (! in_array($type, ['rich_text', 'custom_html'], true)) {
                continue;
            }

            $data['uid'] = $data['uid'] ?? Str::lower(Str::random(8));
            $scope = 'lp-'.$data['uid'];

            if ($type === 'rich_text') {
                $result = HtmlCleaner::clean((string) ($data['content'] ?? ''), $scope);
                $data['content_clean'] = HtmlSanitizer::downgradeH1($result['html']);
                $blocks[$index]['data'] = $data;
                array_push($notes, ...self::lines($label, $result));

                continue;
            }

            $source = (string) ($data['html'] ?? '');

            if (strlen($source) > self::MAX_HTML_BYTES) {
                $source = substr($source, 0, self::MAX_HTML_BYTES);
                $notes[] = "{$label}: the HTML was longer than 1 MB and was cut off.";
            }

            $requested = (bool) ($data['allow_scripts'] ?? false);
            $allowed = $requested && $mayAllowScripts && ! $focusOrderForm;

            if ($requested && ! $mayAllowScripts) {
                $notes[] = "{$label}: scripts were asked for, but you do not have the permission to allow them, so they were removed.";
            } elseif ($requested && $focusOrderForm) {
                $notes[] = "{$label}: scripts were kept off because this page shows the order form in Focus mode. Choose the Normal layout, or take the order form out, to use scripts.";
            }

            $result = HtmlCleaner::clean($source, $scope, $allowed, self::assets($data, $user));
            $data['html'] = $source;
            $data['html_clean'] = $result['html'];
            $data['scripts_active'] = $result['has_scripts'];
            $blocks[$index]['data'] = $data;

            if ($result['has_scripts']) {
                $notes[] = "{$label}: WARNING. This block runs scripts you allowed. Every visitor's browser runs them, so keep them only if you wrote the code or fully trust its source. Scripts from other sites also need that site added to the Content-Security-Policy.";
            }

            array_push($notes, ...self::lines($label, $result));
        }

        $page->blocks = $blocks;
        $page->notes = $notes;
    }

    /**
     * @param  array{removed: list<string>, unresolved: list<string>}  $result
     * @return list<string>
     */
    private static function lines(string $label, array $result): array
    {
        $lines = array_map(fn (string $line): string => "{$label}: {$line}", $result['removed']);

        foreach ($result['unresolved'] as $path) {
            $lines[] = "{$label}: image not found among the uploaded images: {$path}. Upload it under \"Images used by this HTML\" with the same file name.";
        }

        return $lines;
    }

    /**
     * File name (lower case) => address, for the images uploaded alongside an HTML block. They are also entered in the
     * media library.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function assets(array $data, ?User $user): array
    {
        $map = [];

        foreach ((array) ($data['assets'] ?? []) as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $map[strtolower(basename($path))] = (string) MediaUrl::resolve($path);

            $disk = Storage::disk('public');

            if ($disk->exists($path)) {
                MediaItem::query()->firstOrCreate(
                    ['disk' => 'public', 'path' => $path],
                    [
                        'original_name' => basename($path),
                        'mime_type' => (string) ($disk->mimeType($path) ?: 'application/octet-stream'),
                        'size' => (int) $disk->size($path),
                        'uploaded_by' => $user?->id,
                    ],
                );
            }
        }

        return $map;
    }

    /**
     * What an uploaded .html file contributes to the code box: its styles and everything inside <body>. Shown as it is, so
     * the author can still edit it; it is cleaned when the page is saved.
     */
    public static function importHtmlFile(string $contents): string
    {
        $contents = trim($contents);

        if ($contents === '') {
            return '';
        }

        $document = HTMLDocument::createFromString($contents, LIBXML_NOERROR);
        $out = '';

        foreach ($document->querySelectorAll('style') as $style) {
            $out .= '<style>'.$style->textContent."</style>\n";
            $style->remove();
        }

        return $out.trim($document->body->innerHTML);
    }

    // ---- reading

    /**
     * The blocks as the storefront draws them: hidden blocks and blocks with nothing to show are left out.
     *
     * @return list<array<string, mixed>>
     */
    public static function payload(LandingPage $page): array
    {
        $blocks = collect($page->blocks ?? [])->filter(fn ($block) => is_array($block) && ($block['data']['is_visible'] ?? true));

        // The reviews block draws from the pieces the page sells.
        $shown = collect();
        $out = [];

        foreach ($blocks as $index => $block) {
            $type = (string) ($block['type'] ?? '');
            $data = (array) ($block['data'] ?? []);
            $entry = match ($type) {
                'hero' => self::hero($data),
                'rich_text' => ['html' => (string) ($data['content_clean'] ?? '')],
                'custom_html' => [
                    'html' => (string) ($data['html_clean'] ?? ''),
                    'scope_class' => 'lp-'.($data['uid'] ?? 'x'),
                    'scripts' => (bool) ($data['scripts_active'] ?? false),
                ],
                'products' => self::products($data, $shown),
                'benefits' => self::benefits($data),
                'faq' => self::faq($data),
                'reviews' => ['heading' => self::text($data['heading'] ?? null), 'limit' => max(1, min((int) ($data['limit'] ?? 6), 20)), '_deferred' => true],
                'order_form' => ['heading' => self::text($data['heading'] ?? null), 'intro' => self::text($data['intro'] ?? null)],
                'countdown' => self::countdown($page, $data),
                default => null,
            };

            if ($entry === null) {
                continue;
            }

            $out[] = ['key' => ($data['uid'] ?? 'b').'-'.$index, 'type' => $type] + $entry;
        }

        // Reviews last, so they know every product shown above and below them.
        $final = [];

        foreach ($out as $entry) {
            if ($entry['type'] === 'reviews') {
                $resolved = self::reviews($entry, $shown);

                if ($resolved === null) {
                    continue;
                }

                $entry = $resolved;
            }

            $final[] = $entry;
        }

        return $final;
    }

    private static function text(mixed $value): ?string
    {
        return filled($value) ? trim((string) $value) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function hero(array $data): array
    {
        return [
            'headline' => self::text($data['headline'] ?? null),
            'subline' => self::text($data['subline'] ?? null),
            'image_desktop' => MediaUrl::resolve($data['image_desktop'] ?? null),
            'image_mobile' => MediaUrl::resolve($data['image_mobile'] ?? null),
            'button_text' => self::text($data['button_text'] ?? null),
            'button_target' => in_array($data['button_target'] ?? null, ['products', 'order', 'url'], true) ? $data['button_target'] : 'products',
            'button_url' => self::text($data['button_url'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Product>  $shown
     * @return array<string, mixed>|null
     */
    private static function products(array $data, $shown): ?array
    {
        $query = Product::query()->where('is_active', true)->withRatings()
            ->with(['category', 'brand', 'images', 'variants.image', 'tags', 'labels']);

        if (($data['source'] ?? 'hand') === 'category') {
            $products = $query->where('category_id', (int) ($data['category_id'] ?? 0))
                ->orderByRaw('stock_quantity > 0 desc')->orderBy('name')
                ->limit(max(1, min((int) ($data['limit'] ?? 12), 48)))->get();
        } else {
            $ids = array_values(array_filter(array_map('intval', (array) ($data['product_ids'] ?? []))));
            $found = $query->whereIn('id', $ids)->get()->keyBy('id');
            $products = collect($ids)->map(fn (int $id) => $found->get($id))->filter()->values();
        }

        if ($products->isEmpty()) {
            return null;
        }

        // Each variant reads its parent for the price and stock it inherits.
        $products->each(fn (Product $product) => $product->variants->each(fn ($variant) => $variant->setRelation('product', $product)));
        $products->each(fn (Product $product) => $shown->push($product));

        return [
            'heading' => self::text($data['heading'] ?? null),
            'layout' => ($data['layout'] ?? 'grid') === 'list' ? 'list' : 'grid',
            'show_price' => (bool) ($data['show_price'] ?? true),
            'show_button' => (bool) ($data['show_button'] ?? true),
            'picker' => (bool) ($data['picker'] ?? true),
            'products' => ProductResource::collection($products)->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function benefits(array $data): ?array
    {
        $items = collect((array) ($data['items'] ?? []))
            ->filter(fn ($item) => filled($item['text'] ?? null))
            ->map(fn ($item) => ['icon' => (string) ($item['icon'] ?? 'check'), 'text' => trim((string) $item['text'])])
            ->values()
            ->all();

        return $items === [] ? null : ['heading' => self::text($data['heading'] ?? null), 'items' => $items];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function faq(array $data): ?array
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($data['faq_ids'] ?? []))));
        $faqs = Faq::query()->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))->orderBy('sort_order')->get();

        if ($faqs->isEmpty()) {
            return null;
        }

        return [
            'heading' => self::text($data['heading'] ?? null),
            'items' => $faqs->map(fn (Faq $faq) => ['id' => $faq->id, 'question' => $faq->question, 'answer' => $faq->answer])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  Collection<int, Product>  $shown
     * @return array<string, mixed>|null
     */
    private static function reviews(array $entry, $shown): ?array
    {
        $ids = $shown->pluck('id')->unique()->values();

        if ($ids->isEmpty()) {
            return null;
        }

        $reviews = ProductReview::query()->approved()->whereIn('product_id', $ids)
            ->with('product:id,name')->latest('approved_at')->latest('id')->limit($entry['limit'])->get();

        if ($reviews->isEmpty()) {
            return null;
        }

        $summary = ProductReview::query()->approved()->whereIn('product_id', $ids)->selectRaw('count(*) as total, avg(rating) as average')->first();

        return [
            'key' => $entry['key'],
            'type' => 'reviews',
            'heading' => $entry['heading'],
            'summary' => ['rating_count' => (int) $summary->total, 'rating_average' => round((float) $summary->average, 1)],
            'items' => $reviews->map(fn (ProductReview $review) => ReviewResource::make($review)->resolve() + ['product_name' => $review->product?->name])->all(),
        ];
    }

    /**
     * Only a real end time makes a countdown, and only while it is still in the future.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private static function countdown(LandingPage $page, array $data): ?array
    {
        if ($page->publish_until === null || $page->publish_until->lte(now())) {
            return null;
        }

        return ['label' => self::text($data['label'] ?? null), 'ends_at' => $page->publish_until->toIso8601String()];
    }
}
