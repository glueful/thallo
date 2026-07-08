<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Render\ColorMode;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

final class ColorModeTest extends AppTestCase
{
    /** Render the real layout.twig with color mode forced on/off. */
    private function renderLayout(bool $colorMode): string
    {
        $base = $this->appContext()->getBasePath();
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            colorModeEnabled: $colorMode,
        );
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $ext,
            $base . '/storage/cache/twig',
        ))->environment();
        return $env->load('layout.twig')->render([
            'site' => ['locale' => 'en', 'name' => 'Test Site'],
            'preview' => false,
        ]);
    }

    /** Render a single block through the real theme with color mode forced on/off. */
    private function renderBlock(string $type, array $data, bool $colorMode): string
    {
        $base = $this->appContext()->getBasePath();
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            colorModeEnabled: $colorMode,
        );
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $ext,
            $base . '/storage/cache/twig',
        ))->environment();
        return $env->createTemplate('{{ blocks(list) }}')->render([
            'list' => [['id' => 'b1', 'type' => $type, 'data' => $data]],
        ]);
    }

    public function testToggleBlockRendersControlWhenEnabledAndNothingWhenDisabled(): void
    {
        $on = $this->renderBlock('color_mode', [], colorMode: true);
        self::assertStringContainsString('thallo-block-color_mode', $on);
        self::assertStringContainsString('data-color-mode-set="system"', $on);

        // Feature off → the toggle emits nothing (a dead switch would only confuse).
        $off = $this->renderBlock('color_mode', [], colorMode: false);
        self::assertSame('', trim($off));
    }

    public function testPublishedHashMatchesTheResolverConstant(): void
    {
        // The documented CSP hash is the source of truth; if the resolver bytes
        // change without re-publishing, this fails (color-mode spec §6). The
        // constant is the bare base64 digest; docs render it as 'sha256-<digest>'.
        self::assertSame(
            base64_encode(hash('sha256', ColorMode::RESOLVER_JS, true)),
            ColorMode::RESOLVER_SHA256,
        );
    }

    public function testLayoutEmitsResolverAndMarkerVerbatimWhenEnabled(): void
    {
        $html = $this->renderLayout(colorMode: true);
        self::assertStringContainsString(ColorMode::scriptTag(), $html);       // byte-for-byte resolver
        self::assertStringContainsString('data-color-mode-enabled="true"', $html);
        self::assertStringNotContainsString('data-theme=', $html);             // mode-agnostic HTML
        // No-flash ordering: the resolver precedes the first stylesheet.
        self::assertLessThan(
            strpos($html, '<link rel="stylesheet"'),
            strpos($html, 'thallo.colorMode'),
            'resolver must be emitted before the CSS links',
        );
    }

    public function testLayoutEmitsNeitherScriptNorMarkerWhenDisabled(): void
    {
        $html = $this->renderLayout(colorMode: false);
        self::assertStringNotContainsString('thallo.colorMode', $html);        // no resolver
        self::assertStringNotContainsString('data-color-mode-enabled', $html); // no marker → runtime inert
    }
}
