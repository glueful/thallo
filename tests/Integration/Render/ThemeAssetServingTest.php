<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\LemmaTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dynamic /theme-assets serving (theme-setting spec §3): the ACTIVE theme's
 * assets dir resolves per request (no boot mount), explicit MIME map (never
 * content-sniffed), traversal guarded.
 */
final class ThemeAssetServingTest extends LemmaTestCase
{
    public function testServesTheActiveThemesCssWithExplicitMime(): void
    {
        $res = $this->handle(Request::create('/theme-assets/site.css?t=default', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/css', (string) $res->headers->get('Content-Type'));
        self::assertStringContainsString('max-age=86400', (string) $res->headers->get('Cache-Control'));
        self::assertStringContainsString('.site-header', (string) $res->getContent());
    }

    public function testServesJsWithJavascriptMime(): void
    {
        $res = $this->handle(Request::create('/theme-assets/blocks.js', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('javascript', (string) $res->headers->get('Content-Type'));
    }

    public function testTraversalAndMissesAre404(): void
    {
        self::assertSame(404, $this->handle(Request::create('/theme-assets/../theme.json', 'GET'))->getStatusCode());
        self::assertSame(404, $this->handle(Request::create('/theme-assets/nope.css', 'GET'))->getStatusCode());
    }
}
