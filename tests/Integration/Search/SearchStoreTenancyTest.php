<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Contracts\Search\KindFilter;
use Thallo\Contracts\Search\SearchDocument;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;
use Thallo\Core\Tests\Support\Search\FixedClock;
use Thallo\Search\Lifecycle\Fence;
use Thallo\Search\Lifecycle\StateRepository;
use Thallo\Search\Store\PostgresIndexStore;
use Thallo\Search\Store\StoreQuery;
use Thallo\Search\Store\Target;

/**
 * Workspaces never see each other's search documents or state (search block spec §3.3): under real
 * tenancy enforcement two workspaces index the same source id, each finds only its own, and a sweep
 * in one leaves the other untouched. Opt-in, like every retrofit suite: THALLO_TENANCY_DEV_LINK=1.
 */
final class SearchStoreTenancyTest extends RetrofittedTenantTestCase
{
    public function testTheSameSourceIdInTwoWorkspacesStaysApart(): void
    {
        foreach ([self::$tenantAUuid => 'Rose from A', self::$tenantBUuid => 'Rose from B'] as $tenant => $title) {
            $this->runAsTenant($tenant, function () use ($title): void {
                [$state, $store] = $this->stores();
                $claim = $state->claimBuild('entries', 60, 0, 1);
                self::assertNotNull($claim, 'each workspace has its own state row and claim');
                $store->write(
                    new Target('pg', Target::POSTGRES, $claim->generation),
                    [new SearchDocument('entries', 'sharedsource1', 'en', 't1', '/rose', $title, 'rose body')],
                    Fence::builder('entries', $claim->token, $claim->generation),
                );
            });
        }

        foreach ([self::$tenantAUuid => 'A', self::$tenantBUuid => 'B'] as $tenant => $mark) {
            $this->runAsTenant($tenant, function () use ($mark): void {
                [, $store] = $this->stores();
                $result = $store->search(
                    [],
                    new StoreQuery('rose', 'en', ['entries' => KindFilter::all()], 10, 0, false),
                );
                self::assertSame(1, $result->total, "workspace {$mark} sees exactly its own document");
                $title = $this->connection()->table('search_documents')->where(
                    'source_id',
                    '=',
                    'sharedsource1',
                )->first();
                self::assertSame("Rose from {$mark}", $title['title']);
            });
        }

        // Rebuilding A (a newer generation, then a sweep) leaves B exactly as it was.
        $this->runAsTenant(self::$tenantAUuid, function (): void {
            [$state, $store] = $this->stores();
            $clockless = $state->row('entries');
            $this->connection()->table('search_index_state')->where('kind', '=', 'entries')
                ->update(['owner_token' => null, 'lease_until' => null]);
            $claim = $state->claimBuild('entries', 60, 0, 1);
            self::assertGreaterThan((int) $clockless['building_generation'], $claim->generation);
            $store->sweep('entries', $claim->generation, Fence::builder('entries', $claim->token, $claim->generation));
            self::assertSame(
                0,
                $this->connection()->table('search_documents')->where('source_id', '=', 'sharedsource1')->count(),
            );
        });
        $this->runAsTenant(self::$tenantBUuid, function (): void {
            self::assertSame(
                1,
                $this->connection()->table('search_documents')->where('source_id', '=', 'sharedsource1')->count(),
            );
        });
    }

    public function testANewWorkspaceGetsDemandAndRebuildsAlone(): void
    {
        $kit = new \Thallo\Core\Tests\Support\Search\LifecycleKit($this->appContext(), $this->connection());
        $kit->source->items = ['a' => ['en' => 'A']];
        $generationA = $this->runAsTenant(self::$tenantAUuid, function () use ($kit): int {
            $kit->reconciler()->runWorkspace(false);
            return (int) $kit->state->row('entries')['generation'];
        });

        $this->runAsTenant(self::$tenantBUuid, function () use ($kit): void {
            self::assertSame('new_workspace', $kit->demand()->pending('entries'));
            $kit->reconciler()->runWorkspace(false);
            self::assertNotNull($kit->state->row('entries')['active_target']);
        });
        $this->runAsTenant(self::$tenantAUuid, function () use ($kit, $generationA): void {
            self::assertSame($generationA, (int) $kit->state->row('entries')['generation'], 'B rebuilt alone');
        });
    }

    /** @return array{0: StateRepository, 1: PostgresIndexStore} */
    private function stores(): array
    {
        $state = new StateRepository($this->connection(), new FixedClock());
        return [$state, new PostgresIndexStore($this->connection(), $state)];
    }
}
