<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The style contract (spec §1.3–1.5), data only: every managed property with its kinds,
 * responsiveness and vocabulary domain, the advanced properties, and the breakpoint contract.
 * Thallo owns this table; block types declare which paths they support (StyleCapabilities).
 */
final class StyleSchema
{
    /** The settings representation version, stamped on documents as `_schema.settings`. */
    public const VERSION = 9;

    /** Platform breakpoints: `md` from 768px, `lg` from 1024px. */
    public const BREAKPOINTS = ['base', 'md', 'lg'];

    public const BREAKPOINT_MIN_WIDTH = ['base' => 0, 'md' => 768, 'lg' => 1024];

    /** @var array<string, PropertyDefinition>|null */
    private static ?array $properties = null;

    /** @var array<string, PropertyDefinition>|null */
    private static ?array $advanced = null;

    /** @return array<string, PropertyDefinition> keyed by path, in table order */
    public static function properties(): array
    {
        if (self::$properties !== null) {
            return self::$properties;
        }
        $token = [ValueKind::Token, ValueKind::Reset];
        $choice = [ValueKind::Choice, ValueKind::Reset];
        $defs = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $defs[] = new PropertyDefinition("spacing.padding.{$side}", 'spacing', $token, true, 'spacing');
        }
        foreach (['top', 'bottom'] as $side) {
            $defs[] = new PropertyDefinition("spacing.margin.{$side}", 'spacing', $token, true, 'spacing');
        }
        $defs[] = new PropertyDefinition('width', 'width', $token, true, 'width');
        foreach (['text', 'content', 'self'] as $kind) {
            // alignment.content distributes a container's children, so it carries the
            // distribution keywords too (container-layout spec §3.2); text and self place one box.
            $defs[] = new PropertyDefinition(
                "alignment.{$kind}",
                'alignment',
                $choice,
                true,
                null,
                $kind === 'content'
                    ? ['start', 'center', 'end', 'between', 'around', 'evenly']
                    : ['start', 'center', 'end'],
            );
        }
        $defs[] = new PropertyDefinition('typography.size', 'typography', $token, true, 'typography.size');
        $defs[] = new PropertyDefinition(
            'typography.weight',
            'typography',
            $choice,
            true,
            null,
            ['regular', 'medium', 'semibold', 'bold'],
        );
        $defs[] = new PropertyDefinition('visibility', 'visibility', $choice, true, null, ['visible', 'hidden']);
        $defs[] = new PropertyDefinition('shadow', 'shadow', $token, true, 'shadow');
        $defs[] = new PropertyDefinition('radius', 'radius', $token, false, 'radius');
        foreach (['surface', 'text', 'border'] as $part) {
            $defs[] = new PropertyDefinition("colors.{$part}", 'colors', $token, false, 'color');
        }
        $defs[] = new PropertyDefinition('border.width', 'border', $choice, false, null, ['none', 'thin', 'thick']);
        $defs[] = new PropertyDefinition('border.style', 'border', $choice, false, null, ['solid', 'dashed']);

        // Layout (container-layout spec §3.2). Parent properties live on a container's `inner`
        // target, the band properties on its `root`; `layout.item` is the group a block declares
        // to size itself inside its parent's flex or grid layout. Two modes (spec §11.1): a
        // container's children are always block-level boxes, so the theme's default — a flex
        // column — is the stack block flow was, and spacing has one source in both (§3.8).
        $defs[] = new PropertyDefinition('layout.display', 'layout', $choice, true, null, [
            'flex', 'grid',
        ]);
        $defs[] = new PropertyDefinition('layout.direction', 'layout', $choice, true, null, [
            'row', 'column', 'row-reverse', 'column-reverse',
        ]);
        $defs[] = new PropertyDefinition('layout.wrap', 'layout', $choice, true, null, ['nowrap', 'wrap']);
        $defs[] = new PropertyDefinition('layout.align_items', 'layout', $choice, true, null, [
            'start', 'center', 'end', 'stretch', 'baseline',
        ]);
        $defs[] = new PropertyDefinition('layout.columns', 'layout', $choice, true, null, [
            '1', '2', '3', '4', '6', '12', '1-2', '2-1', '1-3', '3-1', '1-2-1', '1-1-2', '2-1-1',
        ]);
        $defs[] = new PropertyDefinition('layout.gap.column', 'layout', $token, true, 'spacing');
        $defs[] = new PropertyDefinition('layout.gap.row', 'layout', $token, true, 'spacing');
        $defs[] = new PropertyDefinition('layout.content_width', 'layout', $token, true, 'width');
        $defs[] = new PropertyDefinition('layout.gutter', 'layout', $token, true, 'spacing');
        $defs[] = new PropertyDefinition('layout.min_height', 'layout', $choice, true, null, [
            'auto', 'half', 'screen',
        ]);
        // One value for every width: an overflow that changed with the viewport would hide content
        // at one size and not another.
        $defs[] = new PropertyDefinition('layout.overflow', 'layout', $choice, false, null, [
            'visible', 'hidden', 'auto',
        ]);
        $defs[] = new PropertyDefinition('layout.span', 'layout.item', $choice, true, null, [
            '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', 'full',
        ]);
        $defs[] = new PropertyDefinition('layout.basis', 'layout.item', $choice, true, null, [
            'auto', '1/4', '1/3', '1/2', '2/3', '3/4', 'full',
        ]);
        $defs[] = new PropertyDefinition('layout.grow', 'layout.item', $choice, true, null, ['0', '1']);
        $defs[] = new PropertyDefinition('layout.shrink', 'layout.item', $choice, true, null, ['0', '1']);
        $defs[] = new PropertyDefinition('layout.align_self', 'layout.item', $choice, true, null, [
            'start', 'center', 'end', 'stretch',
        ]);

