<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Theme\ThemeColors;

final class ThemeColorsTest extends AppTestCase
{
    public function testDefaultPairEmitsNothing(): void
    {
        self::assertSame('', ThemeColors::css('blue', 'slate'));
    }

    public function testEveryEnumFamilyIsRenderable(): void
    {
        foreach (ThemeColors::ACCENTS as $a) {
            foreach (ThemeColors::NEUTRALS as $n) {
                if ($a === 'blue' && $n === 'slate') {
                    continue;
                }
                $css = ThemeColors::css($a, $n);
                self::assertStringContainsString(':root', $css, "$a/$n :root");
                self::assertStringContainsString('html[data-theme="dark"]', $css, "$a/$n dark");
                $tokens = [
                    '--bg', '--surface', '--surface-2', '--ink',
                    '--muted', '--line', '--accent', '--accent-ink',
                ];
                foreach ($tokens as $t) {
                    self::assertStringContainsString($t, $css, "$a/$n missing $t");
                }
            }
        }
    }

    public function testFrozenDefaultValuesMatchSiteCss(): void
    {
        // P2b: the blue/slate row must equal the shipped site.css values verbatim.
        $light = ThemeColors::tokens('blue', 'slate', 'light');
        $dark = ThemeColors::tokens('blue', 'slate', 'dark');
        self::assertSame([
            '--bg' => '#ffffff', '--surface' => '#f6f7f9', '--surface-2' => '#eef0f4',
            '--ink' => '#0f172a', '--muted' => '#64748b', '--line' => '#e2e8f0',
            '--accent' => '#2563eb', '--accent-ink' => '#ffffff',
        ], $light);
        self::assertSame([
            '--bg' => '#0b1120', '--surface' => '#111a2e', '--surface-2' => '#16213a',
            '--ink' => '#e2e8f0', '--muted' => '#94a3b8', '--line' => '#1e293b',
            '--accent' => '#3b82f6', '--accent-ink' => '#ffffff',
        ], $dark);
    }

    public function testAPairAlsoPublishesBothModesUnderFixedNamesForWhiteAndBlackBackgrounds(): void
    {
        // A white background keeps the light set in dark mode and a black one the dark set in light
        // mode, so both sets must be readable at once, under names no colour mode re-maps.
        $css = ThemeColors::css('rose', 'stone');
        $fixed = substr($css, (int) strrpos($css, ':root{'));
        foreach (['light', 'dark'] as $mode) {
            foreach (ThemeColors::tokens('rose', 'stone', $mode) as $var => $hex) {
                self::assertStringContainsString('--thallo-' . $mode . substr($var, 1) . ':' . $hex . ';', $fixed);
            }
        }
        self::assertStringStartsWith(':root{--bg:', $css, 'the colour-mode blocks come first, unchanged');
    }

    public function testTheDefaultThemeDefinesBothFixedSetsAsItsOwnDefaultPair(): void
    {
        // The default pair emits nothing, so the stylesheet carries its fixed sets — the same values.
        $css = (string) file_get_contents(
            dirname(__DIR__, 3) . '/packages/thallo-render/themes/default/assets/site.css',
        );
        $root = substr($css, (int) strpos($css, ':root {'), (int) strpos($css, 'html[data-theme="dark"] {'));
        foreach (['light', 'dark'] as $mode) {
            foreach (ThemeColors::tokens('blue', 'slate', $mode) as $var => $hex) {
                self::assertStringContainsString('--thallo-' . $mode . substr($var, 1) . ': ' . $hex . ';', $root);
            }
        }
        // White and black backgrounds point the palette at those sets.
        foreach (['white' => 'light', 'black' => 'dark'] as $literal => $mode) {
            self::assertMatchesRegularExpression(
                '~:where\(\.t-bg-' . $literal . ', \.t-abg-' . $literal . '\) \{[^}]*--ink: var\(--thallo-' . $mode
                    . '-ink\);[^}]*--accent-ink: var\(--thallo-' . $mode . '-accent-ink\);~',
                $css,
            );
        }
    }

    public function testWhiteAccentInkMeetsContrastForEveryAccent(): void
    {
        // Every accent's light --accent must reach >= 4.5:1 against white ink,
        // so --accent-ink can stay white uniformly (spec §3).
        foreach (ThemeColors::ACCENTS as $a) {
            $accent = ThemeColors::tokens($a, 'slate', 'light')['--accent'];
            self::assertGreaterThanOrEqual(
                4.5,
                self::contrastWithWhite($accent),
                "accent '$a' light fill fails AA against white text ($accent)",
            );
        }
    }

    public function testNormalizeRejectsUnknown(): void
    {
        self::assertNull(ThemeColors::normalizeAccent('banana'));
        self::assertSame('blue', ThemeColors::normalizeAccent('blue'));
        self::assertNull(ThemeColors::normalizeNeutral('octarine'));
        self::assertSame('slate', ThemeColors::normalizeNeutral('slate'));
    }

    private static function contrastWithWhite(string $hex): float
    {
        $l = self::relLuminance($hex);
        return (1.0 + 0.05) / ($l + 0.05);
    }

    private static function relLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $c = static function (int $v): float {
            $s = $v / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $c((int) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $c((int) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $c((int) hexdec(substr($hex, 4, 2)));
    }
}
