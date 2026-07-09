<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

abstract class AppTestCase extends TestCase
{
    protected static ?ApplicationContext $app = null;

    // Truncate order is child -> parent (no FKs in v1, but keep it deterministic).
    private const TABLES = [
        'block_type_migrations',
        'blobs',
        'block_types',
        'render_template_versions', 'render_templates',
        'navigation_items', 'navigation_menus',
        'workflow_transitions', 'workflow_review_states',
        'entry_schedules',
        'import_export_reports', 'import_export_errors', 'import_export_files',
        'import_export_batches', 'import_export_jobs',
        'entry_schema_migrations', 'entry_references', 'published_entry_references',
        'entry_redirects', 'entry_routes', 'entry_publications',
        'entry_versions', 'entry_drafts', 'entries', 'content_types',
        'form_submissions',
    ];

    public static function setUpBeforeClass(): void
    {
        // Reuse the single process-shared boot (see TestApplication). The framework's
        // ServiceProvider::loadRoutesFrom() latches each extension route file in a process-global
        // static with no reset hook, so booting the framework more than once per process drops
        // every extension route (e.g. /v1/collections/*) from the later boot's router. Routing
        // ALL suites through one boot is the only correct isolation boundary. TestApplication
        // also resets RouteManifest and clears the stale compiled route cache on that first boot.
        // Framework::boot() returns a Glueful\Application; we keep its ApplicationContext
        // (both expose getContainer()).
        if (self::$app === null) {
            self::$app = TestApplication::instance()->getContext();
        }
    }

    /** Verified once per process: are the tables actually migrated? */
    private static bool $schemaVerified = false;

    private function grantSeedActorBypass(): void
    {
        $db = $this->connection();
        $perm = $db->table('permissions')->select(['uuid'])
            ->where('slug', '=', 'workflow.bypass')->first();
        if ($perm === null) {
            return; // workflow pack absent — nothing to bypass
        }
        $roleSlug = 'test-seed-bypass';
        $role = $db->table('roles')->select(['uuid'])->where('slug', '=', $roleSlug)->first();
        $roleUuid = $role !== null ? (string) $role['uuid'] : \Glueful\Helpers\Utils::generateNanoID();
        if ($role === null) {
            $db->table('roles')->insert(['uuid' => $roleUuid, 'slug' => $roleSlug, 'name' => $roleSlug]);
        }
        $link = $db->table('role_permissions')->select(['id'])
            ->where('role_uuid', '=', $roleUuid)
            ->where('permission_uuid', '=', (string) $perm['uuid'])
            ->first();
        if ($link === null) {
            $db->table('role_permissions')->insert([
                'uuid' => \Glueful\Helpers\Utils::generateNanoID(),
                'role_uuid' => $roleUuid,
                'permission_uuid' => (string) $perm['uuid'],
            ]);
        }
        $assigned = $db->table('user_roles')->select(['id'])
            ->where('user_uuid', '=', 'user00000001')
            ->where('role_uuid', '=', $roleUuid)
            ->first();
        if ($assigned === null) {
            $this->container()->get(\Glueful\Extensions\Aegis\AegisPermissionProvider::class)
                ->assignRole('user00000001', $roleSlug);
        }
    }

    protected function setUp(): void
    {
        // Fail loud and clear if the test DB isn't migrated, instead of letting every
        // test trip over a raw "relation ... does not exist" on the first truncate
        // (which masks the real cause — e.g. the migration bootstrap dying on a
        // ConnectionPoolException). Checked once per process.
        if (!self::$schemaVerified) {
            $schema = $this->connection()->getSchemaBuilder();
            foreach (self::TABLES as $t) {
                if (!$schema->hasTable($t)) {
                    self::fail(
                        "Test database is not migrated: table '{$t}' is missing. "
                        . "Run `composer test:migrate`. In CI, check the migration step for a "
                        . "ConnectionPoolException (the pool must be off: DB_POOLING_ENABLED=false)."
                    );
                }
            }
            self::$schemaVerified = true;
        }

        // TEST HARNESS ONLY: the workflow publish gate is live suite-wide, and the shared
        // seeding actor publishes fixture content without going through review — grant it
        // workflow.bypass so pre-existing publish-path tests behave exactly as before the
        // gate existed. Re-asserted EVERY test (cheap + idempotent): some suites clean RBAC
        // tables, and a once-per-process grant silently dies with them. Production grants
        // are ONLY the administrator dependent migration.
        $this->grantSeedActorBypass();

        // QueryBuilder has no truncate(); delete-all via a tautological predicate
        // (every Thallo table has an integer `id`). Deletes commit immediately.
        // forceDelete, NOT delete: the framework's soft-delete handler turns plain
        // delete() into "UPDATE deleted_at" on tables that carry the column (blobs),
        // leaving soft-deleted rows whose uuids still occupy unique indexes.
        foreach (self::TABLES as $t) {
            $this->connection()->table($t)->where('id', '>', 0)->forceDelete();
        }
        // Instance settings (varchar `key` PK — no integer id): a prior test's
        // install/save (e.g. listing_types) must never shadow another test's
        // config/.env fallback.
        $this->connection()->table('settings')->where('key', '!=', '')->forceDelete();
        // Chrome regions (varchar `slug` PK — no integer id): a prior test's saved
        // header/footer must never leak chrome into another test's render.
        $this->connection()->table('regions')->where('slug', '!=', '')->forceDelete();

        // The SettingsStore singleton memoises settings rows per process:
        // the truncation above just deleted rows its cache may still hold (or a
        // prior test's install wrote rows a later warm read would resurrect).
        $this->container()->get(\App\Settings\SettingsStore::class)->clearCache();

        // The CONTAINER BlockTypeRepository memoises schemasBySlug() per instance:
        // a prior test that warmed it through container-resolved services (render
        // resolver, validator, …) would poison this test's registry when fixtures
        // create types through FRESH repo instances. Reset the singleton per test.
        $this->container()->get(\App\Content\Blocks\BlockTypeRepository::class)->resetSchemaMemo();
    }

