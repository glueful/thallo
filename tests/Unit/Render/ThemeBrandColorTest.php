<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Render\Theme\ThemeColors;

/**
 * A site's accent was one of seventeen families. A brand has ONE colour, and it is rarely one of
 * them — so the site accent may also be that colour, as a hex. The brand is used exactly as given
 * on a light ground; what Thallo derives is what the owner cannot be asked to work out: the ink
 * that stays readable on it, and a lifted variant that stays visible on the dark ground.
 */
final class ThemeBrandColorTest extends TestCase
{
    public function testTheSiteAccentIsAFamilyOrABrandHex(): void
    {
        self::assertSame('teal', ThemeColors::normalizeSiteAccent('teal'));
        self::assertSame('#0a7c66', ThemeColors::normalizeSiteAccent('#0A7C66'));
        self::assertSame('#aabbcc', ThemeColors::normalizeSiteAccent('#abc'), 'short form, written out');
        $junk = [
            '', 'chartreuse', '#12', '#12345', '#gggggg', 'red;}body{display:none', '#fff;color:red', 'rgb(1,2,3)',
        ];
        foreach ($junk as $bad) {
            self::assertNull(ThemeColors::normalizeSiteAccent($bad), $bad);
        }
        // A scoped skin's class is built from the accent's NAME: a hex never becomes one.
        self::assertNull(ThemeColors::normalizeAccent('#0a7c66'));
        self::assertSame('', ThemeColors::skinClass('#0a7c66', null));
    }

    public function testABrandColourIsUsedAsGivenWithAnInkThatStaysReadableOnIt(): void
    {
        $navy = ThemeColors::tokens('#1e3a8a', 'slate', 'light');
        self::assertSame('#1e3a8a', $navy['--accent']);
        self::assertSame('#ffffff', $navy['--accent-ink']);

        $yellow = ThemeColors::tokens('#facc15', 'slate', 'light');
        self::assertSame('#facc15', $yellow['--accent'], 'the brand is the brand: never nudged on a light ground');
        self::assertSame('#000000', $yellow['--accent-ink'], 'white on yellow is unreadable');

        // Whatever the colour, a button's label meets AA on it.
        foreach (['#000000', '#ffffff', '#777777', '#ff0000', '#00ff00', '#0000ff', '#808000', '#6b7280'] as $brand) {
            $tokens = ThemeColors::tokens($brand, 'slate', 'light');
            self::assertGreaterThanOrEqual(
                4.5,
                ThemeColors::contrast($tokens['--accent'], $tokens['--accent-ink']),
                $brand,
            );
        }
    }

    public function testOnTheDarkGroundABrandColourIsLiftedUntilItCanBeSeen(): void
    {
        $ground = ThemeColors::neutralTokens('slate', 'dark')['--bg'];
        $navy = ThemeColors::tokens('#1e3a8a', 'slate', 'dark');
        self::assertNotSame('#1e3a8a', $navy['--accent'], 'navy on near-black is invisible');
        self::assertGreaterThanOrEqual(4.5, ThemeColors::contrast($navy['--accent'], $ground));
        self::assertGreaterThanOrEqual(4.5, ThemeColors::contrast($navy['--accent'], $navy['--accent-ink']));
        // Still the brand's hue: blue stays the largest channel.
        [$r, $g, $b] = sscanf($navy['--accent'], '#%02x%02x%02x');
        self::assertGreaterThan($r, $b);
        self::assertGreaterThan($g, $b);

        // A colour that already shows on the dark ground is left alone.
        self::assertSame('#facc15', ThemeColors::tokens('#facc15', 'slate', 'dark')['--accent']);
    }

    public function testTheStylesheetCarriesTheBrandInBothModes(): void
    {
        $css = ThemeColors::css('#0a7c66', 'slate');
        self::assertStringContainsString(':root{', $css);
        self::assertStringContainsString('--accent:#0a7c66;', $css);
        self::assertStringContainsString('html[data-theme="dark"]{', $css);
        self::assertMatchesRegularExpression('~\A[a-z0-9:;{}\[\]="#.,\- ]+\z~i', $css, 'nothing but tokens');
        // The default pair still emits nothing.
        self::assertSame('', ThemeColors::css('blue', 'slate'));
    }

    public function testContrastIsTheWcagRatio(): void
    {
        self::assertEqualsWithDelta(21.0, ThemeColors::contrast('#000000', '#ffffff'), 0.01);
        self::assertEqualsWithDelta(1.0, ThemeColors::contrast('#336699', '#336699'), 0.001);
        self::assertEqualsWithDelta(4.54, ThemeColors::contrast('#767676', '#ffffff'), 0.01);
        self::assertSame(
            ThemeColors::contrast('#1e3a8a', '#ffffff'),
            ThemeColors::contrast('#ffffff', '#1e3a8a'),
        );
    }
}
