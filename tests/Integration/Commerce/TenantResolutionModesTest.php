<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Commerce\Catalog\CatalogReader;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;
use Thallo\Commerce\Tenancy\ThalloCommerceTenantResolution;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 7: the pack's three-mode binding of Commerce's `CommerceTenantResolution` seam.
 *
 * Modes (a) clean-install and (b) widened-default are driven entirely by
 * {@see SystemFlags} and need no glueful/tenancy plumbing, so they run unconditionally
 * against the container-bound seam (proving `CommerceIntegrationServiceProvider::services()`
 * really wires it, outside the `thallo.commerce` capability gate).
 *
 * Mode (c) enforcement's DELEGATION behavior (per-call, never latched) is proven against a
 * hand-built instance with a fake {@see CurrentTenantResolver}, independent of whether
 * glueful/tenancy's enforcement provider is actually booted in this process -- the default
 * test environment strips it (see config/testing/extensions.php), so `CurrentTenantResolver`
 * is not container-bound unless THALLO_TENANCY_DEV_LINK=1. A full container-wired,
 * request-resolved end-to-end proof of mode (c) is included but self-skips without that
 * flag, mirroring TenantOracleTestCase's established opt-in gate.
 */
final class TenantResolutionModesTest extends AppTestCase
{
    /** @var list<string> tenant uuids this test created, cleaned up in tearDown */
    private array $seededTenants = [];

