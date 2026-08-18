<?php

declare(strict_types=1);

namespace App\Tests\Unit\Schema;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

/**
 * The adoption behavior contract for every schema-owning pack (schema policy spec B7): each
 * basename gets an ISOLATED fixture — a fresh database carrying its prerequisites but missing at
 * least one effect that migration owns — asserting the verifier is FALSE, then TRUE only after
 * that exact migration's up() ran. Seed migrations run against a minimal pre-created
 * `permissions` table (their prerequisite, owned by aegis in production) and construct their own
 * default Connection internally, so the fixture pins the DB_* env to the per-basename database.
 */
final class PackVerifierBehaviorTest extends TestCase
{
    private const VERIFIERS = [
        'thallo-analytics' => \Thallo\Analytics\Schema\AnalyticsSchemaVerifier::class,
        'thallo-collections' => \Thallo\Collections\Schema\CollectionsSchemaVerifier::class,
        'thallo-commerce' => \Thallo\Commerce\Schema\CommerceLinkSchemaVerifier::class,
        'thallo-navigation' => \Thallo\Navigation\Schema\NavigationSchemaVerifier::class,
        'thallo-render' => \Thallo\Render\Schema\RenderSchemaVerifier::class,
        'thallo-seo' => \Thallo\Seo\Schema\SeoSchemaVerifier::class,
        'thallo-tenancy' => \Thallo\Tenancy\Schema\TenancySchemaVerifier::class,
        'thallo-workflow' => \Thallo\Workflow\Schema\WorkflowSchemaVerifier::class,
    ];

    private const PINNED_ENV = ['DB_DRIVER', 'DB_SQLITE_DATABASE'];

    /** @var list<string> */
    private array $dbPaths = [];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // The suite runs on the phpunit.xml DB env (pgsql); restore it EXACTLY in tearDown —
        // clearing these keys instead would strand every later test on the sqlite fallback.
        foreach (self::PINNED_ENV as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
        foreach ($this->dbPaths as $path) {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
        $this->dbPaths = [];
    }

    /** @return array{0: Connection, 1: MigrationManager} */
    private function isolatedFixture(string $pack): array
    {
        $path = sys_get_temp_dir() . '/pack-verify-' . uniqid('', true) . '.sqlite';
        $this->dbPaths[] = $path;
        // Seed migrations construct `new Connection()` internally: pin the default connection
        // env to THIS fixture so their reads/writes land where the migration ran.
        putenv('DB_DRIVER=sqlite');
        putenv('DB_SQLITE_DATABASE=' . $path);
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_SQLITE_DATABASE'] = $path;
        $connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $path],
            'pooling' => ['enabled' => false],
        ]);
        // The seeds' internal default Connection is a SECOND handle on this sqlite file, writing
        // while the migration wrapper transaction holds this handle open (the collections seed
        // even READS via the wrapper first, taking a shared lock). WAL lets the reader and the
        // writer coexist; busy_timeout absorbs writer-vs-writer overlap at receipt time. Both are
        // test-only concerns — production runs PostgreSQL, where MVCC makes this a non-issue.
        $connection->getPDO()->exec('PRAGMA journal_mode = WAL');
        $connection->getPDO()->exec('PRAGMA busy_timeout = 5000');
        $appDir = sys_get_temp_dir() . '/pack-verify-app-' . uniqid('', true);
        mkdir($appDir);
        $manager = new MigrationManager($appDir, new FileFinder(), null, $connection);
        return [$connection, $manager];
    }

    private function createMinimalPermissionsTable(Connection $connection): void
    {
        $connection->getPDO()->exec(
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid VARCHAR(12), '
            . 'slug VARCHAR(191), name VARCHAR(191), category VARCHAR(100), description TEXT, '
            . 'is_system BOOLEAN)'
        );
    }

    public function testEveryPackMigrationProofFlipsOnItsOwnIsolatedFixture(): void
    {
        foreach (self::VERIFIERS as $pack => $class) {
            $verifier = new $class();
            $migrationsDir = dirname(__DIR__, 3) . "/packages/{$pack}/migrations";
            foreach ($verifier->migrationBasenames() as $basename) {
                [$connection, $manager] = $this->isolatedFixture($pack);
                $isSeed = str_contains($basename, 'Seed');
                if ($isSeed) {
                    // Prerequisite exists, effect (the seeded slugs) absent.
                    $this->createMinimalPermissionsTable($connection);
                }
                self::assertFalse(
                    $verifier->verify($connection, $basename),
                    "{$pack}/{$basename}: proof must be FALSE on its incomplete fixture"
                );
                $result = $manager->migrate($migrationsDir . '/' . $basename);
                self::assertSame(
                    [],
                    $result['failed'],
                    "{$pack}/{$basename}: fixture migration must apply"
                );
                self::assertTrue(
                    $verifier->verify($connection, $basename),
                    "{$pack}/{$basename}: proof must be TRUE once its migration ran"
                );
            }
        }
    }

    public function testUnknownBasenamesAreNeverAdoptable(): void
    {
        foreach (self::VERIFIERS as $pack => $class) {
            [$connection] = $this->isolatedFixture($pack);
            self::assertFalse((new $class())->verify($connection, '999_Unknown.php'), $pack);
        }
    }
}
