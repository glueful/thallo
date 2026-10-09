<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

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
        'signup_continuations', 'signup_verifiers', 'signup_intents',
        'signup_rate_counters', 'signup_daily_counters',
        'tenant_role_overrides', 'tenant_roles', 'tenant_role_policy',
        'thallo_commerce_payment_link_deliveries',
        'thallo_commerce_product_links', 'thallo_commerce_product_slugs', 'thallo_commerce_checkout_attempts',
        'block_type_migrations',
        'font_faces', 'font_families',
        'blobs',
        'block_types',
        'style_class_jobs', 'style_classes', 'style_generations', 'saved_sections', 'admin_ui_settings', 'layouts',
        'render_template_versions', 'render_templates',
        'navigation_items', 'navigation_menus',
        'search_index_acks', 'search_index_changes', 'search_index_demand', 'search_index_state',
        'search_documents',
        'workflow_transitions', 'workflow_review_states',
        'entry_schedules',
        'import_export_reports', 'import_export_errors', 'import_export_files',
        'import_export_batches', 'import_export_jobs',
        'entry_schema_migrations', 'entry_references', 'published_entry_references',
        'entry_redirects', 'entry_routes', 'entry_publications',
        'entry_versions', 'entry_drafts', 'entries', 'content_types',
        // "Used in" is an index over the entries above: truncated with them, so a later test never
        // counts another's rows (MediaUsageRebuildTest).
        'media_usage',
        'form_submissions',
    ];

    /**
     * The per-test rows, deleted: every table a test writes, instance settings and chrome regions.
     * Run before each test, and once more after each class — so a run leaves none behind for what
     * comes next outside the suite (a fixture script seeding the same fixed rows).
     */
    private static function wipe(Connection $db): void
    {
        // QueryBuilder has no truncate(); delete-all via a tautological predicate
        // (every Thallo table has an integer `id`). Deletes commit immediately.
        // forceDelete, NOT delete: the framework's soft-delete handler turns plain
        // delete() into "UPDATE deleted_at" on tables that carry the column (blobs),
        // leaving soft-deleted rows whose uuids still occupy unique indexes.
        foreach (self::TABLES as $t) {
            $db->table($t)->where('id', '>', 0)->forceDelete();
        }
        // Instance settings (varchar `key` PK — no integer id): a prior test's
        // install/save (e.g. listing_types) must never shadow another test's
        // config/.env fallback.
        $db->table('settings')->where('key', '!=', '')->forceDelete();
        // Chrome regions (varchar `slug` PK — no integer id): a prior test's saved
        // header/footer must never leak chrome into another test's render.
        $db->table('regions')->where('slug', '!=', '')->forceDelete();
        // Tenancy flags too, on the way out as well as in: the NEXT process's boot freezes its
        // compatibility write scope from whatever flags it finds, so a class whose last test left
        // a widened schema behind would stamp tenant_uuid into every later write of that process.
        if ($db->getSchemaBuilder()->hasTable('thallo_system_flags')) {
            $db->table('thallo_system_flags')->where('key', 'LIKE', 'tenancy.%')->forceDelete();
        }
    }

    /**
     * Whether this class ran the per-test wipe: a helper class that skips it on purpose — a
     * concurrent writer another test launches as its own process, while that test owns the tables —
     * must not wipe them on its way out either.
     */
    private static bool $wipedThisClass = false;

    public static function tearDownAfterClass(): void
    {
        // The last test's rows go too (the shared boot's own connection: a harness class has
        // restored it by now).
        if (self::$wipedThisClass) {
            $context = TestApplication::instance()->getContext();
            self::wipe($context->getContainer()->get(Connection::class));
            self::$wipedThisClass = false;
        }
        parent::tearDownAfterClass();
    }

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
            // The shared boot takes its capability snapshot from the baseline: a run that leaves
            // capabilities off (THALLO_TEST_CAPABILITIES_OFF) clears them first, and any other run
            // puts back what an earlier off run cleared.
            try {
                CapabilityBaseline::restore(self::baselinePdo());
            } catch (\PDOException) {
                // No migrated test database yet (unit runs, a fresh reset): nothing to put back.
            }
            self::$app = TestApplication::instance()->getContext();
        }

        // Self-heal the process-shared RBAC provider (2026-07-28). PermissionManager holds the
        // active provider in a STATIC, and Aegis registers it exactly once — during the single
        // shared boot above. Several things legitimately clear that static mid-run: the
        // framework's own Glueful\Testing\TestCase::tearDown() (which the Feature suite
        // inherits), a secondary boot, and PreviewFlowTest's deliberate drop. Left null, EVERY
        // later permission check default-denies — surfacing as a cascade of confusing
        // "expected true, got false" failures and, far worse, letting a test that asserts
        // DENIAL pass for entirely the wrong reason. Order-dependence was proven, not guessed:
        // `phpunit tests/Feature tests/Integration/Http` failed 10 RBAC assertions while the
        // reverse order passed, and the full suite only survived because an unrelated
        // secondary-boot test happened to re-register the provider first.
        self::restoreSharedPermissionProviderIfCleared();

        // An adoption leaves payments' tables refusing tenant '' rows; a class that died before its
        // own cleanup must not turn every later single-store payment into a constraint violation.
        PaymentTenantConstraints::drop(self::$app->getContainer()->get(Connection::class));
    }

    /**
     * Re-register the shared boot's RBAC provider when the process-static has been cleared.
     * No-op when a provider is already active (never re-initializes a healthy one).
     */
    private static function restoreSharedPermissionProviderIfCleared(): void
    {
        if (self::$app === null) {
            return;
        }
        $container = self::$app->getContainer();
        if (!$container->has('permission.manager')) {
            return;
        }
        if ($container->get('permission.manager')->getProvider() !== null) {
            return;
        }
        self::restoreSharedPermissionProvider();
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

        // The shared style class provider memoises one snapshot per request (visual builder
        // spec §4.3); a test is a request, so its memo must not outlive the truncation below.
        if ($this->container()->has(\Thallo\Contracts\Style\StyleClassProvider::class)) {
            $this->container()->get(\Thallo\Contracts\Style\StyleClassProvider::class)->refresh();
        }
        // So does the font library snapshot (block typeface spec §3.4): one per request.
        if ($this->container()->has(\Thallo\Render\Style\RequestFontSnapshot::class)) {
            $this->container()->get(\Thallo\Render\Style\RequestFontSnapshot::class)->refresh();
        }
        // And the palette (custom palette spec §3.2): one reading per request.
        if ($this->container()->has(\Thallo\Render\Style\RequestPalette::class)) {
            $this->container()->get(\Thallo\Render\Style\RequestPalette::class)->refresh();
        }

        self::wipe($this->connection());
        self::$wipedThisClass = true;

        // The SettingsStore singleton memoises settings rows per process:
        // the truncation above just deleted rows its cache may still hold (or a
        // prior test's install wrote rows a later warm read would resurrect).
        $this->container()->get(\Thallo\Core\Settings\SettingsStore::class)->clearCache();

        // System-global tenancy flags (varchar `key` PK — no integer id): a prior test that
        // ENABLED tenancy must never leave scoping on for an unrelated test (that would arm the
        // insert stamper suite-wide). Guarded on existence — the table is created by a dependent
        // migration, so it is absent on a pre-migration DB. Then drop the shared memo.
        if ($this->connection()->getSchemaBuilder()->hasTable('thallo_system_flags')) {
            $this->connection()->table('thallo_system_flags')->where('key', '!=', '')->forceDelete();
            // The clean slate is still a site that activated its engine-backed capabilities.
            CapabilityBaseline::restore($this->connection()->getPDO());
        }
        $this->container()->get(\Thallo\Tenancy\System\SystemFlags::class)->clearCache();

        // A test is a unit of work: a prior test's single-store payment work held the adoption
        // gate for the rest of ITS unit — release it, as a request's end would, so an adoption
        // flip in this test is not refused by a unit that already finished.
        \Thallo\Tenancy\Adoption\AdoptionGate::endUnitOfWork();

        // The CONTAINER BlockTypeRepository memoises schemasBySlug() per instance:
        // a prior test that warmed it through container-resolved services (render
        // resolver, validator, …) would poison this test's registry when fixtures
        // create types through FRESH repo instances. Reset the singleton per test.
        $this->container()->get(\Thallo\Core\Content\Blocks\BlockTypeRepository::class)->resetSchemaMemo();
    }

    protected function appContext(): ApplicationContext
    {
        return self::$app;
    }

    /**
     * The render cache's appearance segment for the active theme (colours, design settings and
     * the theme artifact hash), followed by the availability fingerprint of the features that are
     * on, as the page caches key it: cache keys in tests derive from it instead of pinning a literal.
     */
    protected function appearanceFingerprint(): string
    {
        $container = $this->container();
        return $container->get(\Thallo\Render\ThemeAppearanceSource::class)->fingerprint()
            . '-a' . $container->get(\Thallo\Contracts\Capability\AvailabilityFingerprint::class)->current();
    }

    /** Reset BaseRepository's process-static connection after a secondary app boot. */
    protected static function resetSharedRepositoryConnection(): void
    {
        if (!class_exists(\Glueful\Repository\BaseRepository::class)) {
            return;
        }

        $property = new \ReflectionProperty(\Glueful\Repository\BaseRepository::class, 'sharedConnection');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    /** Restore the process-static permission provider to the process-shared app after a secondary boot. */
    protected static function restoreSharedPermissionProvider(): void
    {
        if (self::$app === null || !class_exists(\Glueful\Extensions\Aegis\AegisPermissionProvider::class)) {
            return;
        }

        $container = self::$app->getContainer();
        if (!$container->has('permission.manager')) {
            return;
        }

        $config = (array) config(self::$app, 'rbac', []);
        $permissionConfig = (array) ($config['permissions'] ?? []);
        $roleConfig = (array) ($config['roles'] ?? []);
        $provider = new \Glueful\Extensions\Aegis\AegisPermissionProvider(self::$app);
        $container->get('permission.manager')->setProvider($provider, [
            'cache_enabled' => $permissionConfig['cache_enabled'] ?? true,
            'cache_ttl' => $permissionConfig['cache_ttl'] ?? 3600,
            'cache_prefix' => $permissionConfig['cache_prefix'] ?? 'rbac:',
            'enable_hierarchy' => $roleConfig['inherit_permissions'] ?? true,
            'enable_inheritance' => $permissionConfig['inheritance_enabled'] ?? true,
            'max_hierarchy_depth' => $roleConfig['max_hierarchy_depth'] ?? 10,
        ]);
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

        // `config/testing/` is NOT purely scratch space: `config/testing/extensions.php` is a
        // PERMANENT, tracked override (the tenancy-off test shield — see its header). A caller
        // overriding the same key must RESTORE the pre-existing file afterwards, not unlink it —
        // the old unconditional unlink deleted the shield on every full-suite run, letting dev
        // dogfooding state (the enforcement provider `extensions:enable` writes into
        // config/extensions.php) leak into later tests as intermittent
        // TenantContextRequiredException failures.
        $previous = is_file($overrideFile) ? (string) file_get_contents($overrideFile) : null;
        file_put_contents($overrideFile, "<?php\nreturn " . var_export($config, true) . ";\n");

        RouteManifest::reset();
        // Providers latch loaded route files process-globally (ServiceProvider::$loadedRoutes):
        // without this reset, whichever dedicated boot loads a pack's routes file FIRST steals
        // it from every later boot in the process — the later app silently registers none of
        // that pack's routes (404s that pass in isolation, fail in the full run).
        \Glueful\Extensions\ServiceProvider::resetLoadedRoutes();
        foreach (glob($root . '/storage/cache/routes_*.php') ?: [] as $f) {
            @unlink($f);
        }

        // An override that switches an activation capability off asks for a context booted with it
        // off. Its stored baseline switch would win over configuration, so it is cleared for the
        // boot (the context decides from the snapshot it takes then) and put back, as it was, after.
        $offAtBoot = [];
        if ($file === 'thallo' && is_array($config['capabilities'] ?? null)) {
            foreach ($config['capabilities'] as $id => $enabled) {
                if ($enabled === false && in_array($id, CapabilityBaseline::ON, true)) {
                    $offAtBoot[] = (string) $id;
                }
            }
        }
        $flags = $offAtBoot === [] ? null : self::baselinePdo();
        $removed = [];
        foreach ($offAtBoot as $id) {
            $key = "capability.{$id}.enabled";
            $stmt = $flags?->prepare('DELETE FROM thallo_system_flags WHERE key = ? RETURNING value');
            $stmt?->execute([$key]);
            $value = $stmt?->fetchColumn();
            if (is_string($value)) {
                $removed[$key] = $value;
            }
        }
        if ($flags !== null && $removed !== []) {
            CapabilityBaseline::advanceVersion($flags);       // a different state: its own route table
        }

        try {
            return Framework::create($root)
                ->withConfigDir($root . '/config')
                ->withEnvironment('testing')
                ->boot()
                ->getContext();
        } finally {
            foreach ($removed as $key => $value) {
                $flags?->prepare(
                    'INSERT INTO thallo_system_flags (key, value) VALUES (?, ?) ON CONFLICT (key) DO NOTHING'
                )->execute([$key, $value]);
            }
            if ($flags !== null && $removed !== []) {
                CapabilityBaseline::advanceVersion($flags);
            }
            if ($previous !== null) {
                file_put_contents($overrideFile, $previous);
            } else {
                @unlink($overrideFile);
                if (is_dir($overrideDir) && count((array) scandir($overrideDir)) === 2) {
                    @rmdir($overrideDir);
                }
            }
            RouteManifest::reset();
            \Glueful\Extensions\ServiceProvider::resetLoadedRoutes();
        }
    }

    /** A connection of its own to the test database, for the boot-time baseline switches. */
    private static function baselinePdo(): \PDO
    {
        return new \PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                env('DB_PGSQL_HOST', '127.0.0.1'),
                env('DB_PGSQL_PORT', '5432'),
                env('DB_PGSQL_DATABASE', 'app_test'),
            ),
            (string) env('DB_PGSQL_USERNAME', 'postgres'),
            (string) env('DB_PGSQL_PASSWORD', ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
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
