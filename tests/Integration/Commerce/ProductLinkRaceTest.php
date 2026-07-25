<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Thallo\Commerce\Links\ProductLinkRepository;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 8 GATES: real two-connection PostgreSQL race lanes for
 * {@see \Thallo\Commerce\Links\ProductLinkService}'s advisory-lock protocol (design spec
 * §5.2). Thallo's test suite already runs on real PostgreSQL for every lane (no sqlite/mysql
 * fallback — see `.env`/`composer.json test:migrate`), so unlike the commerce extension's own
 * `*PgsqlTest` files this needs no `skipUnlessPgsql()` guard.
 *
 * Mirrors the commerce extension's `SellerWebhookPgsqlTest`/`SellerSuspensionPgsqlTest` harness
 * shape (`launchRaceChild()`/`collectRaceChild()` via `proc_open`, a SECOND real `Connection`
 * manually holding the SAME advisory locks the real protocol would, `usleep(300_000)` to let the
 * child reach and block on the held lock before releasing) — but per the Task 8 brief, the
 * child fixture ({@see __DIR__}/../../fixtures/product_link_race_child.php) boots the REAL
 * Thallo application (not a hand-built minimal container the way commerce's fixture does),
 * since {@see \Thallo\Commerce\Links\ProductLinkService} needs its full set of real
 * dependencies (CatalogReader, EntryExistenceReader, CommerceTenantResolution, EventService).
 *
 * Tenant is mode (b) (widened schema + a persisted default tenant): the PARENT test writes
 * `thallo_system_flags` into the shared `app_test` database BEFORE launching any child, so both
 * the parent's own connections and every child subprocess resolve the SAME tenant from that one
 * live row (SystemFlags is read live, uncached across processes). `entries.tenant_uuid` is
 * added transiently for this class's run only (see ProductLinkServiceTest's identical stand-in
 * technique) and dropped in tearDown.
 */
final class ProductLinkRaceTest extends AppTestCase
{
    private const TENANT = 'plracetenant';
    private const TABLE = 'thallo_commerce_product_links';

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        $this->container()->get(SystemFlags::class)->put('tenancy.schema_state', 'widened');
        $this->container()->get(SystemFlags::class)->put('tenancy.default_tenant_uuid', self::TENANT);
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

    // ==================================================================
    // 1. Concurrent link() on ONE product -> exactly one winner, one 409, one row.
    //    BOTH orderings: which of the two competing entries ends up the winner.
    // ==================================================================

    public function testConcurrentLinkOneWinnerOneConflictOneRowOrderingAWins(): void
    {
        $this->runConcurrentLinkRace(winnerIsA: true);
    }

    public function testConcurrentLinkOneWinnerOneConflictOneRowOrderingBWins(): void
    {
        $this->runConcurrentLinkRace(winnerIsA: false);
    }

    private function runConcurrentLinkRace(bool $winnerIsA): void
    {
        $product = $this->seedProduct('race-link');
        $entryA = $this->seedEntry();
        $entryB = $this->seedEntry();
        [$winnerEntry, $loserEntry] = $winnerIsA ? [$entryA, $entryB] : [$entryB, $entryA];

        $connA = $this->secondConnection();
        $repoA = new ProductLinkRepository($connA);
        $connA->getTransactionManager()->begin();
        $repoA->lockIdentities(
            $connA,
            ProductLinkRepository::productKey(self::TENANT, $product),
            ProductLinkRepository::entryKey(self::TENANT, $winnerEntry),
        );
        // Manually replicates link()'s critical section post-lock: no existing row, no entry
        // claim -> a plain insert (mirrors ProductLinkService::link()'s own happy path).
        $repoA->insert(self::TENANT, $product, $winnerEntry);

        // The REAL subprocess attempts to link the SAME product to the OTHER entry -- its own
        // product-key lock claim blocks entirely on connection A's held lock.
        $handle = $this->launchRaceChild('link', [
            'productUuid' => $product,
            'entryUuid' => $loserEntry,
        ]);

        usleep(300_000);
        $connA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'the loser must fail: ' . json_encode($result));
        self::assertSame(\Thallo\Commerce\Links\LinkConflictException::class, $result['exceptionClass']);

