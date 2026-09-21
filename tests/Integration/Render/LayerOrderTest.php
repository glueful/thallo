<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Style\CompiledStyleArtifacts;
use Thallo\Render\Style\ThemeStylesheetArtifacts;
use Thallo\Render\ThemeLocator;

/**
 * Visual builder spec §2.3: the layer order sheet first, the theme artifact (every manifest and
 * package stylesheet inside @layer theme) next, the variables-only colours block after it, and
 * the unlayered site custom CSS last.
 */
final class LayerOrderTest extends AppTestCase
{
    public function testTheHeadLinksLayersThenTheArtifactThenColoursThenCustomCss(): void
    {
        $html = (string) $this->handle(Request::create('/', 'GET'))->getContent();
        $head = substr($html, 0, strpos($html, '</head>') ?: 0);

        $layers = strpos($head, '/_thallo/layers.css?v=');
        self::assertNotFalse($layers, 'layer order sheet linked');
        $linked = preg_match('~href="/theme-assets/(theme-[0-9a-f]{16}\.css)"~', $head, $m);
        self::assertSame(1, $linked, 'artifact linked');
        $artifact = strpos($head, $m[1]);
        self::assertLessThan($artifact, $layers, 'layers before the artifact');
        $settings = strpos($head, '/theme-assets/settings-');
        self::assertNotFalse($settings, 'the compiled style artifact linked');
        self::assertLessThan($settings, $artifact, 'the theme artifact before the compiled style artifact');
        self::assertSame(3, preg_match_all('~<link rel="stylesheet"~', $head), 'layers, theme, settings, nothing else');
        self::assertStringNotContainsString('site.css', $head);
        self::assertStringNotContainsString('blocks.css', $head);

        $css = (string) $this->handle(Request::create('/theme-assets/' . $m[1], 'GET'))->getContent();
        self::assertStringStartsWith('@layer theme {', $css);
        foreach (['/* site.css */', '/* blocks.css */', '/* navigation.css */', '/* stepper.css */'] as $marker) {
            self::assertStringContainsString($marker, $css);
        }
        self::assertLessThan(strpos($css, '/* blocks.css */'), strpos($css, '/* site.css */'), 'manifest order kept');
    }

    public function testTheColoursBlockIsVariablesOnlyAndSitsBetweenTheArtifactAndCustomCss(): void
    {
        // A non-default appearance through a hand-built extension (the shared page cache holds
        // its fingerprint from boot, so an HTTP render would serve the cached default page).
        $base = $this->appContext()->getBasePath();
        $provider = new class implements \Thallo\Contracts\Settings\ThemeAppearanceProvider {
            public function accent(): string
            {
                return 'emerald';
            }
            public function neutral(): string
            {
                return 'zinc';
            }
            public function radius(): string
            {
                return 'round';
            }
            public function font(): string
            {
                return 'sans';
            }
            public function background(): string
            {
                return 'plain';
            }
            public function fontFaces(): array
            {
                return [];
            }
        };
        $ext = new \Thallo\Render\RenderContextExtension(
            null,
            $this->container()->get(\Thallo\Contracts\Delivery\EntryTargetResolver::class),
            'en',
            appearance: new \Thallo\Render\ThemeAppearanceSource($provider, new \Psr\Log\NullLogger()),
            themeArtifacts: $this->container()->get(ThemeStylesheetArtifacts::class),
            compiledArtifacts: $this->container()->get(CompiledStyleArtifacts::class),
        );
        $env = (new \Thallo\Render\TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $ext,
            $base . '/storage/cache/twig',
        ))->environment();
        $html = $env->load('layout.twig')->render(
            ['site' => ['locale' => 'en', 'name' => 'Test'], 'preview' => false],
        );
        $head = substr($html, 0, strpos($html, '</head>') ?: 0);

        $found = preg_match('~<style>([^<]*--accent:#047857[^<]*)</style>~', $head, $style);
        self::assertSame(1, $found, 'colours block');
        $artifact = (int) strpos($head, '/theme-assets/theme-');
        self::assertGreaterThan($artifact, (int) strpos($head, $style[0]), 'colours after the artifact');
        // Variables only: every rule is :root or the dark root, nothing else.
        $selectors = preg_split('~\{[^}]*\}~', $style[1]) ?: [];
        foreach (array_filter(array_map('trim', $selectors)) as $selector) {
            self::assertContains($selector, [':root', 'html[data-theme="dark"]'], "selector {$selector}");
        }
    }

    public function testTheArtifactHashJoinsTheAppearanceFingerprint(): void
    {
        $artifacts = $this->container()->get(ThemeStylesheetArtifacts::class);
        $hash = $artifacts->forTheme($this->container()->get(ThemeLocator::class))->hash;

        $settings = $this->container()->get(CompiledStyleArtifacts::class)
            ->forTheme($this->container()->get(ThemeLocator::class))['hash'];

        self::assertStringContainsString(
            '-t' . substr($hash, 0, 8) . '-s' . substr($settings, 0, 8),
            $this->appearanceFingerprint(),
        );
    }
}
