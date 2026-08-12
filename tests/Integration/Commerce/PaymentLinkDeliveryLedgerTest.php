<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Thallo\Commerce\Payments\PaymentLinkDeliveryRepository;

/**
 * Payment links Task 12 (payment-links spec §2.4): the pack-owned delivery-idempotency ledger
 * `thallo_commerce_payment_link_deliveries` — its migration (fresh install AND a real upgrade
 * over an install that already carries the pack's earlier tables, on BOTH SQLite and
 * PostgreSQL) and its claim/replay/stale semantics.
 *
 * ## Why the migration is driven twice, on two drivers
 *
 * Spec §2.4 requires "fresh-install and real-upgrade migration fixtures ... on SQLite and
 * PostgreSQL". The PostgreSQL half is the SUITE's own database: `composer test` runs
 * `migrate:run` before phpunit, so every assertion here about the live connection's schema is
 * an assertion about a migration that genuinely ran on PostgreSQL. The SQLite half constructs
 * a throwaway `:memory:` connection and drives {@see \CreatePaymentLinkDeliveries} directly —
 * the same technique {@see AdminOrderPaymentsTest} already uses for driver-specific schema
 * work in this suite.
 *
 * "Real upgrade" means exactly that: the upgrade fixture first builds the pack's PRE-Task-12
 * schema (the four earlier migrations), then runs ONLY the new one, and asserts the pre-existing
 * tables and their rows survive untouched. A fresh-install fixture that merely creates the table
 * would not catch a migration that drops or rewrites a sibling.
 *
 * ## The clock is a parameter, never `time()`
 *
 * Every staleness assertion passes an explicit `\DateTimeImmutable`, so the 299s/300s boundary
 * is exact rather than approximately-now: a `processing` claim 299 seconds old is still
 * `processing` (someone may genuinely still be sending), and at exactly 300 it becomes
 * `indeterminate` — the state that tells the operator plaintext is unrecoverable and a NEW key
 * or a regenerate is the only honest recovery.
 */
final class PaymentLinkDeliveryLedgerTest extends AppTestCase
{
    private const TABLE = 'thallo_commerce_payment_link_deliveries';

    /** The pack's migration directory, in order — the "real upgrade" fixture replays 1..4. */
    private const MIGRATION_DIR = __DIR__ . '/../../../packages/thallo-commerce/migrations';

