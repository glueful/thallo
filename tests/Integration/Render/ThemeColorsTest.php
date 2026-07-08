<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
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
