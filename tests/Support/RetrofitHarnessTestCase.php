<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Execution\QueryExecutor;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Engine-unit base: boots ONE app against a DEDICATED THROWAWAY PostgreSQL DB with tenancy BOUND but
 * scoping OFF (narrow schema). Isolates all retrofit DDL from the shared suite DB. Because process-
 * global hooks accumulate (boot() has no idempotency guard) and closures bind to this throwaway
 * connection, we CLEAR every tenancy hook/registry/context before booting AND in teardown, and drop
 * the throwaway DB + restore env on the way out.
 */
abstract class RetrofitHarnessTestCase extends AppTestCase
{
    private const TEMPLATE_DB = 'thallo_retrofit_template_test';

    protected static ?ApplicationContext $engineApp = null;
    private static string $priorDb = '';
    private static string $priorPooling = '';
    protected static string $throwawayDb = '';

    /** Migrate the shared template at most once per PHPUnit process. */
    private static bool $templateChecked = false;

    /**
     * Clear ALL tenancy process-global state so a boot registers exactly one fresh set. Only STATIC
     * resets belong here. TenantContext::clear() is deliberately absent — it is an INSTANCE method over
     * per-request ApplicationContext::requestState (dies with the boot); calling it statically is fatal.
     */
    protected static function resetTenancyGlobals(): void
    {
        Connection::clearInsertHooks();
        Connection::clearTableHooks();
        QueryExecutor::clearQueryInterceptors();
        if (class_exists(\Glueful\Extensions\Tenancy\Query\TenantTableRegistry::class)) {
            \Glueful\Extensions\Tenancy\Query\TenantTableRegistry::clear();
            \Glueful\Extensions\Tenancy\Context\CurrentContext::clear(); // static process-pointer reset
        }
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (getenv('THALLO_TENANCY_DEV_LINK') !== '1') {
            self::markTestSkipped('Retrofit harness is opt-in (THALLO_TENANCY_DEV_LINK=1).');
        }
        self::registerTenancyAutoloaderOrSkip();

        self::$throwawayDb = getenv('THALLO_RETROFIT_TEST_DB') ?: 'thallo_retrofit_test';
        if (!str_ends_with(self::$throwawayDb, '_test')) {
            self::fail('Throwaway retrofit DB name must end with _test.');
        }
        self::assertValidDbName(self::$throwawayDb);
        self::createThrowawayFromTemplate(self::$throwawayDb);

        self::$priorDb = (string) getenv('DB_PGSQL_DATABASE');
        self::$priorPooling = (string) getenv('DB_POOLING_ENABLED');
        self::putEnv('DB_PGSQL_DATABASE', self::$throwawayDb);
        self::putEnv('DB_POOLING_ENABLED', 'false');

        self::resetTenancyGlobals(); // drop the shared app's hooks before our first boot
        self::resetSharedRepositoryConnection(); // never inherit a prior class's (possibly foreign-DB) shared conn
        /** @var array{enabled: list<string>} $base */
        $base = require dirname(__DIR__, 2) . '/config/serviceproviders.php';
        $providers = [...$base['enabled'], 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider'];
        self::$engineApp = self::bootAppWithConfigOverride('serviceproviders', ['enabled' => $providers]);

        self::$engineApp->getContainer()->get(Connection::class)->getPDO()->exec(
            "INSERT INTO users (uuid, username, email, status)
             VALUES ('user00000001', 'owner', 'owner@example.test', 'active') ON CONFLICT (uuid) DO NOTHING"
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$engineApp = null;
        self::resetTenancyGlobals();             // stop stale throwaway-bound closures leaking into later classes
        if (self::$throwawayDb !== '') {
            self::dropThrowaway(self::$throwawayDb); // maintenance PDO: terminate connections + DROP DATABASE
        }
        self::resetSharedRepositoryConnection();  // drop the dead throwaway-bound Connection before the next class
        self::putEnv('DB_PGSQL_DATABASE', self::$priorDb);
        self::putEnv('DB_POOLING_ENABLED', self::$priorPooling);
        parent::tearDownAfterClass();
    }

    /**
     * Null out {@see \Glueful\Repository\BaseRepository}'s process-static $sharedConnection.
     *
     * BaseRepository memoises ONE Connection across every repository in the process and never resets it
     * between framework boots. Once we DROP the throwaway DB in teardown, that static still points at the
     * now-terminated Connection; the NEXT retrofit class boots fresh but AppTestCase::setUp constructs
     * RoleRepository context-only, which reuses the dead shared Connection → "PDOException: no connection
     * to the server". Nulling it forces the next repository to lazily rebuild from its own live context.
     * Reflection because the framework exposes no reset seam (a public BaseRepository::resetSharedConnection()
     * would be the cleaner long-term fix). Harmless outside this harness: the shared suite DB is never dropped.
     */
    protected static function resetSharedRepositoryConnection(): void
    {
        if (!class_exists(\Glueful\Repository\BaseRepository::class)) {
            return;
        }
        $prop = new \ReflectionProperty(\Glueful\Repository\BaseRepository::class, 'sharedConnection');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function container(): ContainerInterface
    {
        return self::$engineApp?->getContainer() ?? parent::container();
    }

    protected function appContext(): ApplicationContext
    {
        return self::$engineApp ?? parent::appContext();
    }

    protected function connection(): Connection
    {
        return $this->container()->get(Connection::class);
    }

    private static function putEnv(string $k, string $v): void
    {
        putenv($k . '=' . $v);
        $_ENV[$k] = $v;
    }

    /** Reject anything not a plain identifier before it reaches an unparameterizable CREATE/DROP DATABASE. */
    private static function assertValidDbName(string $db): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $db) !== 1) {
            self::fail("Refusing to use an unsafe throwaway DB name: {$db}");
        }
    }