    private const KEY = 'idem-key-0123456789abcdef';

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec('DELETE FROM ' . self::TABLE);
        parent::tearDown();
    }

    // ==================================================================
    // Migration — PostgreSQL (the suite's own migrated database)
    // ==================================================================

    public function testTheTableExistsOnPostgresqlAfterTheSuiteMigrationRun(): void
    {
        self::assertTrue(
            $this->connection()->getSchemaBuilder()->hasTable(self::TABLE),
            'the deliveries table must exist after `composer test:migrate` on PostgreSQL',
        );
    }

    public function testPostgresqlCarriesTheSpecShapeAndTheTenantScopedIdempotencyUnique(): void
    {
        $columns = $this->postgresColumns(self::TABLE);

        self::assertSame(
            [
                'created_at', 'error_code', 'fingerprint', 'id', 'idempotency_key', 'link_uuid',
                'mode', 'order_uuid', 'provider_message_id', 'recipient_hash', 'status',
                'tenant_uuid', 'updated_at', 'uuid',
            ],
            $columns,
            'the deliveries table must carry exactly the spec §2.4 column set',
        );

        self::assertContains(
            'uniq_commerce_link_delivery_tenant_key',
            $this->postgresIndexes(self::TABLE),
            'the (tenant_uuid, idempotency_key) unique index is the ledger\'s ground truth',
        );
    }

    public function testPostgresqlRefusesASecondRowForTheSameTenantAndIdempotencyKey(): void
    {
        $this->seedRow($this->connection(), '', self::KEY, 'fp-a');

        $this->expectException(\Throwable::class);
        $this->seedRow($this->connection(), '', self::KEY, 'fp-b');
    }

    public function testPostgresqlPermitsTheSameKeyUnderADifferentTenant(): void
    {
        $this->seedRow($this->connection(), '', self::KEY, 'fp-a');
        $this->seedRow($this->connection(), Utils::generateNanoID(12), self::KEY, 'fp-a');

        self::assertSame(
            2,
            (int) $this->connection()->table(self::TABLE)->where('idempotency_key', '=', self::KEY)->count(),
            'the unique constraint is tenant-scoped, so two tenants may reuse one key',
        );
    }

    // ==================================================================
    // Migration — SQLite (fresh install + real upgrade)
    // ==================================================================

    public function testSqliteFreshInstallCreatesTheTableWithTheTenantScopedUnique(): void
    {
        $connection = $this->sqliteConnection();
        $this->runPackMigrations($connection, 5);

        self::assertTrue($connection->getSchemaBuilder()->hasTable(self::TABLE));

        $this->seedRow($connection, '', self::KEY, 'fp-a');
        $threw = false;
        try {
            $this->seedRow($connection, '', self::KEY, 'fp-b');
        } catch (\Throwable) {
            $threw = true;
        }
        self::assertTrue($threw, 'SQLite must enforce the same (tenant_uuid, idempotency_key) unique');
    }

    public function testSqliteRealUpgradeAddsTheTableAndLeavesTheEarlierPackTablesAndRowsIntact(): void
    {
        $connection = $this->sqliteConnection();

        // The PRE-Task-12 install: the pack's first four migrations only.
        $this->runPackMigrations($connection, 4);
        self::assertFalse(
            $connection->getSchemaBuilder()->hasTable(self::TABLE),
            'the pre-upgrade fixture must genuinely not have the new table yet',
        );

        $connection->table('thallo_commerce_product_links')->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => '',
            'product_uuid' => Utils::generateNanoID(),
            'entry_uuid' => Utils::generateNanoID(),
        ]);

        // The upgrade itself: ONLY the new migration runs.
        $this->runPackMigration($connection, '005_CreatePaymentLinkDeliveries.php');

        self::assertTrue($connection->getSchemaBuilder()->hasTable(self::TABLE));
        foreach (
            [
                'thallo_commerce_product_links',
                'thallo_commerce_product_slugs',
                'thallo_commerce_checkout_attempts',
            ] as $table
        ) {
            self::assertTrue(
                $connection->getSchemaBuilder()->hasTable($table),
                "the upgrade must leave {$table} in place",
            );
        }
        self::assertSame(
            1,
            (int) $connection->table('thallo_commerce_product_links')->count(),
            'the upgrade must not touch a single pre-existing row',
        );
    }

    public function testSqliteUpgradeIsIdempotentWhenReRunOverAnAlreadyUpgradedInstall(): void
    {
        $connection = $this->sqliteConnection();
        $this->runPackMigrations($connection, 5);
        $this->seedRow($connection, '', self::KEY, 'fp-a');

        $this->runPackMigration($connection, '005_CreatePaymentLinkDeliveries.php');

        self::assertSame(
            1,
            (int) $connection->table(self::TABLE)->count(),
            're-running the migration must be a no-op, never a table rebuild',
        );
    }

    public function testSqliteDownDropsOnlyTheDeliveriesTable(): void
    {
        $connection = $this->sqliteConnection();
        $this->runPackMigrations($connection, 5);

        $this->packMigration('005_CreatePaymentLinkDeliveries.php')->down($connection->getSchemaBuilder());

        self::assertFalse($connection->getSchemaBuilder()->hasTable(self::TABLE));
        self::assertTrue($connection->getSchemaBuilder()->hasTable('thallo_commerce_product_links'));
    }

    // ==================================================================
    // Claim / replay / conflict
    // ==================================================================

    public function testAFirstClaimInsertsAProcessingRowAndReportsFresh(): void
    {
        $claim = $this->repository()->claim(
            '',
            self::KEY,
            'fp-a',
            'order0000001',
            str_repeat('a', 64),
            'regenerate',
            300,
            $this->at('12:00:00'),
        );

        self::assertTrue($claim->isFresh());
        self::assertSame(PaymentLinkDeliveryRepository::STATUS_PROCESSING, (string) $claim->row['status']);
        self::assertSame('regenerate', (string) $claim->row['mode']);
        self::assertNull($claim->row['link_uuid']);
        self::assertSame(1, (int) $this->connection()->table(self::TABLE)->count());
    }

    public function testTheSameKeyAndFingerprintReplaysTheRecordedOutcomeWithoutASecondRow(): void
    {
        $repository = $this->repository();
        $first = $repository->claim(
            '',
            self::KEY,
            'fp-a',
            'order0000001',
            str_repeat('a', 64),
            'regenerate',
            300,
            $this->at('12:00:00'),
        );
        $repository->markSent((string) $first->row['uuid'], 'provider-msg-1', $this->at('12:00:01'));

        $replay = $repository->claim(
            '',
            self::KEY,
            'fp-a',
            'order0000001',
            str_repeat('a', 64),
            'regenerate',
            300,
            $this->at('12:05:00'),
        );

        self::assertTrue($replay->isReplay());
        self::assertSame(PaymentLinkDeliveryRepository::STATUS_SENT, (string) $replay->row['status']);
        self::assertSame('provider-msg-1', (string) $replay->row['provider_message_id']);
        self::assertSame(1, (int) $this->connection()->table(self::TABLE)->count());
    }

    public function testTheSameKeyWithADifferentFingerprintIsAConflict(): void
    {
        $repository = $this->repository();
        $repository->claim(
            '',
            self::KEY,
            'fp-a',
            'order0000001',
            str_repeat('a', 64),
            'regenerate',
            300,
            $this->at('12:00:00'),
        );

        $conflict = $repository->claim(
            '',
            self::KEY,
            'fp-b',
            'order0000001',
            str_repeat('a', 64),
            'regenerate',
            300,
            $this->at('12:00:05'),
        );

        self::assertTrue($conflict->isConflict());
        self::assertNull($conflict->row);
    }

    // ==================================================================
    // Deterministic-clock staleness (299 / 300)
    // ==================================================================

    public function testAProcessingClaimAt299SecondsIsStillProcessing(): void
    {
        $repository = $this->repository();
        $repository->claim(
            '',
            self::KEY,
            'fp-a',
            'order0000001',
            str_repeat('a', 64),
            'current',
            300,
            $this->at('12:00:00'),
        );

        $replay = $repository->claim(
            '',
            self::KEY,
            'fp-a',
            'order0000001',
            str_repeat('a', 64),
            'current',
            300,
            $this->at('12:04:59'),
        );

        self::assertTrue($replay->isReplay());
        self::assertSame(PaymentLinkDeliveryRepository::STATUS_PROCESSING, (string) $replay->row['status']);
        self::assertSame(
            PaymentLinkDeliveryRepository::STATUS_PROCESSING,
            (string) $this->row(self::KEY)['status'],
            'the stored row must not have been transitioned before the threshold',
        );
    }

    public function testAProcessingClaimAtExactly300SecondsBecomesIndeterminate(): void
    {
        $repository = $this->repository();
        $repository->claim(
            '',
            self::KEY,
            'fp-a',
            'order0000001',
            str_repeat('a', 64),
            'current',
            300,
            $this->at('12:00:00'),
        );

        $replay = $repository->claim(
            '',
            self::KEY,
            'fp-a',
            'order0000001',
            str_repeat('a', 64),
            'current',
            300,
            $this->at('12:05:00'),
        );

        self::assertTrue($replay->isReplay());
        self::assertSame(PaymentLinkDeliveryRepository::STATUS_INDETERMINATE, (string) $replay->row['status']);
        self::assertSame(
            PaymentLinkDeliveryRepository::STATUS_INDETERMINATE,
            (string) $this->row(self::KEY)['status'],
            'the transition must be PERSISTED, not merely reported',
        );
    }

    public function testAnIndeterminateRowNeverResendsAndNeverRegeneratesSilently(): void
    {
        $repository = $this->repository();
        $repository->claim(
            '',
            self::KEY,
            'fp-a',
            'order0000001',
            str_repeat('a', 64),
            'regenerate',
            300,
            $this->at('12:00:00'),
        );
        $repository->claim(
            '',
            self::KEY,
            'fp-a',
            'order0000001',
            str_repeat('a', 64),
            'regenerate',
            300,
            $this->at('12:05:00'),
        );

        $again = $repository->claim(
            '',
            self::KEY,
            'fp-a',
            'order0000001',
            str_repeat('a', 64),
            'regenerate',
            300,
            $this->at('13:00:00'),
        );

        self::assertTrue($again->isReplay());
        self::assertSame(PaymentLinkDeliveryRepository::STATUS_INDETERMINATE, (string) $again->row['status']);
        self::assertSame(1, (int) $this->connection()->table(self::TABLE)->count());
    }

    // ==================================================================
    // Stale-window configuration clamp
    // ==================================================================

    /** @dataProvider staleSecondsCases */
    public function testTheStaleWindowClampsInto60To3600(mixed $configured, int $expected): void
    {
        $context = $this->appContext();
        $context->mergeConfigDefaults('thallo-commerce', [
            'payment_links' => ['delivery_processing_stale_seconds' => $configured],
        ]);

        try {
            self::assertSame($expected, PaymentLinkDeliveryRepository::staleSeconds($context));
        } finally {
            $context->mergeConfigDefaults('thallo-commerce', [
                'payment_links' => ['delivery_processing_stale_seconds' => 300],
            ]);
        }
    }

    /** @return array<string, array{0:mixed, 1:int}> */
    public static function staleSecondsCases(): array
    {
        return [
            'default' => [300, 300],
            'below the floor' => [1, 60],
            'zero' => [0, 60],
            'negative' => [-5, 60],
            'above the ceiling' => [99999, 3600],
            'at the floor' => [60, 60],
            'at the ceiling' => [3600, 3600],
        ];
    }

    // ==================================================================
    // Custody: the ledger speaks in hashes, never addresses or tokens
    // ==================================================================

    public function testTheRecipientHashIsASha256OfTheLowercasedAddressAndNeverTheAddress(): void
    {
        $hash = PaymentLinkDeliveryRepository::recipientHash('  Buyer@Example.COM ');

        self::assertSame(hash('sha256', 'buyer@example.com'), $hash);
        self::assertStringNotContainsStringIgnoringCase('buyer@example.com', $hash);
    }

    public function testTheFingerprintCoversTheRequestFactsAndChangesWithEachOfThem(): void
    {
        $base = PaymentLinkDeliveryRepository::fingerprint('order0000001', 'regenerate', 'rh', 7);

        self::assertSame($base, PaymentLinkDeliveryRepository::fingerprint('order0000001', 'regenerate', 'rh', 7));
        self::assertNotSame($base, PaymentLinkDeliveryRepository::fingerprint('order0000002', 'regenerate', 'rh', 7));
        self::assertNotSame($base, PaymentLinkDeliveryRepository::fingerprint('order0000001', 'current', 'rh', 7));
        self::assertNotSame($base, PaymentLinkDeliveryRepository::fingerprint('order0000001', 'regenerate', 'x', 7));
        self::assertNotSame($base, PaymentLinkDeliveryRepository::fingerprint('order0000001', 'regenerate', 'rh', 8));
        self::assertNotSame(
            $base,
            PaymentLinkDeliveryRepository::fingerprint('order0000001', 'regenerate', 'rh', null),
        );
        self::assertSame(64, strlen($base));
    }

    // ==================================================================
    // helpers
    // ==================================================================

    private function repository(): PaymentLinkDeliveryRepository
    {
        return $this->container()->get(PaymentLinkDeliveryRepository::class);
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-12 ' . $time, new \DateTimeZone('UTC'));
    }

    /** @return array<string,mixed> */
    private function row(string $key): array
    {
        $row = $this->connection()->table(self::TABLE)->where('idempotency_key', '=', $key)->first();
        self::assertIsArray($row, "delivery row for '{$key}' must exist");

        return $row;
    }

    private function seedRow(Connection $connection, string $tenant, string $key, string $fingerprint): void
    {
        $connection->table(self::TABLE)->insert([
            'uuid' => Utils::generateNanoID(),
            'tenant_uuid' => $tenant,
            'idempotency_key' => $key,
            'fingerprint' => $fingerprint,
            'order_uuid' => 'order0000001',
            'recipient_hash' => str_repeat('a', 64),
            'mode' => 'regenerate',
            'status' => 'processing',
            'created_at' => '2026-08-12 12:00:00',
            'updated_at' => '2026-08-12 12:00:00',
        ]);
    }

    private function sqliteConnection(): Connection
    {
        return new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
    }

    /** Runs the pack's migrations 001..$through (skipping the permission SEED, which is data). */
    private function runPackMigrations(Connection $connection, int $through): void
    {
        $files = [
            1 => '001_CreateProductLinkTable.php',
            3 => '003_CreateProductSlugLedger.php',
            4 => '004_CreateCheckoutAttempts.php',
            5 => '005_CreatePaymentLinkDeliveries.php',
        ];
        foreach ($files as $index => $file) {
            if ($index <= $through) {
                $this->runPackMigration($connection, $file);
            }
        }
    }

    private function runPackMigration(Connection $connection, string $file): void
    {
        $this->packMigration($file)->up($connection->getSchemaBuilder());
    }

    private function packMigration(string $file): \Glueful\Database\Migrations\MigrationInterface
    {
        $path = self::MIGRATION_DIR . '/' . $file;
        self::assertFileExists($path);
        $before = get_declared_classes();
        require_once $path;
        $declared = array_values(array_diff(get_declared_classes(), $before));
        $class = $declared === []
            ? $this->classForMigrationFile($file)
            : $declared[array_key_last($declared)];

        /** @var \Glueful\Database\Migrations\MigrationInterface $migration */
        $migration = new $class();

        return $migration;
    }

    private function classForMigrationFile(string $file): string
    {
        return (string) preg_replace('/\A\d+_|\.php\z/', '', $file);
    }

    /** @return list<string> */
    private function postgresColumns(string $table): array
    {
        $statement = $this->connection()->getPDO()->prepare(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY column_name'
        );
        $statement->execute([$table]);
        $columns = array_map(
            static fn (array $row): string => (string) $row['column_name'],
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );

        return $columns;
    }

    /** @return list<string> */
    private function postgresIndexes(string $table): array
    {
        $statement = $this->connection()->getPDO()->prepare(
            'SELECT indexname FROM pg_indexes WHERE tablename = ?'
        );
        $statement->execute([$table]);

        return array_map(
            static fn (array $row): string => (string) $row['indexname'],
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }
}
