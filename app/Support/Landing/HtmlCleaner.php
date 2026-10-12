<?php

namespace App\Support\Landing;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * Cleans author HTML (pasted or uploaded) for a landing page.
 *
 * It works from an allow-list: a tag or attribute that is not known to be harmless is taken out, and the result is
 * reported in plain words so the author sees exactly what changed. What it does:
 *  - removes script tags, inline event handlers (onclick and the like) and javascript: addresses;
 *  - keeps iframes only for YouTube and Vimeo (YouTube is switched to its no-cookie host);
 *  - removes forms (the order form block is the way to order) and form fields;
 *  - removes external stylesheets, and puts every inline <style> rule under one wrapper class so it cannot restyle the site;
 *  - rewrites relative image paths to uploaded images where the file name matches, and lists the ones it could not match.
 *
 * With $allowScripts, script tags, inline handlers and javascript: addresses are left alone. Everything else still applies.
 */
final class HtmlCleaner
{
    /** Tags kept as they are. */
    private const ALLOWED_TAGS = [
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'bdi', 'bdo', 'blockquote', 'br', 'button', 'caption', 'center', 'cite',
        'code', 'col', 'colgroup', 'dd', 'del', 'details', 'dfn', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'i', 'img', 'ins', 'kbd', 'label', 'li', 'main', 'mark',
        'nav', 'ol', 'p', 'picture', 'pre', 'q', 's', 'samp', 'section', 'small', 'source', 'span', 'strong', 'sub', 'summary',
        'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'u', 'ul', 'var', 'video', 'audio', 'wbr', 'iframe',
        'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon', 'defs', 'lineargradient', 'radialgradient',
        'stop', 'text', 'tspan', 'clippath', 'mask',
    ];

    /** Removed together with everything inside them. */
    private const DROP_WITH_CONTENT = ['script', 'noscript', 'object', 'embed', 'applet', 'frame', 'frameset', 'param', 'template', 'portal', 'canvas'];

    /** Page-level tags that mean nothing inside a block. */
    private const HEAD_TAGS = ['meta', 'base', 'link', 'title'];

    private const FORM_FIELDS = ['input', 'select', 'textarea', 'option', 'optgroup', 'datalist', 'output', 'keygen'];

    private const ALLOWED_ATTRIBUTES = [
        'class', 'id', 'style', 'title', 'alt', 'lang', 'dir', 'role', 'tabindex', 'hidden', 'href', 'target', 'rel', 'src', 'srcset',
        'sizes', 'width', 'height', 'loading', 'decoding', 'colspan', 'rowspan', 'scope', 'headers', 'span', 'controls', 'poster',
        'preload', 'muted', 'loop', 'autoplay', 'playsinline', 'type', 'start', 'reversed', 'datetime', 'cite', 'open', 'allow',
        'allowfullscreen', 'frameborder', 'referrerpolicy', 'media', 'name', 'for', 'align', 'valign',
        // SVG drawing attributes.
        'viewbox', 'xmlns', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'fill-rule',
        'clip-rule', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'points', 'transform', 'offset', 'stop-color',
        'stop-opacity', 'opacity', 'fill-opacity', 'stroke-opacity', 'gradientunits', 'gradienttransform', 'preserveaspectratio',
        'font-size', 'text-anchor', 'font-family', 'font-weight', 'dominant-baseline', 'clip-path', 'mask',
    ];

    /** Attributes holding one address. */
    private const URL_ATTRIBUTES = ['href', 'src', 'poster', 'cite', 'action', 'formaction', 'background', 'data'];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, list<string>> */
    private array $detail = [];

    /** @var list<string> */
    private array $cssNotes = [];

    /** @var array<string, true> */
    private array $unresolved = [];

    private bool $keptScripts = false;

    /**
     * @param  array<string, string>  $assets  lower-case file name => the address it was uploaded to
     */
    private function __construct(
        private readonly string $scope,
        private readonly bool $allowScripts,
        private readonly array $assets,
    ) {}

