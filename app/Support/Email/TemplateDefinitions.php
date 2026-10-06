<?php

namespace App\Support\Email;

/**
 * The five transactional emails and their built-in wording, in the brand voice (docs/brand-kit-extract.md): host
 * voice, sentence case, nothing the kit does not say. The wording lives in PHP, not in a seeded database row, so
 * it can never be damaged by anything that happens to the email_templates table: a template that is switched off or
 * left blank falls back to it, field by field.
 */
final class TemplateDefinitions
{
    public const CONFIRMATION = 'order_confirmation';

    public const SHIPPED = 'order_shipped';

    public const DELIVERED = 'order_delivered';

    public const CANCELLED = 'order_cancelled';

    public const WELCOME = 'welcome';

    public const KEYS = [self::CONFIRMATION, self::SHIPPED, self::DELIVERED, self::CANCELLED, self::WELCOME];

    /** Order status => the template sent when an order changes to it. */
    public const STATUS_TEMPLATES = [
        'shipped' => self::SHIPPED,
        'delivered' => self::DELIVERED,
        'cancelled' => self::CANCELLED,
    ];

    /**
     * Every placeholder, with what it stands for. {{...}} tokens are filled with plain text; block tokens with a
     * small table or list.
     *
     * @var array<string, string>
     */
    public const TOKENS = [
        '{{customer_name}}' => 'the customer\'s name',
        '{{order_number}}' => 'the order number',
        '{{order_total}}' => 'the order total, like ৳1,450',
        '{{order_date}}' => 'the day the order was placed',
        '{{payment_method}}' => 'how the order is paid',
        '{{delivery_address}}' => 'the delivery address',
        '{{site_name}}' => 'the shop name',
        '{{tracking_number}}' => 'the courier tracking number (blank if none)',
        '{{courier_name}}' => 'the courier (blank if none)',
        '{{order_items_table}}' => 'block: the table of items ordered',
        '{{order_totals}}' => 'block: subtotal, delivery and total',
        '{{shipping_details}}' => 'block: the courier and tracking number, only when there is one',
        '{{review_links}}' => 'block: "How was it? Review your purchase" for each item',
    ];

    /**
     * @return array<string, array{label: string, description: string, cta_label: string, cta_path: string, blocks: list<string>}>
     */
    public static function all(): array
    {
        return [
            self::CONFIRMATION => [
                'label' => 'Order confirmation',
                'description' => 'Sent when a customer places an order. The invoice PDF is attached.',
                'cta_label' => 'Track your order',
                'cta_path' => '/track-order',
                // Added after the intro when the wording does not place them itself.
                'blocks' => ['{{order_items_table}}', '{{order_totals}}', '{{order_details}}'],
            ],
            self::SHIPPED => [
                'label' => 'Order shipped',
                'description' => 'Sent once, when an admin changes the order to Shipped.',
                'cta_label' => 'Track your order',
                'cta_path' => '/track-order',
                'blocks' => ['{{order_items_table}}'],
            ],
            self::DELIVERED => [
                'label' => 'Order delivered',
                'description' => 'Sent once, when an admin changes the order to Delivered. It asks for a review of each item.',
                'cta_label' => 'Shop tea sets',
                'cta_path' => '/shop',
                'blocks' => [],
            ],
            self::CANCELLED => [
                'label' => 'Order cancelled',
                'description' => 'Sent once, when an admin changes the order to Cancelled.',
                'cta_label' => 'Shop tea sets',
                'cta_path' => '/shop',
                'blocks' => ['{{order_items_table}}'],
            ],
            self::WELCOME => [
                'label' => 'Welcome',
                'description' => 'Sent after a customer registers, or signs in with Google or Facebook for the first time.',
                'cta_label' => 'Shop tea sets',
                'cta_path' => '/shop',
                'blocks' => [],
            ],
        ];
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    /**
     * @return array{subject: string, intro_html: string, closing_html: string, footer_note: string}
     */
    public static function defaults(string $key): array
    {
        return match ($key) {
            self::CONFIRMATION => [
                'subject' => 'Order #{{order_number}} confirmed - {{site_name}}',
                'intro_html' => '<p>Thank you. Your order is being packed by hand and will be with you soon.</p>'
                    .'<p>Order #{{order_number}}, placed on {{order_date}}. Your invoice is attached.</p>',
                'closing_html' => '<p>Packed by hand, sent with care.</p>',
                'footer_note' => '',
            ],
            self::SHIPPED => [
                'subject' => 'Your order #{{order_number}} has shipped - {{site_name}}',
                'intro_html' => '<p>{{customer_name}}, your order #{{order_number}} is on its way to you.</p>{{shipping_details}}',
                'closing_html' => '<p>Packed by hand, sent with care.</p>',
                'footer_note' => '',
            ],
            self::DELIVERED => [
                'subject' => 'Your order #{{order_number}} has been delivered - {{site_name}}',
                'intro_html' => '<p>Your order #{{order_number}} has been delivered. We hope you enjoy it.</p>{{review_links}}',
                'closing_html' => '',
                'footer_note' => '',
            ],
            self::CANCELLED => [
                'subject' => 'Your order #{{order_number}} has been cancelled - {{site_name}}',
                'intro_html' => '<p>Your order #{{order_number}} has been cancelled.</p>'
                    .'<p>If you did not ask for this, or you have a question, please contact us and we will help.</p>',
                'closing_html' => '',
                'footer_note' => '',
            ],
            self::WELCOME => [
                'subject' => 'Welcome to {{site_name}}',
                'intro_html' => '<p>Welcome, {{customer_name}}. Thank you for creating your account.</p>'
                    .'<p>Anaiza Nest is a Dhaka gifting house for tea sets, gift boxes and homeware, packed by hand and ready to give.</p>',
                'closing_html' => '',
                'footer_note' => '',
            ],
            default => ['subject' => '', 'intro_html' => '', 'closing_html' => '', 'footer_note' => ''],
        };
    }
}
