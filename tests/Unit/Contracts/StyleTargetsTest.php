<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Contracts\Style\TargetKind;

/** Visual builder spec §1.7: named style targets with layout kinds. */
final class StyleTargetsTest extends TestCase
{
    private function button(): StyleTargets
    {
        return StyleTargets::fromDeclaration([
            'targets' => [
                'root' => ['kind' => 'row'],
                'control' => ['kind' => 'box'],
            ],
            'map' => [
                'spacing' => 'root',
                'alignment.content' => 'root',
                'advanced.anchor' => 'root',
                'advanced.attributes' => 'root',
                'radius' => 'control',
                'colors' => 'control',
                'typography' => 'control',
                'advanced.accessibility.label' => 'control',
            ],
        ]);
    }

    public function testTargetsExposeKindsOptionalityAndOwnership(): void
    {
        $t = $this->button();

        self::assertSame(['root', 'control'], $t->names());
        self::assertSame(TargetKind::Row, $t->kind('root'));
        self::assertFalse($t->optional('root'));
        self::assertSame('root', $t->targetFor('spacing.padding.top'), 'a group maps every property in it');
        self::assertSame('control', $t->targetFor('colors.text'));
        self::assertSame('control', $t->targetFor('advanced.accessibility.label'));
        self::assertNull($t->targetFor('shadow'), 'unmapped capability has no target');
        self::assertSame(
            ['spacing.padding.top', 'spacing.padding.right', 'spacing.padding.bottom',
            'spacing.padding.left', 'spacing.margin.top', 'spacing.margin.bottom', 'alignment.content'],
            $t->stylePathsFor('root')
        );
        self::assertSame(['advanced.anchor', 'advanced.attributes'], $t->advancedPathsFor('root'));
    }

