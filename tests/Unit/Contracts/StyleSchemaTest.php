<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\PropertyDefinition;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\ValueKind;
use Thallo\Contracts\Style\Vocabulary;

/**
 * Visual builder spec §1.1–1.4 and §2.1: the style contract every pack compiles against.
 */
final class StyleSchemaTest extends TestCase
{
    public function testAllCapabilitiesIsEveryPropertyInTableOrder(): void
    {
        self::assertSame(array_keys(StyleSchema::properties()), StyleCapabilities::all()->paths());
        self::assertTrue(StyleCapabilities::all()->allows('radius'));
    }

    public function testTheStyleTableIsTheSpecTable(): void
    {
        $paths = array_keys(StyleSchema::properties());

        self::assertSame([
            'spacing.padding.top', 'spacing.padding.right', 'spacing.padding.bottom', 'spacing.padding.left',
            'spacing.margin.top', 'spacing.margin.bottom',
            'width',
            'alignment.text', 'alignment.content', 'alignment.self',
            'typography.size', 'typography.weight',
            'visibility',
            'shadow',
            'radius',
            'colors.surface', 'colors.text', 'colors.border',
            'border.width', 'border.style',
            // Layout (container-layout spec §3.2), in table order after the original rows.
            'layout.display', 'layout.direction', 'layout.wrap', 'layout.align_items', 'layout.columns',
            'layout.gap.column', 'layout.gap.row', 'layout.content_width', 'layout.gutter',
            'layout.min_height', 'layout.overflow',
            'layout.span', 'layout.basis', 'layout.grow', 'layout.shrink', 'layout.align_self',
            // A block's marker — a feature's icon chip or number badge — has corners and a shadow
            // of its own. They are their own paths because `radius` and `shadow` are the card's:
            // one path holds one value.
            'marker.radius', 'marker.shadow',
        ], $paths);
        self::assertSame(4, StyleSchema::VERSION);
        self::assertSame(['base', 'md', 'lg'], StyleSchema::BREAKPOINTS);
    }

    public function testEveryStylePropertyDeclaresItsKindsAndAcceptsReset(): void
    {
        foreach (StyleSchema::properties() as $path => $def) {
            self::assertInstanceOf(PropertyDefinition::class, $def);
            self::assertSame($path, $def->path);
            self::assertNotEmpty($def->kinds, $path);
            self::assertContains(ValueKind::Reset, $def->kinds, "$path accepts reset");
            self::assertNotContains(ValueKind::Literal, $def->kinds, "$path never accepts literal");
        }
    }

    public function testKindsResponsivenessAndDomainsFollowTheTable(): void
    {
        $p = StyleSchema::properties();

        self::assertTrue($p['spacing.padding.top']->responsive);
        self::assertSame('spacing', $p['spacing.padding.top']->tokenDomain);
        self::assertSame([ValueKind::Token, ValueKind::Reset], $p['spacing.padding.top']->kinds);

        self::assertFalse($p['radius']->responsive, 'radius is non-responsive in v1');
        self::assertFalse($p['colors.text']->responsive);
        self::assertSame('color', $p['colors.text']->tokenDomain);

        self::assertSame([ValueKind::Choice, ValueKind::Reset], $p['visibility']->kinds);
        self::assertSame(['visible', 'hidden'], $p['visibility']->choices);
        self::assertTrue($p['visibility']->responsive);

        self::assertSame(['start', 'center', 'end'], $p['alignment.text']->choices);
        self::assertSame(['regular', 'medium', 'semibold', 'bold'], $p['typography.weight']->choices);
        self::assertSame('typography.size', $p['typography.size']->tokenDomain);
        self::assertSame(['none', 'thin', 'thick'], $p['border.width']->choices);
        self::assertSame(['solid', 'dashed'], $p['border.style']->choices);
        self::assertSame('width', $p['width']->tokenDomain);
    }

    public function testAdvancedPropertiesAreIdentifiersAndLists(): void
    {
        $a = StyleSchema::advanced();

        self::assertSame(['anchor', 'css_classes', 'attributes', 'accessibility.label'], array_keys($a));
        self::assertSame([ValueKind::Identifier], $a['anchor']->kinds);
        self::assertFalse($a['anchor']->responsive);
        self::assertSame('advanced', $a['css_classes']->group);
    }

    public function testCapabilitiesExpandGroupsAndDefaultToNone(): void
    {
        $none = StyleCapabilities::fromDeclaration(null);
        self::assertSame([], $none->paths());
        self::assertFalse($none->allows('spacing.padding.top'));
        self::assertTrue(StyleCapabilities::none()->paths() === []);

        $caps = StyleCapabilities::fromDeclaration(['spacing', 'colors.text', 'alignment.text']);
        self::assertTrue($caps->allows('spacing.padding.left'));
        self::assertTrue($caps->allows('spacing.margin.bottom'));
        self::assertTrue($caps->allows('colors.text'));
        self::assertFalse($caps->allows('colors.surface'));
        self::assertFalse($caps->allows('radius'));
        self::assertSame(
            ['spacing.padding.top', 'spacing.padding.right', 'spacing.padding.bottom', 'spacing.padding.left',
                'spacing.margin.top', 'spacing.margin.bottom', 'colors.text', 'alignment.text'],
            $caps->paths(),
        );
    }

