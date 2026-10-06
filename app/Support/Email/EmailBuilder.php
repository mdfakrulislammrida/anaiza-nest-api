<?php

namespace App\Support\Email;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Support\MediaUrl;
use App\Support\WalletPayments;

/**
 * Turns a template (the admin's wording or the built-in one) and an order or customer into the subject and branded
 * HTML of one email.
 *
 * It never throws because of what an admin typed: placeholders that do not exist are left as they are, a missing
 * one is simply absent, and the branded header, footer and (for the confirmation) invoice attachment are not part of
 * the template at all.
 */
final class EmailBuilder
{
    /**
     * @param  array<string, mixed>|null  $override  unsaved form state (preview and test emails); used as if the template were switched on
     * @return array{subject: string, html: string, text: string}
     */
    public static function build(string $key, ?Order $order, ?Customer $customer, ?SiteSetting $site, ?array $override = null): array
    {
        $site ??= SiteSetting::query()->first() ?? new SiteSetting;
        $customer ??= $order?->customer;

        $parts = self::parts($key, $override);
        $plain = self::plainTokens($order, $customer, $site);
        $blocks = self::blockTokens($order, $site);

        $htmlTokens = array_merge(array_map(fn (string $value): string => e($value), $plain), $blocks);
        $definition = TemplateDefinitions::all()[$key];

        if ($parts['use_custom_html'] && filled($parts['custom_html'])) {
            $body = strtr($parts['custom_html'], $htmlTokens);
        } else {
            $intro = strtr($parts['intro_html'], $htmlTokens);
            $closing = strtr($parts['closing_html'], $htmlTokens);

            // The tables and details that belong to this email, unless the wording has already placed them.
            $written = $parts['intro_html'].$parts['closing_html'];
            $blockHtml = '';
            foreach ($definition['blocks'] as $token) {
                if (! str_contains($written, $token)) {
                    $blockHtml .= $htmlTokens[$token] ?? '';
                }
            }

            $body = $intro.$blockHtml.self::button($definition['cta_label'], rtrim((string) config('app.frontend_url'), '/').$definition['cta_path']);

            if (trim(strip_tags($closing)) !== '') {
                $body .= '<div style="margin:0 0 16px;font-family:Georgia,&quot;Times New Roman&quot;,serif;font-size:18px;line-height:28px;color:#1B2A41;">'.$closing.'</div>';
            }

            if (filled($parts['footer_note'])) {
                $body .= '<div style="margin:16px 0 0;font-size:13px;line-height:20px;color:#6F675C;">'.strtr($parts['footer_note'], $htmlTokens).'</div>';
            }
        }

        $subject = trim(preg_replace('/\s+/', ' ', strtr($parts['subject'], $plain + array_fill_keys(array_keys($blocks), ''))) ?? '');

        $html = view('emails.layout', [
            'body' => $body,
            'subject' => $subject,
            'site' => $site,
            'siteName' => $plain['{{site_name}}'],
            'logo' => MediaUrl::resolve($site->logo_navy ?: $site->logo_url),
            'tagline' => $site->tagline ?: 'Gifted, beautifully.',
        ])->render();

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => trim(html_entity_decode(strip_tags(preg_replace('#</(p|div|tr|h\d|li)>#i', "$0\n", $body) ?? $body))),
        ];
    }

    /**
     * The wording to use: the admin's when the template is switched on (field by field, blank meaning the built-in text),
     * otherwise the built-in wording.
     *
     * @param  array<string, mixed>|null  $override
     * @return array{subject: string, intro_html: string, closing_html: string, footer_note: string, use_custom_html: bool, custom_html: string}
     */
    public static function parts(string $key, ?array $override = null): array
    {
        $defaults = TemplateDefinitions::defaults($key);
        $custom = null;

        try {
            $custom = $override ?? EmailTemplate::query()->where('key', $key)->where('is_active', true)->first()?->only(
                ['subject', 'intro_html', 'closing_html', 'footer_note', 'use_custom_html', 'custom_html'],
            );
        } catch (\Throwable) {
            // A problem reading the table must never stop an email: the built-in wording is always there.
        }

        $pick = fn (string $field): string => filled($custom[$field] ?? null) ? (string) $custom[$field] : $defaults[$field];

        return [
            'subject' => $pick('subject'),
            'intro_html' => $pick('intro_html'),
            'closing_html' => $pick('closing_html'),
            'footer_note' => $pick('footer_note'),
            'use_custom_html' => (bool) ($custom['use_custom_html'] ?? false),
            'custom_html' => (string) ($custom['custom_html'] ?? ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function plainTokens(?Order $order, ?Customer $customer, SiteSetting $site): array
    {
        $method = $order?->payment_method;

        return [
            '{{customer_name}}' => (string) ($customer?->name ?? ''),
            '{{order_number}}' => (string) ($order?->id ?? ''),
            '{{order_total}}' => $order ? '৳'.number_format($order->total) : '',
            '{{order_date}}' => $order?->created_at ? $order->created_at->format('j F Y') : '',
            '{{payment_method}}' => $method === null ? '' : ($method === 'cod' ? 'Cash on delivery' : (WalletPayments::LABELS[$method] ?? strtoupper($method))),
            '{{delivery_address}}' => $customer ? implode(', ', array_filter([$customer->address, $customer->thana, $customer->district, $customer->division])) : '',
            '{{site_name}}' => (string) ($site->site_name ?: config('app.name')),
            '{{tracking_number}}' => (string) ($order?->tracking_number ?? ''),
            '{{courier_name}}' => (string) ($order?->courier_name ?? ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function blockTokens(?Order $order, SiteSetting $site): array
    {
        if ($order === null) {
            return array_fill_keys(['{{order_items_table}}', '{{order_totals}}', '{{shipping_details}}', '{{review_links}}', '{{order_details}}'], '');
        }

        $phone = (string) $site->contact_phone;

        return [
            '{{order_items_table}}' => view('emails.blocks.items', ['order' => $order])->render(),
            '{{order_totals}}' => view('emails.blocks.totals', ['order' => $order])->render(),
            '{{shipping_details}}' => view('emails.blocks.shipping', ['order' => $order])->render(),
            '{{review_links}}' => view('emails.blocks.reviews', ['order' => $order, 'frontend' => rtrim((string) config('app.frontend_url'), '/')])->render(),
            // Fixed (not in the list admins see): who it is going to, how it is paid, and where the payment stands.
            '{{order_details}}' => view('emails.blocks.details', ['order' => $order, 'phone' => $phone])->render(),
        ];
    }

    /**
     * The one main action of an email: a burgundy button with a 2px radius.
     */
    private static function button(string $label, string $url): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td style="background:#661E29;border-radius:2px;">'
            .'<a href="'.e($url).'" style="display:inline-block;padding:16px 32px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:24px;color:#F6F1E7;text-decoration:none;">'
            .e($label).'</a></td></tr></table>';
    }
}
