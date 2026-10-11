<?php

namespace App\Support\Coupons;

/**
 * A coupon that cannot be used, with the reason in words a customer can act on.
 */
final class CouponRejected extends \RuntimeException {}
