<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\CapabilityBlockSeeder;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Tests\Support\CapabilityBaseline;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;
use Thallo\Tenancy\Contracts\TenantSeedActivator;

/**
 * With workspaces on, a third-party capability's blocks reach every workspace exactly as Thallo's
 * own do (feature activation spec §7): acme/bookings' activation seeds each workspace while it is
 * still off, and a workspace created while it is preparing seeds them itself.
 */
final class ThirdPartyActivationTenancyTest extends RetrofittedTenantTestCase
{
    private const ID = 'acme.bookings';
    private const PROVIDER = 'Acme\\Bookings\\BookingsServiceProvider';
    private const BLOCK = 'booking_form';

    private static ?ContainerInterface $withEngine = null;

    protected function tearDown(): void
    {
        $pdo = $this->connection()->getPDO();
        $pdo->prepare('DELETE FROM capability_activation_events WHERE capability = ?')->execute([self::ID]);
        $pdo->prepare('DELETE FROM capability_activations WHERE capability = ?')->execute([self::ID]);
        $pdo->prepare('DELETE FROM thallo_system_flags WHERE key = ?')
            ->execute([CapabilityStateStore::PREFIX . self::ID . '.enabled']);
        CapabilityBaseline::restore($pdo);
        parent::tearDown();
    }

    /** An application booted with workspaces and the Bookings engine, as the activation's continue is. */
    private static function withEngine(): ContainerInterface
    {
        if (self::$withEngine === null) {
            /** @var array{enabled: list<string>} $base */
            $base = require dirname(__DIR__, 3) . '/config/serviceproviders.php';
            $providers = [...$base['enabled'], 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider', self::PROVIDER];
            self::$withEngine = self::bootAppWithConfigOverride('serviceproviders', ['enabled' => $providers])
                ->getContainer();
        }
        return self::$withEngine;
    }

    private function hasBlock(string $tenantUuid): bool
    {
        return $this->runAsTenant(
            $tenantUuid,
            fn (): bool => $this->container()->get(BlockTypeRepository::class)->findBySlug(self::BLOCK) !== null,
        );
    }

    /** As production: create the workspace, then seed its starters, in $container. */
    private function createWorkspaceWith(ContainerInterface $container, string $slug): string
    {
        $uuid = Utils::generateNanoID(12);
        $context = $container->get(ApplicationContext::class);
        $container->get(TenantProvisioner::class)
            ->provisionDefault($context, $uuid, $slug, ucfirst($slug), 'user00000001');
        $container->get(TenantSeedActivator::class)->seedAndActivate($uuid, 'user00000001');
        return $uuid;
    }

    public function testEveryWorkspaceGetsItsBlocks(): void
    {
        $this->container()->get(CapabilityStateStore::class)->put(self::ID, false);
        $created = self::withEngine()->get(CapabilityBlockSeeder::class)->seedAll(self::ID);
        foreach ([self::$tenantAUuid, self::$tenantBUuid] as $tenant) {
            self::assertArrayHasKey($tenant, $created);
            self::assertTrue($this->hasBlock($tenant), $tenant);
        }
    }

    public function testAWorkspaceCreatedWhilePreparingGetsThem(): void
    {
        $store = $this->container()->get(ActivationStore::class);
        $store->initializeRow(self::ID);                                // the turn-on creates the row
        $store->startOrJoin(self::ID, 'test');                          // stores it off, preparing
        $tenant = $this->createWorkspaceWith(
            self::withEngine(),
            'mid-prep-' . strtolower(Utils::generateNanoID(4)),
        );
        self::assertTrue($this->hasBlock($tenant));
    }
}
