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

    /**
     * The placeholders and links the review and cart emails get from the shop, made up for the admin's preview and test email.
     *
     * @return array{plain: array<string, string>, blocks: array<string, string>, cta_url: string, unsubscribe_url: string}
     */
    public static function context(string $key): array
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $items = [
            ['name' => 'Sample tea set for two', 'variant' => null, 'quantity' => 1, 'price' => 2200],
            ['name' => 'Sample ceramic mug', 'variant' => 'Colour: Ivory', 'quantity' => 2, 'price' => 350],
        ];

        return [
            'plain' => [
                '{{customer_name}}' => 'Sample customer',
                '{{product_names}}' => 'Sample tea set for two, Sample ceramic mug',
                '{{recovery_link}}' => $frontend.'/cart?recover=sample',
                '{{coupon_code}}' => $key === TemplateDefinitions::CART_OFFER ? 'ANZ-7K3M9' : '',
                '{{coupon_value}}' => $key === TemplateDefinitions::CART_OFFER ? '10%' : '',
                '{{coupon_expires_at}}' => $key === TemplateDefinitions::CART_OFFER ? now()->addHours(48)->timezone('Asia/Dhaka')->format('j F Y, g:i a') : '',
                '{{minimum_order}}' => $key === TemplateDefinitions::CART_OFFER ? 'It applies to orders of ৳1,500 or more.' : '',
            ],
            'blocks' => [
                '{{cart_items_block}}' => view('emails.blocks.cart', ['items' => $items])->render(),
            ],
            'cta_url' => in_array($key, [TemplateDefinitions::REVIEW_REQUEST, TemplateDefinitions::REVIEW_REMINDER], true)
                ? $frontend.'/product/sample-tea-set?review=sample#reviews'
                : $frontend.'/cart?recover=sample',
            'unsubscribe_url' => $frontend.'/unsubscribe/sample',
        ];
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
