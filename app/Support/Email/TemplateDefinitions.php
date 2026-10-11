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

    public const REVIEW_REQUEST = 'review_request';

    public const REVIEW_REMINDER = 'review_request_reminder';

    public const CART_REMINDER = 'cart_reminder';

    public const CART_OFFER = 'cart_offer';

    public const KEYS = [
        self::CONFIRMATION, self::SHIPPED, self::DELIVERED, self::CANCELLED, self::WELCOME,
        self::REVIEW_REQUEST, self::REVIEW_REMINDER, self::CART_REMINDER, self::CART_OFFER,
    ];

    /** The emails that go to someone who has not placed an order: each carries a one-click unsubscribe link. */
    public const MARKETING = [self::REVIEW_REQUEST, self::REVIEW_REMINDER, self::CART_REMINDER, self::CART_OFFER];

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
        '{{product_names}}' => 'review requests: the names of what was ordered',
        '{{cart_items_block}}' => 'cart emails: block with the pieces left in the cart',
        '{{recovery_link}}' => 'cart emails: the link that opens the saved cart',
        '{{coupon_code}}' => 'cart offer: the code made for this customer',
        '{{coupon_value}}' => 'cart offer: what the code takes off, like 10% or ৳200',
        '{{coupon_expires_at}}' => 'cart offer: the exact end of the code, in Dhaka time',
        '{{minimum_order}}' => 'cart offer: the minimum order sentence (blank when there is none)',
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
            self::REVIEW_REQUEST => [
                'label' => 'Review request',
                'description' => 'Sent once, a few days after an order is delivered (see Review requests). Each piece has its own review link.',
                'cta_label' => 'Write a review',
                'cta_path' => '/shop',
                'blocks' => ['{{review_links}}'],
            ],
            self::REVIEW_REMINDER => [
                'label' => 'Review request reminder',
                'description' => 'One gentle reminder, only when the review request has not been answered.',
                'cta_label' => 'Write a review',
                'cta_path' => '/shop',
                'blocks' => ['{{review_links}}'],
            ],
            self::CART_REMINDER => [
                'label' => 'Cart reminder',
                'description' => 'Sent when a cart or checkout was left (see Cart recovery). No offer.',
                'cta_label' => 'Open your cart',
                'cta_path' => '/cart',
                'blocks' => ['{{cart_items_block}}'],
            ],
            self::CART_OFFER => [
                'label' => 'Cart offer',
                'description' => 'The second cart email, with one plain offer and its real end time.',
                'cta_label' => 'Open your cart',
                'cta_path' => '/cart',
                'blocks' => ['{{cart_items_block}}'],
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
            self::REVIEW_REQUEST => [
                'subject' => 'How has it settled in? Order #{{order_number}}',
                'intro_html' => '<p>Hello {{customer_name}},</p>'
                    .'<p>We hope your order #{{order_number}} has found its place. If you have a minute, a few honest words help the next person choose well.</p>'
                    .'{{review_links}}',
                'closing_html' => '<p>Packed by hand, sent with care.</p>',
                'footer_note' => '',
            ],
            self::REVIEW_REMINDER => [
                'subject' => 'A quiet reminder about order #{{order_number}}',
                'intro_html' => '<p>Hello {{customer_name}},</p>'
                    .'<p>A gentle note, in case the first one slipped past: we would love to hear how order #{{order_number}} is getting on.</p>'
                    .'{{review_links}}',
                'closing_html' => '<p>Thank you for choosing us.</p>',
                'footer_note' => '',
            ],
            self::CART_REMINDER => [
                'subject' => 'Something you chose is still waiting',
                'intro_html' => '<p>Hello {{customer_name}},</p>'
                    .'<p>You chose something lovely, and we have kept it for you. Your cart is saved, so you can pick up exactly where you left off.</p>'
                    .'{{cart_items_block}}',
                'closing_html' => '<p>Order now, pay on delivery.</p>',
                'footer_note' => '',
            ],
            self::CART_OFFER => [
                'subject' => 'A little something for your cart, {{customer_name}}',
                'intro_html' => '<p>Hello {{customer_name}},</p>'
                    .'<p>The pieces you chose are still in your cart. To make the choice a little easier, here is {{coupon_value}} off your order with the code <strong>{{coupon_code}}</strong>.</p>'
                    .'{{cart_items_block}}'
                    .'<p>The code is yours alone, works once, and ends on {{coupon_expires_at}}. {{minimum_order}}</p>',
                'closing_html' => '<p>Order now, pay on delivery.</p>',
                'footer_note' => '',
            ],
            default => ['subject' => '', 'intro_html' => '', 'closing_html' => '', 'footer_note' => ''],
        };
    }
}
