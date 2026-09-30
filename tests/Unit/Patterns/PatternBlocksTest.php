<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Patterns;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Patterns\PatternBlocks;

/**
 * The page library's construction toolkit, shared with packs: the shapes are the structure
 * picker's presets, exactly as the shipped sections have always been built.
 */
final class PatternBlocksTest extends TestCase
{
    public function testABandWrapsItsContentInTheSectionPreset(): void
    {
        $heading = [
            'type' => 'heading',
            'data' => ['text' => 'Hi', 'level' => 'h2'],
            'settings' => ['style' => [
                'alignment' => ['text' => ['base' => ['type' => 'choice', 'value' => 'center']]],
            ]],
        ];
        self::assertSame($heading, PatternBlocks::heading('Hi', 'h2', 'center'));

        self::assertSame([
            'type' => 'container',
            'data' => ['element' => 'section', 'content' => [$heading]],
            'settings' => ['style' => [
                'spacing' => ['padding' => [
                    'top' => ['base' => ['type' => 'token', 'value' => 'spacing.3xl']],
                    'bottom' => ['base' => ['type' => 'token', 'value' => 'spacing.3xl']],
                ]],
                'layout' => [
                    'content_width' => ['base' => ['type' => 'token', 'value' => 'width.container']],
                    'display' => ['base' => ['type' => 'choice', 'value' => 'flex']],
                    'direction' => ['base' => ['type' => 'choice', 'value' => 'column']],
                    'gap' => ['row' => ['base' => ['type' => 'token', 'value' => 'spacing.xl']]],
                ],
            ]],
        ], PatternBlocks::band([PatternBlocks::heading('Hi', 'h2', 'center')]));
    }

    public function testACallToActionLeadsWithASolidButton(): void
    {
        $cta = PatternBlocks::cta('T', 'D', 'soft', 'horizontal', ['A', 'B']);

        self::assertSame('cta', $cta['type']);
        self::assertSame(['solid', 'outline'], array_column(array_column($cta['data']['links'], 'data'), 'variant'));
        self::assertSame(['A', 'B'], array_column(array_column($cta['data']['links'], 'data'), 'label'));
    }
}
