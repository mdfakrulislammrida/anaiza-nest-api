<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    public const ORDER_CONFIRMATION = 'order_confirmation';

    protected $fillable = [
        'key',
        'subject',
        'body_html',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Today's hardcoded wording for the order confirmation email, expressed
     * with the same {{token}} placeholders a custom template uses. This is
     * the true fallback -- kept in PHP rather than as a seeded-but-inactive
     * database row -- so it can never be corrupted by anything that happens
     * to the email_templates table, and both the default and a custom
     * template render through the exact same substitution path.
     */
    public static function defaultOrderConfirmationSubject(): string
    {
        return 'Order #{{order_number}} confirmed - {{site_name}}';
    }

    public static function defaultOrderConfirmationBodyHtml(): string
    {
        return <<<'HTML'
        <h1 style="font-size:20px;margin:0 0 4px;">Thanks for your order, {{customer_name}}!</h1>
        <p style="margin:0;color:#52525b;font-size:14px;">Order #{{order_number}} placed on {{order_date}} has been confirmed. A detailed invoice is attached to this email.</p>
        HTML;
    }

    /**
     * Substitutes every {{token}} in $subject/$body_html with the given
     * values. strtr (not regex or repeated str_replace) so every token is
     * replaced in a single pass with no cascading-replacement risk.
     *
     * @param  array<string, string>  $tokens  e.g. ['{{order_number}}' => '482']
     * @return array{subject: string, body_html: string}
     */
    public function render(array $tokens): array
    {
        return [
            'subject' => strtr($this->subject ?? '', $tokens),
            'body_html' => strtr($this->body_html ?? '', $tokens),
        ];
    }
}
