<?php

namespace App\Http\Resources;

use App\Models\HomepageSection;
use App\Support\MediaUrl;
use App\Support\SectionLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HomepageSectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'position' => $this->position,
            // Title and subtitle the admin set for a built-in section; null means the storefront default.
            'custom_title' => $this->custom_title,
            'custom_subtitle' => $this->custom_subtitle,
            'custom_html' => $this->custom_html,
            // ISO 8601 with offset; only the Special prices section carries one.
            'deal_ends_at' => $this->type === 'hot_deals' ? $this->deal_ends_at?->toIso8601String() : null,
            // Shop by occasion: only tiles that are switched on and have somewhere to go.
            'tiles' => $this->type === 'occasions' ? $this->liveTiles() : null,
            // Why Anaiza Nest: lines with something in them, never more than five.
            'reasons' => $this->type === 'why_us' ? $this->liveReasons() : null,
        ];
    }

    /**
     * @return list<array{label: string, image: ?string, href: string}>
     */
    private function liveTiles(): array
    {
        return collect($this->tiles ?? [])
            ->filter(fn ($tile) => ($tile['is_enabled'] ?? true) && filled($tile['label'] ?? null))
            ->map(fn ($tile) => [
                'label' => trim((string) $tile['label']),
                'image' => self::pictureUrl($tile['image'] ?? null),
                'href' => SectionLinks::href($tile),
            ])
            ->filter(fn ($tile) => $tile['href'] !== null)
            ->values()
            ->all();
    }

    /** A tile's picture, or null when it has none or its file is gone (an empty tile, never a broken picture). */
    private static function pictureUrl(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (! Str::startsWith($value, ['http://', 'https://']) && ! Storage::disk('public')->exists($value)) {
            return null;
        }

        return MediaUrl::resolve($value);
    }

    /**
     * @return list<array{title: ?string, line: string}>
     */
    private function liveReasons(): array
    {
        return collect($this->reasons ?? [])
            ->filter(fn ($row) => filled($row['line'] ?? null))
            ->map(fn ($row) => [
                'title' => filled($row['title'] ?? null) ? trim((string) $row['title']) : null,
                'line' => trim((string) $row['line']),
            ])
            ->take(HomepageSection::MAX_REASONS)
            ->values()
            ->all();
    }
}
