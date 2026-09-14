<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dynamic /theme-assets serving (theme-setting spec §3): the ACTIVE theme's
 * assets dir resolves per request (no boot mount), explicit MIME map (never
 * content-sniffed), traversal guarded.
 */
final class ThemeAssetServingTest extends AppTestCase
{
    public function testServesTheActiveThemesCssWithExplicitMime(): void
    {
        $res = $this->handle(Request::create('/theme-assets/site.css?t=default', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/css', (string) $res->headers->get('Content-Type'));
        self::assertStringContainsString('max-age=86400', (string) $res->headers->get('Cache-Control'));
        self::assertStringContainsString('.site-header', (string) $res->getContent());
    }

    public function testRemovedCompatibilityLoaderFourOhFours(): void
    {
        // The default theme ships CSS only since the blocks.js compatibility loader was
        // removed (theme-runtime spec §11.4). JS mime coverage for served scripts lives
        // in RuntimeDeliveryTest (the /_thallo/runtime endpoint).
        $res = $this->handle(Request::create('/theme-assets/blocks.js', 'GET'));
        self::assertSame(404, $res->getStatusCode());
    }

    public function testTraversalAndMissesAre404(): void
    {
        self::assertSame(404, $this->handle(Request::create('/theme-assets/../theme.json', 'GET'))->getStatusCode());
        self::assertSame(404, $this->handle(Request::create('/theme-assets/nope.css', 'GET'))->getStatusCode());
    }

    public function testServesTheLayeredThemeArtifactByHashImmutably(): void
    {
        $artifacts = $this->container()->get(\Thallo\Render\Style\ThemeStylesheetArtifacts::class);
        $hash = $artifacts->forTheme($this->container()->get(\Thallo\Render\ThemeLocator::class))->hash;

        $res = $this->handle(Request::create("/theme-assets/theme-{$hash}.css", 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/css', (string) $res->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $res->headers->get('Cache-Control'));
        self::assertStringStartsWith('@layer theme {', (string) $res->getContent());
        self::assertStringContainsString('.site-header', (string) $res->getContent());

        $unknown = $this->handle(Request::create('/theme-assets/theme-0000000000000000.css', 'GET'));
        self::assertSame(404, $unknown->getStatusCode());
        $settings = $this->handle(Request::create('/theme-assets/settings-0000000000000000.css', 'GET'));
        self::assertSame(404, $settings->getStatusCode(), 'an unknown compiled style artifact is not served');
        $layers = $this->handle(Request::create('/_thallo/layers.css', 'GET'));
        self::assertSame(200, $layers->getStatusCode());
        self::assertStringContainsString('@layer theme, settings;', (string) $layers->getContent());
    }
}
