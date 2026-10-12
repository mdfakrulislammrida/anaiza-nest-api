<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Support\Landing\LandingBlocks;
use App\Support\Landing\LandingStats;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandingPageController extends Controller
{
    /**
     * The pages that are open right now: what the storefront build needs to make a static page for each.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => LandingPage::query()->live()->orderBy('slug')->get(['id', 'slug', 'title', 'noindex', 'updated_at'])
                ->map(fn (LandingPage $page) => [
                    'id' => $page->id,
                    'slug' => $page->slug,
                    'title' => $page->title,
                    'noindex' => (bool) $page->noindex,
                    'updated_at' => $page->updated_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    /**
     * A page, or (with a valid preview link) a draft. A draft or a page that has not opened yet does not exist as far as
     * a visitor can tell; a page whose time has passed says so, calmly, with nothing else.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $page = LandingPage::query()->where('slug', $slug)->first();

        abort_if($page === null, 404);

        $preview = $page->previewIsValid($request->query('preview'));
        $state = $page->state();

        if (! $preview && in_array($state, [LandingPage::DRAFT, LandingPage::SCHEDULED], true)) {
            abort(404);
        }

        $ended = $state === LandingPage::ENDED && ! $preview;

        return response()->json(['data' => [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'state' => $preview ? 'preview' : $state,
            'layout' => $page->layout,
            'publish_until' => $page->publish_until?->toIso8601String(),
            'seo' => [
                'title' => $page->seo_title ?: $page->title,
                'description' => $page->meta_description,
                'og_image' => MediaUrl::resolve($page->og_image),
                // A preview is never indexed, whatever the page says.
                'noindex' => $preview || $ended || (bool) $page->noindex,
                'canonical' => $page->canonical,
            ],
            'blocks' => $ended ? [] : LandingBlocks::payload($page),
        ]]);
    }

    /**
     * A page was viewed (once per visitor session, decided by the browser) or a piece was added to the cart from it.
     * Always answers the same, and counts nothing for bots, drafts or pages that are not open.
     */
    public function event(Request $request, string $slug): JsonResponse
    {
        $type = $request->input('type');

        if (in_array($type, ['view', 'add_to_cart'], true) && ! LandingStats::looksLikeBot($request->userAgent())) {
            $page = LandingPage::query()->where('slug', $slug)->first();

            if ($page !== null && $page->state() === LandingPage::LIVE) {
                LandingStats::record($page, $type);
            }
        }

        return response()->json(['ok' => true]);
    }
}
