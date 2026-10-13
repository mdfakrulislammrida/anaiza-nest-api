<?php

namespace App\Support;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * The one cleaner for long written content: a category's long description, a page's content and a blog article.
 *
 * It works from an allow-list. What may stay: paragraphs, headings h1 to h4, strong, em, del, br, links, lists, quotes,
 * preformatted text, rules, pictures with their caption, and tables (table, thead, tbody, tr, th, td). On those, only the
 * attributes the content needs: href on a link; src, alt, width and height on a picture; colspan and rowspan (1 to 6) on a cell.
 * No class, no style, no id, no scripts, no event handlers, no frames, no forms.
 *
 * The one rule that matters for existing content: when nothing needs to be taken out, the text comes back exactly as it was
 * given, byte for byte. A save by someone who never touched a table, a script or a class changes nothing. Only content that
 * has something to remove is re-written (and the list of what was removed is returned).
 */
final class ContentHtml
{
    /** @var list<string> Kept as they are. */
    private const ALLOWED = [
        'p', 'h1', 'h2', 'h3', 'h4', 'strong', 'em', 'del', 'br', 'a', 'ul', 'ol', 'li', 'blockquote', 'pre', 'hr',
        'figure', 'figcaption', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];

    /** @var array<string, string> Said another way. */
    private const RENAMED = ['b' => 'strong', 'i' => 'em', 'h5' => 'h4', 'h6' => 'h4', 'strike' => 'del', 's' => 'del'];

