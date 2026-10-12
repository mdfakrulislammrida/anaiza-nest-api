<?php

namespace App\Support\Landing;

/**
 * Makes author CSS safe to place on a page that already has its own: every rule is limited to one wrapper element, so
 * `body { background: red }` or `.header { display: none }` in a landing page's HTML can only ever change that page's
 * own content, never the site's header, the order form or the cookie banner.
 */
final class CssScoper
{
    /** @var list<string> at-rules whose body is more rules */
    private const NESTING = ['media', 'supports', 'layer', 'container'];

    /** @var list<string> at-rules kept as they are (their bodies are not selectors) */
    private const VERBATIM = ['keyframes', '-webkit-keyframes', '-moz-keyframes'];

    /**
     * @param  list<string>  $removed  filled with a plain sentence per kind of thing taken out
     */
    public static function scope(string $css, string $scope, array &$removed = []): string
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? '';

        // Rules that pull in other files or define fonts are not allowed.
        $css = preg_replace_callback('/@(import|charset|namespace)\b[^;]*;/i', function (array $m) use (&$removed): string {
            $removed[] = 'Removed a CSS @'.strtolower($m[1]).' rule (stylesheets and fonts from other places are not allowed).';

            return '';
        }, $css) ?? '';

        return trim(self::rules($css, $scope, $removed));
    }

    /**
     * The declarations of an inline style="" attribute, with anything that can run code or load an unsafe address taken out.
     *
     * @param  list<string>  $removed
     */
    public static function declarationsOnly(string $css, array &$removed = []): string
    {
        return self::declarations($css, $removed);
    }

    /**
     * @param  list<string>  $removed
     */
    private static function rules(string $css, string $scope, array &$removed): string
    {
        $out = '';
        $length = strlen($css);
        $i = 0;

        while ($i < $length) {
            $open = strpos($css, '{', $i);

            if ($open === false) {
                break;
            }

            $prelude = trim(substr($css, $i, $open - $i));
            $close = self::matchingBrace($css, $open);
            $body = substr($css, $open + 1, $close - $open - 1);
            $i = $close + 1;

            if ($prelude === '') {
                continue;
            }

            if ($prelude[0] === '@') {
                $name = strtolower((string) strtok(substr($prelude, 1), " \t\n("));

                if (in_array($name, self::NESTING, true)) {
                    $out .= $prelude.'{'.self::rules($body, $scope, $removed).'}';
                } elseif (in_array($name, self::VERBATIM, true)) {
                    $out .= $prelude.'{'.self::keyframes($body).'}';
                } else {
                    $removed[] = 'Removed a CSS @'.$name.' rule.';
                }

                continue;
            }

            $declarations = self::declarations($body, $removed);

            if ($declarations === '') {
                continue;
            }

            $out .= implode(',', array_map(fn (string $selector): string => self::selector($selector, $scope), self::splitSelectors($prelude))).'{'.$declarations.'}';
        }

        return $out;
    }

    private static function keyframes(string $body): string
    {
        $removed = [];
        $out = '';
        $length = strlen($body);
        $i = 0;

        while ($i < $length) {
            $open = strpos($body, '{', $i);

            if ($open === false) {
                break;
            }

            $selector = trim(substr($body, $i, $open - $i));
            $close = self::matchingBrace($body, $open);
            $declarations = self::declarations(substr($body, $open + 1, $close - $open - 1), $removed);
            $i = $close + 1;

            if ($selector !== '' && $declarations !== '') {
                $out .= $selector.'{'.$declarations.'}';
            }
        }

        return $out;
    }

    private static function matchingBrace(string $css, int $open): int
    {
        $depth = 0;
        $length = strlen($css);

        for ($i = $open; $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth === 0) {
                return $i;
            }
        }

        return $length - 1;
    }

    /**
     * @return list<string>
     */
    private static function splitSelectors(string $selectors): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($selectors) as $character) {
            if ($character === '(' || $character === '[') {
                $depth++;
            } elseif ($character === ')' || $character === ']') {
                $depth--;
            }

            if ($character === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = $current;

        return array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));
    }

    private static function selector(string $selector, string $scope): string
    {
        // The page's own root and body are the wrapper.
        $rest = trim(preg_replace('/^(?:(?:html|body|:root)(?![\w-])\s*)+/i', '', $selector) ?? $selector);

        return $rest === '' ? $scope : $scope.' '.$rest;
    }

    /**
     * @param  list<string>  $removed
     */
    private static function declarations(string $body, array &$removed): string
    {
        $kept = [];

        foreach (self::splitDeclarations($body) as $declaration) {
            $declaration = trim($declaration);

            if ($declaration === '' || ! str_contains($declaration, ':')) {
                continue;
            }

            $lower = strtolower($declaration);

            if (preg_match('/expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|@import/', $lower)) {
                $removed[] = 'Removed a CSS declaration that can run code.';

                continue;
            }

            if (preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $declaration, $urls)) {
                $unsafe = false;

                foreach ($urls[2] as $url) {
                    if (! UrlRules::safeUrl($url, imagesOnlyData: true)) {
                        $unsafe = true;
                    }
                }

                if ($unsafe) {
                    $removed[] = 'Removed a CSS declaration with an address that is not allowed.';

                    continue;
                }
            }

            $kept[] = $declaration;
        }

        return implode(';', $kept);
    }

    /**
     * @return list<string>
     */
    private static function splitDeclarations(string $body): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;

        foreach (str_split($body) as $character) {
            if ($quote !== null) {
                $current .= $character;

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            }

            if ($character === ';' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = $current;

        return $parts;
    }
}
