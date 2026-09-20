<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * What a chrome region — the header, the footer — may be styled with, and where each setting
 * lands. Policy as code, like the region palettes: a region is not a block type, so it has no row
 * to declare this in. It lives in the contracts because two sides read it: the app validates a
 * save against the capabilities, and the renderer emits classes through the targets.
 *
 * A region is styled like a band: spacing, a shadow, corners, colours, a border, a backdrop. Not
 * visibility (hiding the chrome is a page's presentation setting), not layout (a region's blocks
 * lay themselves out), not typography.
 */
final class RegionStyle
{
    public const CAPABILITIES = ['spacing', 'shadow', 'radius', 'colors', 'border', 'backdrop'];

    public static function capabilities(): StyleCapabilities
    {
        return StyleCapabilities::fromDeclaration(self::CAPABILITIES);
    }

    /**
     * Two targets. `root` is the bar itself — the `<header>` or `<footer>`, painted edge to edge.
     * `inner` is its content, held to the container's measure, and it is where a theme pads: so
     * padding lands there, and everything else on the bar.
     */
    public static function targets(): StyleTargets
    {
        $map = [];
        foreach (self::capabilities()->paths() as $path) {
            $map[$path] = str_starts_with($path, 'spacing.padding.') ? 'inner' : 'root';
        }
        return StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'box'], 'inner' => ['kind' => 'box']],
            'map' => $map,
        ]);
    }
}
