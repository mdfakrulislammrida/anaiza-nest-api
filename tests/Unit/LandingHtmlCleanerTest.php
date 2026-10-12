<?php

namespace Tests\Unit;

use App\Support\Landing\CssScoper;
use App\Support\Landing\HtmlCleaner;
use App\Support\Landing\LandingBlocks;
use PHPUnit\Framework\TestCase;

class LandingHtmlCleanerTest extends TestCase
{
    private function clean(string $html, bool $scripts = false, array $assets = []): array
    {
        return HtmlCleaner::clean($html, 'lp-t', $scripts, $assets);
    }

    private function removedContains(array $result, string $needle): bool
    {
        foreach ($result['removed'] as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function test_script_tags_are_removed_and_reported(): void
    {
        $r = $this->clean('<p>Hi</p><script>alert(1)</script><script src="https://evil.example/x.js"></script>');

        $this->assertStringNotContainsString('<script', $r['html']);
        $this->assertStringContainsString('<p>Hi</p>', $r['html']);
        $this->assertTrue($this->removedContains($r, 'Removed 2 script tags'));
        $this->assertFalse($r['has_scripts']);
    }

    public function test_inline_event_handlers_are_removed(): void
    {
        $r = $this->clean('<div onclick="steal()" onmouseover="x()" class="a">x</div><img src="https://e.example/a.png" onerror="y()">');

        $this->assertStringNotContainsString('onclick', $r['html']);
        $this->assertStringNotContainsString('onmouseover', $r['html']);
        $this->assertStringNotContainsString('onerror', $r['html']);
        $this->assertStringContainsString('class="a"', $r['html']);
        $this->assertTrue($this->removedContains($r, '3 inline event handlers'));
    }

    public function test_javascript_addresses_are_removed_however_they_are_disguised(): void
    {
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', " java\tscript:alert(1)", 'jav&#x61;script:alert(1)', 'vbscript:x'] as $href) {
            $r = $this->clean('<a href="'.$href.'">go</a>');

            $this->assertStringNotContainsString('script:', strtolower($r['html']), $href);
            $this->assertStringNotContainsString('href=', $r['html'], $href);
        }
    }

    public function test_iframes_are_kept_only_for_youtube_and_vimeo(): void
    {
        $r = $this->clean(
            '<iframe src="https://evil.example/frame"></iframe>'
            .'<iframe src="https://www.youtube.com/embed/abc123"></iframe>'
            .'<iframe src="https://player.vimeo.com/video/42"></iframe>'
            .'<iframe src="javascript:alert(1)"></iframe>'
        );

        $this->assertStringNotContainsString('evil.example', $r['html']);
        $this->assertStringContainsString('https://www.youtube-nocookie.com/embed/abc123', $r['html']);
        $this->assertStringContainsString('https://player.vimeo.com/video/42', $r['html']);
        $this->assertSame(2, substr_count($r['html'], '<iframe'));
        $this->assertTrue($this->removedContains($r, '2 iframes'));
    }

    public function test_forms_are_removed_and_never_post_anywhere(): void
    {
        $r = $this->clean('<form action="https://evil.example/post" method="post"><label>Name</label><input name="n"><button type="submit">Go</button></form>');

        $this->assertStringNotContainsString('<form', $r['html']);
        $this->assertStringNotContainsString('<input', $r['html']);
        $this->assertStringNotContainsString('evil.example', $r['html']);
        $this->assertStringContainsString('<button type="button">Go</button>', $r['html'], 'a button cannot submit');
        $this->assertTrue($this->removedContains($r, 'Removed 1 form'));
    }

    public function test_external_stylesheets_are_removed_but_inline_styles_are_kept(): void
    {
        $r = $this->clean('<html><head><link rel="stylesheet" href="https://cdn.example/site.css"><style>.a{color:red}</style></head><body><p class="a" style="color:blue">x</p></body></html>');

        $this->assertStringNotContainsString('cdn.example', $r['html']);
        $this->assertStringContainsString('<style>.lp-t .a{color:red}</style>', $r['html']);
        $this->assertStringContainsString('style="color:blue"', $r['html']);
        $this->assertTrue($this->removedContains($r, '1 external stylesheet'));
    }

    public function test_normal_markup_classes_and_images_are_kept(): void
    {
        $html = '<section class="hero"><h2 class="t">Title</h2><ul><li>One</li></ul><img src="https://cdn.example/p.jpg" alt="A picture" width="300"><a href="https://shop.example" target="_blank">Link</a><table><tr><td colspan="2">x</td></tr></table></section>';
        $r = $this->clean($html);

        foreach (['<section class="hero">', '<h2 class="t">', '<li>One</li>', 'src="https://cdn.example/p.jpg"', 'alt="A picture"', 'width="300"', 'colspan="2"'] as $kept) {
            $this->assertStringContainsString($kept, $r['html']);
        }
        $this->assertStringContainsString('rel="noopener noreferrer"', $r['html']);
        $this->assertSame([], $r['removed']);
    }

    public function test_css_rules_are_scoped_to_the_wrapper(): void
    {
        $css = 'body{background:red} html,:root{margin:0} .header{display:none} @media (max-width:600px){.x{padding:1px}} @import url(https://e.example/a.css); @font-face{font-family:F;src:url(https://e.example/f.woff)}';
        $notes = [];
        $out = CssScoper::scope($css, '.lp-t', $notes);

        $this->assertStringContainsString('.lp-t{background:red}', $out);
        $this->assertStringContainsString('.lp-t{margin:0}', $out);
        $this->assertStringContainsString('.lp-t .header{display:none}', $out);
        $this->assertStringContainsString('@media (max-width:600px){.lp-t .x{padding:1px}}', $out);
        $this->assertStringNotContainsString('@import', $out);
        $this->assertStringNotContainsString('font-face', $out);
        $this->assertNotEmpty($notes);
    }

    public function test_css_that_can_run_code_is_dropped(): void
    {
        $out = CssScoper::scope('.a{width:expression(alert(1))} .b{background:url(javascript:alert(1))} .c{color:green}', '.lp-t');

        $this->assertStringNotContainsString('expression', $out);
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringContainsString('.lp-t .c{color:green}', $out);
    }

    public function test_relative_images_are_pointed_at_uploads_and_unmatched_ones_are_listed(): void
    {
        $r = $this->clean(
            '<img src="images/Photo.JPG"><img src="./banner.png"><img src="missing/pic.webp"><div style="background:url(images/bg.jpg)"></div>',
            false,
            ['photo.jpg' => 'https://cdn.example/storage/photo.jpg', 'banner.png' => 'https://cdn.example/storage/banner.png', 'bg.jpg' => 'https://cdn.example/storage/bg.jpg'],
        );

        $this->assertStringContainsString('src="https://cdn.example/storage/photo.jpg"', $r['html']);
        $this->assertStringContainsString('src="https://cdn.example/storage/banner.png"', $r['html']);
        $this->assertStringContainsString("url('https://cdn.example/storage/bg.jpg')", $r['html']);
        $this->assertSame(['missing/pic.webp'], $r['unresolved']);
    }

    public function test_scripts_stay_only_when_allowed_and_everything_else_is_still_cleaned(): void
    {
        $r = $this->clean('<div onclick="a()">x</div><script>ok()</script><form action="https://e.example"><input></form><iframe src="https://e.example"></iframe>', scripts: true);

        $this->assertStringContainsString('<script>ok()</script>', $r['html']);
        $this->assertStringContainsString('onclick="a()"', $r['html']);
        $this->assertTrue($r['has_scripts']);
        $this->assertStringNotContainsString('<form', $r['html']);
        $this->assertStringNotContainsString('<iframe', $r['html']);
    }

    public function test_ids_and_anchor_links_cannot_collide_with_the_site(): void
    {
        $r = $this->clean('<a href="#checkout-form">jump</a><div id="checkout-form">x</div>');

        $this->assertStringContainsString('id="lp-t-checkout-form"', $r['html']);
        $this->assertStringContainsString('href="#lp-t-checkout-form"', $r['html']);
    }

    public function test_an_uploaded_file_contributes_its_styles_and_body(): void
    {
        $out = LandingBlocks::importHtmlFile('<!doctype html><html><head><title>T</title><style>.a{color:red}</style></head><body><h1 class="a">Hello</h1><p>World</p></body></html>');

        $this->assertStringContainsString('<style>.a{color:red}</style>', $out);
        $this->assertStringContainsString('<h1 class="a">Hello</h1>', $out);
        $this->assertStringNotContainsString('<title>', $out);
        $this->assertStringNotContainsString('<body', $out);
    }
}
