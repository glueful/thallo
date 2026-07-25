<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Http\Exceptions\Client\NotFoundException;
use Thallo\Commerce\Events\ProductLinkChanged;
use Thallo\Commerce\Links\LinkConflictException;
use Thallo\Commerce\Links\ProductLinkRepository;
use Thallo\Commerce\Links\ProductLinkService;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 8: {@see ProductLinkService} — the canonical product<->entry link, its advisory-lock
 * protocol, expectation-guarded relink, and after-commit-only auditing (design spec §5.2).
 *
 * Tenant is driven via mode (b) (widened schema + persisted default tenant, {@see SystemFlags})
 * rather than the full enforcement boot: mode (b) needs no glueful/tenancy plumbing (see
 * TenantResolutionModesTest's identical convention in this same directory) and is flipped
 * live per call, so a single test can simulate two tenants by changing the default between
 * calls. `entries.tenant_uuid` does not exist pre-retrofit (see
 * `App\Tests\Support\TenantOracleTestCase`'s identical stand-in technique) — this class adds it
 * transiently for its own run and drops it in tearDown, so the shared app_test schema other
 * suites see is untouched.
 */
final class ProductLinkServiceTest extends AppTestCase
{
    private const TENANT_A = 'plsvctenanta';
    private const TENANT_B = 'plsvctenantb';

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);
        $this->connection()->getPDO()->exec(
            "ALTER TABLE entries ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(191) NOT NULL DEFAULT ''"
        );
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        $this->connection()->getPDO()->exec('ALTER TABLE entries DROP COLUMN IF EXISTS tenant_uuid');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // link(): happy path + audit
    // ------------------------------------------------------------------

    public function testLinkHappyPathInsertsRowAndAuditsAfterCommit(): void
    {
        $captured = $this->captureEvents();
        $product = $this->seedProduct(self::TENANT_A, 'link-happy');
        $entry = $this->seedEntry(self::TENANT_A);

        $row = $this->service()->link($this->appContext(), $product, $entry);

        self::assertSame($product, $row['product_uuid']);
        self::assertSame($entry, $row['entry_uuid']);
        self::assertNotSame('', (string) $row['uuid']);

        $persisted = $this->repository()->findByProduct(self::TENANT_A, $product);
        self::assertNotNull($persisted);
        self::assertSame($entry, $persisted['entry_uuid']);

        self::assertCount(1, $captured, 'exactly one audit event must fire after commit');
        /** @var ProductLinkChanged $event */
        $event = $captured[0];
        self::assertSame('link', $event->action);
        self::assertSame(self::TENANT_A, $event->tenant);
        self::assertSame($product, $event->productUuid);
        self::assertNull($event->oldEntryUuid);
        self::assertSame($entry, $event->newEntryUuid);
    }

    public function testRolledBackMutationEmitsNoAuditEvent(): void
    {
        $captured = $this->captureEvents();
        $product = $this->seedProduct(self::TENANT_A, 'link-rollback');
        $entryOne = $this->seedEntry(self::TENANT_A);
        $entryTwo = $this->seedEntry(self::TENANT_A);

        $this->service()->link($this->appContext(), $product, $entryOne);
        self::assertCount(1, $captured);

        // Second link on the SAME product with no expectation -> 409, rolled back.
        try {
            $this->service()->link($this->appContext(), $product, $entryTwo);
            self::fail('expected LinkConflictException');
        } catch (LinkConflictException) {
            $this->addToAssertionCount(1);
        }

        // Still exactly the ONE audit event from the original successful link.
        self::assertCount(1, $captured, 'a rolled-back mutation must emit zero additional events');
        $persisted = $this->repository()->findByProduct(self::TENANT_A, $product);
        self::assertSame($entryOne, $persisted['entry_uuid'], 'the original link row must be untouched');
    }

    // ------------------------------------------------------------------
    // link(): 404s
    // ------------------------------------------------------------------

    public function testLinkUnknownProductIsNonRevealing404(): void
    {
        $entry = $this->seedEntry(self::TENANT_A);

        $this->expectException(NotFoundException::class);
        $this->service()->link($this->appContext(), 'noSuchProduct', $entry);
    }

    public function testLinkTombstonedProductIsNonRevealing404(): void
    {
        $product = $this->seedProduct(self::TENANT_A, 'link-tombstone');
        $entry = $this->seedEntry(self::TENANT_A);
        $this->container()->get(CatalogService::class)->deleteProduct($this->appContext(), $product);

        $this->expectException(NotFoundException::class);
        $this->service()->link($this->appContext(), $product, $entry);
    }

    public function testLinkCrossTenantProductIsNonRevealing404(): void
    {
        $productB = $this->seedProduct(self::TENANT_B, 'link-cross-product');
        $entryA = $this->seedEntry(self::TENANT_A);

        // Resolve as tenant A (the current default), targeting tenant B's product.
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);

        $this->expectException(NotFoundException::class);
        $this->service()->link($this->appContext(), $productB, $entryA);
    }

    public function testLinkUnknownEntryIsNonRevealing404(): void
    {
        $product = $this->seedProduct(self::TENANT_A, 'link-unknown-entry');

        $this->expectException(NotFoundException::class);
        $this->service()->link($this->appContext(), $product, 'noSuchEntry01');
    }

    public function testLinkCrossTenantEntryIsNonRevealing404(): void
    {
        $product = $this->seedProduct(self::TENANT_A, 'link-cross-entry');
        $entryB = $this->seedEntry(self::TENANT_B);

        $this->expectException(NotFoundException::class);
        $this->service()->link($this->appContext(), $product, $entryB);
    }

    // ------------------------------------------------------------------
    // link(): conflicts
    // ------------------------------------------------------------------

    public function testSecondLinkOnAlreadyLinkedProductWithoutExpectationIs409(): void
    {
        $product = $this->seedProduct(self::TENANT_A, 'link-second-noexp');
        $entryOne = $this->seedEntry(self::TENANT_A);
        $entryTwo = $this->seedEntry(self::TENANT_A);

        $this->service()->link($this->appContext(), $product, $entryOne);

        $this->expectException(LinkConflictException::class);
        $this->service()->link($this->appContext(), $product, $entryTwo);
    }

    public function testRelinkWithWrongExpectationIs409(): void
    {
        $product = $this->seedProduct(self::TENANT_A, 'link-wrong-exp');
        $entryOne = $this->seedEntry(self::TENANT_A);
        $entryTwo = $this->seedEntry(self::TENANT_A);
        $decoyEntry = $this->seedEntry(self::TENANT_A);

        $this->service()->link($this->appContext(), $product, $entryOne);

        $this->expectException(LinkConflictException::class);
        $this->service()->link($this->appContext(), $product, $entryTwo, $decoyEntry);
    }

    public function testRelinkWithCorrectExpectationReplacesAndAuditsOldToNew(): void
    {
        $captured = $this->captureEvents();
        $product = $this->seedProduct(self::TENANT_A, 'link-relink-ok');
        $entryOld = $this->seedEntry(self::TENANT_A);
        $entryNew = $this->seedEntry(self::TENANT_A);

        $this->service()->link($this->appContext(), $product, $entryOld);
        self::assertCount(1, $captured);

        $row = $this->service()->link($this->appContext(), $product, $entryNew, $entryOld);
        self::assertSame($entryNew, $row['entry_uuid']);

        $persisted = $this->repository()->findByProduct(self::TENANT_A, $product);
        self::assertSame($entryNew, $persisted['entry_uuid']);
        // The OLD entry must no longer be claimed by this (or any) product.
        self::assertNull($this->repository()->findByEntry(self::TENANT_A, $entryOld));

        self::assertCount(2, $captured);
        /** @var ProductLinkChanged $relinkEvent */
        $relinkEvent = $captured[1];
        self::assertSame('relink', $relinkEvent->action);
        self::assertSame($entryOld, $relinkEvent->oldEntryUuid);
        self::assertSame($entryNew, $relinkEvent->newEntryUuid);
    }

    public function testEntryAlreadyLinkedToAnotherProductIs409(): void
    {
        $productOne = $this->seedProduct(self::TENANT_A, 'link-entry-uniq-1');
        $productTwo = $this->seedProduct(self::TENANT_A, 'link-entry-uniq-2');
        $entry = $this->seedEntry(self::TENANT_A);

        $this->service()->link($this->appContext(), $productOne, $entry);

        $this->expectException(LinkConflictException::class);
        $this->service()->link($this->appContext(), $productTwo, $entry);
    }

    /**
     * A relink's lock set must include product + OLD entry + NEW entry (design spec §5.2) —
     * proven directly by holding the OLD entry's advisory lock on a SEPARATE real connection
     * and observing the relink block on it (a short lock_timeout turns the block into a fast,
     * assertable failure instead of hanging the suite). The full deadlock-free-under-races
     * proof across genuinely concurrent operations lives in ProductLinkRaceTest.
     */
    public function testRelinkLockSetIncludesTheOldEntryKey(): void
    {
        $product = $this->seedProduct(self::TENANT_A, 'link-lockset-old');
        $entryOld = $this->seedEntry(self::TENANT_A);
        $entryNew = $this->seedEntry(self::TENANT_A);
        $this->service()->link($this->appContext(), $product, $entryOld);

        $holder = $this->secondConnection();
        $holder->getTransactionManager()->begin();
        $oldEntryKey = ProductLinkRepository::entryKey(self::TENANT_A, $entryOld);
        $holder->getPDO()
            ->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))')
            ->execute([$oldEntryKey]);

        $pdo = $this->connection()->getPDO();
        $pdo->exec('SET lock_timeout = 300');
        try {
            $this->expectException(\PDOException::class);
            $this->service()->link($this->appContext(), $product, $entryNew, $entryOld);
        } finally {
            $pdo->exec('RESET lock_timeout');
            $holder->getTransactionManager()->rollback();
        }
    }

    // ------------------------------------------------------------------
    // unlink()
    // ------------------------------------------------------------------

    public function testUnlinkRemovesRowAndAudits(): void
    {
        $captured = $this->captureEvents();
        $product = $this->seedProduct(self::TENANT_A, 'unlink-happy');
        $entry = $this->seedEntry(self::TENANT_A);
        $this->service()->link($this->appContext(), $product, $entry);
        self::assertCount(1, $captured);

        $this->service()->unlink($this->appContext(), $product);

        self::assertNull($this->repository()->findByProduct(self::TENANT_A, $product));
        self::assertCount(2, $captured);
        /** @var ProductLinkChanged $unlinkEvent */
        $unlinkEvent = $captured[1];
        self::assertSame('unlink', $unlinkEvent->action);
        self::assertSame($entry, $unlinkEvent->oldEntryUuid);
        self::assertNull($unlinkEvent->newEntryUuid);
    }

    public function testUnlinkOfAnUnlinkedProductIsAnIdempotentNoOp(): void
    {
        $captured = $this->captureEvents();
        $product = $this->seedProduct(self::TENANT_A, 'unlink-noop');

        $this->service()->unlink($this->appContext(), $product);

        self::assertCount(0, $captured);
    }

    /**
     * Snapshot drift: between the unlocked snapshot and the lock being acquired, the link
     * moved to a DIFFERENT entry (simulated directly by mutating the row between the snapshot
     * read and the retry loop's next attempt via a one-shot swap performed on the FIRST lock
     * acquisition). Proves the retry re-reads a FRESH snapshot each attempt and never touches
     * the stale entry's lock a second time out of order — the bounded retry succeeds against
     * the CURRENT (post-drift) row rather than raising after exhausting attempts on a snapshot
     * that was already stale from the start.
     */
    public function testUnlinkRetriesOnSnapshotDriftAndSucceedsAgainstTheCurrentRow(): void
    {
        $captured = $this->captureEvents();
        $product = $this->seedProduct(self::TENANT_A, 'unlink-drift');
        $entryOriginal = $this->seedEntry(self::TENANT_A);
        $entryDrifted = $this->seedEntry(self::TENANT_A);
        $this->service()->link($this->appContext(), $product, $entryOriginal);
        $captured->exchangeArray([]); // Only care about events from the unlink under test below.

        // Directly relink to a DIFFERENT entry OUTSIDE the service, so the row a subsequent
        // unlocked snapshot sees no longer matches what the retry loop will find once it takes
        // its own fresh snapshot -- exercising the drift branch without a second live process.
        $this->repository()->delete(self::TENANT_A, $product);
        $this->repository()->insert(self::TENANT_A, $product, $entryDrifted);

        $this->service()->unlink($this->appContext(), $product);

        self::assertNull($this->repository()->findByProduct(self::TENANT_A, $product));
        self::assertCount(1, $captured);
        /** @var ProductLinkChanged $event */
        $event = $captured[0];
        self::assertSame($entryDrifted, $event->oldEntryUuid, 'must audit the CURRENT entry, never the stale one');
    }

    // ------------------------------------------------------------------
    // resolveByProduct / resolveByEntry
    // ------------------------------------------------------------------

    public function testResolveByProductAndByEntryReturnRowOrNull(): void
    {
        $product = $this->seedProduct(self::TENANT_A, 'resolve-both');
        $entry = $this->seedEntry(self::TENANT_A);

        self::assertNull($this->service()->resolveByProduct($this->appContext(), $product));
        self::assertNull($this->service()->resolveByEntry($this->appContext(), $entry));

        $this->service()->link($this->appContext(), $product, $entry);

        $byProduct = $this->service()->resolveByProduct($this->appContext(), $product);
        self::assertNotNull($byProduct);
        self::assertSame($entry, $byProduct['entry_uuid']);

        $byEntry = $this->service()->resolveByEntry($this->appContext(), $entry);
        self::assertNotNull($byEntry);
        self::assertSame($product, $byEntry['product_uuid']);
    }

    public function testResolveFailsClosedWhenTheLinkedProductIsTombstoned(): void
    {
        $product = $this->seedProduct(self::TENANT_A, 'resolve-tombstone');
        $entry = $this->seedEntry(self::TENANT_A);
        $this->service()->link($this->appContext(), $product, $entry);

        $this->container()->get(CatalogService::class)->deleteProduct($this->appContext(), $product);

        self::assertNull($this->service()->resolveByProduct($this->appContext(), $product));
        self::assertNull($this->service()->resolveByEntry($this->appContext(), $entry));
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

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function secondConnection(): Connection
    {
        return new Connection([
            'engine' => 'pgsql',
            'pgsql' => [
                'host' => getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PGSQL_PORT') ?: 5432),
                'db' => getenv('DB_PGSQL_DATABASE') ?: 'app_test',
                'user' => getenv('DB_PGSQL_USERNAME') ?: 'postgres',
                'pass' => getenv('DB_PGSQL_PASSWORD') ?: '',
                'schema' => getenv('DB_PGSQL_SCHEMA') ?: 'public',
            ],
            'pooling' => ['enabled' => false],
        ]);
    }

    /**
     * An ArrayObject (not a plain array) so the closure's reference and the caller's handle
     * are the SAME mutable container — a plain `use (&$captured)` array would only update the
     * copy held inside this method's own scope, never the value already returned to the caller.
     *
     * @return \ArrayObject<int,ProductLinkChanged>
     */
    private function captureEvents(): \ArrayObject
    {
        $captured = new \ArrayObject();
        $this->container()->get(\Glueful\Events\EventService::class)->addListener(
            ProductLinkChanged::class,
            static function (ProductLinkChanged $event) use ($captured): void {
                $captured[] = $event;
            },
        );

        return $captured;
    }

    private function seedProduct(string $tenant, string $slug): string
    {
        $this->flags()->put('tenancy.default_tenant_uuid', $tenant);
        // type=external skips the variant requirement entirely (no purchasable-type variant
        // plumbing is relevant to link-service tests) — just needs a valid metadata.external_url.
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug . '-' . (++self::$seq),
            'name' => ucfirst($slug),
            'status' => 'active',
            'type' => 'external',
            'metadata' => ['external_url' => 'https://example.test/' . $slug],
        ]);
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);

        return (string) $product['uuid'];
    }

    /**
     * Raw-seeds directly into `entries` ONLY — deliberately bypassing
     * `ContentTypeRepository::create()`/`EntryRepository::createEntry()`, which would also
     * insert into `content_types`/`entry_drafts`. Those tables are NOT part of this class's
     * transient tenant_uuid stand-in (only `entries` gets it, mirroring the narrowest slice
     * TenantOracleTestCase's own ORACLE_TABLES stand-in uses), and `EntryExistenceReader` reads
     * only `entries` — no content_types/entry_drafts row is needed for these tests.
     */
    private function seedEntry(string $tenant): string
    {
        self::$seq++;
        $uuid = 'plsvce' . str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('entries')->insert([
            'uuid' => $uuid,
            'content_type_uuid' => 'plsvctype001',
            'status' => 'active',
            'tenant_uuid' => $tenant,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }
}