    protected function appContext(): ApplicationContext
    {
        return self::$app;
    }

    /**
     * Boot a SECOND app with a temporary `config/testing/{$file}.php` override — the
     * capability/extension enable-disable tests' shared choreography. The override file
     * is removed (and the process-global RouteManifest latch + compiled route caches
     * reset) in a finally, so the shared boot other test classes rely on is never
     * poisoned even when the boot itself throws. Callers cache the returned context in
     * their own static — a per-class boot is expensive.
     *
     * @param array<string,mixed> $config the override config tree to write
     */
    protected static function bootAppWithConfigOverride(string $file, array $config): ApplicationContext
    {
        $root = dirname(__DIR__, 2);
        $overrideDir = $root . '/config/testing';
        $overrideFile = $overrideDir . '/' . $file . '.php';

        if (!is_dir($overrideDir)) {
            mkdir($overrideDir, 0755, true);
        }
        file_put_contents($overrideFile, "<?php\nreturn " . var_export($config, true) . ";\n");

        RouteManifest::reset();
        foreach (glob($root . '/storage/cache/routes_*.php') ?: [] as $f) {
            @unlink($f);
        }

        try {
            return Framework::create($root)
                ->withConfigDir($root . '/config')
                ->withEnvironment('testing')
                ->boot()
                ->getContext();
        } finally {
            @unlink($overrideFile);
            if (is_dir($overrideDir) && count((array) scandir($overrideDir)) === 2) {
                @rmdir($overrideDir);
            }
            RouteManifest::reset();
        }
    }

    protected function connection(): Connection
    {
        return $this->container()->get(Connection::class);
    }

    protected function container(): ContainerInterface
    {
        return self::$app->getContainer();
    }

    protected function router(): Router
    {
        return $this->container()->get(Router::class);
    }

    /**
     * Drive a request through the real application kernel (Router::dispatch via
     * Application::handle) — the same entry point public/index.php uses.
     */
    protected function handle(Request $request): HttpResponse
    {
        return (new Application(self::$app))->handle($request);
    }

    /** Build a JSON request with method, path and (optional) body. */
    protected function jsonRequest(string $method, string $path, ?array $body = null): Request
    {
        return Request::create(
            $path,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body === null ? null : (string) json_encode($body)
        );
    }

    /**
     * Find a registered route by method + exact path. Returns the Router's route
     * descriptor (handler, middleware, name, ...) or null if no such route exists.
     *
     * @return array<string, mixed>|null
     */
    protected function findRoute(string $method, string $path): ?array
    {
        foreach ($this->router()->getAllRoutes() as $route) {
            if (
                strtoupper((string) $route['method']) === strtoupper($method)
                && (string) $route['path'] === $path
            ) {
                return $route;
            }
        }
        return null;
    }

    /**
     * Assert a runtime `data` payload's keys match a doc-only ResponseData DTO's
     * constructor params. With $exact=false, the payload keys must be a SUBSET of the
     * DTO params (for shapes that omit falsy keys, e.g. ContentTypeSchema::toArray()).
     * Never recurses into freeform `fields`.
     *
     * @param array<string,mixed>           $data
     * @param class-string<\Glueful\Http\Contracts\ResponseData> $dtoClass
     */
    protected static function assertDataMatchesDtoShape(array $data, string $dtoClass, bool $exact = true): void
    {
        $params = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod($dtoClass, '__construct'))->getParameters()
        );
        $actual = array_keys($data);
        if ($exact) {
            sort($params);
            sort($actual);
            self::assertSame($params, $actual, "Payload keys differ from {$dtoClass}");
        } else {
            self::assertSame([], array_diff($actual, $params), "Payload has keys not in {$dtoClass}");
        }
    }
}