    public function testValidationEnforcesKindRulesCoverageAndSingleOwnership(): void
    {
        $caps = StyleCapabilities::fromDeclaration(['spacing', 'alignment.content', 'radius', 'colors', 'typography']);
        self::assertSame([], $this->button()->validateAgainst($caps));

        // A stack now carries alignment.content too (container-layout spec §3.1: a container's
        // inner area distributes its children); a box still does not.
        $wrongKind = StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'box']],
            'map' => ['alignment.content' => 'root'],
        ]);
        self::assertSame(
            ['alignment.content requires a row or stack target; "root" is box'],
            $wrongKind->validateAgainst(StyleCapabilities::fromDeclaration(['alignment.content'])),
        );

        $unmapped = StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'text']],
            'map' => ['alignment.text' => 'root'],
        ]);
        self::assertSame(
            ['capability spacing.padding.top has no target'],
            array_slice(
                $unmapped->validateAgainst(StyleCapabilities::fromDeclaration(['alignment.text', 'spacing'])),
                0,
                1,
            ),
        );

        $textOnRow = StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'row']],
            'map' => ['alignment.text' => 'root'],
        ]);
        self::assertSame(
            ['alignment.text requires a text target; "root" is row'],
            $textOnRow->validateAgainst(StyleCapabilities::fromDeclaration(['alignment.text'])),
        );
    }

    public function testLayoutKindRulesAcceptTheKindsTheyName(): void
    {
        // Container-layout spec §3.1/§3.6: parent layout belongs on a stack target, band
        // properties on a box, and item properties on any outermost root — box, row or text.
        $stack = StyleTargets::fromDeclaration([
            'targets' => ['inner' => ['kind' => 'stack']],
            'map' => ['layout.columns' => 'inner', 'layout.gap.column' => 'inner', 'alignment.content' => 'inner'],
        ]);
        self::assertSame([], $stack->validateAgainst(StyleCapabilities::fromDeclaration([
            'layout.columns', 'layout.gap.column', 'alignment.content',
        ])));

        $box = StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'box']],
            'map' => ['layout.min_height' => 'root', 'layout.overflow' => 'root', 'layout.item' => 'root',
                'alignment.self' => 'root'],
        ]);
        self::assertSame([], $box->validateAgainst(StyleCapabilities::fromDeclaration([
            'layout.min_height', 'layout.overflow', 'layout.item', 'alignment.self',
        ])));

        // A text root (heading, rich text) is a block-level element: item sizing and Placement
        // are meaningful on it, text alignment stays text-only.
        $text = StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'text']],
            'map' => ['layout.item' => 'root', 'alignment.self' => 'root', 'alignment.text' => 'root'],
        ]);
        self::assertSame([], $text->validateAgainst(StyleCapabilities::fromDeclaration([
            'layout.item', 'alignment.self', 'alignment.text',
        ])));

        $row = StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'row']],
            'map' => ['layout.item' => 'root', 'alignment.content' => 'root'],
        ]);
        self::assertSame([], $row->validateAgainst(StyleCapabilities::fromDeclaration([
            'layout.item', 'alignment.content',
        ])));
    }

    public function testLayoutKindRulesRefuseTheWrongKindNamingEveryAllowedKind(): void
    {
        $box = StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'box']],
            'map' => ['layout.columns' => 'root'],
        ]);
        $errors = $box->validateAgainst(StyleCapabilities::fromDeclaration(['layout.columns']));
        self::assertCount(1, $errors);
        self::assertStringContainsString('layout.columns requires a stack target', $errors[0]);

        $stack = StyleTargets::fromDeclaration([
            'targets' => ['inner' => ['kind' => 'stack']],
            'map' => ['layout.min_height' => 'inner'],
        ]);
        $errors = $stack->validateAgainst(StyleCapabilities::fromDeclaration(['layout.min_height']));
        self::assertCount(1, $errors);
        self::assertStringContainsString('layout.min_height requires a box target', $errors[0]);

        // A set of kinds names every allowed one in its message.
        $textOnly = StyleTargets::fromDeclaration([
            'targets' => ['label' => ['kind' => 'text']],
            'map' => ['alignment.content' => 'label'],
        ]);
        $errors = $textOnly->validateAgainst(StyleCapabilities::fromDeclaration(['alignment.content']));
        self::assertCount(1, $errors);
        self::assertStringContainsString('alignment.content requires a row or stack target', $errors[0]);
    }

    public function testAnAdvancedPathMappedTwiceIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('advanced.anchor is mapped more than once');
        StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'box'], 'control' => ['kind' => 'box']],
            'map' => ['advanced.anchor' => ['root', 'control']],
        ]);
    }

    public function testUnknownTargetKindAndUnknownTargetNameAreErrors(): void
    {
        try {
            StyleTargets::fromDeclaration(['targets' => ['root' => ['kind' => 'grid']], 'map' => []]);
            self::fail('unknown kind accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('unknown target kind "grid" for target "root"', $e->getMessage());
        }
        try {
            StyleTargets::fromDeclaration(['targets' => ['root' => ['kind' => 'box']], 'map' => ['radius' => 'media']]);
            self::fail('unknown target accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('radius maps to undeclared target "media"', $e->getMessage());
        }
    }

    public function testOptionalTargetsAreDeclared(): void
    {
        $hero = StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'stack'], 'media' => ['kind' => 'box', 'optional' => true]],
            'map' => ['spacing' => 'root', 'radius' => 'media', 'shadow' => 'media'],
        ]);
        self::assertTrue($hero->optional('media'));
        self::assertSame(['shadow', 'radius'], $hero->stylePathsFor('media'), 'table order');
    }

    public function testRootDeclaresOneNonOptionalRootOwningTheCapabilitiesAndTheAuthorsAdvancedPaths(): void
    {
        $decl = StyleTargets::root('row', ['spacing', 'alignment.content'], [
            'targets' => ['control' => ['kind' => 'box']],
            'map' => ['radius' => 'control', 'advanced.accessibility.label' => 'control'],
        ]);
        $targets = StyleTargets::fromDeclaration($decl);

        self::assertSame(['root', 'control'], $targets->names());
        self::assertSame(TargetKind::Row, $targets->kind('root'));
        self::assertFalse($targets->optional('root'));
        self::assertSame('root', $targets->targetFor('spacing.padding.top'));
        self::assertSame('root', $targets->targetFor('advanced.css_classes'));
        self::assertSame('control', $targets->targetFor('radius'));
        self::assertSame('control', $targets->targetFor('advanced.accessibility.label'));
        $caps = StyleCapabilities::fromDeclaration(['spacing', 'alignment.content', 'radius']);
        self::assertSame([], $targets->validateAgainst($caps));
    }
}
