<?php

namespace App\Support\Landing;

use App\Models\LandingPage;
use App\Models\LandingPageStat;
use App\Models\Order;
use App\Support\Dhaka;
use Illuminate\Support\Carbon;

/**
 * What a landing page did, for a range of Dhaka calendar days.
 *
 * Views and add-to-carts are counted by the storefront (a view once per visitor session, bots left out). Orders and
 * revenue come straight from the orders that carry the page's id, so they can never disagree with the order list.
 * Cancelled orders and payments that failed are not a sale.
 */
final class LandingStats
{
    /** User agents that are not a person looking at a page. */
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|facebot|whatsapp|preview|monitor|uptime|headless|lighthouse|pingdom|curl|wget|python|httpclient|axios|node-fetch|go-http|java\//i';

    public static function looksLikeBot(?string $userAgent): bool
    {
        return $userAgent === null || trim($userAgent) === '' || preg_match(self::BOTS, $userAgent) === 1;
    }

    public static function record(LandingPage $page, string $type): void
    {
        $column = $type === 'add_to_cart' ? 'add_to_carts' : 'views';
        $day = Dhaka::day();

        // The day is kept as plain text (2026-10-14), so the one row per page per day is found exactly.
        LandingPageStat::query()->insertOrIgnore(['landing_page_id' => $page->id, 'day' => $day, 'views' => 0, 'add_to_carts' => 0]);
        LandingPageStat::query()->where('landing_page_id', $page->id)->where('day', $day)->increment($column);
    }

    /**
     * @return array{views: int, add_to_carts: int, orders: int, conversion: ?float, revenue: int}
     */
    public static function forPage(LandingPage $page, ?Carbon $from = null, ?Carbon $until = null): array
    {
        $from = ($from ?? Dhaka::now()->subDays(29))->copy()->timezone(Dhaka::ZONE)->startOfDay();
        $until = ($until ?? Dhaka::now())->copy()->timezone(Dhaka::ZONE)->endOfDay();

        $counts = LandingPageStat::query()->where('landing_page_id', $page->id)
            ->whereBetween('day', [$from->toDateString(), $until->toDateString()])
            ->selectRaw('coalesce(sum(views), 0) as views, coalesce(sum(add_to_carts), 0) as add_to_carts')->first();

        $orders = Order::query()->where('landing_page_id', $page->id)
            ->where('status', '!=', 'cancelled')
            ->where(fn ($query) => $query->whereNull('payment_status')->orWhere('payment_status', '!=', 'failed'))
            ->whereBetween('created_at', [$from->copy()->utc(), $until->copy()->utc()]);

        $count = (clone $orders)->count();
        $views = (int) $counts->views;

        return [
            'views' => $views,
            'add_to_carts' => (int) $counts->add_to_carts,
            'orders' => $count,
            'conversion' => $views > 0 ? round($count / $views * 100, 1) : null,
            'revenue' => (int) (clone $orders)->sum('total'),
        ];
    }
}
