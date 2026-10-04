<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Psr\Log\NullLogger;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\ArraySource;
use Thallo\Core\Tests\Support\Search\FakeMeilisearch;
use Thallo\Core\Tests\Support\Search\FixedClock;
use Thallo\Search\Lifecycle\DemandResolver;
use Thallo\Search\Lifecycle\Drainer;
use Thallo\Search\Lifecycle\Fence;
use Thallo\Search\Lifecycle\IndexRetirement;
use Thallo\Search\Lifecycle\LiveSearchIndex;
use Thallo\Search\Lifecycle\RebuildOutcome;
use Thallo\Search\Lifecycle\Rebuilder;
use Thallo\Search\Lifecycle\SearchIndexLocator;
use Thallo\Search\Lifecycle\StaleFence;
use Thallo\Search\Lifecycle\StateRepository;
use Thallo\Search\Lifecycle\Workspace;
use Thallo\Search\Sources\DefaultSearchSourceRegistry;
use Thallo\Search\Store\IndexStore;
use Thallo\Search\Store\MeilisearchIndexStore;
use Thallo\Search\Store\PostgresIndexStore;
use Thallo\Search\Store\Target;

/**
 * Rebuilds (search block spec §3.5.3–§3.5.8): each ownership attempt gets its own generation (and,
 * on Meilisearch, its own index); changes made during a build are replayed into it; promotion waits
 * until every relevant journal entry is acknowledged on the build target; completion uses the
 * promoted fence and the values captured when the build began; old indexes are retired after a
 * grace period, and orphans are collected.
 */
