<?php

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;

/**
 * Helper text shown next to long-form content fields, so every form gives
 * the same writing advice. Inline styles on purpose: Filament's compiled
 * stylesheet only contains the utility classes Filament itself uses.
 */
class AuthoringGuidance
{
    /**
     * @param  string  $extra  A sentence specific to the field, shown first.
     */
    public static function html(string $extra = ''): HtmlString
    {
        $example = e(<<<'HTML'
<table>
  <thead>
    <tr><th>Option</th><th>Best for</th><th>Price</th></tr>
  </thead>
  <tbody>
    <tr><td>Option A</td><td>...</td><td>...</td></tr>
    <tr><td>Option B</td><td>...</td><td>...</td></tr>
  </tbody>
</table>
HTML);

        $extra = $extra !== '' ? e($extra).' ' : '';

        return new HtmlString(
            $extra.
            'Write headings as the questions people actually ask (e.g. &ldquo;How do I care for a ceramic tea set?&rdquo;) '.
            'using <code>&lt;h2&gt;</code>/<code>&lt;h3&gt;</code>, and keep each section self-contained: '.
            'about 50&ndash;100 words that still make sense if read on their own. '.
            'Comparison tables help readers and search engines &mdash; paste them as raw HTML below '.
            '(the visual editor can&rsquo;t build tables) and they will be styled and scroll sideways on phones.'.
            '<details style="margin-top:.5rem"><summary style="cursor:pointer">Show an example comparison table</summary>'.
            '<pre style="margin-top:.5rem;overflow-x:auto;font-size:.75rem"><code>'.$example.'</code></pre></details>'
        );
    }

    public static function faq(): string
    {
        return 'Write each question the way a customer would ask it (it is shown as a heading on the page) '.
            'and answer it in the first sentence. Keep answers short and self-contained.';
    }

    /**
     * "42 words" for a live counter. Splits on whitespace so it also works for Bangla.
     */
    public static function wordCount(?string $text): int
    {
        $text = trim((string) $text);

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text));
    }
}
