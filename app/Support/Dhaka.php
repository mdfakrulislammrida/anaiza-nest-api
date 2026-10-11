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