    /**
     * @param  array<string, string>  $assets
     * @return array{html: string, removed: list<string>, unresolved: list<string>, has_scripts: bool}
     */
    public static function clean(string $html, string $scopeClass, bool $allowScripts = false, array $assets = []): array
    {
        return (new self($scopeClass, $allowScripts, $assets))->run($html);
    }

    /**
     * @return array{html: string, removed: list<string>, unresolved: list<string>, has_scripts: bool}
     */
    private function run(string $html): array
    {
        if (trim($html) === '') {
            return ['html' => '', 'removed' => [], 'unresolved' => [], 'has_scripts' => false];
        }

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $body = $document->body;

        // Page-level tags in the head are not part of a block.
        foreach ($document->querySelectorAll('head > link, head > meta, head > base, head > title') as $tag) {
            $this->noteHead($tag);
        }

        // A fragment that opens with a script lands it in the head; it belongs with the rest of the content.
        $anchor = $body->firstChild;

        foreach (iterator_to_array($document->querySelectorAll('head > script'), false) as $script) {
            $body->insertBefore($script, $anchor);
        }

        // Every <style> wherever it is (a fragment's first style lands in the head), scoped and taken out for re-adding first.
        $styles = [];

        foreach (iterator_to_array($document->querySelectorAll('style'), false) as $style) {
            $scoped = CssScoper::scope($style->textContent ?? '', '.'.$this->scope, $this->cssNotes);
            $scoped = $this->rewriteCssUrls($scoped);

            if ($scoped !== '') {
                $styles[] = $scoped;
            }

            $style->remove();
        }

        foreach (iterator_to_array($body->querySelectorAll('*'), false) as $element) {
            if ($element->isConnected) {
                $this->element($element);
            }
        }

        $this->comments($body);

        $out = '';

        foreach ($styles as $css) {
            // A </style> inside the CSS would end the block early.
            $out .= '<style>'.str_ireplace('</style', '<\/style', $css).'</style>';
        }

        $out .= trim($body->innerHTML);

        return [
            'html' => $out,
            'removed' => $this->report(),
            'unresolved' => array_keys($this->unresolved),
            'has_scripts' => $this->keptScripts,
        ];
    }

    private function element(Element $element): void
    {
        $tag = strtolower($element->localName);

        if ($tag === 'script') {
            if ($this->allowScripts) {
                $this->keptScripts = true;
                $this->scriptSource($element);

                return;
            }

            $this->count('script');
            $element->remove();

            return;
        }

        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            $this->count('object');
            $element->remove();

            return;
        }

        if (in_array($tag, self::HEAD_TAGS, true)) {
            $this->noteHead($element);
            $element->remove();

            return;
        }

        if ($tag === 'iframe') {
            $this->iframe($element);

            return;
        }

        if ($tag === 'form') {
            $this->count('form');
            $this->unwrap($element);

            return;
        }

        if (in_array($tag, self::FORM_FIELDS, true)) {
            $this->count('field');
            $element->remove();

            return;
        }

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            $this->count('tag', $tag);
            $this->unwrap($element);

