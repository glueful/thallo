<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Glueful\Database\Connection;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Core\Content\Style\Classes\EngineStyleClassProvider;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Content\Style\Classes\StyleClassSnapshotUnstable;
use Thallo\Core\Content\Style\SiteStyleGeneration;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Visual builder spec §4.3: a request obtains one consistent class snapshot whose generation
 * names it. The invariant asserted everywhere is that the returned generation matches the
 * returned rows; a retry is required only when the two generation reads actually differ, and
 * exhaustion is a hard failure, never a stale fallback.
 */
final class StyleClassSnapshotLoadTest extends AppTestCase
{
    private function repository(): StyleClassRepository
    {
        return $this->container()->get(StyleClassRepository::class);
    }

    public function testTheSnapshotNamesExactlyTheRowsItHolds(): void
    {
        $provider = $this->container()->get(StyleClassProvider::class);
        $provider->refresh();
        $empty = $provider->snapshot();
        self::assertSame($this->container()->get(SiteStyleGeneration::class)->current(), $empty->generation);
        self::assertSame([], $empty->classes);

        $band = $this->repository()->create(['name' => 'Band', 'style' => ['radius' => ['type' => 'reset']]]);
        $archived = $this->repository()->create(['name' => 'Old', 'style' => []]);
        $this->repository()->archive($archived['id']);

        $snapshot = $provider->snapshot();
        self::assertSame($this->container()->get(SiteStyleGeneration::class)->current(), $snapshot->generation);
        self::assertTrue($snapshot->has($band['id']));
        self::assertTrue($snapshot->has($archived['id']), 'archived classes are held');
        self::assertTrue($snapshot->get($archived['id'])['archived']);
        self::assertSame('Band', $snapshot->get($band['id'])['name']);
        self::assertSame(
            [['id' => $band['id'], 'style' => ['radius' => ['type' => 'reset']]]],
            $snapshot->refsFor([$band['id']]),
        );
    }

    public function testASnapshotIsMemoisedUntilRefresh(): void
    {
        $provider = $this->container()->get(StyleClassProvider::class);
        $provider->refresh();
        $before = $provider->snapshot();
        $this->repository()->create(['name' => 'Band', 'style' => []]);
        // The repository refreshes the provider after every write: a new snapshot follows.
        $after = $provider->snapshot();
        self::assertNotSame($before, $after);
        self::assertSame($after, $provider->snapshot(), 'memoised');
        self::assertSame($before->generation + 1, $after->generation);
    }

    public function testAWriteObservedBetweenTheTwoReadsRetriesAndReturnsTheNewerRowsWithTheirGeneration(): void
    {
        $repository = $this->repository();
        $db = $this->connection();
        $generation = $this->container()->get(SiteStyleGeneration::class);
        $provider = new class ($db, $generation, $repository) extends EngineStyleClassProvider {
            public int $reads = 0;
            private bool $interleaved = false;

            public function __construct(
                Connection $db,
                SiteStyleGeneration $generation,
                private StyleClassRepository $repo,
            ) {
                parent::__construct($db, $generation);
            }

            protected function readGeneration(): int
            {
                $this->reads++;
                if ($this->reads === 2 && !$this->interleaved) {
                    $this->interleaved = true;
                    // A class committed while the first attempt was loading: the second read observes it.
                    $this->repo->create(['name' => 'Late', 'style' => []]);
                }
                return parent::readGeneration();
            }
        };
        $snapshot = $provider->snapshot();
        self::assertSame(4, $provider->reads, 'one retry');
        self::assertSame($generation->current(), $snapshot->generation);
        self::assertCount(1, $snapshot->classes, 'the newer rows travel with the newer generation');
    }

    public function testReadsThatNeverAgreeExhaustIntoAHardFailure(): void
    {
        $generation = $this->container()->get(SiteStyleGeneration::class);
        $provider = new class ($this->connection(), $generation) extends EngineStyleClassProvider {
            private int $tick = 0;

            protected function readGeneration(): int
            {
                return ++$this->tick;
            }
        };
        $this->expectException(StyleClassSnapshotUnstable::class);
        $provider->snapshot();
    }
}