        // A block's marker — a feature's icon chip or number badge — has corners and a shadow of
        // its own. They are their own paths because `radius` and `shadow` are the card's, and one
        // path holds one value; they mirror those two in kind, domain and responsiveness, so the
        // Style tab draws the same controls and a theme's tokens mean the same thing on both.
        $defs[] = new PropertyDefinition('marker.radius', 'marker', $token, false, 'radius');
        $defs[] = new PropertyDefinition('marker.shadow', 'marker', $token, true, 'shadow');

        // A tabs block's strip: the bar's corners, and the tab's — the pill behind the active label.
        // Their own paths for the same reason: `radius` is the panels area's. Both mirror `radius`.
        $defs[] = new PropertyDefinition('tabs.bar_radius', 'tabs', $token, false, 'radius');
        $defs[] = new PropertyDefinition('tabs.tab_radius', 'tabs', $token, false, 'radius');

        // Which sides the border is drawn on. In the `border` group, so a block that declares a
        // border has it; last in the table, because its utility must follow the width's (it takes
        // three of the width's four sides away, at equal specificity).
        $defs[] = new PropertyDefinition('border.sides', 'border', $choice, false, null, [
            'all', 'top', 'right', 'bottom', 'left',
        ]);

        // The backdrop pair, a group of its own that a block or region opts into: how much of the
        // background colour shows (a percentage; the rest is see-through), and how much of what
        // lies behind the element is blurred. The opacity is the surface colour's, hence its path.
        $defs[] = new PropertyDefinition('colors.surface_opacity', 'backdrop', $choice, false, null, [
            '100', '90', '80', '70', '60', '50',
        ]);
        $defs[] = new PropertyDefinition('backdrop.blur', 'backdrop', $choice, false, null, [
            'none', 'sm', 'md', 'lg',
        ]);

        // The third typography property: how far apart a text's lines sit. In the group, so every
        // block that declares typography gains it on the target it already names; responsive, as
        // size is — a heading set large on a desktop wants tighter lines there.
        $defs[] = new PropertyDefinition('typography.line_height', 'typography', $choice, true, null, [
            'tight', 'snug', 'normal', 'relaxed', 'loose',
        ]);

        // Motion: how a block ENTERS as it scrolls into view. `motion` is a group any block may
        // declare — the entrance, how long it takes, how long it waits, whether it replays each
        // time. `motion.children` is the arranger's alone (a container's content area): stagger
        // spaces out the entrances of ITS CHILDREN. None is responsive: an entrance is one event,
        // not a layout.
        $defs[] = new PropertyDefinition('motion.entrance', 'motion', $choice, false, null, [
            'none', 'fade', 'fade-up', 'fade-down', 'slide-left', 'slide-right', 'zoom-in',
        ]);
        $defs[] = new PropertyDefinition('motion.duration', 'motion', $choice, false, null, [
            'fast', 'normal', 'slow',
        ]);
        $defs[] = new PropertyDefinition('motion.delay', 'motion', $choice, false, null, [
            'none', 'short', 'medium', 'long',
        ]);
        $defs[] = new PropertyDefinition('motion.repeat', 'motion', $choice, false, null, ['once', 'always']);
        $defs[] = new PropertyDefinition('motion.stagger', 'motion.children', $choice, false, null, [
            'none', 'short', 'medium', 'long',
        ]);
        // Ken Burns: a picture drifting slowly and continuously inside its frame. Not an entrance;
        // the group of a block that HAS a picture in a frame, and it lands on the frame.
        $defs[] = new PropertyDefinition('motion.ken_burns', 'motion.media', $choice, false, null, [
            'none', 'zoom-in', 'zoom-out', 'pan-left', 'pan-right',
        ]);
        // A hero's aside — the blocks in its media column — as a panel of its own: padded and filled.
        // Their own paths because `spacing` and `colors.surface` are the band's; the aside's corners
        // and shadow are the block's `radius` and `shadow`, which already land on the media box.
        $defs[] = new PropertyDefinition('aside.padding', 'aside', $token, true, 'spacing');
        $defs[] = new PropertyDefinition('aside.surface', 'aside', $token, false, 'color');

        $byPath = [];
        foreach ($defs as $def) {
            $byPath[$def->path] = $def;
        }
        return self::$properties = $byPath;
    }

    /** @return array<string, PropertyDefinition> the `settings.advanced` properties (spec §1.4) */
    public static function advanced(): array
    {
        return self::$advanced ??= [
            'anchor' => new PropertyDefinition('anchor', 'advanced', [ValueKind::Identifier], false),
            'css_classes' => new PropertyDefinition('css_classes', 'advanced', [ValueKind::Identifier], false),
            'attributes' => new PropertyDefinition('attributes', 'advanced', [ValueKind::Identifier], false),
            'accessibility.label' => new PropertyDefinition(
                'accessibility.label',
                'advanced',
                [ValueKind::Identifier],
                false,
            ),
        ];
    }

    /** @return list<string> the style groups in table order */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::properties() as $def) {
            $groups[$def->group] = true;
        }
        return array_keys($groups);
    }

    public static function property(string $path): ?PropertyDefinition
    {
        return self::properties()[$path] ?? null;
    }

    /** @return list<string> */
    public static function pathsInGroup(string $group): array
    {
        $paths = [];
        foreach (self::properties() as $def) {
            if ($def->group === $group) {
                $paths[] = $def->path;
            }
        }
        return $paths;
    }
}
