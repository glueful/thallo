<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Thallo\Contracts\Style\StyleClassRef;
use Thallo\Contracts\Style\StyleClassSnapshot;

/**
 * Visual builder spec §4.1 and §4.3: one generation names one exact set of class records; a
 * snapshot answers references in the block's order and holds archived classes so old
 * revisions still resolve.
 */
final class StyleClassSnapshotTest extends TestCase
{
    private function snapshot(): StyleClassSnapshot
    {
        return new StyleClassSnapshot(7, [
            'band' => [
                'id' => 'band', 'name' => 'Band', 'archived' => false,
                'style' => ['radius' => ['type' => 'token', 'value' => 'radius.lg']],
            ],
            'old' => ['id' => 'old', 'name' => 'Old', 'style' => [], 'archived' => true],
        ]);
    }

    public function testItKnowsItsGenerationAndItsClasses(): void
    {
        $snapshot = $this->snapshot();
        self::assertSame(7, $snapshot->generation);
        self::assertTrue($snapshot->has('band'));
        self::assertTrue($snapshot->has('old'), 'an archived class is still owned');
        self::assertFalse($snapshot->has('other'));
        self::assertSame('Band', $snapshot->get('band')['name']);
        self::assertNull($snapshot->get('other'));
    }

    public function testRefsKeepTheCallersOrderAndSkipUnknownIds(): void
    {
        $refs = $this->snapshot()->refsFor(['old', 'missing', 'band']);
        self::assertSame(['old', 'band'], array_column($refs, 'id'));
        self::assertSame('radius.lg', $refs[1]['style']['radius']['value']);
        self::assertSame([], $this->snapshot()->refsFor([]));
    }

    public function testAnEmptySnapshotIsGenerationZero(): void
    {
        $empty = StyleClassSnapshot::empty();
        self::assertSame(0, $empty->generation);
        self::assertSame([], $empty->refsFor(['band']));
    }

    public function testARefIsTheResolverShape(): void
    {
        $ref = new StyleClassRef('band', ['radius' => ['type' => 'reset']]);
        self::assertSame(['id' => 'band', 'style' => ['radius' => ['type' => 'reset']]], $ref->toArray());
    }
}
