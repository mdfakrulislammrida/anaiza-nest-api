<?php

namespace App\Support;

class HtmlSanitizer
{
    /**
     * Downgrades every <h1> in admin-authored HTML (product descriptions,
     * CMS pages, etc.) to <h2>, so the page it's embedded in keeps exactly
     * one real H1 -- the page title -- no matter what an editor pastes in.
     * Attributes on the tag are preserved.
     */
    public static function downgradeH1(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        $html = preg_replace('/<h1(\s[^>]*)?>/i', '<h2$1>', $html);

        return preg_replace('/<\/h1\s*>/i', '</h2>', $html);
    }
}
