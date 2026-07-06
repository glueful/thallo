<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\ReadmeRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Pins the safe-rendering contract for extension READMEs: CommonMark is configured as a
 * safe renderer (escape raw HTML, block unsafe links, harden external links, strip images),
 * so the SPA can treat the output as trusted HTML.
 */
final class ReadmeRendererTest extends TestCase
{
    private ReadmeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new ReadmeRenderer('thallo.test');
    }

    public function testRendersBasicMarkdown(): void
    {
        $html = $this->renderer->render("# Title\n\nSome **bold** text.");

        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function testEscapesRawHtml(): void
    {
        $html = $this->renderer->render("<script>alert(1)</script>\n\n<iframe src=\"x\"></iframe>");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testStripsUnsafeLinkSchemes(): void
    {
        $html = $this->renderer->render('[click](javascript:alert(1)) and [data](data:text/html,evil)');

        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('data:text/html', $html);
    }

    public function testExternalLinksAreHardened(): void
    {
        $html = $this->renderer->render('[ext](https://example.com/page)');

        self::assertStringContainsString('href="https://example.com/page"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringContainsString('target="_blank"', $html);
    }

    public function testStripsMarkdownImages(): void
    {
        $html = $this->renderer->render('![badge](https://img.shields.io/badge/x.svg)');

        self::assertStringNotContainsString('<img', $html);
    }

    public function testRawImgTagIsEscapedNotRendered(): void
    {
        $html = $this->renderer->render('<img src="https://evil.example/x.png">');

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img', $html);
    }
}
