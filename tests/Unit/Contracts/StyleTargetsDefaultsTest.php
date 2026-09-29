<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\StyleTargets;

/**
 * A target may declare the arrangement the theme gives it when nothing is set (type layouts plan
 * C2): the Product list's cards are an adaptive grid before anyone touches them, and the inspector
 * and the emitter both need to know it. `defaults` is validated like the rest of the declaration.
 */
final class StyleTargetsDefaultsTest extends TestCase
{
    /** @param array<string,mixed> $defaults */
    private static function withDefaults(array $defaults): StyleTargets
    {
        return StyleTargets::fromDeclaration(StyleTargets::root('box', ['spacing'], [
            'targets' => ['cards' => ['kind' => 'stack', 'defaults' => $defaults]],
            'map' => ['layout.display' => 'cards', 'layout.columns' => 'cards'],
        ]));
    }

    public function testATargetsDeclaredDefaultsAreExposed(): void
    {
        $defaults = [
            'display' => 'grid',
            'columns' => ['label' => 'Adaptive — as many 15rem columns as fit'],
            'gap' => ['row' => '1.75rem', 'column' => '1.5rem'],
        ];
        $targets = self::withDefaults($defaults);
        self::assertSame($defaults, $targets->defaults('cards'));
        self::assertNull($targets->defaults('root'), 'a target without defaults has none');
        self::assertSame(['display' => 'flex'], self::withDefaults(['display' => 'flex'])->defaults('cards'));
    }

    public function testAnUnknownTargetHasNoDefaultsToAskFor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::withDefaults(['display' => 'grid'])->defaults('nowhere');
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function invalid(): iterable
    {
        yield 'no display' => [['columns' => ['label' => 'Adaptive']]];
        yield 'a display the vocabulary has no mode for' => [['display' => 'block']];
        yield 'an unknown key' => [['display' => 'grid', 'rows' => 2]];
        yield 'columns without a label' => [['display' => 'grid', 'columns' => []]];
        yield 'columns with an empty label' => [['display' => 'grid', 'columns' => ['label' => '']]];
        yield 'columns with an unknown key' => [['display' => 'grid', 'columns' => ['label' => 'A', 'min' => '15rem']]];
        yield 'a gap that is not text' => [['display' => 'grid', 'gap' => ['row' => 28]]];
        yield 'an unknown gap' => [['display' => 'grid', 'gap' => ['both' => '1rem']]];
        yield 'defaults that are not a map' => [['grid']];
    }

    /**
     * @param array<string,mixed> $defaults
     * @dataProvider invalid
     */
    public function testAnInvalidDeclarationIsRefused(array $defaults): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::withDefaults($defaults);
    }
}
