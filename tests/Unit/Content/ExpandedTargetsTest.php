<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Delivery\ExpandedTargets;
use PHPUnit\Framework\TestCase;

final class ExpandedTargetsTest extends TestCase
{
    public function testCollectsDedupedEntriesAndSortedVersionIdentities(): void
    {
        $t = new ExpandedTargets();
        $t->add('entryB000001', 'verB00000001');
        $t->add('entryA000001', 'verA00000001');
        $t->add('entryB000001', 'verB00000002'); // dupe entry: first version wins
        $t->add('', 'ignored00001');             // empty entry uuid ignored

        self::assertSame(['entryB000001', 'entryA000001'], $t->entryUuids());
        // Sorted identities — stable regardless of splice order.
        self::assertSame(
            ['entryA000001:verA00000001', 'entryB000001:verB00000001'],
            $t->versionIdentities(),
        );
    }

    public function testEmptyCollector(): void
    {
        $t = new ExpandedTargets();
        self::assertSame([], $t->entryUuids());
        self::assertSame([], $t->versionIdentities());
    }
}
