<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Render\Style\BlockStyleEmitter;
use Thallo\Render\Style\ClassNames;

/** Custom palette spec §3.2: an unavailable reference contributes no colour override, in every layer. */
final class BlockStyleEmitterPaletteTest extends TestCase
{
    private function targets(): StyleTargets
    {
        return StyleTargets::fromDeclaration(StyleTargets::root('box', ['colors', 'hover']));
    }

    /** @return array{type: string, value: string} */
    private static function tok(string $v): array
    {
        return ['type' => 'token', 'value' => $v];
    }

    /**
     * @param array<string,mixed> $settings
     * @param list<array{id: string, style: array<string,mixed>}> $classes
     * @return list<string>
     */
    private function emit(array $settings, array $classes, Palette $palette): array
    {
        return (new BlockStyleEmitter())->classesFor($settings, $this->targets(), 'root', $classes, null, $palette);
    }

    public function testAClassAccentShowsThroughAnUnavailableInstanceBrand(): void
    {
        $settings = ['classes' => ['c1'], 'style' => ['colors' => ['text' => self::tok('color.brand-1')]]];
        $classes = [['id' => 'c1', 'style' => ['colors' => ['text' => self::tok('color.accent')]]]];
        self::assertSame(['t-fg-accent'], $this->emit($settings, $classes, Palette::empty()));
    }

    public function testAnUnavailableClassValueUnderAnInstanceAccentStaysAccent(): void
    {
        $settings = ['classes' => ['c1'], 'style' => ['colors' => ['text' => self::tok('color.accent')]]];
        $classes = [['id' => 'c1', 'style' => ['colors' => ['text' => self::tok('color.brand-2')]]]];
        self::assertSame(['t-fg-accent'], $this->emit($settings, $classes, Palette::empty()));
    }

    public function testAnUnavailableValueAloneEmitsNothing(): void
    {
        $settings = ['style' => ['colors' => ['text' => self::tok('color.brand-3-contrast')]]];
        self::assertSame([], $this->emit($settings, [], Palette::empty()));
    }

    public function testAMissingHoverColourLeavesTheNormalColour(): void
    {
        $settings = ['style' => [
            'colors' => ['text' => self::tok('color.accent')],
            'hover' => ['colors' => ['text' => self::tok('color.brand-1')]],
        ]];
        self::assertSame(['t-fg-accent'], $this->emit($settings, [], Palette::empty()));
    }

    public function testAConfiguredSlotEmitsItsUtility(): void
    {
        $p = new Palette(brands: [1 => new BrandSlot('Gold dark', '#8a6a2a'), 2 => null, 3 => null]);
        $settings = ['style' => [
            'colors' => ['text' => self::tok('color.brand-1')],
            'hover' => ['colors' => ['surface' => self::tok('color.brand-1-contrast')]],
        ]];
        $out = $this->emit($settings, [], $p);
        self::assertContains(ClassNames::for('colors.text', 'color.brand-1'), $out);
        self::assertContains(ClassNames::for('hover.colors.surface', 'color.brand-1-contrast'), $out);
    }

    public function testPartsFollowTheSameRule(): void
    {
        $targets = StyleTargets::fromDeclaration([
            'targets' => ['root' => ['kind' => 'box']],
            'parts' => ['link' => ['label' => 'Link', 'capabilities' => ['colors.text', 'hover.colors.text']]],
        ]);
        $settings = ['parts' => ['link' => ['colors' => ['text' => self::tok('color.brand-2')]]]];
        $emitter = new BlockStyleEmitter();
        self::assertSame([], $emitter->classesFor($settings, $targets, 'link', [], null, Palette::empty()));
    }

    public function testWithoutAPaletteNothingIsFiltered(): void
    {
        $settings = ['style' => ['colors' => ['text' => self::tok('color.brand-1')]]];
        self::assertSame(
            [ClassNames::for('colors.text', 'color.brand-1')],
            (new BlockStyleEmitter())->classesFor($settings, $this->targets(), 'root'),
        );
    }
}
