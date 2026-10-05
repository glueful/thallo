<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Psr\Log\NullLogger;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Fonts\BlobRouteMediaUrls;
use Thallo\Core\Tests\Support\Fonts\FixedThemeAppearance;
use Thallo\Core\Tests\Support\ThemeFixture;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeAppearanceSource;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * The theme's face is always declared, so any block can choose Theme; only the preload depends on the
 * site-wide Text using it (block typeface spec §2.2; plan Task 4).
 */
final class ThemeFaceTest extends AppTestCase
{
    private const ARGS = ['Figtree', 'fonts/figtree-roman-latin.woff2', 'fonts/figtree-italic-latin.woff2'];
    private const PRE = __DIR__ . '/../../fixtures/render/pre-typeface';

    /** @param array{text?: string, headings?: string} $families */
    private function ext(string $font, array $families = [], ?string $assets = null): RenderContextExtension
    {
        $base = $this->appContext()->getBasePath();
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            mediaUrls: new BlobRouteMediaUrls(),
            appearance: new ThemeAppearanceSource(new FixedThemeAppearance($font, $families), new NullLogger()),
        );
        $ext->bindTheme(new ThemeLocator('default', $base . '/themes'));
        $ext->setAssetContext(null, $assets ?? $base . '/packages/thallo-render/themes/default/assets');
        return $ext;
    }

    public function testASerifSiteDeclaresTheFaceWithoutPreloadingIt(): void
    {
        $html = (string) $this->ext('serif')->fontFacesStyle(...self::ARGS);
        self::assertStringContainsString('@font-face { font-family: "Figtree"; src: url(', $html);
        self::assertStringContainsString('font-style: italic', $html);
        self::assertStringNotContainsString('rel="preload"', $html);
    }

    public function testASansSiteDeclaresAndPreloadsIt(): void
    {
        $html = (string) $this->ext('sans')->fontFacesStyle(...self::ARGS);
        self::assertStringContainsString('@font-face { font-family: "Figtree"', $html);
        self::assertStringContainsString('<link rel="preload" as="font" type="font/woff2"', $html);
    }

    public function testCustomTextDeclaresTheFaceWithoutPreloadingIt(): void
    {
        $html = (string) $this->ext('custom', ['text' => 'serif'])->fontFacesStyle(...self::ARGS);
        self::assertStringContainsString('@font-face { font-family: "Figtree"', $html);
        self::assertStringNotContainsString('rel="preload"', $html);
    }

    /** The declaration is exactly what a Sans site's was before; only the preload moved. */
    public function testTheDeclarationIsTheOneAThemeFaceSiteAlwaysHad(): void
    {
        $before = (string) file_get_contents(self::PRE . '/head-sans.html');
        self::assertSame(1, preg_match('#<style>\n@font-face.*?</style>#s', $before, $declared));
        $now = (string) $this->ext('serif')->fontFacesStyle(...self::ARGS);
        // The asset query (theme and cache-buster) depends on the request; the declaration does not.
        $normalise = static fn (string $s): string => (string) preg_replace('/\?t=[^"]*/', '', $s);
        self::assertSame($normalise($declared[0]), $normalise(trim($now)));
    }

    public function testMissingFaceFilesDeclareNothingAndErrorNothing(): void
    {
        $empty = sys_get_temp_dir() . '/thallo-noface-' . uniqid();
        mkdir($empty);
        try {
            self::assertSame('', (string) $this->ext('sans', [], $empty)->fontFacesStyle(...self::ARGS));
            self::assertSame('', (string) $this->ext('serif', [], $empty)->fontFacesStyle(...self::ARGS));
        } finally {
            rmdir($empty);
        }
    }

    public function testACustomThemeWithoutAFaceRendersUnsetBlocksAsBefore(): void
    {
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $themes = $this->appContext()->getBasePath() . '/themes';
        ThemeFixture::write($themes . '/pretypeface', 'pretypeface');
        try {
            $extension = $this->container()->get(RenderContextExtension::class);
            $env = (new TwigFactory(
                new ThemeLocator('pretypeface', $themes),
                $extension,
                sys_get_temp_dir() . '/pretypeface-twig-' . uniqid(),
            ))->environment();
            $extension->resetPerRenderState();
            $extension->setAnnotationScope('none');
            $extension->setLocale('en');
            $html = $extension->blocks($env, ['entry' => null, 'site' => ['locale' => 'en', 'locales' => ['en']]], [
                ['id' => 'preheading01', 'type' => 'heading', 'settings' => [],
                    'data' => ['text' => 'Unset heading', 'level' => 'h2']],
                ['id' => 'prerichtext1', 'type' => 'rich_text', 'settings' => [],
                    'data' => ['body' => '<p>Unset <em>rich</em> text.</p>']],
            ]);
            self::assertSame((string) file_get_contents(self::PRE . '/custom-theme-blocks.html'), $html . "\n");
        } finally {
            foreach (['theme.json', 'assets/site.css'] as $file) {
                @unlink($themes . '/pretypeface/' . $file);
            }
            @rmdir($themes . '/pretypeface/templates');
            @rmdir($themes . '/pretypeface/assets');
            @rmdir($themes . '/pretypeface');
        }
    }
}
