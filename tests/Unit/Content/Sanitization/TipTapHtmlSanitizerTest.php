<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Sanitization;

use App\Content\Sanitization\TipTapHtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class TipTapHtmlSanitizerTest extends TestCase
{
    private TipTapHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new TipTapHtmlSanitizer();
    }

    /** The attack matrix (spec §6): each payload must strip/neutralize. */
    public function testAttackMatrixStripsEverything(): void
    {
        $cases = [
            // [input, must-not-contain fragments]
            ['<p>x</p><script>alert(1)</script>', ['<script', 'alert(1)']],
            ['<p onclick="alert(1)">x</p>', ['onclick']],
            ['<img src=x onerror=alert(1)>', ['<img', 'onerror']],
            ['<a href="javascript:alert(1)">x</a>', ['javascript:']],
            ['<a href="JAVASCRIPT:alert(1)">x</a>', ['avascript:']], // case-variant
            ['<a href="jav&#x09;ascript:alert(1)">x</a>', ['ascript:alert']], // entity-obfuscated
            // allowRelativeLinks(true) treats network-path URLs as relative — the
            // custom attribute sanitizer must drop the href (P1 review finding).
            ['<a href="//evil.com">x</a>', ['//evil.com']],
            ['<p style="background:url(javascript:x)">x</p>', ['style=']],
            ['<svg onload=alert(1)><circle/></svg>', ['<svg', 'onload']],
            ['<a href="data:text/html,<script>x</script>">x</a>', ['data:']],
            ['<iframe src="https://evil"></iframe>', ['<iframe']],
            ['<object data="x"></object><embed src="x"><form action="x"></form>', ['<object', '<embed', '<form']],
            ['<p>unclosed <strong>mis<em>nested</strong></em>', ['<script']], // malformed: must not throw
        ];
        foreach ($cases as [$input, $fragments]) {
            $out = $this->sanitizer->sanitize($input);
            foreach ($fragments as $fragment) {
                self::assertStringNotContainsStringIgnoringCase($fragment, $out, $input);
            }
        }
    }

    /** TipTap fidelity (spec §6): the allowed vocabulary round-trips unmangled. */
    public function testTipTapVocabularyRoundTrips(): void
    {
        $doc = '<h2>Title</h2><p>Body with <strong>bold</strong>, <em>italic</em>, '
            . '<s>strike</s> and <u>underline</u>.</p>'
            . '<ul><li>one</li><li>two</li></ul><ol><li>1</li></ol>'
            . '<blockquote><p>quote</p></blockquote><pre><code>code()</code></pre>'
            . '<p><a href="https://example.com/x">abs</a> <a href="/rel">rel</a> '
            . '<a href="mailto:a@b.c">mail</a></p><hr><p>line<br>break</p>';
        $out = $this->sanitizer->sanitize($doc);
        foreach (
            ['<h2>Title</h2>', '<strong>bold</strong>', '<em>italic</em>', '<s>strike</s>',
             '<u>underline</u>', '<li>one</li>', '<blockquote>', '<pre>', '<code>',
             // NOTE: the component's canonical output entity-encodes @ in hrefs.
             'href="https://example.com/x"', 'href="/rel"', 'href="mailto:a&#64;b.c"', '<hr', '<br'] as $keep
        ) {
            self::assertStringContainsString($keep, $out);
        }
    }

    /** The protocol-relative pin in isolation: // drops, legitimate hrefs survive. */
    public function testProtocolRelativeHrefIsDroppedWhileRelativeAndAbsoluteSurvive(): void
    {
        $out = $this->sanitizer->sanitize(
            '<a href="//evil.com">pr</a><a href="/local">rel</a>'
            . '<a href="https://ok.example">abs</a><a href="mailto:a@b.c">mail</a>',
        );
        self::assertStringNotContainsString('//evil.com', $out);
        self::assertStringContainsString('>pr</a>', $out); // link text survives, href dropped
        self::assertStringContainsString('href="/local"', $out);
        self::assertStringContainsString('href="https://ok.example"', $out);
        self::assertStringContainsString('href="mailto:a&#64;b.c"', $out); // canonical @-encoding
    }

    public function testTaskListShapeSurvivesWithInputsStripped(): void
    {
        $out = $this->sanitizer->sanitize(
            '<ul data-type="taskList"><li data-checked="true">'
            . '<input type="checkbox" checked>done</li></ul>',
        );
        self::assertStringContainsString('data-type="taskList"', $out);
        self::assertStringContainsString('data-checked="true"', $out);
        self::assertStringNotContainsString('<input', $out);
    }

    public function testIdempotentAndLongInputSafe(): void
    {
        $doc = '<p>' . str_repeat('long content ', 5000) . '<strong>end</strong></p>'; // ~65KB
        $once = $this->sanitizer->sanitize($doc);
        self::assertSame($once, $this->sanitizer->sanitize($once)); // idempotent
        self::assertStringContainsString('<strong>end</strong>', $once); // no silent truncation
    }
}
