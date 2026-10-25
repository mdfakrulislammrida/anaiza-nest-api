<?php

namespace App\Support;

use Closure;

/**
 * The one rule for "is this item on sale": a sale price counts only when it is more than 0 and lower than the regular
 * price. A blank, 0, negative or not-lower sale price means no sale, so the customer pays and sees the regular price.
 */
final class Pricing
{
    public static function saleApplies(?int $salePrice, ?int $regularPrice): bool
    {
        return $salePrice !== null && $regularPrice !== null && $salePrice > 0 && $regularPrice > 0 && $salePrice < $regularPrice;
    }

    /**
     * The admin form rule for a sale price field. Blank is fine (no sale). $regularPrice is read when the form is checked.
     *
     * @param  Closure(): mixed  $regularPrice
     */
    public static function formRule(Closure $regularPrice, string $missingRegularMessage): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($regularPrice, $missingRegularMessage): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_numeric($value) || (float) $value <= 0) {
                $fail('A sale price must be more than ৳0. Leave it blank if there is no sale.');

                return;
            }

            $regular = $regularPrice();

            if (! is_numeric($regular) || (float) $regular <= 0) {
                $fail($missingRegularMessage);

                return;
            }

            if ((float) $value >= (float) $regular) {
                $fail('The sale price must be lower than the regular price (৳'.number_format((float) $regular).'). Leave it blank if there is no sale.');
            }
        };
    }
}
