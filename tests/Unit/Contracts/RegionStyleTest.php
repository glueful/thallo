<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\RegionStyle;
use Thallo\Contracts\Style\StyleSchema;

/**
 * What a chrome region — the header, the footer — may be styled with, and where each setting
 * lands. Policy as code, like the region palettes: a region is not a block type, so it has no
 * row to declare this in. In the contracts package because two sides read it: the app validates
 * a save against the capabilities, and the renderer emits classes through the targets.
 */
final class RegionStyleTest extends TestCase
{
    public function testARegionIsStyledLikeABandWithoutLayoutOrVisibility(): void
    {
        self::assertSame(
            ['spacing', 'shadow', 'radius', 'colors', 'border', 'backdrop'],
            RegionStyle::CAPABILITIES,
        );
        $paths = RegionStyle::capabilities()->paths();
        $styled = [
            'spacing.padding.top', 'spacing.margin.bottom', 'shadow', 'radius', 'colors.surface',
            'border.sides', 'colors.surface_opacity', 'backdrop.blur',
        ];
        foreach ($styled as $path) {
            self::assertContains($path, $paths);
        }
        // A hidden header is the page's presentation setting, and a region's blocks lay
        // themselves out: neither is the region's style.
        self::assertNotContains('visibility', $paths);
        self::assertNotContains('layout.display', $paths);
        self::assertNotContains('typography.size', $paths);
    }

    public function testPaddingLandsInsideTheBarAndEverythingElseOnIt(): void
    {
        // The theme pads the INNER element (the bar's content, at the container's measure) and
        // paints the outer one, edge to edge. The settings follow.
        $targets = RegionStyle::targets();
        foreach (StyleSchema::pathsInGroup('spacing') as $path) {
            $expected = str_starts_with($path, 'spacing.padding.') ? 'inner' : 'root';
            self::assertSame($expected, $targets->targetFor($path), $path);
        }
        $onTheBar = [
            'shadow', 'radius', 'colors.surface', 'colors.text', 'colors.border', 'border.width',
            'border.style', 'border.sides', 'colors.surface_opacity', 'backdrop.blur',
        ];
        foreach ($onTheBar as $path) {
            self::assertSame('root', $targets->targetFor($path), $path);
        }
        self::assertSame([], $targets->validateAgainst(RegionStyle::capabilities()));
    }
}
