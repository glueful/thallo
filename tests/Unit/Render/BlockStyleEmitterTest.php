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
        self::assertSame(['md:t-pt-lg', 't-content-center'], $root);
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
