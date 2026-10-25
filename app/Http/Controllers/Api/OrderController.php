<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Mail\WalletPaymentReceivedMail;
use App\Models\AbandonedCheckout;
use App\Models\LandingPage;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Support\Checkout\CustomerResolver;
use App\Support\Contact;
use App\Support\ConversionEvents;
use App\Support\Coupons\CouponRejected;
use App\Support\Coupons\CouponService;
use App\Support\DeliveryFeeCalculator;
use App\Support\EmailThumbs;
use App\Support\Email\TransactionalEmails;
use App\Support\Recovery\CheckoutTracker;
use App\Support\System\ShopAlerts;
use App\Support\System\SystemEvents;
use App\Support\WalletPayments;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request)
    {
        $order = $this->placeWithRetry($request);

        $order->load(['customer', 'items']);

        // Any left cart this order belongs to is closed, and no reminder is sent for it any more. It can never hold up an
        // order, so a problem here is only logged.
        try {
            CheckoutTracker::markOrdered(
                $order,
                $request->input('checkout_session'),
                $request->input('customer_email'),
                $request->input('customer_phone'),
                $request->input('recovery_token'),
            );
        } catch (\Throwable $e) {
            Log::warning('Closing the abandoned checkout failed.', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        // Sent right after this response (there is no queue worker), and a mail problem can never fail checkout.
        TransactionalEmails::orderConfirmation($order);
        $this->announceWalletOrder($order);
        ShopAlerts::newOrder($order);

        // Queued: each job no-ops on its own if that platform's pixel ID +
        // access token aren't both configured, and a slow/failed call to
        // Meta/TikTok must never delay or break this response. IP/user
        // agent are captured here, from the request itself, since neither
        // is available any more once the job actually runs.
        // Cash on delivery is a sale now. A wallet order is not one until its payment is verified, so nothing is
        // sent for it here (its visitor details were kept for that moment). In opt-in cookie mode a visitor who has
        // not allowed marketing cookies is never sent.
        if (! WalletPayments::isWallet($order->payment_method)) {
            ConversionEvents::sendAtPlacement($order, $request->ip(), $request->userAgent());
        }

        return OrderResource::make($order)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Places the order, trying again when the database is briefly busy with another writer (an order arriving at the same
     * moment, the scheduler, an admin save). SQLite has one writer at a time; a second one waits its turn here instead of
     * showing the customer an error.
     */
    private function placeWithRetry(StoreOrderRequest $request): Order
    {
        $attempts = 4;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->placeOrder($request);
            } catch (UniqueConstraintViolationException $e) {
                // Only the transaction ID can be told apart for the customer. Two orders arrived at once with the same ID and
                // the database let one through; anything else is not the customer's mistake and is not blamed on them.
                if (str_contains($e->getMessage(), 'payment_trx_id')) {
                    throw ValidationException::withMessages([
                        'payment_trx_id' => 'This transaction ID has already been used on another order. Please check it, or contact us.',
                    ]);
                }

                throw $e;
            } catch (QueryException|DeadlockException $e) {
                if (! self::isBusy($e)) {
                    throw $e;
                }

                if ($attempt === $attempts) {
                    Log::warning('Order placement kept finding the database busy.', ['attempts' => $attempts]);

                    throw new HttpResponseException(response()->json([
                        'message' => 'We are a little busy just now and could not place your order. Nothing has been taken. Please press the button again in a moment.',
                    ], 503, ['Retry-After' => '2']));
                }

                usleep(random_int(120, 350) * 1000 * $attempt);
            }
        }

        throw new \LogicException('Unreachable.');
    }

    /** SQLite "database is locked" / "table is locked" (SQLSTATE HY000, codes 5 and 6), and MySQL deadlocks. */
    private static function isBusy(\Throwable $e): bool
    {
        $message = mb_strtolower($e->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout');
    }

    private function keepsVisitorDetails(StoreOrderRequest $request): bool
    {
        return WalletPayments::isWallet($request->input('payment_method')) && ConversionEvents::allowed($request->boolean('marketing_consent'));
    }

    private function placeOrder(StoreOrderRequest $request): Order
    {
        return DB::transaction(function () use ($request) {
            // What was typed is kept on the order. An existing customer is never rewritten by a guest checkout (see
            // CustomerResolver), so a later order cannot change this one, or anyone's account.
            $typed = [
                'name' => trim($request->string('customer_name')->toString()),
                'phone' => Contact::phone($request->string('customer_phone')->toString()) ?? trim($request->string('customer_phone')->toString()),
                'email' => Contact::email($request->input('customer_email')),
                'address' => trim($request->string('customer_address')->toString()),
                'division' => trim($request->string('division')->toString()),
                'district' => trim($request->string('district')->toString()),
                'thana' => trim($request->string('thana')->toString()),
            ];

            // The campaign page it came from, if any. An unknown or removed page is simply not recorded.
            $landing = filled($request->input('landing_page'))
                ? LandingPage::query()->where('slug', $request->string('landing_page')->toString())->first()
                : null;

            $customer = CustomerResolver::forOrder($typed);

            $products = Product::query()
                ->whereIn('id', collect($request->input('items'))->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $variantIds = collect($request->input('items'))->pluck('variant_id')->filter()->values();
            $variants = ProductVariant::query()
                ->whereIn('id', $variantIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0;
            $itemsToCreate = [];

            foreach ($request->input('items') as $item) {
                /** @var Product $product */
                $product = $products->get($item['product_id']);

                $variant = null;
                if (! empty($item['variant_id'])) {
                    $variant = $variants->get($item['variant_id']);
                    if ($variant && $variant->product_id !== $product->id) {
                        $variant = null;
                    }
                }
                // A variant's effective_* accessors fall back to its parent
                // product, so it needs that relation set without a lazy-load
                // query (the locked row above is the one that must be used).
                $variant?->setRelation('product', $product);

                $availableStock = $variant?->effective_stock ?? $product->stock_quantity;

                if ($availableStock < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => "Insufficient stock for {$product->name}.",
                    ]);
                }

                $price = $variant?->effective_price ?? $product->effective_price;
                $lineTotal = $price * $item['quantity'];
                $subtotal += $lineTotal;

                $itemsToCreate[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $item['quantity'],
                    'price' => $price,
                    'variant_name' => $variant?->name,
                    'variant_value' => $variant?->value,
                    'sku' => $variant?->effective_sku ?? $product->sku,
                    // The piece's small picture as it is today, kept with the line for the emails and the invoice.
                    'image_path' => EmailThumbs::snapshot($product, $variant),
                ];

                // A variant with its own stock count is a separate pool from
                // the product's; one with no override shares the product's.
                if ($variant && $variant->stock_quantity !== null) {
                    $variant->decrement('stock_quantity', $item['quantity']);
                } else {
                    $product->decrement('stock_quantity', $item['quantity']);
                }
            }

            // The delivery fee follows the cart as it stands (before any coupon), so a discount never costs a customer free
            // delivery. The coupon is checked again here, under lock, whatever the browser showed.
            $deliveryFee = DeliveryFeeCalculator::forDivision($request->string('division')->toString(), $subtotal);

            $coupon = null;
            $discount = 0;

            if (filled($request->input('coupon_code'))) {
                try {
                    ['coupon' => $coupon, 'discount' => $discount] = CouponService::evaluate(
                        (string) $request->input('coupon_code'),
                        $subtotal,
                        $request->input('customer_email'),
                        $request->input('customer_phone'),
                        now(),
                        true,
                    );
                } catch (CouponRejected $e) {
                    throw ValidationException::withMessages(['coupon_code' => $e->getMessage()]);
                }
            }

            // An order that arrives through a recovery link is marked as coming from it, so it can be told apart in reports.
            $fromRecovery = filled($request->input('recovery_token'))
                && AbandonedCheckout::query()->where('recovery_token', $request->input('recovery_token'))->exists();

            $order = Order::create([
                'customer_id' => $customer->id,
                'ship_name' => $typed['name'],
                'ship_email' => $typed['email'],
                'ship_phone' => $typed['phone'],
                'ship_address' => $typed['address'],
                'ship_division' => $typed['division'],
                'ship_district' => $typed['district'],
                'ship_thana' => $typed['thana'],
                'status' => 'pending',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $subtotal - $discount + $deliveryFee,
                'discount_amount' => $discount,
                'coupon_code' => $coupon?->code,
                'coupon_id' => $coupon?->id,
                'payment_method' => $request->input('payment_method'),
                'payment_status' => WalletPayments::initialStatus($request->input('payment_method')),
                'payment_trx_id' => $request->input('payment_trx_id'),
                'payment_sender_number' => $request->input('payment_sender_number'),
                'gift_note' => $request->input('gift_note'),
                // A message only counts when the order is marked as a gift; free, no price change.
                'marketing_consent' => $request->boolean('marketing_consent'),
                // Kept only for a wallet order whose visitor may be reported to ad platforms, until it is verified.
                'client_ip' => $this->keepsVisitorDetails($request) ? $request->ip() : null,
                'client_user_agent' => $this->keepsVisitorDetails($request) ? mb_substr((string) $request->userAgent(), 0, 500) : null,
                'is_gift' => $request->boolean('is_gift'),
                'gift_message' => $request->boolean('is_gift') ? (trim((string) $request->input('gift_message')) ?: null) : null,
                'utm_source' => $fromRecovery ? 'cart_recovery' : $request->input('utm_source'),
                'utm_medium' => $fromRecovery ? 'email' : $request->input('utm_medium'),
                'utm_campaign' => $fromRecovery ? 'abandoned_cart' : $request->input('utm_campaign'),
                'utm_content' => $request->input('utm_content'),
                'utm_term' => $request->input('utm_term'),
                'fbclid' => $request->input('fbclid'),
                'gclid' => $request->input('gclid'),
                'ttclid' => $request->input('ttclid'),
                'landing_page_id' => $landing?->id,
                'landing_slug' => $landing?->slug,
            ]);

            $order->items()->createMany($itemsToCreate);

            // Used up inside the same transaction as the order, so two orders can never share a single-use code.
            if ($coupon !== null) {
                $coupon->forceFill(['used_count' => $coupon->used_count + 1, 'used_at' => now(), 'used_order_id' => $order->id])->save();
            }

            return $order;
        });
    }

    /**
     * Tells the shop a wallet order is waiting to be checked. It runs after the response is sent (no queue
     * worker needed), and a failure is only logged: the order is saved and shows in the admin either way.
     */
    private function announceWalletOrder(Order $order): void
    {
        if (! WalletPayments::isWallet($order->payment_method)) {
            return;
        }

        $to = PaymentSetting::query()->value('payment_notify_email') ?: SiteSetting::query()->value('contact_email');

        if (blank($to)) {
            return;
        }

        dispatch(function () use ($order, $to): void {
            try {
                Mail::to($to)->send(new WalletPaymentReceivedMail($order));
            } catch (\Throwable $e) {
                Log::warning('Wallet payment notification email failed.', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
                SystemEvents::record('mail_failed', 'Wallet payment notification email: '.$e->getMessage());
            }
        })->afterResponse();
    }

    /**
     * Guest order tracking: an order is only returned when both the order
     * ID and the phone number on file for it match, so order IDs alone
     * (sequential integers) can't be used to browse other customers' orders.
     */
    public function lookup(Request $request)
    {
        $request->validate([
            'order_id' => ['required', 'integer'],
            'phone' => ['required', 'string'],
        ]);

        $order = Order::query()
            ->with(['customer', 'items'])
            ->where('id', $request->integer('order_id'))
            ->first();

        // The phone number is compared as a number (01712345678 and +8801712345678 are the same), against the one typed
        // at checkout and, for older orders, the customer's.
        $matches = $order !== null && (
            Contact::samePhone($order->recipient()['phone'], $request->string('phone')->toString())
            || Contact::samePhone($order->customer?->phone, $request->string('phone')->toString())
        );

        if (! $matches) {
            return response()->json([
                'message' => 'No order found with that ID and phone number.',
            ], 404);
        }

        return OrderResource::make($order);
    }
}