            return;
        }

        $this->attributes($element, $tag);
    }

    private function iframe(Element $element): void
    {
        $embed = UrlRules::embedUrl((string) $element->getAttribute('src'));

        if ($embed === null) {
            $this->count('iframe', (string) $element->getAttribute('src'));
            $element->remove();

            return;
        }

        foreach (iterator_to_array($element->attributes, false) as $attribute) {
            $element->removeAttribute($attribute->nodeName);
        }

        $element->setAttribute('src', $embed);
        $element->setAttribute('loading', 'lazy');
        $element->setAttribute('allowfullscreen', '');
        $element->setAttribute('allow', 'autoplay; fullscreen; picture-in-picture');
        $element->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        $element->setAttribute('title', 'Video');
    }

    private function attributes(Element $element, string $tag): void
    {
        foreach (iterator_to_array($element->attributes, false) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->value;

            if (str_starts_with($name, 'on')) {
                if ($this->allowScripts) {
                    $this->keptScripts = true;

                    continue;
                }

                $this->count('handler');
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if ($name === 'style') {
                $element->setAttribute($attribute->nodeName, $this->rewriteCssUrls($this->inlineStyle($value)));

                continue;
            }

            if (in_array($name, self::URL_ATTRIBUTES, true) || $name === 'xlink:href') {
                if (! $this->address($element, $attribute->nodeName, $name, $value, $tag)) {
                    continue;
                }

                continue;
            }

            if ($name === 'srcset') {
                $element->setAttribute($attribute->nodeName, $this->srcset($value));

                continue;
            }

            if ($name === 'id') {
                $element->setAttribute('id', $this->scope.'-'.preg_replace('/[^A-Za-z0-9_\-]/', '', $value));

                continue;
            }

            if ($name === 'for') {
                $element->setAttribute('for', $this->scope.'-'.preg_replace('/[^A-Za-z0-9_\-]/', '', $value));

                continue;
            }

            if ($name === 'target') {
                if (strtolower($value) === '_blank') {
                    $element->setAttribute('rel', 'noopener noreferrer');
                } else {
                    $element->removeAttribute($attribute->nodeName);
                }

                continue;
            }

            if (str_starts_with($name, 'aria-') || str_starts_with($name, 'data-')) {
                continue;
            }

            if (! in_array($name, self::ALLOWED_ATTRIBUTES, true)) {
                $this->count('attribute', $name);
                $element->removeAttribute($attribute->nodeName);
            }
        }

        // A button here is a plain button: it can never submit anything.
        if ($tag === 'button') {
            $element->setAttribute('type', 'button');
        }
    }

    /**
     * @return bool whether the attribute was kept
     */
    private function address(Element $element, string $actualName, string $name, string $value, string $tag): bool
    {
        if ($name === 'action' || $name === 'formaction') {
            $element->removeAttribute($actualName);

            return false;
        }

        $normal = UrlRules::normalise($value);

        if (preg_match('/^(javascript|vbscript):/i', $normal)) {
            if ($this->allowScripts) {
                $this->keptScripts = true;

                return true;
            }

            $this->count('jsurl');
            $element->removeAttribute($actualName);

            return false;
        }

        if (! UrlRules::safeUrl($value)) {
            $this->count('address', $normal);
            $element->removeAttribute($actualName);

            return false;
        }

        if ($name === 'href' && str_starts_with($normal, '#') && strlen($normal) > 1) {
            $element->setAttribute($actualName, '#'.$this->scope.'-'.preg_replace('/[^A-Za-z0-9_\-]/', '', substr($normal, 1)));

            return true;
        }

        if (in_array($name, ['src', 'poster'], true) && UrlRules::isRelative($value)) {
            $element->setAttribute($actualName, $this->resolve($normal));
        }

        return true;
    }

    private function srcset(string $value): string
    {
        $parts = [];

        foreach (explode(',', $value) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            $pieces = preg_split('/\s+/', $candidate, 2);
            $url = $pieces[0];

            if (! UrlRules::safeUrl($url, imagesOnlyData: true)) {
                $this->count('address', $url);

                continue;
            }

            $parts[] = (UrlRules::isRelative($url) ? $this->resolve($url) : $url).(isset($pieces[1]) ? ' '.$pieces[1] : '');
        }

        return implode(', ', $parts);
    }

    private function inlineStyle(string $value): string
    {
        $notes = [];
        $clean = CssScoper::declarationsOnly($value, $notes);
        array_push($this->cssNotes, ...$notes);

        return $clean;
    }

    /** Relative picture addresses in CSS (url(images/a.png)) are pointed at the uploaded copy. */
    private function rewriteCssUrls(string $css): string
    {
        return preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', function (array $match): string {
            $url = $match[2];

            return UrlRules::isRelative($url) ? "url('".$this->resolve(UrlRules::normalise($url))."')" : $match[0];
        }, $css) ?? $css;
    }

    private function resolve(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $file = strtolower(basename($path));

        if (isset($this->assets[$file])) {
            return $this->assets[$file];
        }

        $this->unresolved[$path !== '' ? $path : $url] = true;

        return $url;
    }

    private function scriptSource(Element $element): void
    {
        $src = (string) $element->getAttribute('src');

        if ($src !== '' && ! preg_match('~^https://~i', UrlRules::normalise($src))) {
            $element->removeAttribute('src');
        }
    }

    private function noteHead(Node $tag): void
    {
        if (! $tag instanceof Element) {
            return;
        }

        $name = strtolower($tag->localName);

        if ($name === 'link' && str_contains(strtolower((string) $tag->getAttribute('rel')), 'stylesheet')) {
            $this->count('stylesheet', (string) $tag->getAttribute('href'));
        } elseif ($name === 'link' || $name === 'base' || $name === 'meta') {
            $this->count('head', $name);
        }

        $tag->remove();
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

    private function comments(Node $node): void
    {
        foreach (iterator_to_array($node->childNodes, false) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $child->parentNode?->removeChild($child);
            } elseif ($child->hasChildNodes()) {
                $this->comments($child);
            }
        }
    }

    private function count(string $kind, ?string $detail = null): void
    {
        $this->counts[$kind] = ($this->counts[$kind] ?? 0) + 1;

        if ($detail !== null && $detail !== '' && ! in_array($detail, $this->detail[$kind] ?? [], true)) {
            $this->detail[$kind][] = mb_substr($detail, 0, 120);
        }
    }

    /**
     * @return list<string>
     */
    private function report(): array
    {
        $lines = [];
        $plural = fn (int $n, string $one, string $many): string => $n === 1 ? $one : $many;

        foreach ($this->counts as $kind => $n) {
            $detail = implode(', ', array_slice($this->detail[$kind] ?? [], 0, 5));

            $lines[] = match ($kind) {
                'script' => "Removed {$n} script ".$plural($n, 'tag', 'tags').'. Scripts are only allowed for people who have been given that permission.',
                'handler' => "Removed {$n} inline event ".$plural($n, 'handler', 'handlers').' such as onclick.',
                'jsurl' => "Removed {$n} ".$plural($n, 'link', 'links').' that would run code (javascript:).',
                'iframe' => "Removed {$n} ".$plural($n, 'iframe', 'iframes').' (only YouTube and Vimeo videos can be embedded)'.($detail !== '' ? ": {$detail}" : '').'.',
                'form' => "Removed {$n} ".$plural($n, 'form', 'forms').'. A form in custom HTML cannot post anywhere; use the Order form block to take orders.',
                'field' => "Removed {$n} form ".$plural($n, 'field', 'fields').'.',
                'stylesheet' => "Removed {$n} external ".$plural($n, 'stylesheet', 'stylesheets').($detail !== '' ? ": {$detail}" : '').'. Put the rules in a style tag instead.',
                'object' => "Removed {$n} embedded ".$plural($n, 'object', 'objects').' (object, embed, applet, canvas or similar).',
                'tag' => "Removed {$n} unsupported ".$plural($n, 'tag', 'tags').' and kept the text inside'.($detail !== '' ? ": {$detail}" : '').'.',
                'attribute' => "Removed {$n} ".$plural($n, 'attribute', 'attributes').' that are not allowed'.($detail !== '' ? ": {$detail}" : '').'.',
                'address' => "Removed {$n} ".$plural($n, 'address', 'addresses').' that are not allowed'.($detail !== '' ? ": {$detail}" : '').'.',
                'head' => "Removed {$n} page-level ".$plural($n, 'tag', 'tags').' (meta, base or link).',
                default => "Removed {$n} {$kind}.",
            };
        }

        foreach (array_values(array_unique($this->cssNotes)) as $note) {
            $lines[] = $note;
        }

        return $lines;
    }
}
