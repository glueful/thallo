<?php

declare(strict_types=1);

namespace App\Tests\Integration\Schema;

use App\Capabilities\ExtensionCapabilityAvailabilityResolver;
use App\Http\Controllers\CapabilityAdminController;
use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\AdoptionService;
use Glueful\Extensions\Schema\DescriptorInventory;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Glueful\Extensions\Schema\MigrationManagerFactory;
use Glueful\Extensions\Schema\ReadinessState;
use Glueful\Extensions\Schema\SchemaReadiness;
use Glueful\Installer\DatabaseConfig;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;

/**
 * The schema program's cross-policy acceptance (Plan 3 Task 10), over the REAL booted app and
 * the real installed tree. Each matrix row asserts the composed end state that the per-task
 * tests proved piecewise: the closed manifest inventory, enable-state source scoping, the
 * capability owner matrix and its two feeds, the canonical-only ledger, and fail-closed
 * behavior before a ledger exists. (The beta.2 upgrade row of the original plan is void: the
 * alias machinery was dropped by decision — pre-beta.3 ledgers re-provision instead.)
 */
final class SchemaProgramAcceptanceTest extends AppTestCase
{
    private const ENGINE_OWNED = [
        'thallo.accounts' => 'glueful/users',
        'thallo.commerce' => 'glueful/commerce',
        'thallo.importers' => 'glueful/import-export',
        'thallo.search' => 'glueful/meilisearch',
        'thallo.subscriptions' => 'glueful/subscriptions',
        'thallo.tenancy' => 'glueful/tenancy',
    ];

    private const APP_OWNED = [
        'thallo.analytics', 'thallo.collections', 'thallo.navigation',
        'thallo.render', 'thallo.seo', 'thallo.workflow',
    ];

    // ── Step 1: closed inventory ─────────────────────────────────────────────────

    public function testEveryInstalledGluefulPackageDeclaresItsSchemaExactlyOnce(): void
    {
        $manifest = new PackageManifest($this->appContext());
        self::assertSame(
            [],
            $manifest->undeclaredGluefulPackages(),
            'every installed extra.glueful package declares descriptors or explicit none'
        );

        $inventory = $this->container()->get(DescriptorInventory::class);
        $manager = $this->container()->get(MigrationManager::class);
        $sources = [];
        foreach ($inventory->all() as $descriptor) {
            $source = $descriptor->source();
            self::assertArrayNotHasKey($source, $sources, "{$source} must appear exactly once");
            $sources[$source] = true;
            self::assertTrue($manager->hasSource($source), "{$source} must be registered");
        }

        // The permanent app-local lane: registered by the root-app provider at boot, never a
        // descriptor of any package.
        self::assertTrue($manager->hasSource('app:dependent'));
        self::assertNull($inventory->bySource('app:dependent'));
        self::assertArrayNotHasKey('app:dependent', $sources);
    }

    // ── Step 2: enable-state source scoping + tenancy custody ────────────────────

    public function testEnableStateScopesTheGlobalViewWhileCoreAlwaysStays(): void
    {
        // The test boot runs the dogfood everything-on posture (config/testing/extensions.php),
        // so enabled engines are global here...
        $manager = $this->container()->get(MigrationManager::class);
        $snapshot = $manager->globalSources();
        foreach (['glueful/commerce', 'glueful/payvia', 'glueful/aegis', 'glueful/users'] as $enabled) {
            self::assertContains($enabled, $snapshot, "{$enabled} is enabled in this boot");
        }
        // ...and CORE sources are global regardless of ANY enablement — including
        // glueful/tenancy, whose enforcement PROVIDER is disabled by the test shield.
        foreach (
            [
                'glueful/thallo-analytics', 'glueful/thallo-collections', 'glueful/thallo-commerce',
                'glueful/thallo-navigation', 'glueful/thallo-render', 'glueful/thallo-seo',
                'glueful/thallo-tenancy', 'glueful/thallo-workflow', 'glueful/tenancy', 'app',
            ] as $core
        ) {
            self::assertContains($core, $snapshot, "{$core} is core — always in the global view");
        }

        // The DISTRIBUTION posture (the committed base config: tier 1 + Subscriptions, with
        // Commerce/Payvia not enabled) proves the other direction: a disabled engine's
        // on_enable source is absent from the global view while every core source stays.
        $root = dirname(__DIR__, 3);
        $base = sys_get_temp_dir() . '/acceptance-dist-' . uniqid('', true);
        mkdir($base . '/config', 0777, true);
        try {
            symlink($root . '/vendor', $base . '/vendor');
            copy($root . '/config/extensions.php', $base . '/config/extensions.php');
            file_put_contents(
                $base . '/config/app.php',
                "<?php\nreturn ['paths' => ['migrations' => "
                . var_export($root . '/database/migrations', true) . "]];\n"
            );
            $context = new ApplicationContext($base);
            $context->setConfigLoader(new \Glueful\Bootstrap\ConfigurationLoader($base, 'testing'));
            $distManager = MigrationManagerFactory::create(
                $context,
                new Connection((new DatabaseConfig(
                    'sqlite',
                    database: $base . '/dist.sqlite'
                ))->toConnectionConfig())
            );
            $dist = $distManager->globalSources();
            foreach (['glueful/commerce', 'glueful/payvia'] as $disabled) {
                self::assertNotContains($disabled, $dist, "{$disabled} is off in the distribution posture");
            }
            self::assertContains('glueful/subscriptions', $dist, 'the bundled engine ships enabled');
            self::assertContains('glueful/thallo-commerce', $dist, 'core stays global even with its engine off');
            self::assertContains('glueful/tenancy', $dist);
        } finally {
            exec('rm -rf ' . escapeshellarg($base));
        }
    }

