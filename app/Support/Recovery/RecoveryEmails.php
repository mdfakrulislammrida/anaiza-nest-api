<?php

namespace App\Support\Recovery;

use App\Mail\TransactionalMail;
use App\Models\AbandonedCheckout;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Support\Coupons\CouponService;
use App\Support\Email\TemplateDefinitions;
use App\Support\Reviews\ReviewLinks;
use Illuminate\Support\Facades\Mail;

/**
 * Builds and sends the review and cart emails. Sent right where they are called (the scheduler runs them; there is no
 * queue), and an exception here is the caller's to catch.
 */
final class RecoveryEmails
{
    public static function recoveryLink(AbandonedCheckout $checkout): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/cart?recover='.$checkout->recovery_token;
    }

    /**
     * The placeholders and links for a cart email.
     *
     * @return array{plain: array<string, string>, blocks: array<string, string>, cta_url: string, unsubscribe_url: string}
     */
    public static function cartContext(AbandonedCheckout $checkout, ?Coupon $coupon = null): array
    {
        $items = collect($checkout->items ?? []);
        $link = self::recoveryLink($checkout);

        return [
            'plain' => [
                '{{customer_name}}' => filled($checkout->name) ? trim($checkout->name) : 'there',
                '{{recovery_link}}' => $link,
                '{{product_names}}' => $items->pluck('name')->filter()->implode(', '),
                '{{coupon_code}}' => $coupon?->code ?? '',
                '{{coupon_value}}' => $coupon?->valueLabel() ?? '',
                '{{coupon_expires_at}}' => $coupon ? CouponService::expiryText($coupon) : '',
                '{{minimum_order}}' => $coupon?->min_order ? 'It applies to orders of ৳'.number_format($coupon->min_order).' or more.' : '',
            ],
            'blocks' => [
                '{{cart_items_block}}' => view('emails.blocks.cart', ['items' => $items->all()])->render(),
            ],
            'cta_url' => $link,
            'unsubscribe_url' => Unsubscribe::url($checkout->email, $checkout->phone),
        ];
    }

    public static function sendCart(AbandonedCheckout $checkout, string $templateKey, ?Coupon $coupon = null): void
    {
        $customer = (new Customer)->forceFill(['name' => $checkout->name, 'email' => $checkout->email, 'phone' => $checkout->phone]);

        Mail::to($checkout->email)->send(new TransactionalMail(
            $templateKey,
            null,
            $customer,
            SiteSetting::query()->first() ?? new SiteSetting,
            null,
            self::cartContext($checkout, $coupon),
        ));
    }

    /**
     * @return array{plain: array<string, string>, cta_url: string, unsubscribe_url: string}
     */
    public static function reviewContext(Order $order): array
    {
        $order->loadMissing(['customer', 'items.product']);
        $first = $order->items->first(fn ($item): bool => filled($item->product?->slug));

        return [
            'plain' => [
                '{{customer_name}}' => filled($order->recipient()['name']) ? trim((string) $order->recipient()['name']) : 'there',
                '{{product_names}}' => $order->items->pluck('product_name')->filter()->implode(', '),
            ],
            'cta_url' => ($first ? ReviewLinks::url($first) : null) ?? rtrim((string) config('app.frontend_url'), '/').'/shop',
            'unsubscribe_url' => Unsubscribe::url($order->recipient()['email'], $order->recipient()['phone']),
        ];
    }

    public static function sendReview(Order $order, bool $reminder = false): void
    {
        $order->loadMissing(['customer', 'items.product']);

        Mail::to($order->recipient()['email'])->send(new TransactionalMail(
            $reminder ? TemplateDefinitions::REVIEW_REMINDER : TemplateDefinitions::REVIEW_REQUEST,
            $order,
            $order->customer,
            SiteSetting::query()->first() ?? new SiteSetting,
            null,
            self::reviewContext($order),
        ));
    }
}
