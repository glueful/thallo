<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Render\Theme\ThemeColors;
use Thallo\Render\Theme\ThemeDesign;

/** Custom palette spec §2.2, §2.3, §3.2, §3.3, §7. */
final class PaletteCssTest extends TestCase
{
    private const SIX = [
        'bg' => '#f8f4ec', 'surface' => '#ffffff', 'surface_2' => '#efe7d8',
        'ink' => '#1b1712', 'muted' => '#6b6156', 'line' => '#e2d8c6',
    ];

    public function testAnEmptyPaletteEmitsExactlyTodaysCss(): void
    {
        foreach ([['blue', 'slate'], ['rose', 'stone'], ['#0a7c66', 'zinc']] as [$a, $n]) {
            $palette = ThemeColors::paletteCss($a, $n, Palette::empty(), true);
            self::assertSame(ThemeColors::css($a, $n), $palette, "{$a}/{$n}");
        }
    }

    public function testCustomEmitsTheSixLightValuesAndTheDarkBaseFamily(): void
    {
        $css = ThemeColors::paletteCss('blue', 'custom', new Palette(self::SIX, 'stone'), true);
        self::assertStringContainsString(
            ':root{--bg:#f8f4ec;--surface:#ffffff;--surface-2:#efe7d8;--ink:#1b1712;--muted:#6b6156;--line:#e2d8c6;',
            $css,
        );
        $dark = substr($css, (int) strpos($css, 'html[data-theme="dark"]{'));
        foreach (ThemeColors::neutralTokens('stone', 'dark') as $var => $hex) {
            self::assertStringContainsString("{$var}:{$hex};", $dark);
        }
    }

    public function testTheDarkBaseDefaultsToSlateWhenUnset(): void
    {
        $css = ThemeColors::paletteCss('blue', 'custom', new Palette(self::SIX, null), true);
        self::assertStringContainsString('--bg:' . ThemeColors::neutralTokens('slate', 'dark')['--bg'] . ';', $css);
    }

    public function testWithColourModeOffNoDarkBlockIsEmitted(): void
    {
        $css = ThemeColors::paletteCss('blue', 'custom', new Palette(self::SIX, 'stone'), false);
        self::assertStringNotContainsString('data-theme="dark"', $css);
    }

    public function testAHexAccentDarkensAgainstTheEffectiveDarkGround(): void
    {
        $custom = ThemeColors::paletteCss('#1b3a8a', 'custom', new Palette(self::SIX, 'stone'), true);
        $family = ThemeColors::css('#1b3a8a', 'stone');
        $pick = static fn (string $css): string
            => preg_match('/html\[data-theme="dark"\]\{[^}]*--accent:(#[0-9a-f]{6})/', $css, $m) === 1 ? $m[1] : '';
        self::assertNotSame('', $pick($family));
        self::assertSame($pick($family), $pick($custom));
    }

    public function testAConfiguredBrandSlotEmitsFillAndInkInBothModesAndAnUnsetOneNothing(): void
    {
        $p = new Palette(brands: [1 => new BrandSlot('Gold dark', '#8a6a2a'), 2 => null, 3 => null]);
        $css = ThemeColors::paletteCss('blue', 'slate', $p, true);
        self::assertMatchesRegularExpression('/:root\{[^}]*--brand-1:#8a6a2a;--brand-1-ink:#(?:000000|ffffff);/', $css);
        self::assertMatchesRegularExpression(
            '/html\[data-theme="dark"\]\{[^}]*--brand-1:#[0-9a-f]{6};--brand-1-ink:#(?:000000|ffffff);/',
            $css,
        );
        self::assertStringNotContainsString('--brand-2', $css);
        $ground = ThemeColors::neutralTokens('slate', 'dark')['--bg'];
        [$darkFill] = ThemeColors::brandVars('#8a6a2a', 'dark', $ground);
        self::assertGreaterThanOrEqual(4.5, ThemeColors::contrast($darkFill, $ground));
    }

    public function testTintedSwapsTheCustomBackgroundAndSurface(): void
    {
        $css = ThemeDesign::css('soft', 'system', 'tinted', 'custom', null, null, self::SIX);
        self::assertStringContainsString('--bg:#ffffff', $css);
        self::assertStringContainsString('--surface:#f8f4ec', $css);
    }
}