    /** @var list<string> Removed together with everything inside them. */
    private const DROPPED = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select',
        'textarea', 'noscript', 'template', 'canvas', 'svg', 'math', 'audio', 'video', 'link', 'meta', 'base', 'title', 'head',
    ];

    /** @var list<string> What the visual editor (Trix) can show and write back without losing anything. */
    private const VISUAL = [
        'p', 'h1', 'h2', 'h3', 'strong', 'em', 'del', 'a', 'ul', 'ol', 'li', 'blockquote', 'pre', 'br', 'span', 'figure',
        'figcaption', 'img', 'u', 'div', 'b', 'i', 'code',
    ];

    /** @var array<string, int> */
    private array $counts = [];

    private int $changes = 0;

    private function __construct() {}

    /**
     * @return array{html: string, changed: bool, removed: list<string>}
     */
    public static function clean(?string $html): array
    {
        $original = (string) $html;

        if (trim($original) === '') {
            return ['html' => $original, 'changed' => false, 'removed' => []];
        }

        return (new self)->run($original);
    }

    /**
     * What the API serves: cleaned, and every h1 shown as an h2 (the page's title is its one h1).
     */
    public static function forOutput(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        return HtmlSanitizer::downgradeH1(self::clean($html)['html']);
    }

    /**
     * Whether the visual editor would lose something if it opened this: a table, a rule, an h4, anything it has no button
     * for. Such content opens in the HTML box, where it is kept exactly as stored.
     */
    public static function needsHtmlEditor(?string $html): bool
    {
        if ($html === null || ! str_contains($html, '<')) {
            return false;
        }

        if (preg_match_all('/<\s*([a-z][a-z0-9]*)/i', $html, $matches) === false) {
            return false;
        }

        foreach ($matches[1] as $tag) {
            if (! in_array(strtolower($tag), self::VISUAL, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{html: string, changed: bool, removed: list<string>}
     */
    private function run(string $original): array
    {
        $document = HTMLDocument::createFromString($original, LIBXML_NOERROR);

        // A whole file (doctype, html, head, body): only its body is kept.
        if (preg_match('/<(!doctype|html|head|body)\b/i', $original) === 1) {
            $this->count('document');
            $this->changes++;
        }

        // A fragment that opens with a style or a script lands it in the head: it is dropped, and counted.
        foreach (iterator_to_array($document->querySelectorAll('head > *'), false) as $stray) {
            $this->count($stray->localName === 'script' ? 'script' : (string) $stray->localName);
            $this->changes++;
        }

        $this->walk($document->body);

        if ($this->changes === 0) {
            return ['html' => $original, 'changed' => false, 'removed' => []];
        }

        return ['html' => trim($document->body->innerHTML), 'changed' => true, 'removed' => $this->report()];
    }

    private function walk(Node $parent): void
    {
        foreach (iterator_to_array($parent->childNodes, false) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $parent->removeChild($child);
                $this->changes++;

                continue;
            }

            if (! $child instanceof Element) {
                continue;
            }

            $this->element($child);
        }
    }

    private function element(Element $element): void
    {
        $tag = strtolower($element->localName);

        if (in_array($tag, self::DROPPED, true)) {
            $this->count($tag);
            $this->changes++;
            $element->remove();

            return;
        }

        if (isset(self::RENAMED[$tag])) {
            $element = $this->rename($element, self::RENAMED[$tag]);
            $tag = strtolower($element->localName);
            $this->changes++;
        }

        if ($tag === 'div') {
            // A paragraph of Trix's older output, or a wrapper: a paragraph when it holds only text and inline markup.
            $this->changes++;
            $this->walk($element);

            if ($this->hasBlockChild($element)) {
                $this->unwrap($element);
            } else {
                $this->rename($element, 'p');
            }

            return;
        }

        if (! in_array($tag, self::ALLOWED, true)) {
            $this->count('tag', $tag);
            $this->changes++;
            $this->walk($element);
            $this->unwrap($element);

            return;
        }

        $this->attributes($element, $tag);
        $this->walk($element);

        // A link with no address says nothing.
        if ($tag === 'a' && ! $element->hasAttribute('href')) {
            $this->changes++;
            $this->unwrap($element);
        }
    }

    private function attributes(Element $element, string $tag): void
    {
        $allowed = match ($tag) {
            'a' => ['href'],
            'img' => ['src', 'alt', 'width', 'height'],
            'th' => ['colspan', 'rowspan'],
            'td' => ['colspan', 'rowspan'],
            default => [],
        };

        foreach (iterator_to_array($element->attributes, false) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->value;

            $keep = in_array($name, $allowed, true) && match ($name) {
                'href' => $this->safeLink($value),
                'src' => $this->safePicture($value),
                'colspan', 'rowspan' => preg_match('/^[1-6]$/', trim($value)) === 1,
                'width', 'height' => preg_match('/^\d{1,4}$/', trim($value)) === 1,
                default => true,
            };

            if (! $keep) {
                $this->count('attribute', $name);
                $this->changes++;
                $element->removeAttribute($attribute->nodeName);
            }
        }
    }

    private function safeLink(string $value): bool
    {
        $value = preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';

        if ($value === '') {
            return false;
        }

        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $value)) {
            return (bool) preg_match('/^(https?|mailto|tel):/i', $value);
        }

        return true;
    }

    private function safePicture(string $value): bool
    {
        $value = preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';

        if ($value === '') {
            return false;
        }

        return ! preg_match('/^[a-z][a-z0-9+.\-]*:/i', $value) || (bool) preg_match('/^https?:/i', $value);
    }

    private function hasBlockChild(Element $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element && in_array(strtolower($child->localName), ['p', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'pre', 'hr', 'table', 'figure', 'div'], true)) {
                return true;
            }
        }

        return false;
    }

    private function rename(Element $element, string $to): Element
    {
        $document = $element->ownerDocument;
        $replacement = $document->createElement($to);

        foreach (iterator_to_array($element->attributes, false) as $attribute) {
            $replacement->setAttribute($attribute->nodeName, (string) $attribute->value);
        }

        while ($element->firstChild !== null) {
            $replacement->appendChild($element->firstChild);
        }

        $element->parentNode?->replaceChild($replacement, $element);

        return $replacement;
    }

    private function unwrap(Element $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $element->remove();
    }

    private function count(string $kind, ?string $detail = null): void
    {
        $key = $detail === null ? $kind : "{$kind}:{$detail}";
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }

    /**
     * @return list<string>
     */
    private function report(): array
    {
        $lines = [];
        $attributes = [];
        $tags = [];

        foreach ($this->counts as $key => $n) {
            [$kind, $detail] = array_pad(explode(':', $key, 2), 2, null);

            if ($kind === 'attribute') {
                $attributes[] = "{$detail} ({$n})";
            } elseif ($kind === 'tag') {
                $tags[] = "<{$detail}>";
            } else {
                $lines[] = match ($kind) {
                    'document' => 'Kept only the body of the file: the shop supplies its own page around it.',
                    'script' => "Removed {$n} script ".($n === 1 ? 'tag' : 'tags').'.',
                    'style' => "Removed {$n} style ".($n === 1 ? 'block' : 'blocks').'. All styling comes from the shop\'s own design.',
                    'iframe' => "Removed {$n} embedded ".($n === 1 ? 'frame' : 'frames').'.',
                    'form', 'input', 'button', 'select', 'textarea' => "Removed {$n} form ".($n === 1 ? 'element' : 'elements')." (<{$kind}>).",
                    default => "Removed {$n} <{$kind}> ".($n === 1 ? 'element' : 'elements').'.',
                };
            }
        }

        if ($attributes !== []) {
            $lines[] = 'Removed attributes the shop does not allow: '.implode(', ', $attributes).'. Class, style and id are never kept: the design is the shop\'s own.';
        }

        if ($tags !== []) {
            $lines[] = 'Removed tags the shop does not use and kept their text: '.implode(', ', array_unique($tags)).'.';
        }

        if ($lines === []) {
            $lines[] = 'Tidied the HTML (wrappers and comments).';
        }

        return $lines;
    }
}
