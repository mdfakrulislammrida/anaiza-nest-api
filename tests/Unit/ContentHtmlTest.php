<?php

namespace Tests\Unit;

use App\Support\ContentHtml;
use PHPUnit\Framework\TestCase;

class ContentHtmlTest extends TestCase
{
    private const TABLE = '<table><thead><tr><th>Budget</th><th>What you get</th></tr></thead><tbody><tr><td>Under ৳1,500</td><td>A single piece &amp; a note</td></tr></tbody></table>';

    public function test_a_table_survives_and_the_text_comes_back_byte_for_byte(): void
    {
        $html = '<h2>Gifts</h2><p>Hand <em>packed</em> &amp; wrapped. <a href="/pages/shipping">Shipping</a></p>'.self::TABLE."\n<p>Done.</p>";

        $result = ContentHtml::clean($html);

        $this->assertFalse($result['changed']);
        $this->assertSame($html, $result['html']);
        $this->assertSame([], $result['removed']);
    }

    public function test_untouched_markup_the_parser_would_rewrite_is_still_returned_as_given(): void
    {
        $html = "<table><tr><td>No tbody here</td></tr></table>\n\n<p>Unclosed paragraph<p>Another";

        $this->assertSame($html, ContentHtml::clean($html)['html']);
    }

    public function test_scripts_styles_classes_and_event_handlers_are_removed_and_reported(): void
    {
        $html = '<h2 class="x" id="a" style="color:red">Title</h2><script>alert(1)</script><style>p{color:red}</style>'
            .'<p onclick="x()">Hi <a href="/ok" class="b" target="_blank">ok</a></p>'
            .'<table class="t" style="width:1px"><tr><td colspan="2" style="x">c</td></tr></table><iframe src="https://example.test"></iframe>';

        $result = ContentHtml::clean($html);

        $this->assertTrue($result['changed']);
        $this->assertSame('<h2>Title</h2><p>Hi <a href="/ok">ok</a></p><table><tbody><tr><td colspan="2">c</td></tr></tbody></table>', $result['html']);
        $report = implode(' ', $result['removed']);
        $this->assertStringContainsString('script', $report);
        $this->assertStringContainsString('style', $report);
        $this->assertStringContainsString('frame', $report);
        $this->assertStringContainsString('class', $report);
        $this->assertStringContainsString('onclick', $report);
    }

    public function test_only_the_table_tags_and_attributes_the_content_needs_are_kept(): void
    {
        $html = '<table border="1" width="100%"><caption>Hidden</caption><thead><tr><th scope="col" colspan="2" align="left">A</th></tr></thead>'
            .'<tbody><tr><td rowspan="9" bgcolor="red">B</td></tr></tbody><tfoot><tr><td>F</td></tr></tfoot></table>';

        $result = ContentHtml::clean($html);

        $this->assertStringContainsString('<th colspan="2">A</th>', $result['html']);
        $this->assertStringNotContainsString('border', $result['html']);
        $this->assertStringNotContainsString('bgcolor', $result['html']);
        $this->assertStringNotContainsString('rowspan', $result['html'], 'A span of 9 is not a table of this shop.');
        $this->assertStringNotContainsString('<caption', $result['html']);
        $this->assertStringContainsString('Hidden', $result['html'], 'The text of a removed tag stays.');
    }

    public function test_dangerous_link_addresses_are_removed(): void
    {
        $result = ContentHtml::clean('<p><a href="javascript:alert(1)">a</a> <a href=" JaVaScRiPt:x">b</a> <a href="data:text/html,x">c</a> <a href="https://example.test/ok">d</a> <a href="mailto:a@example.test">e</a> <a href="/path#frag">f</a></p>');

        $this->assertSame('<p>a b c <a href="https://example.test/ok">d</a> <a href="mailto:a@example.test">e</a> <a href="/path#frag">f</a></p>', $result['html']);
    }

    public function test_a_whole_file_is_reduced_to_its_body(): void
    {
        $file = "<!doctype html>\n<html><head><title>T</title><style>body{color:red}</style></head><body><h1>Hi</h1><p>Text</p></body></html>";

        $result = ContentHtml::clean($file);

        $this->assertTrue($result['changed']);
        $this->assertSame('<h1>Hi</h1><p>Text</p>', $result['html']);
        $this->assertStringContainsString('body of the file', implode(' ', $result['removed']));
    }

    public function test_the_underline_spans_the_visual_editor_leaves_behind_are_unwrapped(): void
    {
        $result = ContentHtml::clean('<div><a href="/x"><span style="text-decoration: underline;">Care guide</span></a></div>');

        $this->assertSame('<p><a href="/x">Care guide</a></p>', $result['html']);
    }

    public function test_output_shows_every_h1_as_an_h2_and_leaves_null_alone(): void
    {
        $this->assertSame('<h2>Title</h2>'.self::TABLE, ContentHtml::forOutput('<h1>Title</h1>'.self::TABLE));
        $this->assertNull(ContentHtml::forOutput(null));
        $this->assertSame('', ContentHtml::forOutput(''));
    }

    public function test_it_knows_when_the_visual_editor_would_lose_something(): void
    {
        $this->assertFalse(ContentHtml::needsHtmlEditor(null));
        $this->assertFalse(ContentHtml::needsHtmlEditor('Plain words'));
        $this->assertFalse(ContentHtml::needsHtmlEditor('<h2>A</h2><p><strong>b</strong> <em>c</em> <a href="/x">d</a></p><ul><li>e</li></ul><blockquote>f</blockquote>'));
        $this->assertTrue(ContentHtml::needsHtmlEditor('<p>x</p>'.self::TABLE));
        $this->assertTrue(ContentHtml::needsHtmlEditor('<h4>Small heading</h4>'));
        $this->assertTrue(ContentHtml::needsHtmlEditor('<p>x</p><hr>'));
    }
}
