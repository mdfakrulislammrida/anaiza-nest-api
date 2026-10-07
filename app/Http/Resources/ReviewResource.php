<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An approved review as the storefront shows it. The customer, the order and the admin's notes stay inside the admin.
 */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'rating' => $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            'photos' => collect($this->photos ?? [])->map(fn (array $photo): array => [
                'url' => MediaUrl::resolve($photo['url'] ?? null),
                'thumb' => MediaUrl::resolve($photo['thumb'] ?? ($photo['url'] ?? null)),
            ])->filter(fn (array $photo): bool => $photo['url'] !== null)->values()->all(),
            'verified_purchase' => (bool) $this->verified_purchase,
            'admin_reply' => filled($this->admin_reply) ? $this->admin_reply : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
