<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Psr\Log\NullLogger;
use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\ArraySource;
use Thallo\Core\Tests\Support\Search\FakeMeilisearch;
use Thallo\Core\Tests\Support\Search\FixedClock;
use Thallo\Search\Lifecycle\Drainer;
use Thallo\Search\Lifecycle\Fence;
use Thallo\Search\Lifecycle\LiveSearchIndex;
use Thallo\Search\Lifecycle\SearchIndexLocator;
use Thallo\Search\Lifecycle\StaleFence;
use Thallo\Search\Lifecycle\StateRepository;
use Thallo\Search\Lifecycle\Workspace;
use Thallo\Search\Sources\DefaultSearchSourceRegistry;
use Thallo\Search\Store\IndexStore;
use Thallo\Search\Store\MeilisearchIndexStore;
use Thallo\Search\Store\PostgresIndexStore;

/**
 * Live changes reach every current target (search block spec §3.5.2): an entry is applied only when
 * each target — the active index and, while one is being built, the build index — has a succeeded
 * acknowledgement; a target whose tasks are still running gets no replacement until they finish;
 * a stale drainer can neither acknowledge nor set status; and a failure never reaches the request.
 */
final class DrainerTest extends AppTestCase
{
    private FixedClock $clock;
    private StateRepository $state;
    private ArraySource $source;
    private SearchSourceRegistry $registry;
    private FakeMeilisearch $meili;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock();
        $this->state = new StateRepository($this->connection(), $this->clock);
        $this->source = new ArraySource();
        $this->registry = new DefaultSearchSourceRegistry();
        $this->registry->register($this->source);
        $this->meili = new FakeMeilisearch();
    }

    public function testAnEntryIsAppliedOnlyWhenEveryCurrentTargetHasIt(): void
    {
        $store = $this->meiliStore();
        $this->targets('content_v2_entries_g1', 1, 'content_v2_entries_g2', 2, $store);
        $this->source->items['a'] = ['en' => 'Rose'];
        $seq = $this->state->appendChange('entries', 'a');

        $this->meili->autoComplete = false;
        $drainer = $this->drainer($store);
        $drainer->drain('entries');
        // Complete everything but the build index's write, then drain again.
        foreach ($this->meili->pendingTasks() as $uid) {
            $this->meili->complete($uid);
        }
        self::assertFalse($this->resolved($seq), 'acks are recorded on the next pass');
        $this->clock->advance(200);
        $drainer->drain('entries');
        self::assertTrue($this->resolved($seq));
        self::assertNotNull($this->meili->document('content_v2_entries_g1', 'entries_a_Len'));
        self::assertNotNull($this->meili->document('content_v2_entries_g2', 'entries_a_Len'));
    }

    public function testAFailedBuildTargetWriteLeavesTheEntryUnresolvedAndTheKindOutOfDate(): void
    {
        $store = $this->meiliStore();
        $this->targets('content_v2_entries_g1', 1, 'content_v2_entries_g2', 2, $store);
        $this->source->items['a'] = ['en' => 'Rose'];
        $seq = $this->state->appendChange('entries', 'a');
        $this->meili->autoComplete = false;
        $this->drainer($store)->drain('entries');
        foreach ($this->meili->pendingTasks() as $uid) {
            $this->meili->fail($uid);
        }
        $this->clock->advance(200);
        $this->meili->autoComplete = false;
        $drainer = $this->drainer($store);
        $drainer->drain('entries');
        foreach ($this->meili->pendingTasks() as $uid) {
            $this->meili->fail($uid);
        }
        $this->clock->advance(200);
        $drainer->drain('entries');

        self::assertFalse($this->resolved($seq));
        self::assertSame('out_of_date', $this->state->row('entries')['status']);
    }

    public function testABuildStartingBetweenTargetReadAndAckIsCaughtUp(): void
    {
        $store = $this->meiliStore();
        $this->targets('content_v2_entries_g1', 1, null, null, $store);
        $store->createTarget(new \Thallo\Search\Store\Target('meilisearch', 'content_v2_entries_g2', 2));
        $this->source->items['a'] = ['en' => 'Rose'];
        $seq = $this->state->appendChange('entries', 'a');
        $once = false;
        $drainer = $this->drainer($store, function () use (&$once): void {
            if (!$once) {
                $once = true;
                $this->connection()->table('search_index_state')->where('kind', '=', 'entries')
                    ->update(['building_generation' => 2, 'building_target' => 'content_v2_entries_g2']);
            }
        });
        $drainer->drain('entries');

        self::assertTrue($this->resolved($seq));
        self::assertNotNull($this->meili->document('content_v2_entries_g2', 'entries_a_Len'));
    }

    public function testAStaleDrainerCannotAcknowledgeOrSetStatus(): void
    {
        $a = Fence::drainer('entries', (string) $this->state->claimDrainer('entries', 60, 30));
        $this->clock->advance(91);
        $this->state->claimDrainer('entries', 60, 30);
        $before = $this->state->row('entries');
        foreach (
            [
            fn () => $this->state->recordAck($a, 1, 'pg:g1', [], 'succeeded'),
            fn () => $this->state->setStatus($a, 'ready'),
            ] as $write
        ) {
            try {
                $write();
                self::fail('a stale drainer must be fenced out');
            } catch (StaleFence) {
            }
        }
        self::assertSame($before['status'], $this->state->row('entries')['status']);
        self::assertNull($this->state->ack('entries', 1, 'pg:g1'));
    }

    public function testTakeoverResolvesTheOldDrainersTasksFirst(): void
    {
        $store = $this->meiliStore();
        $this->targets('content_v2_entries_g1', 1, null, null, $store);
        $this->source->items['a'] = ['en' => 'Rose'];
        $seq = $this->state->appendChange('entries', 'a');
        $this->meili->autoComplete = false;
        $this->drainer($store)->drain('entries');
        $ack = $this->state->ack('entries', $seq, 'content_v2_entries_g1');
        self::assertSame('pending', $ack['status']);

        // Drainer A stops; B takes over after quiescence and first confirms A's tasks.
        foreach ($ack['task_uids'] as $uid) {
            $this->meili->complete($uid);
        }
        $this->clock->advance(200);
        $tasksBefore = count($this->meili->pendingTasks());
        $this->drainer($store)->drain('entries');

        self::assertTrue($this->resolved($seq));
        self::assertSame($tasksBefore, count($this->meili->pendingTasks()), 'no replacement was submitted');
    }

    public function testAPendingDeletionIsConfirmedBeforeAnyRetry(): void
    {
        $store = $this->meiliStore();
        $this->targets('content_v2_entries_g1', 1, null, null, $store);
        $this->source->items['a'] = ['en' => 'Rose'];
        $this->state->appendChange('entries', 'a');
        $this->drainer($store)->drain('entries');

        // The source drops a locale; its delete task is held past the confirm timeout.
        $this->source->items['a'] = ['en' => 'Rose'];
        $seq = $this->state->appendChange('entries', 'a');
        $this->meili->autoComplete = false;
        $this->drainer($store)->drain('entries');
        [$add, $delete] = $this->state->ack('entries', $seq, 'content_v2_entries_g1')['task_uids'];
        $this->meili->complete($add);

        $this->clock->advance(200);
        $this->drainer($store)->drain('entries');
        self::assertFalse($this->resolved($seq), 'the original deletion is still running');
        self::assertSame([$delete], $this->state->ack('entries', $seq, 'content_v2_entries_g1')['task_uids']);
        self::assertSame([$delete], $this->meili->pendingTasks(), 'no second replacement was submitted');

        $this->meili->complete($delete);
        $this->clock->advance(200);
        $this->drainer($store)->drain('entries');
        self::assertTrue($this->resolved($seq));
        self::assertSame([], $this->meili->pendingTasks());
    }

    public function testAPendingTaskIsKeptWhenItsSiblingFailed(): void
    {
        $store = $this->meiliStore();
        $this->targets('content_v2_entries_g1', 1, null, null, $store);
        $this->source->items['a'] = ['en' => 'Rose'];
        $seq = $this->state->appendChange('entries', 'a');
        $this->meili->autoComplete = false;
        $this->drainer($store)->drain('entries');
        [$add, $delete] = $this->state->ack('entries', $seq, 'content_v2_entries_g1')['task_uids'];
        $this->meili->fail($add);

        $this->clock->advance(200);
        $this->drainer($store)->drain('entries');
        self::assertSame(
            ['status' => 'pending', 'task_uids' => [$delete]],
            $this->state->ack('entries', $seq, 'content_v2_entries_g1'),
        );

        $this->meili->complete($delete);
        $this->meili->autoComplete = true;
        $this->clock->advance(200);
        $this->drainer($store)->drain('entries');
        self::assertTrue(
            $this->resolved($seq),
            'once the delete finished, the failed receipt was retried and succeeded',
        );
    }

    public function testAMeilisearchDeletionFailureKeepsTheEntryUnresolved(): void
    {
        $store = $this->meiliStore();
        $this->targets('content_v2_entries_g1', 1, null, null, $store);
        $this->source->items['a'] = ['en' => 'Rose', 'fr' => 'Rose'];
        $this->state->appendChange('entries', 'a');
        $this->drainer($store)->drain('entries');

        unset($this->source->items['a']['fr']);
        $seq = $this->state->appendChange('entries', 'a');
        $this->meili->autoComplete = false;
        $this->drainer($store)->drain('entries');
        [$add, $delete] = $this->state->ack('entries', $seq, 'content_v2_entries_g1')['task_uids'];
        $this->meili->complete($add);
        $this->meili->fail($delete);
        $this->clock->advance(200);
        $this->meili->autoComplete = false;
        $this->drainer($store)->drain('entries');
        foreach ($this->meili->pendingTasks() as $uid) {
            $this->meili->fail($uid);
        }
        $this->clock->advance(200);
        $this->drainer($store)->drain('entries');
        self::assertFalse($this->resolved($seq));
        self::assertSame('out_of_date', $this->state->row('entries')['status']);

        // The retry's tasks, still enqueued, now succeed; the next pass acknowledges them.
        foreach ($this->meili->pendingTasks() as $uid) {
            $this->meili->complete($uid);
        }
        $this->meili->autoComplete = true;
        $this->clock->advance(200);
        $this->drainer($store)->drain('entries');
        self::assertTrue($this->resolved($seq));
        self::assertNull($this->meili->document('content_v2_entries_g1', 'entries_a_Lfr'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function engines(): iterable
    {
        yield 'postgres' => ['pg'];
        yield 'meilisearch' => ['meili'];
    }

    /** @dataProvider engines */
    public function testRemovingOneOfTwoLocalesAndRemovingTheSource(string $engine): void
    {
        $store = $engine === 'pg' ? $this->pgStore() : $this->meiliStore();
        $engine === 'pg'
            ? $this->targets('pg', 1, null, null, $store)
            : $this->targets('content_v2_entries_g1', 1, null, null, $store);
        $index = $this->live($store);

        $this->source->items['a'] = ['en' => 'Rose', 'fr' => 'Rose'];
        $index->changed('entries', 'a');
        unset($this->source->items['a']['fr']);
        $index->changed('entries', 'a');
        self::assertSame(['en'], $this->locales($engine, 'a'));

        unset($this->source->items['a']);
        $seq = $this->state->appendChange('entries', 'a');
        $this->clock->advance(200);
        $this->drainer($store)->drain('entries');
        self::assertSame([], $this->locales($engine, 'a'));
        self::assertTrue($this->resolved($seq));
    }

    public function testALiveChangeQueuesTheDrainInsteadOfRunningItInTheRequest(): void
    {
        $store = $this->pgStore();
        $this->targets('pg', 1, null, null, $store);
        $wakes = [];
        $index = new LiveSearchIndex(
            $this->state,
            $this->drainer($store),
            $this->connection(),
            new NullLogger(),
            static function (array $data) use (&$wakes): void {
                $wakes[] = $data;
            },
        );
        $this->source->items['a'] = ['en' => 'Rose'];
        $index->changed('entries', 'a');

        self::assertSame([['workspace' => null, 'drain' => 'entries']], $wakes, 'the queue worker applies it');
        self::assertSame([], $this->locales('pg', 'a'), 'not applied in the saving request');
    }

    public function testWhenTheQueueIsDownALiveChangeIsAppliedInline(): void
    {
        $store = $this->pgStore();
        $this->targets('pg', 1, null, null, $store);
        $index = new LiveSearchIndex(
            $this->state,
            $this->drainer($store),
            $this->connection(),
            new NullLogger(),
            static function (): void {
                throw new \RuntimeException('queue down');
            },
        );
        $this->source->items['a'] = ['en' => 'Rose'];
        $index->changed('entries', 'a');

        self::assertSame(['en'], $this->locales('pg', 'a'));
    }

    public function testAnOldGenerationAckCannotSatisfyTheBuild(): void
    {
        $this->state->ensure('entries');
        $seq = $this->state->appendChange('entries', 'a');
        $drainer = Fence::drainer('entries', (string) $this->state->claimDrainer('entries', 60, 30));
        $this->state->recordAck($drainer, $seq, 'pg:g1', [], 'succeeded');
        self::assertSame([], $this->state->ackedFor('entries', 'pg:g2', [$seq]));
    }

    public function testAPausedLiveWriterOnPostgresIsRejected(): void
    {
        $store = $this->pgStore();
        $this->targets('pg', 1, null, null, $store);
        $a = Fence::drainer('entries', (string) $this->state->claimDrainer('entries', 60, 30));
        $this->clock->advance(91);
        $this->state->claimDrainer('entries', 60, 30);
        $this->source->items['a'] = ['en' => 'Rose'];

        $this->expectException(StaleFence::class);
        $store->replaceSource(
            [new \Thallo\Search\Store\Target('pg', 'pg', 1)],
            'entries',
            'a',
            $this->source->documents('a'),
            $a,
        );
    }

    public function testDrainingFailureNeverReachesTheRequest(): void
    {
        // A broken engine (a mock store counts as Meilisearch here), with its own active index.
        $this->targets('content_v2_entries_g1', 1, null, null, $this->meiliStore());
        $broken = $this->createMock(IndexStore::class);
        $broken->method('replaceSource')->willThrowException(
            new \RuntimeException('connect failed: http://meili_user:secret@meili.internal:7700/indexes'),
        );
        $this->source->items['a'] = ['en' => 'Rose'];

        $this->live($broken)->changed('entries', 'a');

        $row = $this->state->row('entries');
        self::assertSame('out_of_date', $row['status']);
        self::assertStringContainsString('[redacted]', (string) $row['last_error']);
        self::assertStringNotContainsString('secret', (string) $row['last_error']);
        self::assertStringNotContainsString('meili.internal', (string) $row['last_error']);
    }

    public function testTheContainerBindsTheLiveIndexOnlyWhileSearchIsOn(): void
    {
        self::assertInstanceOf(
            \Thallo\Search\Lifecycle\NullSearchIndex::class,
            $this->container()->get(\Thallo\Contracts\Search\SearchIndex::class),
        );
        $on = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.search' => true]]);
        self::assertInstanceOf(
            LiveSearchIndex::class,
            $on->getContainer()->get(\Thallo\Contracts\Search\SearchIndex::class),
        );
        self::assertInstanceOf(PostgresIndexStore::class, $on->getContainer()->get(IndexStore::class));
    }

    private function pgStore(): PostgresIndexStore
    {
        return new PostgresIndexStore($this->connection(), $this->state);
    }

    private function meiliStore(): MeilisearchIndexStore
    {
        return new MeilisearchIndexStore($this->meili, 20, 5);
    }

    private function locator(IndexStore $store): SearchIndexLocator
    {
        $engine = $store instanceof PostgresIndexStore ? 'pg' : 'meilisearch';
        return new SearchIndexLocator($this->state, new Workspace($this->appContext()), $engine, 'content');
    }

    private function drainer(IndexStore $store, ?\Closure $afterTargetRead = null): Drainer
    {
        return new Drainer(
            $this->state,
            $store,
            $this->registry,
            $this->locator($store),
            60,
            10,
            5,
            new NullLogger(),
            $afterTargetRead,
        );
    }

    private function live(IndexStore $store): LiveSearchIndex
    {
        return new LiveSearchIndex($this->state, $this->drainer($store), $this->connection(), new NullLogger());
    }

    private function targets(
        string $active,
        int $generation,
        ?string $building,
        ?int $buildingGeneration,
        IndexStore $store,
    ): void {
        $this->state->ensure('entries');
        $this->connection()->table('search_index_state')->where('kind', '=', 'entries')->update([
            'active_target' => $active, 'generation' => $generation,
            'building_target' => $building, 'building_generation' => $buildingGeneration,
            'status' => 'ready',
        ]);
        foreach (
            array_filter(
                [[$active, $generation], [$building, $buildingGeneration]],
                static fn ($t) => $t[0] !== null,
            ) as [$name, $g]
        ) {
            $store->createTarget(new \Thallo\Search\Store\Target(
                $store instanceof PostgresIndexStore ? 'pg' : 'meilisearch',
                (string) $name,
                (int) $g,
            ));
        }
    }

    private function resolved(int $seq): bool
    {
        $row = $this->connection()->table('search_index_changes')->where(
            'kind',
            '=',
            'entries',
        )->where('seq', '=', $seq)->first();
        return (int) ($row['resolved'] ?? 0) === 1;
    }

    /** @return list<string> */
    private function locales(string $engine, string $sourceId): array
    {
        if ($engine === 'pg') {
            $rows = $this->connection()->table('search_documents')->where('source_id', '=', $sourceId)->get();
            $locales = array_map(static fn (array $r): string => (string) $r['locale'], $rows);
        } else {
            $locales = [];
            foreach ($this->meili->ids('content_v2_entries_g1') as $id) {
                if (str_starts_with($id, 'entries_' . $sourceId . '_L')) {
                    $locales[] = substr($id, strlen('entries_' . $sourceId . '_L'));
                }
            }
        }
        sort($locales);
        return $locales;
    }
}
