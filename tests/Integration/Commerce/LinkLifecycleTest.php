<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Content\Repositories\EntryRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Events\ProductDeleted;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Commerce\Console\ReconcileLinksCommand;
use Thallo\Commerce\Diagnostics\CommerceIntegrationDiagnostics;
use Thallo\Commerce\Events\ProductLinkChanged;
use Thallo\Commerce\Links\LinkReconciler;
use Thallo\Commerce\Links\ProductLinkRepository;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Content\EntryExistenceReader;
use Thallo\Contracts\Events\ContentLifecycleEvent;

/**
 * Task 9: lifecycle cleanup listeners + reconcile sweep + diagnostics (design spec §6.2).
 *
 * Runs entirely under mode (a) -- the sentinel '' tenant -- which is the DEFAULT state after
 * {@see AppTestCase::setUp()} clears SystemFlags (no `tenancy.schema_state`/
 * `tenancy.default_tenant_uuid` set anywhere in this class), matching
 * TenantResolutionModesTest's `testEndToEndCatalogReaderReadLandsInModeASentinelTenant`. This
 * needs neither the `entries.tenant_uuid` transient-column dance nor glueful/tenancy plumbing:
 * both `ThalloCommerceTenantResolution` (products) and `EngineEntryExistenceReader` (entries,
 * whose tenant check short-circuits when the column is absent) land on the same '' tenant.
 *
 * Self-cleans `commerce_products` (not in AppTestCase::TABLES) exactly like
 * ProductLinkServiceTest/TenantResolutionModesTest; `thallo_commerce_product_links` and
 * `entries` are already in that managed list.
 */
final class LinkLifecycleTest extends AppTestCase
{
    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // EntryDeletedListener
    // ------------------------------------------------------------------

    public function testRealEntryDeleteRemovesLinkAndAuditsAfterCommit(): void
    {
        $product = $this->seedProduct('entry-delete');
        $entry = $this->createRealEntry();
        $this->service()->link($this->appContext(), $product, $entry);

        $captured = $this->captureAuditEvents();
        $this->containerEntries()->softDelete($entry);

        self::assertNull($this->repository()->findByProduct('', $product), 'the link row must be gone');
        self::assertCount(1, $captured, 'exactly one after-commit unlink audit must fire');
        /** @var ProductLinkChanged $event */
        $event = $captured[0];
        self::assertSame('unlink', $event->action);
        self::assertSame($product, $event->productUuid);
        self::assertSame($entry, $event->oldEntryUuid);
        self::assertNull($event->newEntryUuid);
    }

    public function testEntryDeleteWithNoLinkIsIdempotentNoOp(): void
    {
        $entry = $this->createRealEntry();
        $captured = $this->captureAuditEvents();

        $this->containerEntries()->softDelete($entry);

        self::assertCount(0, $captured, 'no link -> no audit, no error');
    }

    /**
     * The T8-review carry-forward proof: `entry.deleted` (and therefore
     * {@see \Thallo\Commerce\Listeners\EntryDeletedListener}) must fire at transaction level 0,
     * so its own `db()->transaction()` opens a REAL top-level transaction rather than a
     * savepoint -- confirmed by tracing {@see \Glueful\Database\Transaction\TransactionManager
     * ::commit()} (sets the level back to 0 BEFORE running after-commit callbacks) and
     * {@see \App\Content\Pipeline\PublishEventEmitter::emitAfterCommit()} (immediate dispatch
     * when not in a transaction).
     */
    public function testEntryDeletedFiresAtTransactionLevelZero(): void
    {
        $entry = $this->createRealEntry();
        $observedLevel = null;
        $this->events()->addListener(
            ContentLifecycleEvent::class,
            function (ContentLifecycleEvent $event) use (&$observedLevel): void {
                if ($event->name() === 'entry.deleted') {
                    $observedLevel = $this->connection()->transactionLevel();
                }
            },
        );

        $this->containerEntries()->softDelete($entry);

        self::assertSame(0, $observedLevel, 'entry.deleted must fire at transactionLevel 0');
    }

    // ------------------------------------------------------------------
    // ProductDeletedListener
    // ------------------------------------------------------------------

