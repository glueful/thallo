<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Psr\Log\NullLogger;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\ThemeStylesheetArtifacts;
use Thallo\Render\ThemeAppearanceSource;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

final class ThemeColorsLayoutTest extends AppTestCase
{
    public function testStyleLandsAfterBlocksCssAndBeforeCustomCss(): void
    {
        $base = $this->appContext()->getBasePath();
        $provider = new class implements ThemeAppearanceProvider {
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
                return 'sharp';
            }
            public function font(): string
            {
                return 'editorial';
            }
            public function background(): string
            {
                return 'plain';
            }
        };
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            appearance: new ThemeAppearanceSource($provider, new NullLogger()),
            themeArtifacts: new ThemeStylesheetArtifacts(sys_get_temp_dir() . '/thallo-style-test'),
        );
        $env = (new TwigFactory(
            new ThemeLocator('default', $base . '/themes'),
            $ext,
            $base . '/storage/cache/twig',
        ))->environment();
        $html = $env->load('layout.twig')->render([
            'site' => ['locale' => 'en', 'name' => 'Test Site'],
            'preview' => false,
        ]);

        $artifact = strpos($html, '/theme-');
        $style = strpos($html, '--accent:#047857');
        self::assertNotFalse($style, 'override style present');
        self::assertGreaterThan($artifact, $style, 'style after the theme artifact link');
        self::assertStringContainsString('<style>:root{', $html);

        // Design tokens (website plan phase 1b) ride in the same block, after the colours.
        $design = strpos($html, '--radius:4px;--radius-lg:8px;--radius-btn:4px');
        self::assertNotFalse($design, 'radius override present');
        self::assertGreaterThan($style, $design, 'design after colours');
        self::assertStringContainsString('--font-display:', $html);

        $siteCss = (string) file_get_contents($base . '/packages/thallo-render/themes/default/assets/site.css');
        self::assertStringContainsString('--font-body:', $siteCss, 'the theme declares the body face as a token');
        self::assertStringContainsString('--font-display: var(--font-body)', $siteCss, 'display follows body');
        self::assertStringContainsString('font-family: var(--font-display)', $siteCss, 'headings read display');
    }
}
