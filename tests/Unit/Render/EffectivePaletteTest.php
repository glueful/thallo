<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Render\Theme\EffectivePalette;
use Thallo\Render\Theme\ThemeColors;

/** Custom palette spec §6: specific pairs, from the effective palette, in both modes, at 4.5:1. */
final class EffectivePaletteTest extends TestCase
{
    public function testRowsCoverTheSpecifiedPairsInBothModes(): void
    {
        $p = new Palette(brands: [1 => new BrandSlot('Gold dark', '#8a6a2a'), 2 => null, 3 => null]);
        $rows = EffectivePalette::of('blue', 'slate', 'plain', $p)->contrastRows();
        $pairs = array_map(static fn (array $r): string => "{$r['mode']}:{$r['fg']}/{$r['on']}", $rows);
        foreach (['light', 'dark'] as $m) {
            foreach (['text', 'muted'] as $fg) {
                foreach (['background', 'surface', 'surface-2'] as $on) {
                    self::assertContains("{$m}:{$fg}/{$on}", $pairs);
                }
            }
            self::assertContains("{$m}:accent/background", $pairs);
            self::assertContains("{$m}:accent-contrast/accent", $pairs);
            self::assertContains("{$m}:brand-1/background", $pairs);
            self::assertContains("{$m}:brand-1-contrast/brand-1", $pairs);
            self::assertNotContains("{$m}:brand-2/background", $pairs);
        }
        self::assertCount(2 * (6 + 2 + 2), $rows);
    }

    public function testTintedSwapsBackgroundAndSurfaceBeforeChecking(): void
    {
        $plain = EffectivePalette::of('blue', 'slate', 'plain', Palette::empty())->values('light');
        $tinted = EffectivePalette::of('blue', 'slate', 'tinted', Palette::empty())->values('light');
        self::assertSame($plain['surface'], $tinted['background']);
        self::assertSame($plain['background'], $tinted['surface']);
    }

    public function testARowBelowTheThresholdFails(): void
    {
        $six = [
            'bg' => '#ffffff', 'surface' => '#ffffff', 'surface_2' => '#ffffff',
            'ink' => '#000000', 'muted' => '#eeeeee', 'line' => '#dddddd',
        ];
        $rows = EffectivePalette::of('blue', 'custom', 'plain', new Palette($six, 'slate'))->contrastRows();
        $muted = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['mode'] === 'light' && $r['fg'] === 'muted' && $r['on'] === 'background',
        ))[0];
        self::assertFalse($muted['passes']);
        self::assertEqualsWithDelta(ThemeColors::contrast('#eeeeee', '#ffffff'), $muted['ratio'], 0.01);
    }

    public function testSwatchesAreLightModeColourTokens(): void
    {
        $s = EffectivePalette::of('blue', 'slate', 'plain', Palette::empty())->swatches();
        self::assertSame(ThemeColors::neutralTokens('slate', 'light')['--surface'], $s['color.surface']);
        self::assertSame('#ffffff', $s['color.white']);
        self::assertArrayNotHasKey('color.transparent', $s);
        self::assertArrayNotHasKey('color.brand-1', $s);
    }
}