    public function testTenancyIsRefusedByTheGenericExecutorLifecycle(): void
    {
        // The generic enable path must refuse the protected provider BEFORE any state change;
        // only the tenancy enablement machine (through migrateProtected + its own activation
        // step) may drive it — proven end-to-end by ExtensionActivationTest.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tenancy enablement flow');
        $this->container()->get(ExtensionSchemaExecutor::class)->enable('glueful/tenancy', 'acceptance');
    }

    // ── Step 3: the capability owner matrix and its two feeds ────────────────────

    public function testEngineOwnedCapabilitiesGateOnTheirOwnerAndAppOwnedDoNot(): void
    {
        $registry = $this->container()->get(CapabilityRegistry::class);
        $registered = array_map(static fn (Capability $c): string => $c->id, $registry->all());

        foreach (self::ENGINE_OWNED as $id => $package) {
            self::assertContains($id, $registered, "{$id} must be registered even when ineffective");
            $availability = $registry->availability($id);
            if (!$availability->available) {
                self::assertStringContainsString(
                    $package,
                    (string) $availability->reason,
                    "{$id}: an unavailable verdict names the owning engine"
                );
                self::assertFalse($registry->isEnabled($id), "{$id}: unavailable means ineffective");
            } else {
                self::assertSame(
                    $registry->isRequestedEnabled($id),
                    $registry->isEnabled($id),
                    "{$id}: available means the switchboard alone decides"
                );
            }
        }

        // The one concretely-OFF owner in this boot: the tenancy enforcement provider is
        // disabled by the test shield, so thallo.tenancy is registered but ineffective.
        $tenancy = $registry->availability('thallo.tenancy');
        self::assertFalse($tenancy->available);
        self::assertStringContainsString('glueful/tenancy', (string) $tenancy->reason);
        self::assertFalse($registry->isEnabled('thallo.tenancy'));

        foreach (self::APP_OWNED as $id) {
            self::assertContains($id, $registered);
            self::assertTrue(
                $registry->availability($id)->available,
                "{$id} is app/library-owned — extension state never gates it"
            );
        }
    }

    public function testTheTwoCapabilityFeedsSplitEffectiveVersusEverything(): void
    {
        $registry = $this->container()->get(CapabilityRegistry::class);
        $controller = $this->container()->get(CapabilityAdminController::class);

        $effectiveIds = array_column(
            json_decode((string) $controller->index()->getContent(), true)['data']['capabilities'],
            'id'
        );
        foreach ($effectiveIds as $id) {
            self::assertTrue($registry->isEnabled($id), "{$id}: the auth-only feed lists only effective ids");
        }
        self::assertNotContains('thallo.tenancy', $effectiveIds);

        $manageRows = json_decode((string) $controller->manage()->getContent(), true)['data']['capabilities'];
        $manageIds = array_column($manageRows, 'id');
        foreach ($registry->all() as $capability) {
            self::assertContains($capability->id, $manageIds, 'the operator feed lists every registered id');
        }
        $tenancyRow = array_column($manageRows, null, 'id')['thallo.tenancy'] ?? null;
        self::assertNotNull($tenancyRow, 'ineffective capabilities appear in the operator feed');
        self::assertFalse($tenancyRow['available']);
        self::assertStringContainsString('glueful/tenancy', (string) $tenancyRow['reason']);
    }

    // ── Step 4: the ledger is canonical-only ─────────────────────────────────────

    public function testTheLedgerCarriesOnlyCanonicalSources(): void
    {
        // Beta.3's ledger is canonical from provision; the pre-manifest names cannot recur.
        $rows = $this->connection()->getPDO()->query(
            "SELECT DISTINCT source FROM migrations WHERE source = 'migrations' OR source LIKE 'thallo-%'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $rows, 'no pre-manifest ledger source may exist');

        $adoption = $this->container()->get(AdoptionService::class);
        foreach ($adoption->classify() as $source => $verdict) {
            self::assertSame(
                \Glueful\Extensions\Schema\AdoptionState::Ready,
                $verdict['state'],
                "{$source}: " . implode('; ', $verdict['reasons'])
            );
        }
    }

    // ── Step 5: fail-closed before a ledger exists ───────────────────────────────

    public function testAMissingLedgerFailsClosedWithoutThrowing(): void
    {
        $root = dirname(__DIR__, 3);
        $dbPath = sys_get_temp_dir() . '/acceptance-empty-' . uniqid('', true) . '.sqlite';
        try {
            $connection = new Connection(
                (new DatabaseConfig('sqlite', database: $dbPath))->toConnectionConfig()
            );
            $context = ApplicationContext::forTesting($root);
            $inventory = MigrationManagerFactory::inventory($context);

            // Readiness on an empty database: every descriptor is Pending, nothing throws.
            $readiness = new SchemaReadiness($connection, $inventory);
            foreach ($inventory->all() as $descriptor) {
                self::assertSame(ReadinessState::Pending, $readiness->classify($descriptor));
            }

            // The capability availability question during a pre-provision boot: unavailable
            // with the cause — never an exception out of provider boot.
            $resolver = new class (
                $this->appContext(),
                $connection,
                $inventory
            ) extends ExtensionCapabilityAvailabilityResolver {
                public function __construct(
                    ApplicationContext $context,
                    private readonly Connection $emptyDb,
                    private readonly DescriptorInventory $inv,
                ) {
                    parent::__construct($context);
                }

                protected function packageReadiness(string $package): array
                {
                    return (new SchemaReadiness($this->emptyDb, $this->inv))->forPackage($package);
                }
            };
            $verdict = $resolver->resolve(new Capability('thallo.accounts', owningPackage: 'glueful/users'));
            self::assertFalse($verdict->available, 'a pending owner schema fails closed');
        } finally {
            @unlink($dbPath);
        }
    }
}
