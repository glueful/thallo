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
        ], $paths);
        self::assertSame(1, StyleSchema::VERSION);
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
