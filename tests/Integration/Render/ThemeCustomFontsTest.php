<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Psr\Log\NullLogger;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Fonts\BlobRouteMediaUrls;
use Thallo\Core\Tests\Support\Fonts\FixedFontLibrary;
use Thallo\Core\Tests\Support\Fonts\FixedThemeAppearance;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Style\RequestFontSnapshot;
use Thallo\Render\ThemeAppearanceSource;
use Thallo\Render\ThemeLocator;

/**
 * A site's own typefaces, from the settings to the page: Custom's Text and Headings are font library
 * families, named in the settings by their ID; the page sets each role to the family's generated name
 * and fallback stack — never to anything a setting says directly (block typeface spec §2.8).
 */
final class ThemeCustomFontsTest extends AppTestCase
{
    private const TEXT = 'Tx3dE5fG7hJ9';
    private const HEAD = 'Hd3dE5fG7hJ9';

    /** @param array{text?: string, headings?: string} $families */
    private function ext(string $font, array $families): RenderContextExtension
    {
        $base = $this->appContext()->getBasePath();
        $ext = new RenderContextExtension(
            null,
            $this->container()->get(EntryTargetResolver::class),
            'en',
            mediaUrls: new BlobRouteMediaUrls(),
            appearance: new ThemeAppearanceSource(new FixedThemeAppearance($font, $families), new NullLogger()),
            fontSnapshots: new RequestFontSnapshot(new FixedFontLibrary([
                FixedFontLibrary::family(self::TEXT, 'fontbody0001'),
                FixedFontLibrary::family(self::HEAD, 'fonthead0001', fallback: 'serif'),
            ])),
        );
        $ext->bindTheme(new ThemeLocator('default', $base . '/themes'));
        $ext->setAssetContext(null, $base . '/packages/thallo-render/themes/default/assets');
        return $ext;
    }

    public function testEachRoleIsSetToItsFamilysGeneratedNameAndFallbackStack(): void
    {
        $css = (string) $this->ext('custom', ['text' => self::TEXT, 'headings' => self::HEAD])->themeColorsStyle();
        self::assertStringContainsString('--font-body:"thallo-font-' . self::TEXT . '",system-ui,', $css);
        self::assertStringContainsString('--font-display:"thallo-font-' . self::HEAD . '","Iowan Old Style"', $css);
        self::assertStringNotContainsString('@font-face', $css, 'faces are the fonts stylesheet\'s');
    }

    public function testAFamilyTheLibraryNoLongerHasIsSimplyNotUsed(): void
    {
        $css = (string) $this->ext('custom', ['text' => 'Zz9yX8wV7uT6', 'headings' => self::HEAD])->themeColorsStyle();
        self::assertStringNotContainsString('--font-body', $css);
        self::assertStringContainsString('--font-display:"thallo-font-' . self::HEAD . '"', $css);
        // And families are only ever used by the custom choice.
        self::assertSame('', (string) $this->ext('sans', ['text' => self::TEXT])->themeColorsStyle());
    }

    public function testTheThemesOwnFaceIsNotPreloadedWhenTheTextIsNotSetInIt(): void
    {
        // Declared either way, so a block can choose the Theme typeface (block typeface spec §2.2);
        // only the preload follows the site-wide text.
        $args = ['Figtree', 'fonts/figtree-roman-latin.woff2', 'fonts/figtree-italic-latin.woff2'];
        self::assertStringContainsString('rel="preload"', (string) $this->ext('sans', [])->fontFacesStyle(...$args));
        self::assertStringContainsString('rel="preload"', (string) $this->ext('slab', [])->fontFacesStyle(...$args));
        foreach ([['serif', []], ['custom', ['text' => self::TEXT]]] as [$font, $families]) {
            $html = (string) $this->ext($font, $families)->fontFacesStyle(...$args);
            self::assertStringNotContainsString('rel="preload"', $html, $font);
            self::assertStringContainsString('@font-face { font-family: "Figtree"', $html, $font);
        }
        self::assertStringContainsString(
            'rel="preload"',
            (string) $this->ext('custom', ['headings' => self::HEAD])->fontFacesStyle(...$args),
            'only the headings changed: the text is still the theme face',
        );
        self::assertStringContainsString(
            'rel="preload"',
            (string) $this->ext('custom', ['text' => 'theme'])->fontFacesStyle(...$args),
            'Theme is the theme face',
        );
    }

    public function testAPreviewCanTryFamiliesBeforeTheyAreSavedAndACachedPageIsReKeyedByThem(): void
    {
        $ext = $this->ext('sans', []);
        $ext->setThemeAppearanceOverride(null, null, ['font' => 'custom', 'font_text_family' => self::TEXT]);
        self::assertStringContainsString('"thallo-font-' . self::TEXT . '"', (string) $ext->themeColorsStyle());

        $plain = new ThemeAppearanceSource(new FixedThemeAppearance('custom', []), new NullLogger());
        $chosen = new ThemeAppearanceSource(
            new FixedThemeAppearance('custom', ['text' => self::TEXT]),
            new NullLogger(),
        );
        self::assertNotSame($plain->fingerprint(), $chosen->fingerprint());
        // With no families, the fingerprint is the one it always had.
        self::assertSame('blue-slate-round-custom-plain', $plain->fingerprint());
        self::assertStringEndsWith('-f' . self::TEXT . '.', $chosen->fingerprint());
        // An ID is an ID: anything else is no family.
        $junk = new ThemeAppearanceSource(new FixedThemeAppearance('custom', ['text' => '../etc']), new NullLogger());
        self::assertSame([], $junk->fontFamilies());
    }
}
