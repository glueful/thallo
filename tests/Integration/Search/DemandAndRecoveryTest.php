<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\ArraySource;
use Thallo\Core\Tests\Support\Search\LifecycleKit;
use Thallo\Search\Lifecycle\RebuildOutcome;
use Thallo\Search\Lifecycle\Reconciler;

/**
 * Durable rebuild demand (search block spec §3.5.7): a capability switch records when it happened,
 * so an off/on cycle search never saw is still demand; manual and taxonomy demand are rows written
 * in the requester's transaction; demand is satisfied only by a promoted build, with the values it
 * captured at the start; and the scheduled reconcile picks up whatever is outstanding — after a
 * failed queue, a failed build, or a change made only in configuration.
 */
final class DemandAndRecoveryTest extends AppTestCase
{
    private LifecycleKit $kit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kit = new LifecycleKit($this->appContext(), $this->connection());
        $this->kit->source->items = ['a' => ['en' => 'A']];
    }

    protected function tearDown(): void
    {
        $this->flags()->forget(Reconciler::AVAILABILITY_MARKER);
        $this->flags()->forget('capability.thallo.search.changed_at');
        parent::tearDown();
    }

    public function testAnOffOnCycleWithNoSearchBootStillCreatesDemand(): void
    {
        $products = new ArraySource('products', ['thallo.commerce']);
        $kit = new LifecycleKit($this->appContext(), $this->connection(), 'pg', $products);
        $products->items = ['P1' => ['*' => 'Rose attar']];
        self::assertSame(RebuildOutcome::PROMOTED, $kit->rebuilder()->run('products'));
        self::assertNull($kit->demand()->pending('products'));

        $switches = $this->container()->get(CapabilityStateStore::class);
        $switches->put('thallo.commerce', false);
        $switches->put('thallo.commerce', true);

        self::assertSame('capability', $kit->demand()->pending('products'));
    }

    public function testAFailedQueueLeavesDemandForTheSchedule(): void
    {
        self::assertSame(RebuildOutcome::PROMOTED, $this->kit->rebuilder()->run('entries'));
        $this->kit->pushFailure = new \RuntimeException('queue down');

        $result = $this->kit->requests()->request('entries', 'manual');

        self::assertTrue($result['recorded']);
        self::assertSame('demand', $this->kit->demand()->pending('entries'));
        self::assertStringStartsWith('queue:', (string) $this->kit->state->row('entries')['last_error']);

        $this->kit->clock->advance(5);
        $this->kit->reconciler()->runWorkspace(false);
        self::assertNull($this->kit->demand()->pending('entries'));
    }

    public function testARolledBackRequestDispatchesNothing(): void
    {
        try {
            $this->connection()->transaction(function (): void {
                $this->kit->requests()->request('entries', 'manual');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $this->connection()->table('search_index_demand')->count());
        self::assertSame([], $this->kit->pushed);
    }

    public function testACommittedRequestDispatchesAfterCommit(): void
    {
        $this->connection()->transaction(function (): void {
            $this->kit->requests()->request('entries', 'manual');
            self::assertSame([], $this->kit->pushed, 'nothing is queued before the commit');
        });
        self::assertCount(1, $this->kit->pushed);
        self::assertArrayHasKey('workspace', $this->kit->pushed[0]);
    }

    public function testTaxonomyDemandIsARow(): void
    {
        self::assertSame(RebuildOutcome::PROMOTED, $this->kit->rebuilder()->run('entries'));
        $live = new \Thallo\Search\Lifecycle\LiveSearchIndex(
            $this->kit->state,
            $this->kit->drainer(),
            $this->connection(),
            new \Psr\Log\NullLogger(),
        );
        $live->kindChanged('entries', 'taxonomy');
        self::assertSame('demand', $this->kit->demand()->pending('entries'));
        self::assertSame(
            'taxonomy',
            $this->connection()->table('search_index_demand')->orderBy('seq', 'DESC')->first()['reason'],
        );
    }

    public function testAFailedCapabilityTriggeredRebuildIsRetriedByTheSchedule(): void
    {
        $kit = new LifecycleKit($this->appContext(), $this->connection(), 'meili');
        $kit->source->items = ['a' => ['en' => 'A']];
        self::assertSame(RebuildOutcome::PROMOTED, $kit->rebuilder()->run('entries'));
        $this->flags()->put('capability.thallo.search.changed_at', '99');
        self::assertSame('capability', $kit->demand()->pending('entries'));

        $kit->meili->failNextAdds = 1;
        $kit->clock->advance(5);
        $kit->reconciler()->runWorkspace(false);
        self::assertSame('capability', $kit->demand()->pending('entries'), 'a failed build satisfies nothing');

        $kit->clock->advance(5);
        $kit->reconciler()->runWorkspace(false);
        self::assertNull($kit->demand()->pending('entries'));
    }

    public function testAFailedSchemaTriggeredRebuildIsRetriedByTheSchedule(): void
    {
        $kit = new LifecycleKit($this->appContext(), $this->connection(), 'meili');
        $kit->source->items = ['a' => ['en' => 'A']];
        self::assertSame(RebuildOutcome::PROMOTED, $kit->rebuilder()->run('entries'));
        $kit->source->schemaVersion = 2;
        self::assertSame('schema', $kit->demand()->pending('entries'));

        $kit->meili->failNextAdds = 1;
        $kit->clock->advance(5);
        $kit->reconciler()->runWorkspace(false);
        self::assertSame('schema', $kit->demand()->pending('entries'));

        $kit->clock->advance(5);
        $kit->reconciler()->runWorkspace(false);
        self::assertNull($kit->demand()->pending('entries'));
    }

    public function testBusyLeavesDemandOutstanding(): void
    {
        self::assertSame(RebuildOutcome::PROMOTED, $this->kit->rebuilder()->run('entries'));
        $this->kit->state->addDemand('entries', 'manual');
        $this->kit->state->claimBuild('entries', 120, 0, 1); // another builder holds it
        $this->kit->reconciler()->runWorkspace(false);
        self::assertSame('demand', $this->kit->demand()->pending('entries'));
    }

    public function testAFullReconcileRebuildsAReadyKind(): void
    {
        self::assertSame(RebuildOutcome::PROMOTED, $this->kit->rebuilder()->run('entries'));
        $generation = (int) $this->kit->state->row('entries')['generation'];
        self::assertNull($this->kit->demand()->pending('entries'));

        $this->kit->clock->advance(5);
        $this->kit->reconciler()->runWorkspace(true);
        self::assertGreaterThan($generation, (int) $this->kit->state->row('entries')['generation']);
    }

    public function testAMissingStateRowIsNewWorkspaceDemandAndUnavailableKindsAreLeftAlone(): void
    {
        self::assertSame('new_workspace', $this->kit->demand()->pending('entries'));
        $this->kit->capabilities['thallo.search'] = false;
        $this->kit->reconciler()->runWorkspace(false);
        self::assertNull($this->kit->state->row('entries'), 'search off: nothing is built');

        $this->kit->capabilities['thallo.search'] = true;
        $this->kit->reconciler()->runWorkspace(false);
        self::assertNotNull($this->kit->state->row('entries')['active_target']);
    }

    public function testBootRecoveryRecordsDemandAndWakesTheQueueWithoutRebuildingInTheRequest(): void
    {
        self::assertSame(RebuildOutcome::PROMOTED, $this->kit->rebuilder()->run('entries'));
        $this->flags()->put(Reconciler::AVAILABILITY_MARKER, json_encode([]));
        $generation = (int) $this->kit->state->row('entries')['generation'];

        $this->kit->clock->advance(5);
        $this->kit->reconciler()->recoverIfDue();

        self::assertSame(
            'capability',
            $this->connection()->table('search_index_demand')->orderBy('seq', 'DESC')->first()['reason'],
        );
        self::assertSame($generation, (int) $this->kit->state->row('entries')['generation'], 'no rebuild in a request');
        self::assertSame([['workspace' => null]], $this->kit->wakes, 'the queue worker does the work');
        self::assertSame(json_encode(['entries']), $this->flags()->get(Reconciler::AVAILABILITY_MARKER));
    }

    public function testTheScheduledRunCatchesAConfigOnlyFlipToo(): void
    {
        self::assertSame(RebuildOutcome::PROMOTED, $this->kit->rebuilder()->run('entries'));
        $this->flags()->put(Reconciler::AVAILABILITY_MARKER, json_encode([]));
        $generation = (int) $this->kit->state->row('entries')['generation'];

        $this->kit->clock->advance(5);
        $this->kit->reconciler()->runAll(false);

        self::assertGreaterThan($generation, (int) $this->kit->state->row('entries')['generation']);
        self::assertSame(json_encode(['entries']), $this->flags()->get(Reconciler::AVAILABILITY_MARKER));
    }

    private function flags(): SystemChannel
    {
        $flags = $this->container()->get(SystemChannel::class);
        if (method_exists($flags, 'clearCache')) {
            $flags->clearCache();
        }
        return $flags;
    }
}
