<?php

namespace App\Support\Email;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

/**
 * Made-up (never saved) order and customer for the admin's email preview and test email, so a template can be seen
 * with every placeholder filled in without touching real orders.
 */
final class SampleData
{
    public static function customer(): Customer
    {
        return (new Customer)->forceFill([
            'name' => 'Sample customer',
            'email' => 'sample.customer@example.com',
            'phone' => '01700000000',
            'address' => 'House 12, Road 5',
            'thana' => 'Banani',
            'district' => 'Dhaka',
            'division' => 'Dhaka',
        ]);
    }

    public static function order(): Order
    {
        $customer = self::customer();

        $items = collect([
            [1, 'Sample tea set for two', 'sample-tea-set', 1, 2200, null, null],
            [2, 'Sample ceramic mug', 'sample-ceramic-mug', 2, 350, 'Colour', 'Ivory'],
        ])->map(function (array $row): OrderItem {
            [$id, $name, $slug, $quantity, $price, $variantName, $variantValue] = $row;

            $item = (new OrderItem)->forceFill([
                'id' => $id, 'product_name' => $name, 'quantity' => $quantity, 'price' => $price,
                'variant_name' => $variantName, 'variant_value' => $variantValue,
            ]);
            $item->setRelation('product', (new Product)->forceFill(['slug' => $slug]));

            return $item;
        });

        $order = (new Order)->forceFill([
            'id' => 1042,
            'status' => 'shipped',
            'subtotal' => 2900,
            'delivery_fee' => 80,
            'total' => 2980,
            'payment_method' => 'cod',
            'payment_status' => 'cod',
            'courier_name' => 'Sample Courier',
            'tracking_number' => 'SC123456789',
            'created_at' => now()->subDay(),
        ]);
        $order->setRelation('customer', $customer);
        $order->setRelation('items', $items);

        return $order;
    }
}
