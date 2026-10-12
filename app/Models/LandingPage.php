<?php

namespace App\Models;

use App\Support\Dhaka;
use App\Support\Landing\LandingBlocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * A campaign page shown at /lp/<slug>: ordered blocks (hero, text, HTML, products, order form...) built in the admin.
 */
class LandingPage extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const FOCUS = 'focus';

    public const NORMAL = 'normal';

    public const LIVE = 'live';

    public const ENDED = 'ended';

    public const SCHEDULED = 'scheduled';

    /** @var array<string, string> */
    public const BLOCK_TYPES = [
        'hero' => 'Hero',
        'rich_text' => 'Text',
        'custom_html' => 'Custom HTML',
        'products' => 'Products',
        'benefits' => 'Benefits row',
        'faq' => 'FAQ',
        'reviews' => 'Reviews',
        'order_form' => 'Order form',
        'countdown' => 'Offer countdown',
    ];

    /** How long a preview link works. */
    public const PREVIEW_HOURS = 2;

    protected $fillable = [
        'title',
        'slug',
        'status',
        'publish_from',
        'publish_until',
        'layout',
        'seo_title',
        'meta_description',
        'og_image',
        'noindex',
        'canonical',
        'blocks',
        'notes',
    ];

    protected $attributes = [
        'status' => self::DRAFT,
        'layout' => self::FOCUS,
        'noindex' => true,
    ];

    protected function casts(): array
    {
        return [
            'publish_from' => 'datetime',
            'publish_until' => 'datetime',
            'noindex' => 'boolean',
            'blocks' => 'array',
            'notes' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (LandingPage $page): void {
            $page->slug = Str::slug((string) $page->slug) ?: Str::slug((string) $page->title);

            // Cleaned when the blocks change (and only then), by the person saving: what they may allow is theirs to allow.
            if ($page->isDirty('blocks') || ! $page->exists) {
                LandingBlocks::process($page, Auth::user());
            }
        });
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function stats(): HasMany
    {
        return $this->hasMany(LandingPageStat::class);
    }

    /**
     * draft, scheduled (published but not yet open), live, or ended.
     */
    public function state(?Carbon $now = null): string
    {
        $now ??= now();

        if ($this->status !== self::PUBLISHED) {
            return self::DRAFT;
        }

        if ($this->publish_from !== null && $now->lt($this->publish_from)) {
            return self::SCHEDULED;
        }

        if ($this->publish_until !== null && $now->gte($this->publish_until)) {
            return self::ENDED;
        }

        return self::LIVE;
    }

    public function scopeLive($query)
    {
        return $query->where('status', self::PUBLISHED)
            ->where(fn ($q) => $q->whereNull('publish_from')->orWhere('publish_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('publish_until')->orWhere('publish_until', '>', now()));
    }

    public function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/lp/'.$this->slug;
    }

    /** "start - end" in Dhaka time, for the list. */
    public function windowLabel(): string
    {
        $from = $this->publish_from ? Dhaka::at($this->publish_from)->format('j M Y, g:i a') : 'now';
        $until = $this->publish_until ? Dhaka::at($this->publish_until)->format('j M Y, g:i a') : 'no end';

        return "{$from} to {$until}";
    }

    // ---- preview links: a draft can be opened by whoever the admin hands the link to, for a couple of hours

    public function previewToken(?Carbon $now = null): string
    {
        $expires = ($now ?? now())->copy()->addHours(self::PREVIEW_HOURS)->timestamp;

        return $expires.'.'.$this->previewSignature($expires);
    }

    public function previewUrl(): string
    {
        return $this->url().'?preview='.$this->previewToken();
    }

    public function previewIsValid(?string $token): bool
    {
        if (! is_string($token) || ! preg_match('/^(\d{9,12})\.([a-f0-9]{64})$/', $token, $match)) {
            return false;
        }

        return (int) $match[1] >= now()->timestamp && hash_equals($this->previewSignature((int) $match[1]), $match[2]);
    }

    private function previewSignature(int $expires): string
    {
        return hash_hmac('sha256', "landing-preview|{$this->id}|{$expires}", (string) config('app.key'));
    }
}