final class RebuilderTest extends AppTestCase
{
    private FixedClock $clock;
    private StateRepository $state;
    private ArraySource $source;
    private DefaultSearchSourceRegistry $registry;
    private FakeMeilisearch $meili;
    /** @var array<string, \Closure> */
    private array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock();
        $this->state = new StateRepository($this->connection(), $this->clock);
        $this->source = new ArraySource();
        $this->registry = new DefaultSearchSourceRegistry();
        $this->registry->register($this->source);
        $this->meili = new FakeMeilisearch();
        $this->hooks = [];
    }

    /** @return iterable<string, array{0: string}> */
    public static function engines(): iterable
    {
        yield 'postgres' => ['pg'];
        yield 'meilisearch' => ['meili'];
    }

    /** @dataProvider engines */
    public function testAnUpdateDeletionAndInsertionDuringABuildAllLand(string $engine): void
    {
        $store = $this->store($engine);
        $this->source->items = ['a' => ['en' => 'A'], 'b' => ['en' => 'B'], 'c' => ['en' => 'C']];
        $live = $this->live($store);
        $first = true;
        $this->source->onEnumerate = function () use (&$first, $live): void {
            if (!$first) {
                return;
            }
            $first = false;
            $this->source->items['a'] = ['en' => 'A edited'];
            unset($this->source->items['b']);
            $this->source->items['d'] = ['en' => 'D'];
            $live->changed('entries', 'a');
            $live->changed('entries', 'b');
            $live->changed('entries', 'd');
        };

        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store, 1)->run('entries'));

        self::assertSame(['a' => 'A edited', 'c' => 'C', 'd' => 'D'], $this->activeTitles($engine));
    }

    /** @dataProvider engines */
    public function testAFailedDeletionFromBeforeTheBuildIsReplayedAndResolved(string $engine): void
    {
        $store = $this->store($engine);
        $this->source->items = ['a' => ['en' => 'A']];
        $seq = $this->state->appendChange('entries', 'z');
        $this->state->markEntryFailed('entries', $seq, 'delete failed');

        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));

        self::assertTrue($this->resolved($seq));
        self::assertSame(['a' => 'A'], $this->activeTitles($engine));
        self::assertSame('ready', $this->state->row('entries')['status']);
    }

    /** @dataProvider engines */
    public function testOverlappingRequestsCoalesce(string $engine): void
    {
        $store = $this->store($engine);
        $this->source->items = ['a' => ['en' => 'A'], 'b' => ['en' => 'B']];
        $this->state->addDemand('entries', 'manual');
        $rebuilder = $this->rebuilder($store, 1);
        $inner = null;
        $this->hooks['afterBatch'] = function () use (&$inner, $rebuilder): void {
            if ($inner === null) {
                $inner = $rebuilder->run('entries');
                $this->state->addDemand('entries', 'manual');
            }
        };

        self::assertSame(RebuildOutcome::PROMOTED, $rebuilder->run('entries'));
        self::assertSame(RebuildOutcome::BUSY, $inner);
        $row = $this->state->row('entries');
        self::assertSame(1, (int) $row['satisfied_seq'], 'only the demand captured at the start');
        self::assertSame(2, $this->state->maxDemandSeq('entries'), 'the later demand stays outstanding');
    }

    /** @dataProvider engines */
    public function testAKilledBuildIsRestartedNotResumed(string $engine): void
    {
        $store = $this->store($engine);
        $this->source->items = ['a' => ['en' => 'A'], 'b' => ['en' => 'B'], 'c' => ['en' => 'C']];
        // Builder A claims, writes one batch, and dies without releasing.
        $a = $this->state->claimBuild('entries', 120, 0, 1);
        $aTarget = $this->locator($store)->buildTarget('entries', $a->generation);
        $store->createTarget($aTarget);
        $this->state->recordBuildTarget(Fence::builder('entries', $a->token, $a->generation), $aTarget->name);
        $store->write($aTarget, $this->source->documents('a'), Fence::builder('entries', $a->token, $a->generation));
        $this->state->advanceCursor(Fence::builder('entries', $a->token, $a->generation), 'a', 1);

        $this->clock->advance(121);
        $processedAtClaim = null;
        $this->hooks['beforeWrite'] = function () use (&$processedAtClaim): void {
            $processedAtClaim ??= (int) $this->state->row('entries')['processed'];
        };
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store, 1)->run('entries'));

        $row = $this->state->row('entries');
        self::assertGreaterThan($a->generation, (int) $row['generation']);
        self::assertSame(0, $processedAtClaim, 'the replacement enumerates from the start');
        self::assertSame(3, (int) $row['processed']);
        if ($engine === 'meili') {
            self::assertNotSame($aTarget->name, $row['active_target']);
        }
    }

    public function testAPausedBuilderCannotTouchTheReplacementOnMeilisearch(): void
    {
        $store = $this->store('meili');
        $this->source->items = ['a' => ['en' => 'A']];
        $a = $this->state->claimBuild('entries', 120, 0, 1);
        $aFence = Fence::builder('entries', $a->token, $a->generation);
        $aTarget = $this->locator($store)->buildTarget('entries', $a->generation);
        $store->createTarget($aTarget);
        $this->state->recordBuildTarget($aFence, $aTarget->name);

        $this->clock->advance(121);
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));
        $active = (string) $this->state->row('entries')['active_target'];
        $before = $this->meili->ids($active);

        // A resumes and sends its stale write.
        $store->write(
            $aTarget,
            [new \Thallo\Contracts\Search\SearchDocument('entries', 'stale', 'en', 't1', '/s', 'Stale', 'old')],
            $aFence,
        );

        self::assertNotSame($aTarget->name, $active);
        self::assertSame($before, $this->meili->ids($active), 'the promoted index is unchanged');
    }

    public function testAPausedBuilderIsRejectedByTheRowLockOnPostgres(): void
    {
        $store = $this->store('pg');
        $this->source->items = ['a' => ['en' => 'A']];
        $a = $this->state->claimBuild('entries', 120, 0, 1);
        $aFence = Fence::builder('entries', $a->token, $a->generation);
        $this->clock->advance(121);
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));

        $this->expectException(StaleFence::class);
        $store->write(new Target('pg', 'pg', $a->generation), $this->source->documents('a'), $aFence);
    }

    /** @dataProvider engines */
    public function testPromotionWaitsForAnEntryAcknowledgedOnlyOnTheOldTarget(string $engine): void
    {
        $store = $this->store($engine);
        $this->source->items = ['a' => ['en' => 'A']];
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));
        $oldKey = Target::keyFor(
            (string) $this->state->row('entries')['active_target'],
            (int) $this->state->row('entries')['generation'],
        );

        $this->hooks['beforePromote'] = function () use ($oldKey): void {
            // After replay: an edit, journaled and acknowledged on the old target only.
            $this->source->items['a'] = ['en' => 'A late'];
            $seq = $this->state->appendChange('entries', 'a');
            $drainer = Fence::drainer('entries', (string) $this->state->claimDrainer('entries', 60, 30));
            $this->state->recordAck($drainer, $seq, $oldKey, [], 'succeeded');
            $this->state->releaseDrainer($drainer);
        };
        $this->clock->advance(5);
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));

        self::assertSame(['a' => 'A late'], $this->activeTitles($engine));
    }

    public function testADrainerWaitingOnATaskDuringPromotionDoesNotCountForTheNewTarget(): void
    {
        $store = $this->store('meili');
        $this->source->items = ['a' => ['en' => 'A']];
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));
        $old = (string) $this->state->row('entries')['active_target'];

        // A drainer writes the old active index and waits on its task.
        $this->source->items['a'] = ['en' => 'A edited'];
        $seq = $this->state->appendChange('entries', 'a');
        $this->meili->autoComplete = false;
        $this->drainer($store)->drain('entries');
        self::assertSame('pending', $this->state->ack('entries', $seq, $old)['status']);
        $held = $this->meili->pendingTasks();
        $this->meili->autoComplete = true;

        $this->clock->advance(200);
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));
        $new = (string) $this->state->row('entries')['active_target'];
        self::assertNotSame($old, $new);
        self::assertSame(
            'succeeded',
            $this->state->ack('entries', $seq, $new)['status'],
            'replayed into the new target',
        );
        self::assertSame('A edited', $this->meili->document($new, 'entries_a_Len')['title']);

        foreach ($held as $uid) {
            $this->meili->complete($uid);
        }
        $this->clock->advance(200);
        $this->drainer($store)->drain('entries');
        self::assertTrue($this->resolved($seq));
    }

    public function testAppendVersusPromotionInBothOrders(): void
    {
        $store = $this->store('pg');
        $this->source->items = ['a' => ['en' => 'A']];
        // Append first: committed before promotion reads S, so promotion requires it.
        $this->hooks['beforePromote'] = function (): void {
            $this->source->items['a'] = ['en' => 'A before'];
            $this->state->appendChange('entries', 'a');
        };
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));
        self::assertSame(['a' => 'A before'], $this->activeTitles('pg'));

        // Promotion first: an append after it takes a seq above S and is pending for the new target.
        $this->hooks = [];
        $this->clock->advance(5);
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));
        $head = (int) $this->state->row('entries')['journal_head'];
        $this->source->items['a'] = ['en' => 'A after'];
        $seq = $this->state->appendChange('entries', 'a');
        self::assertGreaterThan($head, $seq);
        self::assertFalse($this->resolved($seq));
        $this->drainer($store)->drain('entries');
        self::assertTrue($this->resolved($seq));
        self::assertSame(['a' => 'A after'], $this->activeTitles('pg'));
    }

    public function testAnAsynchronousBatchFailureFailsTheBuildAndNeverSweeps(): void
    {
        $store = $this->store('meili');
        $this->source->items = ['a' => ['en' => 'A'], 'b' => ['en' => 'B']];
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));
        $active = (string) $this->state->row('entries')['active_target'];

        $this->clock->advance(5);
        $this->meili->failNextAdds = 1;
        self::assertSame(RebuildOutcome::FAILED, $this->rebuilder($store, 1)->run('entries'));

        $row = $this->state->row('entries');
        self::assertSame('failed', $row['status']);
        self::assertSame($active, $row['active_target'], 'the previous index is still the active one');
        self::assertSame(['entries_a_Len', 'entries_b_Len'], $this->meili->ids($active));
        self::assertSame([], json_decode((string) ($row['retired_targets'] ?? '[]'), true) ?? []);
    }

    /** @dataProvider engines */
    public function testAFailureAfterPromotionIsNotErasedByTheBuild(string $engine): void
    {
        $store = $this->store($engine);
        $this->source->items = ['a' => ['en' => 'A']];
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));
        $this->state->markOutOfDate('entries', 'a later change failed');
        self::assertSame('out_of_date', $this->state->row('entries')['status']);

        // A failure recorded while a build runs keeps the build from reporting a clean ready only
        // if it is still unresolved at promotion; one replayed successfully is applied and resolved.
        $this->hooks['beforePromote'] = function (): void {
            $seq = $this->state->appendChange('entries', 'a');
            $this->state->markEntryFailed('entries', $seq, 'failed on the old index');
            $this->state->markOutOfDate('entries', 'failed on the old index');
        };
        $this->clock->advance(5);
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));
        self::assertSame('ready', $this->state->row('entries')['status'], 'the replayed change reached the new index');
    }

    public function testCompletionUsesTheValuesCapturedAtTheStart(): void
    {
        $store = $this->store('pg');
        $this->source->items = ['a' => ['en' => 'A'], 'b' => ['en' => 'B']];
        $flags = $this->container()->get(\Thallo\Contracts\Settings\SystemChannel::class);
        $flags->put('capability.thallo.search.changed_at', '7');
        $this->hooks['afterBatch'] = static function () use ($flags): void {
            $flags->put('capability.thallo.search.changed_at', '8');
        };
        try {
            self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store, 1)->run('entries'));
            self::assertSame(7, (int) $this->state->row('entries')['reconciled_version']);
            self::assertSame(1, (int) $this->state->row('entries')['schema_version']);
        } finally {
            $flags->forget('capability.thallo.search.changed_at');
        }
    }

    public function testRetirementGraceAndOrphans(): void
    {
        $store = $this->store('meili');
        $this->source->items = ['a' => ['en' => 'A']];
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));
        $first = (string) $this->state->row('entries')['active_target'];
        $this->clock->advance(5);
        self::assertSame(RebuildOutcome::PROMOTED, $this->rebuilder($store)->run('entries'));
        $second = (string) $this->state->row('entries')['active_target'];

        $retirement = $this->retirement($store);
        $retirement->collect('entries');
        self::assertArrayHasKey($first, $this->meili->indexes, 'still within its grace period');

        $this->clock->advance(121);
        $retirement->collect('entries');
        self::assertArrayNotHasKey($first, $this->meili->indexes);
        self::assertArrayHasKey($second, $this->meili->indexes);

        // A late write recreates an abandoned attempt's index; the next collection removes it.
        $this->meili->addDocuments('content_v2_entries_g99', [['id' => 'x', 'kind' => 'entries', 'title' => 'x']]);
        $retirement->collect('entries');
        self::assertArrayNotHasKey('content_v2_entries_g99', $this->meili->indexes);
        self::assertArrayHasKey($second, $this->meili->indexes);
    }

    private function store(string $engine): IndexStore
    {
        return $engine === 'pg'
            ? new PostgresIndexStore($this->connection(), $this->state)
            : new MeilisearchIndexStore($this->meili, 20, 5);
    }

    private function locator(IndexStore $store): SearchIndexLocator
    {
        return new SearchIndexLocator(
            $this->state,
            new Workspace($this->appContext()),
            $store instanceof PostgresIndexStore ? SearchIndexLocator::POSTGRES : SearchIndexLocator::MEILISEARCH,
            'content',
        );
    }

    private function retirement(IndexStore $store): IndexRetirement
    {
        return new IndexRetirement($this->state, $store, $this->locator($store), $this->registry, 120);
    }

    private function rebuilder(IndexStore $store, int $batch = 100): Rebuilder
    {
        $demand = new DemandResolver(
            $this->registry,
            $this->container()->get(\Thallo\Contracts\Settings\SystemChannel::class),
        );
        return new Rebuilder(
            $this->state,
            $store,
            $this->registry,
            $this->locator($store),
            $demand,
            $this->retirement($store),
            120,
            $batch,
            new NullLogger(),
            fn (string $point, int $batch = 0) => isset($this->hooks[$point]) ? ($this->hooks[$point])($batch) : null,
        );
    }

    private function drainer(IndexStore $store): Drainer
    {
        return new Drainer($this->state, $store, $this->registry, $this->locator($store), 60, 10, 5, new NullLogger());
    }

    private function live(IndexStore $store): LiveSearchIndex
    {
        return new LiveSearchIndex($this->state, $this->drainer($store), $this->connection(), new NullLogger());
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

    /** @return array<string, string> the active target's documents, source id => title */
    private function activeTitles(string $engine): array
    {
        $out = [];
        if ($engine === 'pg') {
            foreach ($this->connection()->table('search_documents')->where('kind', '=', 'entries')->get() as $row) {
                $out[(string) $row['source_id']] = (string) $row['title'];
            }
        } else {
            $active = (string) $this->state->row('entries')['active_target'];
            foreach ($this->meili->indexes[$active]['docs'] ?? [] as $doc) {
                $out[(string) $doc['source_id']] = (string) $doc['title'];
            }
        }
        ksort($out);
        return $out;
    }
}
