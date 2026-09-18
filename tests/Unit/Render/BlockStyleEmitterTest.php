<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Render\Style\BlockStyleEmitter;

/** Visual builder spec §2.5: settings become classes and attributes per target, nothing more. */
final class BlockStyleEmitterTest extends TestCase
{
    private function buttonTargets(): StyleTargets
    {
        return StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'row'], 'control' => ['kind' => 'box']],
            'map' => [
                'spacing' => 'root',
                'alignment.content' => 'root',
                'visibility' => 'root',
                'radius' => 'control',
                'colors' => 'control',
                'typography' => 'control',
                'advanced.anchor' => 'root',
                'advanced.attributes' => 'root',
                'advanced.css_classes' => 'root',
                'advanced.accessibility.label' => 'control',
            ],
        ]);
    }

    public function testOnlyExactlyDeclaredBreakpointsBecomeClassesMobileFirst(): void
    {
        $emitter = new BlockStyleEmitter();
        $settings = ['style' => [
            'spacing' => ['padding' => ['top' => [
                'md' => ['type' => 'token', 'value' => 'spacing.lg'],
            ]]],
            'alignment' => ['content' => ['base' => ['type' => 'choice', 'value' => 'center']]],
            'radius' => ['type' => 'token', 'value' => 'radius.full'],
        ]];

        $root = $emitter->classesFor($settings, $this->buttonTargets(), 'root');
        // Spacing keeps exact-only emission; alignment.content is layout, so it is emitted at
        // every breakpoint it resolves (container-layout spec §3.3).
        self::assertSame(
            ['md:t-pt-lg', 't-content-center', 'md:t-content-center', 'lg:t-content-center'],
            $root,
        );
        self::assertSame(['t-radius-full'], $emitter->classesFor($settings, $this->buttonTargets(), 'control'));
    }

    public function testAResetIsEmittedWhereItIsDeclared(): void
    {
        $settings = ['style' => ['spacing' => ['padding' => ['top' => [
            'base' => ['type' => 'token', 'value' => 'spacing.sm'],
            'md' => ['type' => 'reset'],
            'lg' => ['type' => 'token', 'value' => 'spacing.xl'],
        ]]]]];

        self::assertSame(
            ['t-pt-sm', 'md:t-pt-reset', 'lg:t-pt-xl'],
            (new BlockStyleEmitter())->classesFor($settings, $this->buttonTargets(), 'root'),
        );
    }

    public function testAnExactClassBreakpointBeatsAnInheritedInstanceValue(): void
    {
        $settings = ['classes' => ['c1'], 'style' => ['spacing' => ['padding' => ['top' => [
            'base' => ['type' => 'token', 'value' => 'spacing.sm'],
        ]]]]];
        $classes = [['id' => 'c1', 'style' => ['spacing' => ['padding' => ['top' => [
            'base' => ['type' => 'token', 'value' => 'spacing.lg'],
            'md' => ['type' => 'token', 'value' => 'spacing.xl'],
        ]]]]]];

        self::assertSame(
            ['t-pt-sm', 'md:t-pt-xl'],
            (new BlockStyleEmitter())->classesFor($settings, $this->buttonTargets(), 'root', $classes),
        );
    }

    public function testLayoutClassesAreEmittedAtEveryBreakpointTheyResolve(): void
    {
        // Container-layout spec §3.3: dormancy and span pairing are decided per breakpoint, so a
        // layout property's state must be visible at every breakpoint, not only where declared.
        $targets = StyleTargets::fromDeclaration([
            'targets' => ['inner' => ['kind' => 'stack']],
            'map' => ['layout.display' => 'inner', 'layout.columns' => 'inner', 'spacing' => 'inner'],
        ]);
        $emitter = new BlockStyleEmitter();

        $flexAtBase = ['style' => ['layout' => ['display' => ['base' => ['type' => 'choice', 'value' => 'flex']]]]];
        self::assertSame(
            [
                't-display-flex', 'md:t-display-flex', 'lg:t-display-flex',
                't-cols-auto', 'md:t-cols-auto', 'lg:t-cols-auto',
            ],
            $emitter->classesFor($flexAtBase, $targets, 'inner'),
        );

        $gridAtMd = ['style' => ['layout' => ['display' => [
            'base' => ['type' => 'choice', 'value' => 'flex'],
            'md' => ['type' => 'choice', 'value' => 'grid'],
        ]]]];
        self::assertSame(
            [
                't-display-flex', 'md:t-display-grid', 'lg:t-display-grid',
                't-cols-auto', 'md:t-cols-auto', 'lg:t-cols-auto',
            ],
            $emitter->classesFor($gridAtMd, $targets, 'inner'),
        );

        $resetAtLg = ['style' => ['layout' => ['display' => [
            'base' => ['type' => 'choice', 'value' => 'grid'],
            'lg' => ['type' => 'reset'],
        ]]]];
        self::assertSame(
            [
                't-display-grid', 'md:t-display-grid', 'lg:t-display-reset',
                't-cols-auto', 'md:t-cols-auto', 'lg:t-cols-auto',
            ],
            $emitter->classesFor($resetAtLg, $targets, 'inner'),
        );

        // Spacing is unchanged: only the breakpoints it declares become classes.
        $padded = ['style' => ['spacing' => ['padding' => ['top' => [
            'md' => ['type' => 'token', 'value' => 'spacing.lg'],
        ]]]]];
        // Emission follows the target's declared path order: spacing, then the layout paths.
        self::assertSame(
            ['md:t-pt-lg', 't-cols-auto', 'md:t-cols-auto', 'lg:t-cols-auto'],
            $emitter->classesFor($padded, $targets, 'inner'),
        );
    }

    public function testAuthorClassesAndAttributesLandOnlyOnTheOwningTarget(): void
    {
        $emitter = new BlockStyleEmitter();
        $settings = ['advanced' => [
            'anchor' => 'pricing',
            'css_classes' => ['is-featured', ''],
            'attributes' => ['data-track' => 'cta', 'onclick' => 'x'],
            'accessibility' => ['label' => 'Buy now'],
        ]];

        self::assertSame(['is-featured'], $emitter->classesFor($settings, $this->buttonTargets(), 'root'));
        self::assertSame([], $emitter->classesFor($settings, $this->buttonTargets(), 'control'));
        $rootAttrs = $emitter->attrsFor($settings, $this->buttonTargets(), 'root');
        self::assertSame(['id' => 'pricing', 'data-track' => 'cta'], $rootAttrs);
        self::assertSame(['aria-label' => 'Buy now'], $emitter->attrsFor($settings, $this->buttonTargets(), 'control'));
        self::assertSame([], $emitter->classesFor([], $this->buttonTargets(), 'root'));
        self::assertSame([], $emitter->attrsFor(['settings' => null], $this->buttonTargets(), 'root'));
    }
}
