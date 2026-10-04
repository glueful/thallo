<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Psr\Log\NullLogger;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\LifecycleKit;
use Thallo\Search\Lifecycle\LiveSearchIndex;
use Thallo\Search\Lifecycle\RebuildOutcome;
use Thallo\Search\Lifecycle\WakeGate;

/**
 * Live changes reach the index through the queue without flooding it (search block spec §3.5.2): a
 * bulk write queues one drain per workspace and kind, that drain empties the whole backlog — past
 * the drainer's batch — and once it has started, the next change wakes the queue again.
 */
final class LiveDrainQueueTest extends AppTestCase
{
    public function testABulkWriteQueuesOneDrainThatDrainsTheWholeBacklog(): void
    {
        $kit = new LifecycleKit($this->appContext(), $this->connection());
        $kit->source->items = ['seed' => ['en' => 'Seed']];
        self::assertSame(RebuildOutcome::PROMOTED, $kit->rebuilder()->run('entries'));

        $gate = new WakeGate($this->container()->get(\Glueful\Cache\CacheStore::class));
        $wakes = [];
        $index = new LiveSearchIndex(
            $kit->state,
            $kit->drainer(),
            $this->connection(),
            new NullLogger(),
            static function (array $data) use (&$wakes): void {
                $wakes[] = $data;
            },
            null,
            $gate,
        );
        for ($i = 0; $i < 120; $i++) {
            $id = sprintf('bulk%03d', $i);
            $kit->source->items[$id] = ['en' => 'Rose ' . $i];
            $index->changed('entries', $id);
        }
        self::assertSame([['workspace' => null, 'drain' => 'entries']], $wakes, 'one wake-up for 120 changes');
        self::assertCount(120, $kit->state->unresolvedEntries('entries'), 'nothing applied in the request');

        $kit->reconciler($gate)->drainBacklog('entries');
        self::assertSame([], $kit->state->unresolvedEntries('entries'), 'past the batch of 50, to the end');

        $kit->source->items['late'] = ['en' => 'Late rose'];
        $index->changed('entries', 'late');
        self::assertCount(2, $wakes, 'the started drain reopened the gate');
    }
}