    public function testRealProductTombstoneRemovesLinkAndPreservesEntry(): void
    {
        $product = $this->seedProduct('product-delete');
        $entry = $this->createRealEntry();
        $this->service()->link($this->appContext(), $product, $entry);

        $captured = $this->captureAuditEvents();
        $this->container()->get(CatalogService::class)->deleteProduct($this->appContext(), $product);

        self::assertNull($this->repository()->findByProduct('', $product), 'the link row must be gone');
        $entryRow = $this->container()->get(EntryExistenceReader::class)->exists($entry, '');
        self::assertNotNull($entryRow, 'the editorial entry must be PRESERVED');
        self::assertCount(1, $captured, 'exactly one after-commit unlink audit must fire');
        /** @var ProductLinkChanged $event */
        $event = $captured[0];
        self::assertSame('unlink', $event->action);
        self::assertSame($product, $event->productUuid);
        self::assertSame($entry, $event->oldEntryUuid);
    }

    public function testProductTombstoneWithNoLinkIsIdempotentNoOp(): void
    {
        $product = $this->seedProduct('product-noop');
        $captured = $this->captureAuditEvents();

        $this->container()->get(CatalogService::class)->deleteProduct($this->appContext(), $product);

        self::assertCount(0, $captured, 'no link -> no audit, no error');
    }

    public function testProductDeletedFiresAtTransactionLevelZero(): void
    {
        $product = $this->seedProduct('product-txlevel');
        $observedLevel = null;
        $this->events()->addListener(
            ProductDeleted::class,
            function (ProductDeleted $event) use (&$observedLevel): void {
                $observedLevel = $this->connection()->transactionLevel();
            },
        );

        $this->container()->get(CatalogService::class)->deleteProduct($this->appContext(), $product);

        self::assertSame(0, $observedLevel, 'ProductDeleted must fire at transactionLevel 0');
    }

    // ------------------------------------------------------------------
    // Both listeners remain active with the capability DISABLED
    // ------------------------------------------------------------------

    public function testBothListenersFireWithCapabilityDisabled(): void
    {
        // Mirrors ProductLinkApiTest::testRoutesAbsentWhenCapabilityDisabled()'s exact
        // choreography: clear the flags this class never sets (defensive symmetry) before the
        // second boot, and reset the shared repository connection afterward no matter what.
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);
        $container = $disabledApp->getContainer();

