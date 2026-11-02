<?php

namespace App\Support;

/**
 * Is an address inside Dhaka city? The one place that decides it; the lists are in config/delivery.php.
 *
 * Names are compared as stored (division, district and thana exactly as the storefront sends them); only case and
 * surrounding or doubled spaces are ignored, and nothing is ever renamed.
 */
final class DhakaCity
{
    public const INSIDE = 'dhaka_city';

    public const OUTSIDE = 'outside_dhaka';

    // Why an address is inside or outside, for the quote and the log.
    public const REASON_CITY = 'dhaka_city_thana';

    public const REASON_OTHER_DIVISION = 'other_division';

    public const REASON_OTHER_DISTRICT = 'other_district_in_dhaka_division';

    public const REASON_UPAZILA = 'dhaka_district_upazila';

    public const REASON_NO_THANA = 'dhaka_district_without_thana';

    public const REASON_UNKNOWN_THANA = 'dhaka_district_unknown_thana';

    /** @return array{inside: bool, reason: string} */
    public static function classify(?string $division, ?string $district, ?string $thana): array
    {
        $city = config('delivery.dhaka_city');

        if (self::norm($division) !== self::norm($city['division'])) {
            return ['inside' => false, 'reason' => self::REASON_OTHER_DIVISION];
        }

        if (self::norm($district) !== self::norm($city['district'])) {
            return ['inside' => false, 'reason' => self::REASON_OTHER_DISTRICT];
        }

        $thana = self::norm($thana);

        if ($thana === '') {
            return ['inside' => false, 'reason' => self::REASON_NO_THANA];
        }

        if (in_array($thana, self::normAll($city['thanas']), true)) {
            return ['inside' => true, 'reason' => self::REASON_CITY];
        }

        if (in_array($thana, self::normAll(config('delivery.dhaka_district_outside')), true)) {
            return ['inside' => false, 'reason' => self::REASON_UPAZILA];
        }

        return ['inside' => false, 'reason' => self::REASON_UNKNOWN_THANA];
    }

    public static function contains(?string $division, ?string $district, ?string $thana): bool
    {
        return self::classify($division, $district, $thana)['inside'];
    }

    /** @return list<string> */
    public static function thanas(): array
    {
        return array_values(config('delivery.dhaka_city.thanas'));
    }

    /** @return list<string> */
    public static function outsideUpazilas(): array
    {
        return array_values(config('delivery.dhaka_district_outside'));
    }

    /** True when the address names Dhaka district but the thana could not be placed (worth a log line). */
    public static function isDoubtful(string $reason): bool
    {
        return in_array($reason, [self::REASON_NO_THANA, self::REASON_UNKNOWN_THANA], true);
    }

    private static function norm(?string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }

    /**
     * @param  array<int, string>  $values
     * @return list<string>
     */
    private static function normAll(array $values): array
    {
        return array_map(self::norm(...), array_values($values));
    }
}
