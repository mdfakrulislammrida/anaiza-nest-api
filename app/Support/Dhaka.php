<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Dhaka time. The application keeps and compares everything in UTC; people (and the quiet hours, the daily cap, a coupon's
 * end) think in Bangladesh time, so every place that shows or reads a time of day goes through here.
 */
final class Dhaka
{
    public const ZONE = 'Asia/Dhaka';

    public static function now(): Carbon
    {
        return Carbon::now(self::ZONE);
    }

    public static function at(\DateTimeInterface|string|null $time): ?Carbon
    {
        return $time === null ? null : Carbon::parse($time)->timezone(self::ZONE);
    }

    /** "14 October 2026, 6:30 pm", as a customer would read it. */
    public static function readable(\DateTimeInterface|string|null $time): string
    {
        return $time === null ? '' : self::at($time)->format('j F Y, g:i a');
    }

    /** How the admin shows a date and time: "04 Nov 2026, 11:14 AM". */
    public const DISPLAY = 'd M Y, h:i A';

    /** "04 Nov 2026, 11:14 AM" in Dhaka time, or the dash when there is no time. */
    public static function format(\DateTimeInterface|string|null $time): string
    {
        return $time === null || $time === '' ? '—' : self::at($time)->format(self::DISPLAY);
    }

    /**
     * Where a Dhaka calendar day starts, in the application's own time zone (UTC) -- what the database is asked. A date typed in the
     * admin is a Dhaka date: 04 Nov 00:00 in Dhaka is 03 Nov 18:00 UTC.
     */
    public static function dayStart(\DateTimeInterface|string $date): Carbon
    {
        return Carbon::parse(self::dateOnly($date), self::ZONE)->startOfDay()->timezone(config('app.timezone'));
    }

    /** Where a Dhaka calendar day ends (23:59:59), in the application's time zone. */
    public static function dayEnd(\DateTimeInterface|string $date): Carbon
    {
        return Carbon::parse(self::dateOnly($date), self::ZONE)->endOfDay()->timezone(config('app.timezone'));
    }

    /** The quick ranges of a "Placed" filter, as Dhaka calendar days. */
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
    ];

    /**
     * A preset as its first and last Dhaka day ("Y-m-d"), or null for an unknown one. "Last 7 days" is today and the six days before it.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function preset(string $key, ?\DateTimeInterface $now = null): ?array
    {
        $today = ($now ? Carbon::instance($now)->timezone(self::ZONE) : self::now())->startOfDay();

        return match ($key) {
            'today' => [$today->toDateString(), $today->toDateString()],
            'yesterday' => [$today->copy()->subDay()->toDateString(), $today->copy()->subDay()->toDateString()],
            'last_7_days' => [$today->copy()->subDays(6)->toDateString(), $today->toDateString()],
            'this_month' => [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            default => null,
        };
    }

    private static function dateOnly(\DateTimeInterface|string $date): string
    {
        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr(trim($date), 0, 10);
    }

    /** The calendar day in Dhaka, for per-day counters. */
    public static function day(?\DateTimeInterface $time = null): string
    {
        return ($time ? Carbon::instance($time) : Carbon::now())->timezone(self::ZONE)->toDateString();
    }

    /**
     * Whether $now falls inside the quiet hours (a window that may cross midnight, like 22:00 to 08:00).
     */
    public static function isQuiet(string $start, string $end, ?\DateTimeInterface $now = null): bool
    {
        $local = $now ? Carbon::instance($now)->timezone(self::ZONE) : self::now();
        $minutes = $local->hour * 60 + $local->minute;
        $from = self::minutes($start);
        $to = self::minutes($end);

        if ($from === $to) {
            return false;
        }

        return $from < $to ? ($minutes >= $from && $minutes < $to) : ($minutes >= $from || $minutes < $to);
    }

    private static function minutes(string $time): int
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return ($hour % 24) * 60 + ($minute % 60);
    }
}