    public function testTheLayoutPropertiesAreDeclared(): void
    {
        // Container-layout spec §3.2: the layout table, its groups and its responsiveness.
        $expected = [
            // Flex and Grid only (spec §11.1): a flex column is the stack block flow was.
            'layout.display' => ['layout', true, null, ['flex', 'grid']],
            'layout.direction' => ['layout', true, null, ['row', 'column', 'row-reverse', 'column-reverse']],
            'layout.wrap' => ['layout', true, null, ['nowrap', 'wrap']],
            'layout.align_items' => ['layout', true, null, ['start', 'center', 'end', 'stretch', 'baseline']],
            'layout.columns' => ['layout', true, null, [
                '1', '2', '3', '4', '6', '12', '1-2', '2-1', '1-3', '3-1', '1-2-1', '1-1-2', '2-1-1',
            ]],
            'layout.gap.column' => ['layout', true, 'spacing', null],
            'layout.gap.row' => ['layout', true, 'spacing', null],
            'layout.content_width' => ['layout', true, 'width', null],
            'layout.gutter' => ['layout', true, 'spacing', null],
            'layout.min_height' => ['layout', true, null, ['auto', 'half', 'screen']],
            'layout.overflow' => ['layout', false, null, ['visible', 'hidden', 'auto']],
            'layout.span' => ['layout.item', true, null, [
                '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', 'full',
            ]],
            'layout.basis' => ['layout.item', true, null, ['auto', '1/4', '1/3', '1/2', '2/3', '3/4', 'full']],
            'layout.grow' => ['layout.item', true, null, ['0', '1']],
            'layout.shrink' => ['layout.item', true, null, ['0', '1']],
            'layout.align_self' => ['layout.item', true, null, ['start', 'center', 'end', 'stretch']],
        ];
        foreach ($expected as $path => [$group, $responsive, $domain, $choices]) {
            $def = StyleSchema::property($path);
            self::assertNotNull($def, $path);
            self::assertSame($group, $def->group, $path);
            self::assertSame($responsive, $def->responsive, $path);
            self::assertSame($domain, $def->tokenDomain, $path);
            self::assertSame($choices, $def->choices, $path);
            self::assertTrue($def->accepts(ValueKind::Reset), $path);
            self::assertTrue(
                $def->accepts($domain === null ? ValueKind::Choice : ValueKind::Token),
                $path,
            );
        }
        // alignment.content gains the distribution keywords; it stays one property.
        self::assertSame(
            ['start', 'center', 'end', 'between', 'around', 'evenly'],
            StyleSchema::property('alignment.content')?->choices,
        );
        // The item group is a capability entry: declaring it grants exactly its five paths.
        self::assertSame(
            ['layout.span', 'layout.basis', 'layout.grow', 'layout.shrink', 'layout.align_self'],
            StyleSchema::pathsInGroup('layout.item'),
        );
        self::assertSame(
            StyleSchema::pathsInGroup('layout.item'),
            StyleCapabilities::fromDeclaration(['layout.item'])->paths(),
        );
        self::assertSame(4, StyleSchema::VERSION);
    }

    public function testTheMarkerPropertiesMirrorTheCardsOwn(): void
    {
        // Same kinds, domains and responsiveness as the properties they sit beside, so the Style
        // tab draws the same controls and a theme's radius and shadow tokens mean the same thing.
        $radius = StyleSchema::property('marker.radius');
        $shadow = StyleSchema::property('marker.shadow');
        self::assertNotNull($radius);
        self::assertNotNull($shadow);

        self::assertSame('marker', $radius->group);
        self::assertSame('marker', $shadow->group);
        self::assertSame(StyleSchema::property('radius')?->tokenDomain, $radius->tokenDomain);
        self::assertSame(StyleSchema::property('shadow')?->tokenDomain, $shadow->tokenDomain);
        self::assertSame(StyleSchema::property('radius')?->responsive, $radius->responsive);
        self::assertSame(StyleSchema::property('shadow')?->responsive, $shadow->responsive);
        self::assertSame(['marker.radius', 'marker.shadow'], StyleSchema::pathsInGroup('marker'));
    }

    public function testCapabilitiesRejectUnknownPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown style capability "colors.glow"');
        StyleCapabilities::fromDeclaration(['colors.glow']);
    }

    public function testTheVocabularyIsTheBaseline(): void
    {
        self::assertSame(1, Vocabulary::VERSION);
        self::assertTrue(Vocabulary::isBaseline('spacing.lg'));
        self::assertTrue(Vocabulary::isBaseline('color.accent-contrast'));
        self::assertTrue(Vocabulary::isBaseline('typography.size.3xl'));
        self::assertFalse(Vocabulary::isBaseline('spacing.hero'));
        self::assertSame('spacing', Vocabulary::domain('spacing.lg'));
        self::assertSame('typography.size', Vocabulary::domain('typography.size.md'));
        self::assertSame(
            ['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl'],
            Vocabulary::names('spacing'),
        );
        self::assertSame(['narrow', 'content', 'container', 'full'], Vocabulary::names('width'));
        self::assertSame(['none', 'sm', 'md', 'lg', 'full'], Vocabulary::names('radius'));
        self::assertSame(
            [
                'background', 'surface', 'surface-2', 'text', 'muted', 'line', 'accent', 'accent-contrast',
                'transparent', 'white',
            ],
            Vocabulary::names('color'),
        );
        self::assertSame(['none', 'xs', 'sm', 'md', 'lg', 'xl'], Vocabulary::names('shadow'));
        self::assertSame(['xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl'], Vocabulary::names('typography.size'));
        self::assertCount(8 + 4 + 5 + 10 + 6 + 7, Vocabulary::all());
    }
}
