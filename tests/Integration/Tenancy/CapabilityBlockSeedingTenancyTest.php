<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Tenancy;

use Thallo\Core\Tests\Support\CapabilityBaseline;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\CapabilityBlockSeeder;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Thallo\Tenancy\Contracts\TenantSeedActivator;

/**
 * With workspaces on, a capability's blocks reach every workspace: preparation seeds each one from
 * the explicit contributions while the capability is still off; a workspace created while it is
 * preparing seeds them itself; a workspace created by a request that booted before the switch reads
 * the state fresh; and Retry drops a workspace that no longer exists.
 */
final class CapabilityBlockSeedingTenancyTest extends RetrofittedTenantTestCase
{
    protected function tearDown(): void
    {
        // Each test starts from an idle Commerce activation with no switch stored.
        $pdo = $this->connection()->getPDO();
        $pdo->exec(
            "UPDATE capability_activations SET status = 'idle', steps_done = '[]', owner_token = NULL,
               lease_expires_at = NULL, workspaces = '{}', result = '{}'
             WHERE capability = 'thallo.commerce'"
        );
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key = 'capability.thallo.commerce.enabled'");
        CapabilityBaseline::restore($this->connection()->getPDO());
        parent::tearDown();
    }

    private function seeder(): CapabilityBlockSeeder
    {
        return $this->container()->get(CapabilityBlockSeeder::class);
    }

    private function store(): ActivationStore
    {
        return $this->container()->get(ActivationStore::class);
    }

    private function states(): CapabilityStateStore
    {
        return $this->container()->get(CapabilityStateStore::class);
    }

    private function hasBlock(string $tenantUuid, string $slug): bool
    {
        return $this->runAsTenant(
            $tenantUuid,
            fn (): bool => $this->container()->get(BlockTypeRepository::class)->findBySlug($slug) !== null,
        );
    }

    /** As production: create the workspace, then seed its starters (TenantSeeder), in $container. */
    private function createWorkspaceWith(ContainerInterface $container, string $slug): string
    {
        $uuid = Utils::generateNanoID(12);
        $context = $container->get(ApplicationContext::class);
        $container->get(TenantProvisioner::class)
            ->provisionDefault($context, $uuid, $slug, ucfirst($slug), 'user00000001');
        $container->get(TenantSeedActivator::class)->seedAndActivate($uuid, 'user00000001');
        return $uuid;
    }

    /**
     * A fresh application booted now, as the harness boots one: its capability snapshot is taken
     * with Commerce stored off, so its registry (and its starter kinds) leave the shop blocks out.
     */
    private function bootWithCommerceStoredOff(): ContainerInterface
    {
        /** @var array{enabled: list<string>} $base */
        $base = require dirname(__DIR__, 3) . '/config/serviceproviders.php';
        $providers = [...$base['enabled'], 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider'];
        $container = self::bootAppWithConfigOverride('serviceproviders', ['enabled' => $providers])->getContainer();
        self::assertFalse(
            $container->get(CapabilityRegistry::class)->isEnabled('thallo.commerce'),
            'the fresh container decided Commerce off at boot',
        );
        return $container;
    }

    public function testPreparationSeedsEveryWorkspaceWhileTheCapabilityIsOff(): void
    {
        $this->states()->put('thallo.commerce', false);
        $created = $this->seeder()->seedAll('thallo.commerce');
        foreach ([self::$tenantAUuid, self::$tenantBUuid] as $tenant) {
            self::assertArrayHasKey($tenant, $created);
            self::assertTrue($this->hasBlock($tenant, 'product-grid'), $tenant);
        }
    }

    public function testAWorkspaceCreatedWhilePreparingGetsTheBlocks(): void
    {
        $this->store()->startOrJoin('thallo.commerce', 'test');       // stores Commerce off
        $booted = $this->bootWithCommerceStoredOff();
        $tenant = $this->createWorkspaceWith($booted, 'mid-prep-' . strtolower(Utils::generateNanoID(4)));
        self::assertTrue($this->hasBlock($tenant, 'product-grid'));
    }

    public function testAWorkspaceCreatedByARequestThatBootedBeforeTheSwitchReadsFreshState(): void
    {
        // This container's registry decided at boot (Commerce off): its starter kinds leave the
        // shop blocks out. The activation completes; the workspace created now still gets them.
        $gen = $this->store()->startOrJoin('thallo.commerce', 'test')->generation;
        $stale = $this->bootWithCommerceStoredOff();                   // booted before the switch
        $lease = $this->store()->acquire('thallo.commerce', $gen);
        self::assertNotNull($lease);
        $this->store()->withinFenced($lease, fn () => $this->states()->put('thallo.commerce', true));
        $tenant = $this->createWorkspaceWith($stale, 'after-switch-' . strtolower(Utils::generateNanoID(4)));
        self::assertTrue($this->hasBlock($tenant, 'product-grid'));
    }

    public function testRetryDropsAWorkspaceSuspendedSinceItFailed(): void
    {
        // A suspended workspace isn't one the seed reaches (only active ones are): Retry drops it
        // instead of failing on it until someone reactivates it.
        $gen = $this->store()->startOrJoin('thallo.commerce', 'test')->generation;
        $lease = $this->store()->acquire('thallo.commerce', $gen);
        self::assertNotNull($lease);
        $this->store()->markWorkspace($lease, self::$tenantBUuid, 'failed');
        $pdo = $this->connection()->getPDO();
        $pdo->prepare("UPDATE tenants SET status = 'suspended' WHERE uuid = ?")->execute([self::$tenantBUuid]);
        try {
            self::assertSame([], $this->seeder()->seedAll('thallo.commerce', $lease, [self::$tenantBUuid]));
        } finally {
            $pdo->prepare("UPDATE tenants SET status = 'active' WHERE uuid = ?")->execute([self::$tenantBUuid]);
        }
        self::assertArrayNotHasKey(self::$tenantBUuid, $this->store()->find('thallo.commerce')->workspaces);
    }

    public function testRetryDropsAWorkspaceDeletedSinceItFailed(): void
    {
        $gen = $this->store()->startOrJoin('thallo.commerce', 'test')->generation;
        $lease = $this->store()->acquire('thallo.commerce', $gen);
        self::assertNotNull($lease);
        $this->store()->markWorkspace($lease, 'gone00000001', 'failed');
        self::assertSame([], $this->seeder()->seedAll('thallo.commerce', $lease, ['gone00000001']));
        self::assertArrayNotHasKey('gone00000001', $this->store()->find('thallo.commerce')->workspaces);
    }
}
