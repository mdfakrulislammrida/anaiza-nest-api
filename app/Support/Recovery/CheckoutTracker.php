<?php

namespace App\Support\Recovery;

use App\Models\AbandonedCheckout;
use App\Models\CheckoutDailyCount;
use App\Models\CookieConsentSetting;
use App\Models\Customer;
use App\Models\EmailOptOut;
use App\Models\Order;
use App\Support\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Keeps what a visitor left behind, and only when there is a way to reach them.
 *
 * A visitor with no valid email or phone number is never stored: the day's count for their stage goes up by one and
 * that is all. Someone who can be contacted is stored when the cookie mode allows it (notice mode always; opt-in mode only
 * with marketing consent). An order, matched by session, email or phone, ends every message for that checkout.
 */
final class CheckoutTracker
{
    /** Stages in the order a visitor moves through them; a checkout never goes back. */
    private const ORDER = ['cart' => 1, 'checkout_started' => 2, 'contact_filled' => 3];

    public const MAX_ITEMS = 30;

    public const MAX_VIEWED = 5;

    /**
     * @param  array<string, mixed>  $data
     * @return array{stored: bool}
     */
    public static function report(array $data, ?Carbon $now = null): array
    {
        $now ??= now();
        $stage = (string) ($data['stage'] ?? 'cart');
        $stage = isset(self::ORDER[$stage]) ? $stage : 'cart';

        $email = Contact::email($data['email'] ?? null);
        $phone = Contact::phone($data['phone'] ?? null);

        if ($email === null && $phone === null) {
            // Nobody to contact: counted, never stored. The browser reports each stage once, with this flag.
            if (! empty($data['count_only'])) {
                CheckoutDailyCount::bump($stage);
            }

            return ['stored' => false];
        }

        // Opt-in mode: an identified cart is only kept when the visitor allowed marketing.
        if (CookieConsentSetting::current()->requiresOptIn() && empty($data['marketing_consent'])) {
            return ['stored' => false];
        }

        $token = (string) ($data['session_token'] ?? '');

        if (! preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
            return ['stored' => false];
        }

        $checkout = AbandonedCheckout::query()->where('session_token', $token)->first();

        // A finished checkout is closed: a new cart starts a new session in the browser.
        if ($checkout !== null && $checkout->stage === 'ordered') {
            return ['stored' => false];
        }

        $items = self::items($data['items'] ?? []);
        $checkout ??= new AbandonedCheckout(['session_token' => $token, 'first_activity_at' => $now, 'status' => AbandonedCheckout::STATUS_OPEN]);

        $currentRank = self::ORDER[$checkout->stage] ?? 1;
        $stage = self::ORDER[$stage] >= $currentRank ? $stage : $checkout->stage;

        $checkout->fill([
            'stage' => $stage,
            'items' => $items,
            'cart_total' => (int) collect($items)->sum(fn (array $item): int => $item['price'] * $item['quantity']),
            'email' => $email ?? $checkout->email,
            'phone' => $phone ?? $checkout->phone,
            'name' => filled($data['name'] ?? null) ? Str::limit(trim((string) $data['name']), 120, '') : $checkout->name,
            'marketing_consent' => (bool) ($data['marketing_consent'] ?? false),
            'whatsapp_optin' => (bool) ($data['whatsapp_optin'] ?? false) || (bool) $checkout->whatsapp_optin,
            'last_activity_at' => $now,
        ]);

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
            if (blank($checkout->{$field}) && filled($data[$field] ?? null)) {
                $checkout->{$field} = Str::limit(trim((string) $data[$field]), 150, '');
            }
        }

        $checkout->viewed_products = self::viewed($checkout->viewed_products ?? [], $data['viewed_products'] ?? []);

        if ($checkout->customer_id === null) {
            $checkout->customer_id = Customer::query()
                ->when($checkout->email, fn ($q) => $q->where('email', $checkout->email))
                ->when(! $checkout->email && $checkout->phone, fn ($q) => $q->where('phone', $checkout->phone))
                ->value('id');
        }