    protected function setUp(): void
    {
        parent::setUp();
        // AppTestCase::setUp() already truncates thallo_system_flags and clears the SystemFlags
        // cache; commerce_products/tenants are not in its managed table list, so this suite owns
        // its own cleanup (mirrors TenantOracleTestCase::seedTenants()'s pattern).
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        $this->seededTenants = [];
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        if ($this->seededTenants !== []) {
            $placeholders = implode(',', array_fill(0, count($this->seededTenants), '?'));
            $statement = $this->connection()->getPDO()
                ->prepare("DELETE FROM tenants WHERE uuid IN ({$placeholders})");
            $statement->execute($this->seededTenants);
        }
        parent::tearDown();
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    /** The pack's binding, resolved through the real container -- proves services() wired it. */
    private function boundSeam(): ThalloCommerceTenantResolution
    {
        $seam = $this->container()->get(CommerceTenantResolution::class);
        self::assertInstanceOf(ThalloCommerceTenantResolution::class, $seam);

        return $seam;
    }

    // --- Mode (a): clean install --------------------------------------------------------------

    public function testModeACleanInstallResolvesToSentinel(): void
    {
        self::assertSame('', $this->boundSeam()->tenantUuid($this->appContext()));
    }

    // --- Mode (b): widened schema ---------------------------------------------------------------

    public function testModeBWidenedWithDefaultTenantResolvesToTheDefault(): void
    {
        $tenant = $this->seedTenant('widened-default');
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', $tenant);

        self::assertSame($tenant, $this->boundSeam()->tenantUuid($this->appContext()));
    }

    public function testModeBWidenedWithoutDefaultTenantFailsClosed(): void
    {
        $this->flags()->put('tenancy.schema_state', 'widened');
        // default_tenant_uuid deliberately left unset.

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Widened tenancy schema without a persisted default tenant.');
        $this->boundSeam()->tenantUuid($this->appContext());
    }

    public function testModeBWidenedWithEmptyStringDefaultAlsoFailsClosed(): void
    {
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', '');

        $this->expectException(\RuntimeException::class);
        $this->boundSeam()->tenantUuid($this->appContext());
    }

    // --- No latch: flag flips mid-process affect only the NEXT call --------------------------

    public function testFlippingSchemaStateAndDefaultMidProcessChangesTheNextCallOnTheSameSeamInstance(): void
    {
        $seam = $this->boundSeam();

        self::assertSame('', $seam->tenantUuid($this->appContext()), 'starts clean (mode a)');

        $tenantOne = $this->seedTenant('flip-one');
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', $tenantOne);
        self::assertSame(
            $tenantOne,
            $seam->tenantUuid($this->appContext()),
            'the SAME seam instance reflects the flip to mode (b) on the next call',
        );

        $tenantTwo = $this->seedTenant('flip-two');
        $this->flags()->put('tenancy.default_tenant_uuid', $tenantTwo);
        self::assertSame(
            $tenantTwo,
            $seam->tenantUuid($this->appContext()),
            'changing the default tenant while still widened is reflected immediately (no latch)',
        );

        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        self::assertSame('', $seam->tenantUuid($this->appContext()), 'flipping back to clean is reflected too');
    }

    // --- Mode (c): enforcement active — delegation behavior (fake resolver, no dev-link needed) -

    public function testModeCEnforcementActiveDelegatesToTheSharedResolver(): void
    {
        $resolver = $this->fakeResolver('tenC0000001');
        $seam = new ThalloCommerceTenantResolution($this->flags(), $this->fakeContainer($resolver));

        $this->flags()->put('tenancy.enabled', '1');
        $this->flags()->put('tenancy.enable_step', 'on');

        self::assertSame('tenC0000001', $seam->tenantUuid($this->appContext()));
    }

    public function testModeCNeverLatchesTheDelegatedTenantAcrossCalls(): void
    {
        $resolver = $this->fakeResolver('tenC0000001');
        $seam = new ThalloCommerceTenantResolution($this->flags(), $this->fakeContainer($resolver));
        $this->flags()->put('tenancy.enabled', '1');
        $this->flags()->put('tenancy.enable_step', 'on');

        self::assertSame('tenC0000001', $seam->tenantUuid($this->appContext()));

        // The DELEGATE's own request-scoped answer changes between calls (e.g. a different
        // request resolved a different tenant) — the seam must reflect it immediately, proving
        // it re-reads the resolver on every call rather than caching the first tenant string.
        $resolver->current = 'tenC0000002';
        self::assertSame('tenC0000002', $seam->tenantUuid($this->appContext()));
    }

    public function testModeCTakesPriorityOverWidenedDefaultWhenBothConditionsHold(): void
    {
        $resolver = $this->fakeResolver('tenPriority1');
        $seam = new ThalloCommerceTenantResolution($this->flags(), $this->fakeContainer($resolver));

        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', 'tenDefault01');
        $this->flags()->put('tenancy.enabled', '1');
        $this->flags()->put('tenancy.enable_step', 'on');

        self::assertSame(
            'tenPriority1',
            $seam->tenantUuid($this->appContext()),
            'enforcement (mode c) must win over widened+default (mode b) when both are true',
        );
    }

    public function testModeCFailsClosedWhenEnforcementIsActiveButNoResolverIsBound(): void
    {
        $seam = new ThalloCommerceTenantResolution($this->flags(), $this->fakeContainer(null));
        $this->flags()->put('tenancy.enabled', '1');
        $this->flags()->put('tenancy.enable_step', 'on');

        $this->expectException(\RuntimeException::class);
        $seam->tenantUuid($this->appContext());
    }

    // --- End-to-end: a real commerce service read lands in the mode's tenant -----------------

    public function testEndToEndCatalogReaderReadLandsInModeASentinelTenant(): void
    {
        $sentinelProduct = $this->seedProduct('', 'sentinel-widget');
        $otherProduct = $this->seedProduct($this->seedTenant('mode-a-other'), 'other-widget');

        $resolvedTenant = $this->boundSeam()->tenantUuid($this->appContext());
        self::assertSame('', $resolvedTenant);

        $reader = $this->container()->get(CatalogReader::class);
        $found = $reader->findLiveProduct($this->appContext(), $resolvedTenant, $sentinelProduct);
        self::assertNotNull($found, 'the sentinel-tenant product must be visible under mode (a)');
        self::assertSame('sentinel-widget', $found['slug']);

        // Isolation: the OTHER tenant's product must not be reachable through the sentinel tenant.
        self::assertNull($reader->findLiveProduct($this->appContext(), $resolvedTenant, $otherProduct));
    }

    public function testEndToEndCatalogReaderReadLandsInModeBDefaultTenant(): void
    {
        $tenantA = $this->seedTenant('mode-b-default');
        $tenantB = $this->seedTenant('mode-b-other');
        $productA = $this->seedProduct($tenantA, 'default-tenant-widget');
        $productB = $this->seedProduct($tenantB, 'other-tenant-widget');

        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', $tenantA);

        $resolvedTenant = $this->boundSeam()->tenantUuid($this->appContext());
        self::assertSame($tenantA, $resolvedTenant);

        $reader = $this->container()->get(CatalogReader::class);
        $found = $reader->findLiveProduct($this->appContext(), $resolvedTenant, $productA);
        self::assertNotNull($found, 'the default tenant product must be visible under mode (b)');
        self::assertSame('default-tenant-widget', $found['slug']);

        // Isolation: mode (b) must land on tenant A's row, never tenant B's.
        self::assertNull($reader->findLiveProduct($this->appContext(), $resolvedTenant, $productB));
    }

    public function testEndToEndCatalogReaderReadLandsInModeCEnforcedTenant(): void
    {
        if (!$this->container()->has(CurrentTenantResolver::class)) {
            self::markTestSkipped(
                'Enforcement provider not bound in this test env (default suite strips '
                . 'glueful/tenancy — see config/testing/extensions.php). Re-run with '
                . 'THALLO_TENANCY_DEV_LINK=1 to exercise the real request-resolved delegation path.'
            );
        }

        $tenantC = $this->seedTenant('mode-c-enforced');
        $tenantD = $this->seedTenant('mode-c-other');
        $productC = $this->seedProduct($tenantC, 'enforced-tenant-widget');
        $productD = $this->seedProduct($tenantD, 'other-enforced-widget');

        $this->flags()->put('tenancy.enabled', '1');
        $this->flags()->put('tenancy.enable_step', 'on');

        $runner = $this->container()->get(TenantContextRunner::class);
        $result = $runner->runAsTenant($tenantC, function () use ($productC, $productD): array {
            $resolvedTenant = $this->boundSeam()->tenantUuid($this->appContext());
            $reader = $this->container()->get(CatalogReader::class);

            return [
                'resolvedTenant' => $resolvedTenant,
                'own' => $reader->findLiveProduct($this->appContext(), $resolvedTenant, $productC),
                'other' => $reader->findLiveProduct($this->appContext(), $resolvedTenant, $productD),
            ];
        });

        self::assertSame($tenantC, $result['resolvedTenant']);
        self::assertNotNull($result['own'], 'the request-resolved tenant\'s own product must be visible');
        self::assertSame('enforced-tenant-widget', $result['own']['slug']);
        self::assertNull($result['other'], 'the OTHER tenant\'s product must not leak through');
    }

    // --- helpers -------------------------------------------------------------------------------

    private function seedTenant(string $slugSuffix): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => 'trm-' . $slugSuffix . '-' . substr($uuid, 0, 6),
            'name' => 'TRM ' . $slugSuffix,
            'status' => 'active',
        ]);
        $this->seededTenants[] = $uuid;

        return $uuid;
    }

    private function seedProduct(string $tenantUuid, string $slug): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->getPDO()->prepare(
            'INSERT INTO commerce_products (uuid, tenant_uuid, slug, name, status) '
            . 'VALUES (?, ?, ?, ?, ?)'
        )->execute([$uuid, $tenantUuid, $slug, ucfirst(str_replace('-', ' ', $slug)), 'active']);

        return $uuid;
    }

    private function fakeResolver(string $initial): object
    {
        return new class ($initial) implements CurrentTenantResolver {
            public function __construct(public string $current)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->current;
            }
        };
    }

    private function fakeContainer(?CurrentTenantResolver $resolver): ContainerInterface
    {
        return new class ($resolver) implements ContainerInterface {
            public function __construct(private readonly ?CurrentTenantResolver $resolver)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === CurrentTenantResolver::class && $this->resolver !== null) {
                    return $this->resolver;
                }

                throw new class ('not found') extends \Exception implements
                    \Psr\Container\NotFoundExceptionInterface
                {
                };
            }

            public function has(string $id): bool
            {
                return $id === CurrentTenantResolver::class && $this->resolver !== null;
            }
        };
    }
}
