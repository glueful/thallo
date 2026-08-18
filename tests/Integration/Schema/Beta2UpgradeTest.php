<?php

declare(strict_types=1);

namespace App\Tests\Integration\Schema;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\Migrate\RunCommand;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Extensions\Schema\AdoptionService;
use Glueful\Extensions\Schema\AdoptionState;
use Glueful\Extensions\Schema\DescriptorInventory;
use Glueful\Extensions\Schema\MigrationLockFactory;
use Glueful\Extensions\Schema\MigrationManagerFactory;
use Glueful\Extensions\Schema\ReadinessState;
use Glueful\Extensions\Schema\ReceiptNormalizer;
use Glueful\Extensions\Schema\SchemaReadiness;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The beta.2 → beta.3 upgrade proof (schema policy spec B4): a ledger seeded EXACTLY as the
 * v1.0.0-beta.2 release wrote it — pack receipts under their legacy aliases (`thallo-*`, render's
 * bare `migrations`), app/dependent/engine receipts canonical — must normalize with zero refusals
 * BEFORE the global migration read, after which the real `migrate:run --force` harness applies
 * ONLY what beta.2 never shipped (the 1.79 `extension_operations` ledger migration) and replays
 * nothing. A tampered alias receipt must refuse normalization and stop the scripted upgrade
 * before `migrate:run`, staying Divergent until repaired.
 *
 * Fixture fidelity: every repo-owned migration file (app, app:dependent, the eight packs) is
 * asserted byte-identical to the `v1.0.0-beta.2` git tag before its checksum is seeded. Engine
 * receipts (vendor glueful/*) are seeded from the installed files — those artifacts are
 * checksum-stable across the schema-on-enable releases (their repos gate on it), and any drift
 * would surface here as a Divergent readiness verdict, failing this test.
 */
final class Beta2UpgradeTest extends AppTestCase
{
    private const BETA2_TAG = 'v1.0.0-beta.2';
    private const NEW_SOURCE = 'glueful/framework:extensions';
    private const NEW_BASENAME = '001_CreateExtensionOperationsTable.php';

    /** Exact beta.2 alias ledger shape: alias source => receipt count. */
    private const ALIAS_COUNTS = [
        'migrations' => 3,
        'thallo-analytics' => 4,
        'thallo-collections' => 3,
        'thallo-commerce' => 5,
        'thallo-navigation' => 3,
        'thallo-seo' => 2,
        'thallo-tenancy' => 6,
        'thallo-workflow' => 3,
    ];

    private const PINNED_ENV = ['DB_DRIVER', 'DB_SQLITE_DATABASE'];

    /** @var list<string> */
    private array $fixturePaths = [];

    protected function tearDown(): void
    {
        foreach ($this->fixturePaths as $path) {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
        $this->fixturePaths = [];
        parent::tearDown();
    }

    public function testBeta2LedgerNormalizesFirstThenUpgradesWithoutReplay(): void
    {
        $root = dirname(__DIR__, 3);
        $inventory = MigrationManagerFactory::inventory(ApplicationContext::forTesting($root));

        [$dbPath, $seedConnection] = $this->beta2Fixture($inventory);
        $before = $this->ledgerRows($seedConnection);
        $aliasRowCount = array_sum(self::ALIAS_COUNTS);

        $saved = [];
        foreach (self::PINNED_ENV as $key) {
            $saved[$key] = getenv($key);
        }
        putenv('DB_DRIVER=sqlite');
        putenv('DB_SQLITE_DATABASE=' . $dbPath);
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_SQLITE_DATABASE'] = $dbPath;

        // Provider boot ADDS to QueryExecutor's process-global hook lists (e.g. the tenancy
        // pack's mutation-quiescence wrapper, whose lock would here capture the sqlite fixture
        // connection). Snapshot and restore them so the second boot cannot poison the shared
        // app's query path for later tests.
        $wrappersProp = new \ReflectionProperty(\Glueful\Database\Execution\QueryExecutor::class, 'executionWrappers');
        $interceptorsProp = new \ReflectionProperty(\Glueful\Database\Execution\QueryExecutor::class, 'interceptors');
        $savedWrappers = $wrappersProp->getValue();
        $savedInterceptors = $interceptorsProp->getValue();

        try {
            // A beta.2 host running beta.3 code: the boot itself must not touch the fixture DB.
            $context = self::bootAppWithConfigOverride('beta2-upgrade', []);
            $container = $context->getContainer();

            // ── Step 1: normalize receipts — BEFORE any migration read ──────────────────
            $report = $container->get(ReceiptNormalizer::class)->normalize();
            self::assertSame([], $report->refused, 'a pristine beta.2 ledger normalizes cleanly');
            self::assertCount($aliasRowCount, $report->rewritten, 'every alias row is rewritten');

            $after = $this->ledgerRows($seedConnection);
            self::assertCount(count($before), $after, 'normalization moves rows, never adds/drops');
            foreach (array_keys(self::ALIAS_COUNTS) as $alias) {
                self::assertArrayNotHasKey($alias, $this->countBySource($after), $alias);
            }
            $canonicalPackCounts = [
                'glueful/thallo-analytics' => 4, 'glueful/thallo-collections' => 3,
                'glueful/thallo-commerce' => 5, 'glueful/thallo-navigation' => 3,
                'glueful/thallo-render' => 3, 'glueful/thallo-seo' => 2,
                'glueful/thallo-tenancy' => 6, 'glueful/thallo-workflow' => 3,
            ];
            $counts = $this->countBySource($after);
            foreach ($canonicalPackCounts as $source => $expected) {
                self::assertSame($expected, $counts[$source] ?? 0, $source);
            }
            $aliasKeys = array_keys(self::ALIAS_COUNTS);
            $untouchedBefore = array_filter($before, fn($r) => !in_array($r['source'], $aliasKeys, true));
            $untouchedAfter = array_filter($after, fn($r) => !str_starts_with($r['source'], 'glueful/thallo-'));
            self::assertSame(
                $this->receiptSet($untouchedBefore),
                $this->receiptSet($untouchedAfter),
                'canonical and app receipts pass through normalization byte-identical'
            );

            // ── Step 2: the real migrate:run harness — only genuinely-new files apply ───
            $tester = new CommandTester(new RunCommand($container, $context));
            $exit = $tester->execute(['--force' => true], ['interactive' => false]);
            self::assertSame(0, $exit, 'migrate:run must succeed: ' . $tester->getDisplay());

            $final = $this->ledgerRows($seedConnection);
            self::assertCount(count($before) + 1, $final, 'exactly one migration is new since beta.2');
            $newRows = array_values(array_udiff(
                $this->receiptSet($final),
                $this->receiptSet($after),
                fn($a, $b) => strcmp(implode('|', $a), implode('|', $b))
            ));
            self::assertCount(1, $newRows);
            self::assertSame(self::NEW_SOURCE, $newRows[0]['source']);
            self::assertSame(self::NEW_BASENAME, $newRows[0]['migration']);
            foreach ($final as $row) {
                if (str_starts_with($row['source'], 'glueful/thallo-')) {
                    self::assertSame(1, (int) $row['batch'], 'pack receipts keep their seeded batch — no replay');
                }
            }

            // ── Step 3: the whole inventory reports Ready ───────────────────────────────
            $readiness = $container->get(SchemaReadiness::class);
            foreach ($inventory->all() as $descriptor) {
                self::assertSame(
                    ReadinessState::Ready,
                    $readiness->classify($descriptor),
                    $descriptor->source() . ': ' . implode('; ', $readiness->explain($descriptor))
                );
            }
            foreach ($container->get(AdoptionService::class)->classify() as $source => $verdict) {
                self::assertSame(AdoptionState::Ready, $verdict['state'], $source);
            }
            $manager = $container->get(MigrationManager::class);
            self::assertSame([], $manager->pendingForSources($manager->globalSources()));
        } finally {
            $wrappersProp->setValue(null, $savedWrappers);
            $interceptorsProp->setValue(null, $savedInterceptors);
            foreach ($saved as $key => $value) {
                if ($value === false) {
                    putenv($key);
                    unset($_ENV[$key]);
                } else {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                }
            }
            // Post-secondary-boot choreography (same as the capability tests), AFTER the env
            // restore — provider re-initialization opens env-driven connections. The boot above
            // captured the sqlite fixture in BaseRepository's process-static connection and
            // swapped the process-static RBAC provider — left in place, the NEXT booted app's
            // Aegis activation reads the fixture DB, throws, and silently aborts its whole
            // extension boot pass (Framework swallows it into error_log).
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }
    }

    public function testATamperedAliasReceiptRefusesNormalizationUntilRepaired(): void
    {
        $root = dirname(__DIR__, 3);
        $inventory = MigrationManagerFactory::inventory(ApplicationContext::forTesting($root));
        [, $connection] = $this->beta2Fixture($inventory);

        $workflow = $inventory->bySource('glueful/thallo-workflow');
        self::assertNotNull($workflow);
        $files = $inventory->filesOf($workflow);
        $tamperedBasename = basename($files[0]);
        $realChecksum = hash_file('sha256', $files[0]);
        $connection->getPDO()->exec(
            "UPDATE migrations SET checksum = '" . str_repeat('0', 64) . "'"
            . " WHERE source = 'thallo-workflow' AND migration = " . $connection->getPDO()->quote($tamperedBasename)
        );

        $lock = MigrationLockFactory::forConnection($connection, null);
        $normalizer = new ReceiptNormalizer($connection, $inventory, $lock);

        // Refusal: the tampered row stays aliased; independently verified rewrites still commit.
        $report = $normalizer->normalize();
        self::assertCount(1, $report->refused, 'exactly the tampered alias row is refused');
        self::assertSame('thallo-workflow', $report->refused[0]['alias']);
        self::assertStringContainsString('checksum', strtolower($report->refused[0]['reason']));
        self::assertCount(array_sum(self::ALIAS_COUNTS) - 1, $report->rewritten);
        // A refusal is the scripted upgrade's stop signal: migrate:normalize-receipts exits
        // non-zero, so the `&&` chain never reaches migrate:run and the divergent alias can
        // never be papered over by a canonical replay. Nothing below runs migrations.
        self::assertNotSame([], $report->refused);

        $counts = $this->countBySource($this->ledgerRows($connection));
        self::assertSame(1, $counts['thallo-workflow'] ?? 0, 'the refused row remains under its alias');
        self::assertSame(2, $counts['glueful/thallo-workflow'] ?? 0, 'its siblings were verified and moved');

        // Divergent until repaired; everything else already Ready (the new 1.79 core migration
        // is still Pending here because the aborted upgrade never reached migrate:run).
        $readiness = new SchemaReadiness($connection, $inventory);
        self::assertSame(ReadinessState::Divergent, $readiness->classify($workflow));
        foreach ($inventory->all() as $descriptor) {
            if ($descriptor->source() === 'glueful/thallo-workflow') {
                continue;
            }
            $expected = $descriptor->source() === self::NEW_SOURCE
                ? ReadinessState::Pending
                : ReadinessState::Ready;
            self::assertSame($expected, $readiness->classify($descriptor), $descriptor->source());
        }

        // Repair, then normalization completes — and a further rerun is an idempotent no-op.
        $connection->getPDO()->exec(
            "UPDATE migrations SET checksum = '" . $realChecksum . "'"
            . " WHERE source = 'thallo-workflow' AND migration = " . $connection->getPDO()->quote($tamperedBasename)
        );
        $repaired = $normalizer->normalize();
        self::assertSame([], $repaired->refused);
        self::assertCount(1, $repaired->rewritten);
        self::assertSame(ReadinessState::Ready, $readiness->classify($workflow));

        $idempotent = $normalizer->normalize();
        self::assertSame([], $idempotent->refused);
        self::assertSame([], $idempotent->rewritten);
    }

    // ───────────────────────────── fixture construction ─────────────────────────────

    /**
     * A fresh sqlite database whose `migrations` ledger holds exactly what a v1.0.0-beta.2
     * install recorded: repo-owned receipts byte-verified against the beta.2 tag, engine
     * receipts from the installed vendor files, and NO receipt for anything 1.79 introduced.
     *
     * @return array{0: string, 1: Connection}
     */
    private function beta2Fixture(DescriptorInventory $inventory): array
    {
        $root = dirname(__DIR__, 3);
        $dbPath = sys_get_temp_dir() . '/beta2-upgrade-' . uniqid('', true) . '.sqlite';
        $this->fixturePaths[] = $dbPath;

        $connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $dbPath],
            'pooling' => ['enabled' => false],
        ]);
        $pdo = $connection->getPDO();
        $pdo->exec(
            'CREATE TABLE migrations ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(255) NOT NULL, '
            . 'batch INTEGER NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, '
            . 'checksum VARCHAR(64) NOT NULL, description TEXT NULL, extension VARCHAR(100) NULL, '
            . "source VARCHAR(191) NOT NULL DEFAULT 'app', UNIQUE(source, migration))"
        );
        $insert = $pdo->prepare(
            'INSERT INTO migrations (migration, batch, checksum, description, source) '
            . "VALUES (?, 1, ?, 'beta.2 receipt (fixture)', ?)"
        );

        // Repo-owned app sources: seed the TAG's file list, byte-verified against today's files.
        foreach (
            [
                'app' => 'database/migrations',
                'app:dependent' => 'database/dependent-migrations',
            ] as $source => $dir
        ) {
            foreach ($this->tagFiles($root, $dir) as $basename) {
                $this->assertMatchesTag($root, "{$dir}/{$basename}");
                $insert->execute([$basename, hash_file('sha256', "{$root}/{$dir}/{$basename}"), $source]);
            }
        }

        // Descriptor-owned sources: packs seed under their beta.2 ledger alias (tag-verified);
        // engine/framework receipts are canonical already. 1.79's new leaf is deliberately absent.
        self::assertNotNull($inventory->bySource(self::NEW_SOURCE));
        foreach ($inventory->all() as $descriptor) {
            if ($descriptor->source() === self::NEW_SOURCE) {
                continue;
            }
            $alias = $descriptor->legacyAliases[0] ?? null;
            $ledgerSource = $alias ?? $descriptor->source();
            $files = $inventory->filesOf($descriptor);
            if ($alias !== null) {
                $packDir = (string) realpath($inventory->pathOf($descriptor));
                $relDir = substr($packDir, strlen((string) realpath($root)) + 1);
                $tagBasenames = $this->tagFiles($root, $relDir);
                $currentBasenames = array_map('basename', $files);
                sort($tagBasenames);
                sort($currentBasenames);
                self::assertSame(
                    $tagBasenames,
                    $currentBasenames,
                    "{$ledgerSource}: pack shipped no new files since beta.2"
                );
                foreach ($currentBasenames as $basename) {
                    $this->assertMatchesTag($root, "{$relDir}/{$basename}");
                }
            }
            foreach ($files as $file) {
                $insert->execute([basename($file), hash_file('sha256', $file), $ledgerSource]);
            }
        }

        $counts = $this->countBySource($this->ledgerRows($connection));
        self::assertSame(21, $counts['app'], 'beta.2 shipped 21 app migrations');
        self::assertSame(11, $counts['app:dependent'], 'beta.2 shipped 11 dependent app migrations');
        foreach (self::ALIAS_COUNTS as $alias => $expected) {
            self::assertSame($expected, $counts[$alias] ?? 0, $alias);
        }
        $hasNew = (int) $pdo->query(
            'SELECT COUNT(*) FROM migrations WHERE migration = ' . $pdo->quote(self::NEW_BASENAME)
        )->fetchColumn();
        self::assertSame(0, $hasNew, 'the fixture predates the 1.79 extension_operations migration');

        return [$dbPath, $connection];
    }

    /** @return list<string> basenames of the tag's php files under $dir */
    private function tagFiles(string $root, string $dir): array
    {
        $out = shell_exec(
            'git -C ' . escapeshellarg($root) . ' ls-tree --name-only '
            . escapeshellarg(self::BETA2_TAG) . ' -- ' . escapeshellarg($dir . '/')
        );
        $basenames = [];
        foreach (preg_split('/\R/', (string) $out, -1, PREG_SPLIT_NO_EMPTY) as $path) {
            if (str_ends_with($path, '.php')) {
                $basenames[] = basename($path);
            }
        }
        self::assertNotSame([], $basenames, "the beta.2 tag ships migrations under {$dir}");
        return $basenames;
    }

    private function assertMatchesTag(string $root, string $relPath): void
    {
        $tagBytes = shell_exec(
            'git -C ' . escapeshellarg($root) . ' show '
            . escapeshellarg(self::BETA2_TAG . ':' . $relPath) . ' 2>/dev/null'
        );
        self::assertNotNull($tagBytes, "{$relPath} must exist in the beta.2 tag");
        self::assertSame(
            hash('sha256', (string) $tagBytes),
            hash_file('sha256', "{$root}/{$relPath}"),
            "{$relPath} must be byte-identical to the beta.2 tag — a drifted file would poison the fixture"
        );
    }

    // ───────────────────────────── ledger helpers ─────────────────────────────

    /** @return list<array{source: string, migration: string, checksum: string, batch: int|string}> */
    private function ledgerRows(Connection $connection): array
    {
        $rows = $connection->getPDO()
            ->query('SELECT source, migration, checksum, batch FROM migrations ORDER BY source, migration')
            ->fetchAll(\PDO::FETCH_ASSOC);
        return array_values($rows);
    }

    /** @return array<string, int> */
    private function countBySource(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['source']] = ($counts[$row['source']] ?? 0) + 1;
        }
        return $counts;
    }

    /** @return list<array{source: string, migration: string, checksum: string}> sorted, batch-free */
    private function receiptSet(array $rows): array
    {
        $set = array_map(
            fn($r) => ['source' => $r['source'], 'migration' => $r['migration'], 'checksum' => $r['checksum']],
            array_values($rows)
        );
        usort($set, fn($a, $b) => [$a['source'], $a['migration']] <=> [$b['source'], $b['migration']]);
        return $set;
    }
}
