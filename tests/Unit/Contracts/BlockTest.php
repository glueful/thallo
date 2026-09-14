<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Content\Block;

/** Visual builder spec §1.2: one Block value object, so no path can forget settings. */
final class BlockTest extends TestCase
{
    public function testRoundTripPreservesEveryKeyAndClassOrder(): void
    {
        $raw = [
            'id' => 'blk000000001',
            'type' => 'hero',
            'data' => [
                'title' => 'Hi',
                'links' => [['id' => 'btn000000001', 'type' => 'button', 'data' => ['label' => 'Go', 'url' => '/']]],
            ],
            'settings' => [
                'classes' => ['zeta', 'alpha', 'mid'],
                'style' => [
                    'spacing' => ['padding' => ['top' => ['md' => ['type' => 'token', 'value' => 'spacing.lg']]]],
                ],
                'advanced' => ['anchor' => 'top'],
            ],
        ];

        $block = Block::fromArray($raw);

        self::assertSame('blk000000001', $block->id);
        self::assertSame('hero', $block->type);
        self::assertSame(['zeta', 'alpha', 'mid'], $block->settings['classes']);
        self::assertSame($raw, $block->toArray(), 'byte-identical round trip, keys in canonical order');
        self::assertSame(['id', 'type', 'data', 'settings'], array_keys($block->toArray()));
    }

    public function testMissingSettingsNormaliseToAnEmptyObjectAndWithersAreImmutable(): void
    {
        $block = Block::fromArray(['id' => 'x', 'type' => 'heading', 'data' => ['text' => 'a']]);
        self::assertSame([], $block->settings);
        self::assertSame(
            ['id' => 'x', 'type' => 'heading', 'data' => ['text' => 'a'], 'settings' => []],
            $block->toArray(),
        );

        $styled = $block->withSettings(['advanced' => ['anchor' => 'a']]);
        self::assertSame([], $block->settings, 'original untouched');
        self::assertSame(['advanced' => ['anchor' => 'a']], $styled->settings);

        $edited = $styled->withData(['text' => 'b']);
        self::assertSame(['text' => 'b'], $edited->data);
        self::assertSame(['advanced' => ['anchor' => 'a']], $edited->settings, 'settings survive a data change');
    }

    public function testIdAndTypeAreRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Block::fromArray(['type' => 'heading', 'data' => []]);
    }
}