        $rows = $this->connection()->table(self::TABLE)
            ->where('tenant_uuid', '=', self::TENANT)->where('product_uuid', '=', $product)->get();
        self::assertCount(1, $rows, 'exactly one row must exist after the race');
        self::assertSame($winnerEntry, $rows[0]['entry_uuid']);
    }

    // ==================================================================
    // 2. Concurrent relink with the SAME stale expectation -> one winner. Both share TWO lock
    //    keys (product + the original entry) — the case the sorted, deduplicated lock order
    //    exists to keep deadlock-free (both sides must acquire those two keys in the SAME
    //    relative order, whichever side got there first). BOTH orderings: which of the two
    //    proposed new entries ends up the winner.
    // ==================================================================

    public function testConcurrentRelinkSameStaleExpectationOneWinnerOrderingAWins(): void
    {
        $this->runConcurrentRelinkRace(winnerIsA: true);
    }

    public function testConcurrentRelinkSameStaleExpectationOneWinnerOrderingBWins(): void
    {
        $this->runConcurrentRelinkRace(winnerIsA: false);
    }

    private function runConcurrentRelinkRace(bool $winnerIsA): void
    {
        $product = $this->seedProduct('race-relink');
        $original = $this->seedEntry();
        $newA = $this->seedEntry();
        $newB = $this->seedEntry();
        [$winnerEntry, $loserEntry] = $winnerIsA ? [$newA, $newB] : [$newB, $newA];

        $this->connection()->table(self::TABLE)->insert($this->linkRow($product, $original));

        $connA = $this->secondConnection();
        $connA->getTransactionManager()->begin();
        $this->manualRelink($connA, $product, $winnerEntry, $original);

        // The REAL subprocess relinks the SAME product away from the SAME original entry to
        // the OTHER new entry, with the SAME (now stale, once A commits) expectation -- its
        // lock claim (sharing product + original-entry with connection A's held set) blocks
        // entirely on A.
        $handle = $this->launchRaceChild('link', [
            'productUuid' => $product,
            'entryUuid' => $loserEntry,
            'expectedEntryUuid' => $original,
        ]);

        usleep(300_000);
        $connA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse($result['ok'] ?? true, 'the stale-expectation loser must fail: ' . json_encode($result));
        self::assertSame(\Thallo\Commerce\Links\LinkConflictException::class, $result['exceptionClass']);

        $rows = $this->connection()->table(self::TABLE)
            ->where('tenant_uuid', '=', self::TENANT)->where('product_uuid', '=', $product)->get();
        self::assertCount(1, $rows, 'exactly one row must exist after the race');
        self::assertSame($winnerEntry, $rows[0]['entry_uuid']);
        // The original entry must be free again -- claimable by anyone else.
        self::assertNull($this->connection()->table(self::TABLE)
            ->where('tenant_uuid', '=', self::TENANT)->where('entry_uuid', '=', $original)->first());
    }

    // ==================================================================
    // 3. Relink-away from entry A racing another product's claim of A -- serializes without
    //    deadlock or corruption. BOTH orderings.
    // ==================================================================

    public function testRelinkAwayRacingAnotherProductsClaimOfTheSameEntrySerializesRelinkFirst(): void
    {
        $this->runRelinkAwayRace(relinkWinsFirst: true);
    }

    public function testRelinkAwayRacingAnotherProductsClaimOfTheSameEntrySerializesClaimFirst(): void
    {
        $this->runRelinkAwayRace(relinkWinsFirst: false);
    }

    private function runRelinkAwayRace(bool $relinkWinsFirst): void
    {
        $productOne = $this->seedProduct('race-away-p1');
        $productTwo = $this->seedProduct('race-away-p2');
        $sharedEntry = $this->seedEntry();
        $newEntry = $this->seedEntry();
        $this->connection()->table(self::TABLE)->insert($this->linkRow($productOne, $sharedEntry));

        $connA = $this->secondConnection();
        $connA->getTransactionManager()->begin();

        if ($relinkWinsFirst) {
            // A: relink productOne AWAY from the shared entry to a new one (expected=shared).
            $this->manualRelink($connA, $productOne, $newEntry, $sharedEntry);
            // B (real): productTwo tries to claim the shared entry -- blocks on it (still held
            // by A until commit); once A commits, the entry is free and B succeeds.
            $handle = $this->launchRaceChild('link', ['productUuid' => $productTwo, 'entryUuid' => $sharedEntry]);
            usleep(300_000);
            $connA->getTransactionManager()->commit();

            $result = $this->collectRaceChild($handle);
            self::assertTrue($result['ok'] ?? false, 'B must succeed once the entry is freed: '
                . json_encode($result));
            self::assertSame($sharedEntry, $this->connection()->table(self::TABLE)
                ->where('tenant_uuid', '=', self::TENANT)->where('product_uuid', '=', $productTwo)
                ->first()['entry_uuid']);
            self::assertSame($newEntry, $this->connection()->table(self::TABLE)
                ->where('tenant_uuid', '=', self::TENANT)->where('product_uuid', '=', $productOne)
                ->first()['entry_uuid']);
        } else {
            // A: hold the shared entry's lock (and productTwo's) WITHOUT relinquishing it —
            // productOne still legitimately owns the shared entry, so B's claim attempt must
            // fail with a conflict once A releases (A performs no mutation: it only proves the
            // REAL subprocess actually contends on the held lock rather than racing past it).
            $repoA = new ProductLinkRepository($connA);
            $repoA->lockIdentities(
                $connA,
                ProductLinkRepository::productKey(self::TENANT, $productTwo),
                ProductLinkRepository::entryKey(self::TENANT, $sharedEntry),
            );

            $handle = $this->launchRaceChild('link', ['productUuid' => $productTwo, 'entryUuid' => $sharedEntry]);
            usleep(300_000);
            $connA->getTransactionManager()->rollback();

            $result = $this->collectRaceChild($handle);
            self::assertFalse($result['ok'] ?? true, 'B must lose: the shared entry is still legitimately owned');
            self::assertSame(\Thallo\Commerce\Links\LinkConflictException::class, $result['exceptionClass']);
            self::assertSame($sharedEntry, $this->connection()->table(self::TABLE)
                ->where('tenant_uuid', '=', self::TENANT)->where('product_uuid', '=', $productOne)
                ->first()['entry_uuid']);
            self::assertNull($this->connection()->table(self::TABLE)
                ->where('tenant_uuid', '=', self::TENANT)->where('product_uuid', '=', $productTwo)->first());
        }
    }

    // ==================================================================
    // 4. Unlink racing relink -- exercises the unlink snapshot/lock/re-read retry.
    //    BOTH orderings: relink-commits-first (unlink must retry against the NEW entry and
    //    still converge to a fully unlinked product), and unlink-commits-first (the relink's
    //    OWN re-read then sees no existing link at all and proceeds as a plain link).
    // ==================================================================

    public function testUnlinkRacingRelinkRetriesOnDriftWhenRelinkCommitsFirst(): void
    {
        $product = $this->seedProduct('race-unlink-relink-a');
        $original = $this->seedEntry();
        $replacement = $this->seedEntry();
        $this->connection()->table(self::TABLE)->insert($this->linkRow($product, $original));

        $connA = $this->secondConnection();
        $connA->getTransactionManager()->begin();
        $this->manualRelink($connA, $product, $replacement, $original);

        // The REAL subprocess's unlink() takes its UNLOCKED snapshot BEFORE A commits (still
        // sees $original) — its own lock claim on the product key blocks on A's held lock.
        $handle = $this->launchRaceChild('unlink', ['productUuid' => $product]);
        usleep(300_000);
        $connA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertTrue($result['ok'] ?? false, 'unlink must converge despite the snapshot drift: '
            . json_encode($result));
        self::assertNull($this->connection()->table(self::TABLE)
            ->where('tenant_uuid', '=', self::TENANT)->where('product_uuid', '=', $product)->first());
    }

    public function testUnlinkRacingRelinkRelinkSeesNoExistingLinkWhenUnlinkCommitsFirst(): void
    {
        $product = $this->seedProduct('race-unlink-relink-b');
        $original = $this->seedEntry();
        $replacement = $this->seedEntry();
        $this->connection()->table(self::TABLE)->insert($this->linkRow($product, $original));

        $connA = $this->secondConnection();
        $repoA = new ProductLinkRepository($connA);
        $connA->getTransactionManager()->begin();
        $repoA->lockIdentities(
            $connA,
            ProductLinkRepository::productKey(self::TENANT, $product),
            ProductLinkRepository::entryKey(self::TENANT, $original),
        );
        $repoA->delete(self::TENANT, $product);

        // The REAL subprocess attempts to relink (expected=$original) -- blocks on A's held
        // lock; once A commits the unlink, the subprocess's re-read finds NO existing link at
        // all, so its stale expectation no longer applies to an existing row -- a mismatch
        // against "no current link" -> a conflict (never an implicit upsert, design spec §5.2).
        $handle = $this->launchRaceChild('link', [
            'productUuid' => $product,
            'entryUuid' => $replacement,
            'expectedEntryUuid' => $original,
        ]);
        usleep(300_000);
        $connA->getTransactionManager()->commit();

        $result = $this->collectRaceChild($handle);
        self::assertFalse(
            $result['ok'] ?? true,
            'a stale expectation against an already-unlinked product must conflict, never upsert: '
                . json_encode($result),
        );
        self::assertSame(\Thallo\Commerce\Links\LinkConflictException::class, $result['exceptionClass']);
        self::assertNull($this->connection()->table(self::TABLE)
            ->where('tenant_uuid', '=', self::TENANT)->where('product_uuid', '=', $product)->first());
    }

    // ==================================================================
    // helpers
    // ==================================================================

    /** @return array<string,mixed> */
    private function linkRow(string $productUuid, string $entryUuid): array
    {
        $now = gmdate('Y-m-d H:i:s');

        return [
            'uuid' => 'plracelnk' . str_pad((string) (++self::$seq), 3, '0', STR_PAD_LEFT),
            'tenant_uuid' => self::TENANT,
            'product_uuid' => $productUuid,
            'entry_uuid' => $entryUuid,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Manually replicates {@see \Thallo\Commerce\Links\ProductLinkService::link()}'s relink
     * critical section (lock product + old + new entry sorted/deduped, then delete-then-insert)
     * on a caller-controlled connection, so the test can hold the transaction open on demand.
     */
    private function manualRelink(Connection $connection, string $product, string $newEntry, string $oldEntry): void
    {
        $repo = new ProductLinkRepository($connection);
        $repo->lockIdentities(
            $connection,
            ProductLinkRepository::productKey(self::TENANT, $product),
            ProductLinkRepository::entryKey(self::TENANT, $newEntry),
            ProductLinkRepository::entryKey(self::TENANT, $oldEntry),
        );
        $repo->delete(self::TENANT, $product);
        $repo->insert(self::TENANT, $product, $newEntry);
    }

    /**
     * @param array<string,mixed> $args
     * @return array{0: resource, 1: array<int,resource>}
     */
    private function launchRaceChild(string $action, array $args): array
    {
        $args += ['tenant' => self::TENANT];
        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 2) . '/fixtures/product_link_race_child.php',
                $action,
                json_encode($args, JSON_THROW_ON_ERROR),
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int,resource>} $handle
     * @return array<string,mixed>
     */
    private function collectRaceChild(array $handle): array
    {
        [$process, $pipes] = $handle;
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode(trim((string) $stdout), true);
        self::assertIsArray($result, "subprocess produced no parseable result. stderr: {$stderr}");

        return $result;
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

    /** Raw-seeds `entries` directly (see ProductLinkServiceTest's identical helper docblock). */
    private function seedEntry(): string
    {
        self::$seq++;
        $uuid = 'plrace' . str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('entries')->insert([
            'uuid' => $uuid,
            'content_type_uuid' => 'plracetype01',
            'status' => 'active',
            'tenant_uuid' => self::TENANT,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }
}