        try {
            self::assertFalse(
                $container->get(CapabilityRegistry::class)->isEnabled('thallo.commerce'),
                'sanity check: the capability really is disabled in this second boot',
            );

            // entry.deleted -> EntryDeletedListener still removes the link.
            $productA = (string) $container->get(CatalogService::class)->createProduct($disabledApp, [
                'slug' => 'disabled-entry-' . (++self::$seq),
                'name' => 'Disabled Entry',
                'status' => 'active',
                'type' => 'external',
                'metadata' => ['external_url' => 'https://example.test/disabled-entry'],
            ])['uuid'];
            $entryA = $container->get(EntryRepository::class)->createEntry('ctype0000001', 'en', 1, null);
            $container->get(ProductLinkService::class)->link($disabledApp, $productA, $entryA);

            $container->get(EntryRepository::class)->softDelete($entryA);
            self::assertNull(
                $container->get(ProductLinkRepository::class)->findByProduct('', $productA),
                'EntryDeletedListener must still fire with thallo.commerce disabled',
            );

            // ProductDeleted -> ProductDeletedListener still removes the link, entry preserved.
            $productB = (string) $container->get(CatalogService::class)->createProduct($disabledApp, [
                'slug' => 'disabled-product-' . (++self::$seq),
                'name' => 'Disabled Product',
                'status' => 'active',
                'type' => 'external',
                'metadata' => ['external_url' => 'https://example.test/disabled-product'],
            ])['uuid'];
            $entryB = $container->get(EntryRepository::class)->createEntry('ctype0000001', 'en', 1, null);
            $container->get(ProductLinkService::class)->link($disabledApp, $productB, $entryB);

            $container->get(CatalogService::class)->deleteProduct($disabledApp, $productB);
            self::assertNull(
                $container->get(ProductLinkRepository::class)->findByProduct('', $productB),
                'ProductDeletedListener must still fire with thallo.commerce disabled',
            );
            self::assertNotNull($container->get(EntryExistenceReader::class)->exists($entryB, ''));
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // ------------------------------------------------------------------
    // LinkReconciler: convergence
    // ------------------------------------------------------------------

    public function testReconcileRemovesTombstonedAndVanishedEntryLinksButLeavesHealthyUntouched(): void
    {
        // Healthy: both sides live.
        $healthyProduct = $this->seedProduct('reconcile-healthy');
        $healthyEntry = $this->createRealEntry();
        $this->repository()->insert('', $healthyProduct, $healthyEntry);

        // Drift: product genuinely tombstoned, THEN linked directly (bypassing
        // ProductLinkService's validation and ProductDeletedListener, which would otherwise
        // clean this up immediately -- no link existed at the moment of the real delete).
        $tombstonedProduct = $this->seedProduct('reconcile-tombstoned');
        $this->container()->get(CatalogService::class)->deleteProduct($this->appContext(), $tombstonedProduct);
        $entryForTombstoned = $this->createRealEntry();
        $this->repository()->insert('', $tombstonedProduct, $entryForTombstoned);

        // Drift: entry genuinely gone, THEN linked directly (same bypass technique).
        $productForVanishedEntry = $this->seedProduct('reconcile-vanished-entry');
        $vanishedEntry = $this->createRealEntry();
        $this->containerEntries()->softDelete($vanishedEntry);
        $this->repository()->insert('', $productForVanishedEntry, $vanishedEntry);

        $captured = $this->captureAuditEvents();
        $reconciler = $this->container()->get(LinkReconciler::class);
        $stale = $reconciler->scanTenant($this->appContext(), '', null);
        $removed = $reconciler->remove($this->appContext(), $stale);

        self::assertSame(2, $removed);
        self::assertNotNull(
            $this->repository()->findByProduct('', $healthyProduct),
            'the healthy link must be untouched',
        );
        self::assertNull($this->repository()->findByProduct('', $tombstonedProduct));
        self::assertNull($this->repository()->findByProduct('', $productForVanishedEntry));
        self::assertCount(2, $captured);
        foreach ($captured as $event) {
            /** @var ProductLinkChanged $event */
            self::assertSame('unlink', $event->action);
        }
    }

    // ------------------------------------------------------------------
    // ReconcileLinksCommand: batch limit / tenant discovery / --tenant
    // ------------------------------------------------------------------

    public function testReconcileCommandBatchLimitAppliesPerInvocationAndAllowsResumeOnRerun(): void
    {
        $this->repository()->insert('', 'noexistprd01', $this->createRealEntry());
        $this->repository()->insert('', 'noexistprd02', $this->createRealEntry());
        $this->repository()->insert('', 'noexistprd03', $this->createRealEntry());

        $context = $this->contextWithBatchSize(2);

        $first = new CommandTester(new ReconcileLinksCommand($this->container(), $context));
        self::assertSame(0, $first->execute([], ['interactive' => false]));
        self::assertCount(1, $this->repository()->forTenant(''), 'exactly batch_size=2 of 3 removed');

        $second = new CommandTester(new ReconcileLinksCommand($this->container(), $context));
        self::assertSame(0, $second->execute([], ['interactive' => false]));
        self::assertCount(0, $this->repository()->forTenant(''), 'the remainder is removed on rerun');
    }

    public function testReconcileCommandDiscoversDistinctTenantsAndPrintsPerTenantCounts(): void
    {
        $this->repository()->insert('', 'noexistprd04', $this->createRealEntry());
        $this->repository()->insert('rcltenantb01', 'noexistprd05', $this->createRealEntry());

        $captured = $this->captureAuditEvents();
        $context = $this->contextWithBatchSize(500);
        $tester = new CommandTester(new ReconcileLinksCommand($this->container(), $context));

        self::assertSame(0, $tester->execute([], ['interactive' => false]));

        self::assertNull($this->repository()->findByProduct('', 'noexistprd04'));
        self::assertNull($this->repository()->findByProduct('rcltenantb01', 'noexistprd05'));

        $output = $tester->getDisplay();
        self::assertStringContainsString('(sentinel): removed 1 stale link(s).', $output);
        self::assertStringContainsString('rcltenantb01: removed 1 stale link(s).', $output);

        self::assertCount(2, $captured, 'each removal audits after commit');
    }

    public function testReconcileCommandTenantOptionLimitsToOneTenant(): void
    {
        $this->repository()->insert('', 'noexistprd06', $this->createRealEntry());
        $this->repository()->insert('rcltenantc01', 'noexistprd07', $this->createRealEntry());

        $context = $this->contextWithBatchSize(500);
        $tester = new CommandTester(new ReconcileLinksCommand($this->container(), $context));

        self::assertSame(0, $tester->execute(['--tenant' => 'rcltenantc01'], ['interactive' => false]));

        self::assertNull($this->repository()->findByProduct('rcltenantc01', 'noexistprd07'));
        self::assertNotNull(
            $this->repository()->findByProduct('', 'noexistprd06'),
            'untouched -- --tenant limited the sweep to rcltenantc01',
        );
    }

    // ------------------------------------------------------------------
    // CommerceIntegrationDiagnostics: the three states
    // ------------------------------------------------------------------

    public function testDiagnosticsReportsHealthyThenStaleLinkCount(): void
    {
        $diagnostics = $this->container()->get(CommerceIntegrationDiagnostics::class);

        $healthy = $diagnostics->report();
        self::assertSame('ok', $healthy['sections']['commerce_active']['status']);
        self::assertSame('ok', $healthy['sections']['stale_links']['status']);
        self::assertSame(0, $healthy['sections']['stale_links']['detail']['stale_count']);
        self::assertTrue($healthy['ok']);

        $this->repository()->insert('', 'noexistprd08', $this->createRealEntry());

        $stale = $diagnostics->report();
        self::assertSame('warn', $stale['sections']['stale_links']['status']);
        self::assertSame(1, $stale['sections']['stale_links']['detail']['stale_count']);
    }

    public function testDiagnosticsReportsMarketplaceEnabledAsWarn(): void
    {
        $context = new ApplicationContext($this->appContext()->getBasePath(), 'testing', []);
        $context->setContainer($this->container());
        $context->overrideConfig('commerce.marketplace.enabled', true);

        $diagnostics = new CommerceIntegrationDiagnostics($context, $this->container()->get(LinkReconciler::class));
        $report = $diagnostics->report();

        self::assertSame('warn', $report['sections']['marketplace']['status']);
    }

    public function testDiagnosticsReportsInactiveCommerceWithoutCrashing(): void
    {
        $fakeContainer = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('not bound in this fake: ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $reconciler = new LinkReconciler($this->repository(), $fakeContainer, $this->flags());
        $diagnostics = new CommerceIntegrationDiagnostics($this->appContext(), $reconciler);

        $report = $diagnostics->report();

        self::assertSame('info', $report['sections']['commerce_active']['status']);
        self::assertSame('info', $report['sections']['stale_links']['status']);
        self::assertTrue($report['ok'], 'an inactive Commerce provider must be reported, never a failure');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function service(): ProductLinkService
    {
        return $this->container()->get(ProductLinkService::class);
    }

    private function repository(): ProductLinkRepository
    {
        return $this->container()->get(ProductLinkRepository::class);
    }

    private function events(): \Glueful\Events\EventService
    {
        return $this->container()->get(\Glueful\Events\EventService::class);
    }

    private function containerEntries(): EntryRepository
    {
        return $this->container()->get(EntryRepository::class);
    }

    private function flags(): \Thallo\Tenancy\System\SystemFlags
    {
        return $this->container()->get(\Thallo\Tenancy\System\SystemFlags::class);
    }

    private function contextWithBatchSize(int $batchSize): ApplicationContext
    {
        $context = new ApplicationContext($this->appContext()->getBasePath(), 'testing', []);
        $context->setContainer($this->container());
        $context->overrideConfig('thallo-commerce.reconcile.batch_size', $batchSize);

        return $context;
    }

    /**
     * An ArrayObject (not a plain array), so the closure's reference and the caller's handle
     * are the SAME mutable container -- mirrors ProductLinkServiceTest::captureEvents().
     *
     * @return \ArrayObject<int,ProductLinkChanged>
     */
    private function captureAuditEvents(): \ArrayObject
    {
        $captured = new \ArrayObject();
        $this->events()->addListener(
            ProductLinkChanged::class,
            static function (ProductLinkChanged $event) use ($captured): void {
                $captured[] = $event;
            },
        );

        return $captured;
    }

    /** Real product via the container-resolved CatalogService, tenant '' (mode (a), sentinel). */
    private function seedProduct(string $slug): string
    {
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug . '-' . (++self::$seq),
            'name' => ucfirst($slug),
            'status' => 'active',
            'type' => 'external',
            'metadata' => ['external_url' => 'https://example.test/' . $slug],
        ]);

        return (string) $product['uuid'];
    }

    /**
     * Real entry via the container-resolved EntryRepository (autowired PublishEventEmitter, so
     * events actually dispatch) -- the "real Thallo entry-delete path" pairing for the
     * subsequent softDelete() calls, same technique as AfterCommitDispatchTest. `ctype0000001`
     * is an arbitrary content-type uuid (createEntry() does not validate FK existence), mirrors
     * EntryRepositoryTest's own convention.
     */
    private function createRealEntry(): string
    {
        return $this->containerEntries()->createEntry('ctype0000001', 'en', 1, null);
    }
}
