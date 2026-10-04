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

    public function testTwoWorkspacesWhereOneFinishesAndOneIsInterruptedOnMeilisearch(): void
    {
        $kit = new \Thallo\Core\Tests\Support\Search\LifecycleKit($this->appContext(), $this->connection(), 'meili');
        $kit->source->items = ['a' => ['en' => 'A']];
        $kit->meili->ensureIndex('content', []); // the shared legacy index
        $cutover = $this->cutover($kit);
        $flags = $this->container()->get(\Thallo\Contracts\Settings\SystemChannel::class);
        $flags->forget(\Thallo\Search\Lifecycle\Cutover::LEGACY_FLAG);

        $this->runAsTenant(self::$tenantAUuid, function () use ($kit, $cutover): void {
            $kit->reconciler()->runWorkspace(false);
            $cutover->flipIfReady();
            self::assertSame('v2', $kit->locator()->readMode('entries'));
        });
        $this->runAsTenant(self::$tenantBUuid, function () use ($kit): void {
            $kit->state->ensure('entries');
            $kit->state->claimBuild('entries', 120, 0, 1); // B's upgrade is interrupted
            self::assertSame('rebuilding', $kit->locator()->readMode('entries'), 'never the shared legacy index');
        });
        $this->runAsSystem(fn () => $cutover->retireLegacyIndexIfUnused());
        self::assertArrayHasKey('content', $kit->meili->indexes, 'B still depends on it');
        self::assertNotSame('retired', $flags->get(\Thallo\Search\Lifecycle\Cutover::LEGACY_FLAG));

        $kit->clock->advance(121);
        // B resumes and finishes; the installation's default workspace moves too — retirement
        // waits for every workspace, not just the two this test names.
        foreach ([self::$tenantBUuid, self::$defaultTenantUuid] as $tenant) {
            $this->runAsTenant($tenant, function () use ($kit, $cutover): void {
                $kit->reconciler()->runWorkspace(false);
                $cutover->flipIfReady();
            });
        }
        $this->runAsSystem(fn () => $cutover->retireLegacyIndexIfUnused());
        if (method_exists($flags, 'clearCache')) {
            $flags->clearCache();
        }
        self::assertArrayNotHasKey('content', $kit->meili->indexes);
        self::assertSame('retired', $flags->get(\Thallo\Search\Lifecycle\Cutover::LEGACY_FLAG));
        $flags->forget(\Thallo\Search\Lifecycle\Cutover::LEGACY_FLAG);
    }

    public function testPostgresLegacyRowsGoPerWorkspace(): void
    {
        $kit = new \Thallo\Core\Tests\Support\Search\LifecycleKit($this->appContext(), $this->connection());
        $kit->source->items = ['legacyone01' => ['en' => 'Rose']];
        foreach ([self::$tenantAUuid, self::$tenantBUuid] as $tenant) {
            $this->runAsTenant($tenant, function (): void {
                $this->connection()->table('search_documents')->insert([
                    'doc_id' => 'legacyone01_en', 'entry_uuid' => 'legacyone01', 'locale' => 'en',
                    'content_type_uuid' => 't1', 'content_type_slug' => 'post', 'href' => '/rose',
                    'title' => 'Rose', 'body' => 'Rose', 'ts_config' => 'english', 'generation' => 0,
                ]);
            });
        }
        $this->runAsTenant(self::$tenantAUuid, function () use ($kit): void {
            $kit->reconciler()->runWorkspace(false);
            $this->cutover($kit)->flipIfReady();
            self::assertSame(0, $this->connection()->table('search_documents')->whereNull('kind')->count());
        });
        $this->runAsTenant(self::$tenantBUuid, function (): void {
            self::assertSame(1, $this->connection()->table('search_documents')->whereNull('kind')->count());
        });
    }

    private function cutover(\Thallo\Core\Tests\Support\Search\LifecycleKit $kit): \Thallo\Search\Lifecycle\Cutover
    {
        return new \Thallo\Search\Lifecycle\Cutover(
            $kit->state,
            $kit->store,
            $kit->locator(),
            new \Thallo\Search\Lifecycle\Workspace($this->appContext()),
            $this->container()->get(\Thallo\Contracts\Settings\SystemChannel::class),
            $this->connection(),
            'content',
        );
    }

    /** @return array{0: StateRepository, 1: PostgresIndexStore} */
    private function stores(): array
    {
        $state = new StateRepository($this->connection(), new FixedClock());
        return [$state, new PostgresIndexStore($this->connection(), $state)];
    }
}