        // Coming back to a left cart reopens it; an unsubscribe is never undone by activity.
        if (in_array($checkout->status, [AbandonedCheckout::STATUS_EXPIRED], true)) {
            $checkout->status = AbandonedCheckout::STATUS_OPEN;
        }

        if (EmailOptOut::covers($checkout->email, $checkout->phone)) {
            $checkout->unsubscribed = true;
            $checkout->status = AbandonedCheckout::STATUS_UNSUBSCRIBED;
        }

        $checkout->save();

        return ['stored' => true];
    }

    /**
     * An order has been placed: every open checkout that belongs to it (same session, email or phone) is closed and stops
     * receiving messages. It counts as recovered only when we had already written to them.
     */
    public static function markOrdered(Order $order, ?string $sessionToken, ?string $email, ?string $phone, ?string $recoveryToken = null, ?Carbon $now = null): int
    {
        $now ??= now();
        $email = Contact::email($email);
        $phone = Contact::phone($phone);

        $matches = AbandonedCheckout::query()
            ->whereNull('order_id')
            ->where(function ($query) use ($sessionToken, $email, $phone, $recoveryToken) {
                if (filled($sessionToken)) {
                    $query->orWhere('session_token', $sessionToken);
                }
                if (filled($recoveryToken)) {
                    $query->orWhere('recovery_token', $recoveryToken);
                }
                if ($email !== null) {
                    $query->orWhere('email', $email);
                }
                if ($phone !== null) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->get();

        foreach ($matches as $checkout) {
            $wasReminded = $checkout->messages_count > 0 || (filled($recoveryToken) && $checkout->recovery_token === $recoveryToken);

            $checkout->forceFill([
                'stage' => 'ordered',
                'order_id' => $order->id,
                'ordered_at' => $now,
                'status' => $checkout->status === AbandonedCheckout::STATUS_UNSUBSCRIBED
                    ? AbandonedCheckout::STATUS_UNSUBSCRIBED
                    : ($wasReminded ? AbandonedCheckout::STATUS_RECOVERED : AbandonedCheckout::STATUS_ORDERED),
            ])->save();
        }

        return $matches->count();
    }

    /**
     * What was in the cart, cleaned: ids and numbers as numbers, text shortened, nothing but what the recovery needs.
     *
     * @param  mixed  $raw
     * @return list<array{product_id: int, variant_id: int|null, sku: string|null, name: string, variant: string|null, price: int, quantity: int, image: string|null, slug: string|null}>
     */
    private static function items($raw): array
    {
        $items = [];

        foreach (array_slice(is_array($raw) ? $raw : [], 0, self::MAX_ITEMS) as $row) {
            if (! is_array($row) || ! isset($row['product_id']) || ! is_numeric($row['product_id'])) {
                continue;
            }

            $items[] = [
                'product_id' => (int) $row['product_id'],
                'variant_id' => isset($row['variant_id']) && is_numeric($row['variant_id']) ? (int) $row['variant_id'] : null,
                'sku' => filled($row['sku'] ?? null) ? Str::limit((string) $row['sku'], 60, '') : null,
                'name' => Str::limit(trim((string) ($row['name'] ?? '')), 150, ''),
                'variant' => filled($row['variant'] ?? null) ? Str::limit((string) $row['variant'], 100, '') : null,
                'price' => max(0, (int) ($row['price'] ?? 0)),
                'quantity' => max(1, min(99, (int) ($row['quantity'] ?? 1))),
                'image' => filled($row['image'] ?? null) ? Str::limit((string) $row['image'], 300, '') : null,
                'slug' => filled($row['slug'] ?? null) ? Str::limit((string) $row['slug'], 150, '') : null,
            ];
        }

        return $items;
    }

    /**
     * The last five products viewed, newest last, without repeats.
     *
     * @param  list<int|string>  $existing
     * @param  mixed  $incoming
     * @return list<int>
     */
    private static function viewed(array $existing, $incoming): array
    {
        $ids = collect(array_merge($existing, is_array($incoming) ? $incoming : []))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->reverse()->unique()->reverse()->values();

        return $ids->slice(-self::MAX_VIEWED)->values()->all();
    }
}
