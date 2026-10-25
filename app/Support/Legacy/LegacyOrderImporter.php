<?php

namespace App\Support\Legacy;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Contact;
use App\Support\Dhaka;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Brings the old site's orders over as history.
 *
 * What is kept exactly as the old site had it: the order number, the date, the status (through a map), the customer's name and
 * phone, the delivery fee, the lines (name, quantity, unit price) and the total. Nothing is priced again: the total is the old
 * `amount`, and the subtotal is whatever makes subtotal + delivery fee - discount equal it.
 *
 * Nothing here sends anything. An imported order is written without model events, carries `source = legacy_import`, and every
 * place that emails, reports to an advertising platform, books a courier or asks for a review skips such an order. Stock is
 * never touched (the old lines have no product, only a name).
 *
 * Reports hold counts and never a name, phone, email or address.
 */
final class LegacyOrderImporter
{
    /** The old site's order_status codes are not documented in the export. These are an assumption, shown in every report. */
    public const DEFAULT_STATUS_MAP = [1 => 'pending', 2 => 'processing', 3 => 'shipped', 5 => 'delivered', 6 => 'cancelled'];

    /** Statuses an old code may be mapped to. "confirmed" is this shop's Processing. */
    public const TARGET_STATUSES = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'returned'];

    private const STATUS_ALIASES = ['confirmed' => 'processing'];

    /**
     * "2=confirmed,3=shipped" as code => status. Throws on a code that is not a number or a status this shop does not have.
     *
     * @return array<int, string>
     */
    public static function parseStatusMap(string $text): array
    {
        $map = [];

        foreach (array_filter(array_map('trim', explode(',', $text)), fn (string $part): bool => $part !== '') as $part) {
            [$code, $status] = array_pad(array_map('trim', explode('=', $part, 2)), 2, '');
            $status = self::STATUS_ALIASES[strtolower($status)] ?? strtolower($status);

            if (! ctype_digit($code) || ! in_array($status, self::TARGET_STATUSES, true)) {
                throw new \InvalidArgumentException("\"{$part}\" is not a status mapping. Use code=status, for example 2=confirmed,5=delivered. Statuses: ".implode(', ', self::TARGET_STATUSES).' (confirmed is processing).');
            }

            $map[(int) $code] = $status;
        }

        return $map;
    }

    /** A mobile number as 01XXXXXXXXX, or null when the old value is not one. Tolerates a missing leading 0 and +88 / 88. */
    public static function phone(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0'.$digits;
        }

        return Contact::phone($digits);
    }

    /**
     * Reads the three tables and works out, without writing anything, what would be imported and what would be left out.
     *
     * @param  array<string, list<array<string, mixed>>>  $tables  customers, orders, order_details
     * @param  array<int, string>  $statusMap
     * @return array<string, mixed>
     */
    public static function plan(array $tables, array $statusMap): array
    {
        $customers = [];
        foreach ($tables['customers'] ?? [] as $row) {
            $customers[(int) ($row['id'] ?? 0)] = $row;
        }

        $lines = [];
        $knownOrderIds = [];
        foreach ($tables['orders'] ?? [] as $row) {
            $knownOrderIds[(int) ($row['id'] ?? 0)] = true;
        }

        $orphanLines = 0;
        foreach ($tables['order_details'] ?? [] as $row) {
            $orderId = (int) ($row['order_id'] ?? 0);

            if (! isset($knownOrderIds[$orderId])) {
                $orphanLines++;

                continue;
            }

            $lines[$orderId][] = $row;
        }

        $already = Order::query()->whereNotNull('legacy_order_id')->pluck('legacy_order_id')->flip()->all();
        $seen = [];
        $skipped = [];
        $orders = [];
        $inFile = 0;
        $alreadyImported = 0;
        $totalMismatch = 0;
        $phoneNotValid = 0;
        $fractional = 0;
        $skip = function (string $reason) use (&$skipped): void {
            $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
        };

        foreach ($tables['orders'] ?? [] as $row) {
            $inFile++;
            $number = trim((string) ($row['invoice_id'] ?? ''));

            if ($number === '') {
                $skip('no order number');

                continue;
            }

            if (isset($seen[$number])) {
                $skip('order number repeated in the export');

                continue;
            }
            $seen[$number] = true;

            if (isset($already[$number])) {
                $alreadyImported++;

                continue;
            }

            $customer = $customers[(int) ($row['customer_id'] ?? 0)] ?? null;
            if ($customer === null) {
                $skip('customer not in the export');

                continue;
            }

            $code = (int) ($row['order_status'] ?? -1);
            if (! isset($statusMap[$code])) {
                $skip("status code {$code} has no mapping");

                continue;
            }

            $orderLines = $lines[(int) $row['id']] ?? [];
            if ($orderLines === []) {
                $skip('no line items in the export');

                continue;
            }

            $amount = $row['amount'] ?? null;
            $fee = $row['shipping_charge'] ?? 0;
            $discount = $row['discount'] ?? 0;
            if (! is_numeric($amount) || ! is_numeric($fee) || ! is_numeric($discount) || $amount < 0 || $fee < 0 || $discount < 0) {
                $skip('amount, delivery fee or discount is not a number');

                continue;
            }

            if (floor((float) $amount) != (float) $amount || floor((float) $fee) != (float) $fee || floor((float) $discount) != (float) $discount) {
                $fractional++;
            }

            $total = (int) round((float) $amount);
            $fee = (int) round((float) $fee);
            $discount = (int) round((float) $discount);
            $subtotal = $total - $fee + $discount;

            if ($subtotal < 0) {
                $skip('delivery fee is larger than the total');

                continue;
            }

            $built = [];
            $bad = false;
            foreach ($orderLines as $line) {
                $quantity = (int) ($line['qty'] ?? 0);
                $price = $line['sale_price'] ?? null;
                $name = trim((string) ($line['product_name'] ?? ''));

                if ($quantity < 1 || ! is_numeric($price) || $price < 0) {
                    $bad = true;
                    break;
                }

                $variant = array_filter([trim((string) ($line['product_size'] ?? '')) !== '' ? ['Size', trim((string) $line['product_size'])] : null, trim((string) ($line['product_color'] ?? '')) !== '' ? ['Colour', trim((string) $line['product_color'])] : null]);
                $built[] = [
                    'product_name' => $name !== '' ? Str::limit($name, 250, '') : 'Item',
                    'quantity' => $quantity,
                    'price' => (int) round((float) $price),
                    'variant_name' => $variant === [] ? null : implode(' / ', array_column($variant, 0)),
                    'variant_value' => $variant === [] ? null : implode(' / ', array_column($variant, 1)),
                ];
            }

            if ($bad) {
                $skip('a line has no quantity or no price');

                continue;
            }

            if (array_sum(array_map(fn (array $l): int => $l['quantity'] * $l['price'], $built)) !== $subtotal) {
                $totalMismatch++;
            }

            $phone = self::phone($customer['phone'] ?? null);
            if ($phone === null) {
                $phoneNotValid++;
            }

            $created = self::time($row['created_at'] ?? null);
            $updated = self::time($row['updated_at'] ?? null) ?? $created;

            $orders[] = [
                'number' => $number,
                'old_customer_id' => (int) $row['customer_id'],
                'old_status' => $code,
                'status' => $statusMap[$code],
                'subtotal' => $subtotal,
                'delivery_fee' => $fee,
                'discount' => $discount,
                'total' => $total,
                'created_at' => $created,
                'updated_at' => $updated,
                'name' => trim((string) ($customer['name'] ?? '')),
                'phone' => $phone,
                'raw_phone' => $phone === null ? trim((string) ($customer['phone'] ?? '')) : null,
                'address' => self::text($customer['address'] ?? null),
                'district' => self::text($customer['district'] ?? null),
                'area' => self::text($customer['area'] ?? null),
                'note' => self::text($row['note'] ?? null),
                'admin_note' => self::text($row['admin_note'] ?? null),
                'lines' => $built,
            ];
        }

        $dates = array_filter(array_column($orders, 'created_at'));

        return [
            'in_file' => $inFile,
            'orders' => $orders,
            'already_imported' => $alreadyImported,
            'skipped' => $skipped,
            'orphan_lines' => $orphanLines,
            'total_not_from_lines' => $totalMismatch,
            'phone_not_valid' => $phoneNotValid,
            'fractional_amounts' => $fractional,
            'date_from' => $dates === [] ? null : min($dates),
            'date_to' => $dates === [] ? null : max($dates),
            'by_status' => array_count_values(array_column($orders, 'status')),
            'by_old_code' => array_count_values(array_column($orders, 'old_status')),
            'lines' => array_sum(array_map(fn (array $o): int => count($o['lines']), $orders)),
            'taka' => array_sum(array_column($orders, 'total')),
            'status_map' => $statusMap,
        ];
    }

    /**
     * Writes a plan, in one transaction. Safe to run again: an order already imported (same old number) is left alone.
     * Customers are matched by normalised phone to the ones the shop already has; the others are created (with a password
     * nobody knows, and no email).
     *
     * @param  array<string, mixed>  $plan
     * @return array{orders: int, items: int, customers_created: int, customers_matched: int, skipped_existing: int}
     */
    public static function import(array $plan): array
    {
        $result = ['orders' => 0, 'items' => 0, 'customers_created' => 0, 'customers_matched' => 0, 'skipped_existing' => 0];

        DB::transaction(function () use ($plan, &$result): void {
            $byPhone = [];
            foreach (Customer::query()->whereNotNull('phone')->orderBy('id')->get(['id', 'phone']) as $existing) {
                $key = self::phone($existing->phone);

                if ($key !== null && ! isset($byPhone[$key])) {
                    $byPhone[$key] = $existing->id;
                }
            }

            $byOldId = [];

            foreach ($plan['orders'] as $row) {
                if (Order::query()->where('legacy_order_id', $row['number'])->exists()) {
                    $result['skipped_existing']++;

                    continue;
                }

                $customerId = $byOldId[$row['old_customer_id']] ?? null;
                $resolved = $customerId !== null;

                if (! $resolved) {
                    $customerId = $row['phone'] !== null ? ($byPhone[$row['phone']] ?? null) : null;
                }

                if ($resolved) {
                    // The same old customer's earlier order in this run.
                } elseif ($customerId !== null) {
                    $result['customers_matched']++;
                } else {
                    $customer = Customer::query()->create([
                        'name' => $row['name'] !== '' ? $row['name'] : 'Customer',
                        'phone' => $row['phone'] ?? ($row['raw_phone'] !== '' ? $row['raw_phone'] : null),
                        'address' => $row['address'],
                        'district' => $row['district'],
                        'thana' => $row['area'],
                        'password' => Str::random(40),
                    ]);
                    $customerId = $customer->id;
                    $result['customers_created']++;

                    if ($row['phone'] !== null) {
                        $byPhone[$row['phone']] = $customerId;
                    }
                }
                $byOldId[$row['old_customer_id']] = $customerId;

                $order = new Order;
                $order->forceFill([
                    'customer_id' => $customerId,
                    'status' => $row['status'],
                    'source' => Order::SOURCE_LEGACY,
                    'legacy_order_id' => $row['number'],
                    'subtotal' => $row['subtotal'],
                    'delivery_fee' => $row['delivery_fee'],
                    'discount_amount' => $row['discount'],
                    'total' => $row['total'],
                    'payment_method' => 'cod',
                    'payment_status' => 'cod',
                    'gift_note' => $row['note'],
                    'payment_note' => $row['admin_note'],
                    'is_gift' => false,
                    'marketing_consent' => false,
                    'ship_name' => $row['name'] !== '' ? $row['name'] : null,
                    'ship_phone' => $row['phone'] ?? ($row['raw_phone'] !== '' ? $row['raw_phone'] : null),
                    'ship_address' => $row['address'],
                    'ship_district' => $row['district'],
                    'ship_thana' => $row['area'],
                    'delivered_at' => $row['status'] === 'delivered' ? ($row['updated_at'] ?? $row['created_at']) : null,
                    'created_at' => $row['created_at'] ?? now(),
                    'updated_at' => $row['updated_at'] ?? $row['created_at'] ?? now(),
                ]);

                // No model events: nothing watching an order being created or changing status runs for history.
                Order::withoutEvents(fn () => $order->save());

                foreach ($row['lines'] as $line) {
                    $item = new OrderItem;
                    $item->forceFill($line + ['order_id' => $order->id, 'product_id' => null, 'sku' => null, 'image_path' => null]);
                    $item->timestamps = false;
                    $item->forceFill(['created_at' => $row['created_at'] ?? now(), 'updated_at' => $row['created_at'] ?? now()]);
                    $item->save();
                    $result['items']++;
                }

                $result['orders']++;
            }
        });

        return $result;
    }

    /** The old site kept Bangladesh time; the shop keeps UTC. */
    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '' || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return Carbon::parse($value, Dhaka::ZONE)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