    /** @return array{host:string,port:string,user:string,pass:string} */
    private static function maintenanceCreds(): array
    {
        return [
            'host' => self::envValue('DB_PGSQL_HOST', '127.0.0.1'),
            'port' => self::envValue('DB_PGSQL_PORT', '5432'),
            'user' => self::envValue('DB_PGSQL_USERNAME', 'postgres'),
            'pass' => self::envValue('DB_PGSQL_PASSWORD', ''),
        ];
    }

    private static function envValue(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return ($value === false || $value === null || $value === '') ? $default : (string) $value;
    }

    /** Open a maintenance connection to the always-present `postgres` database (never the target). */
    private static function maintenancePdo(): PDO
    {
        $c = self::maintenanceCreds();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $c['host'], $c['port']);

        return new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private static function terminateBackends(PDO $pdo, string $db): void
    {
        $stmt = $pdo->prepare(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity
             WHERE datname = :db AND pid <> pg_backend_pid()'
        );
        $stmt->execute(['db' => $db]);
    }

    /**
     * Ensure a migrated template exists (created + migrated at most once per run), then clone the
     * throwaway from it: DROP DATABASE IF EXISTS <db>; CREATE DATABASE <db> TEMPLATE <template>.
     */
    private static function createThrowawayFromTemplate(string $db): void
    {
        $pdo = self::maintenancePdo();
        self::ensureTemplate($pdo);

        self::terminateBackends($pdo, self::TEMPLATE_DB);
        self::terminateBackends($pdo, $db);
        $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s"', $db));
        $pdo->exec(sprintf('CREATE DATABASE "%s" TEMPLATE "%s"', $db, self::TEMPLATE_DB));
    }

    /** Create the template DB if absent and migrate it once (idempotent across runs via a table probe). */
    private static function ensureTemplate(PDO $pdo): void
    {
        if (self::$templateChecked) {
            return;
        }

        $exists = $pdo->query(
            "SELECT 1 FROM pg_database WHERE datname = " . $pdo->quote(self::TEMPLATE_DB)
        )->fetchColumn();
        if ($exists === false) {
            $pdo->exec(sprintf('CREATE DATABASE "%s"', self::TEMPLATE_DB));
        }

        if (!self::templateIsMigrated()) {
            self::migrateTemplate();
        }
        self::$templateChecked = true;
    }

    /** Probe the template for a sentinel owned table to decide whether it still needs migrating. */
    private static function templateIsMigrated(): bool
    {
        $c = self::maintenanceCreds();
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], self::TEMPLATE_DB);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $found = $pdo->query(
            "SELECT to_regclass('public.content_types')"
        )->fetchColumn();

        return $found !== null && $found !== false;
    }

    /** Run the shared test-migration script against the template in a clean child process. */
    private static function migrateTemplate(): void
    {
        $root = dirname(__DIR__, 2);
        $c = self::maintenanceCreds();
        $env = [
            'APP_ENV=testing',
            'DB_DRIVER=pgsql',
            'DB_POOLING_ENABLED=false',
            'DB_PGSQL_DATABASE=' . self::TEMPLATE_DB,
            'DB_PGSQL_HOST=' . $c['host'],
            'DB_PGSQL_PORT=' . $c['port'],
            'DB_PGSQL_USERNAME=' . $c['user'],
            'DB_PGSQL_PASSWORD=' . $c['pass'],
        ];
        $cmd = implode(' ', array_map('escapeshellarg', $env))
            . ' ' . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($root . '/scripts/run-test-migrations.php')
            . ' 2>&1';

        $output = [];
        $code = 0;
        exec('env ' . $cmd, $output, $code);
        if ($code !== 0) {
            self::fail(
                "Failed to migrate retrofit template DB '" . self::TEMPLATE_DB . "':\n" . implode("\n", $output)
            );
        }
    }

    private static function dropThrowaway(string $db): void
    {
        $pdo = self::maintenancePdo();
        self::terminateBackends($pdo, $db);
        $pdo->exec(sprintf('DROP DATABASE IF EXISTS "%s"', $db));
    }

    /**
     * Targeted opt-in autoloader for the dev-linked tenancy extension (test helper, not bootstrap).
     * Skips the whole class if the extension is not symlinked into vendor/.
     */
    private static function registerTenancyAutoloaderOrSkip(): void
    {
        static $registered = false;
        if (!$registered) {
            $registered = true;
            $srcRoot = dirname(__DIR__, 2) . '/vendor/glueful/tenancy/src/';
            spl_autoload_register(static function (string $class) use ($srcRoot): void {
                $prefix = 'Glueful\\Extensions\\Tenancy\\';
                if (!str_starts_with($class, $prefix)) {
                    return;
                }
                $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
                $file = $srcRoot . $rel . '.php';
                if (is_file($file)) {
                    require $file;
                }
            });
        }

        if (!class_exists(\Glueful\Extensions\Tenancy\TenancyServiceProvider::class)) {
            self::markTestSkipped('glueful/tenancy is not symlinked into vendor/ — retrofit harness skipped.');
        }
    }

    // NOTE: no SoftDeleteHandler reset needed — its deleted_at cache is keyed by the connection-specific
    //   cache namespace, so the throwaway connection cannot poison another DB's cache.
}
