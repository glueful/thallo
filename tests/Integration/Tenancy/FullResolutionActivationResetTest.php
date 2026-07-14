<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Extensions\Contracts\Tenancy\DomainReverificationResult;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Thallo\Tenancy\Enablement\EnablementException;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\PublicOrigin\PublicOriginService;
use Thallo\Tenancy\PublicOrigin\PublicOriginStore;
use Thallo\Tenancy\Resolution\FullResolutionActivation;
use Thallo\Tenancy\Resolution\ResolutionActivationStep;
use Thallo\Tenancy\Resolution\ResolutionActivationStore;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 6 — the origin-revision gate (Pin 1) and failed-state recovery. A stale public origin makes
 * advance()/retry() refuse WITHOUT recording a failure (step untouched); resetFailed() releases only
 * the configured required-host mappings from the default tenant and returns the machine to INACTIVE,
 * and any cleanup failure leaves it FAILED. The container factory must wire the real PublicOriginStore
 * so the gate is live in production, not only under direct construction.
 */
final class FullResolutionActivationResetTest extends AppTestCase
{
    private const DEFAULT_TENANT = 'ten000000001';

    private SystemFlags $flags;
    private ResolutionActivationStore $store;
    /** @var object{domains: list<array{uuid:string,host:string}>, throwOnRelease: bool} */
    private object $fakeDomains;
    /** @var list<string> */
    private array $requiredHosts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->flags = $this->container()->get(SystemFlags::class);
        $this->store = new ResolutionActivationStore($this->flags, $this->connection());
        $this->fakeDomains = $this->makeFakeDomains();
        $this->requiredHosts = [];
    }

    public function testAdvanceRefusesWhenOriginStaleAndLeavesStepUnchanged(): void
    {
        $activation = $this->makeActivation(originStale: true);
        try {
            $activation->advance();
            self::fail('expected restart-required exception');
        } catch (EnablementException $exception) {
            self::assertStringContainsString('restart required', $exception->getMessage());
        }
        self::assertSame(ResolutionActivationStep::INACTIVE, $this->store->step());
        self::assertNull($this->store->failure());
        self::assertTrue($activation->status()['origin_restart_required']);
    }

    public function testResetFailedReleasesOnlyConfiguredRequiredHostsAndReturnsInactive(): void
    {
        $this->configureHosts(['apex.example']);
        // default tenant has two domains: the required one + an unrelated one
        $this->seedDefaultTenantDomains(['apex.example', 'other.example']);
        $this->forceFailedState();
        $status = $this->makeActivation()->resetFailed();
        self::assertSame('inactive', $status['step']);
        self::assertSame(['other.example'], $this->remainingDefaultTenantHosts()); // apex released, other kept
    }

    public function testResetFailedStaysFailedWhenCleanupThrows(): void
    {
        $this->configureHosts(['apex.example']);
        $activation = $this->makeActivation(domainsThatThrowOnRelease: true);
        $this->forceFailedState();
        try {
            $activation->resetFailed();
            self::fail('expected failure');
        } catch (\Throwable) {
        }
        self::assertSame('failed', $this->store->step()->value);
    }

    public function testFactoryWiresOriginSoStatusReportsRestartRequired(): void
    {
        // A null origin would always report false; a fresh revision proving stale confirms the
        // factory passed the real shared PublicOriginStore into the container-built activation.
        $activation = $this->container()->get(FullResolutionActivation::class);
        $this->flags->put('tenancy.public_origin.revision', bin2hex(random_bytes(16)));
        self::assertTrue($activation->status()['origin_restart_required']);
    }

    public function testServiceWriteMakesActivationRefuseStaleOriginWithoutChangingStep(): void
    {
        // Build the activation FIRST (its origin captures the current revision), then a real service
        // write bumps the revision — advance() must refuse and leave the step INACTIVE (write-first).
        $activation = $this->makeActivation();
        $this->makeService()->save('apex.example', ['apex.example']);
        try {
            $activation->advance();
            self::fail('expected restart-required exception');
        } catch (EnablementException) {
        }
        self::assertSame(ResolutionActivationStep::INACTIVE, $this->store->step());
    }

    // --- harness ------------------------------------------------------------------------------

    private function configureHosts(array $hosts): void
    {
        $this->requiredHosts = $hosts;
    }

    /** @param list<string> $hosts */
    private function seedDefaultTenantDomains(array $hosts): void
    {
        $this->flags->put('tenancy.default_tenant_uuid', self::DEFAULT_TENANT);
        $i = 0;
        foreach ($hosts as $host) {
            $uuid = 'dom' . str_pad((string) ++$i, 9, '0', STR_PAD_LEFT);
            $this->fakeDomains->domains[] = ['uuid' => $uuid, 'host' => $host];
        }
    }

    private function forceFailedState(): void
    {
        $this->store->recordFailure(ResolutionActivationStep::MAPPING_HOSTS, 'boom');
    }

    /** @return list<string> */
    private function remainingDefaultTenantHosts(): array
    {
        return array_values(array_map(static fn (array $d): string => $d['host'], $this->fakeDomains->domains));
    }

    private function makeActivation(
        bool $originStale = false,
        bool $domainsThatThrowOnRelease = false,
    ): FullResolutionActivation {
        $context = $this->freshContext(
            ['tenancy' => ['public_origin' => ['default_hosts' => $this->requiredHosts]]]
        );
        $origin = new PublicOriginStore($context, $this->flags, $this->connection());
        if ($originStale) {
            $this->flags->put('tenancy.public_origin.revision', bin2hex(random_bytes(16)));
        }
        if ($domainsThatThrowOnRelease) {
            $this->flags->put('tenancy.default_tenant_uuid', self::DEFAULT_TENANT);
            if ($this->fakeDomains->domains === []) {
                $this->fakeDomains->domains[] = ['uuid' => 'dom000000001', 'host' => 'apex.example'];
            }
            $this->fakeDomains->throwOnRelease = true;
        }

        return new FullResolutionActivation(
            $context,
            $this->store,
            new EnablementLock($this->connection()),
            $this->flags,
            $this->domainsAdmin(),
            null,
            $this->container()->get(TenantRuntimeReadiness::class),
            null,
            $origin,
        );
    }

    private function makeService(): PublicOriginService
    {
        $context = $this->freshContext(
            ['tenancy' => ['public_origin' => ['reserved_labels' => ['www', 'api', 'admin']]]]
        );
        $store = new PublicOriginStore($context, $this->flags, $this->connection());
        $store->hydrate();

        return new PublicOriginService($context, $store, $this->store, new EnablementLock($this->connection()));
    }

    /** @param array<string,mixed> $file */
    private function freshContext(array $file): ApplicationContext
    {
        $loader = new class ($file) extends ConfigurationLoader {
            /** @param array<string,mixed> $file */
            public function __construct(private readonly array $file)
            {
            }

            public function loadConfig(string $name): array
            {
                return $this->file[$name] ?? [];
            }
        };
        $context = new ApplicationContext('/tmp/glueful-activation-reset-test', 'testing');
        $context->setConfigLoader($loader);
        $context->setContainer($this->container());

        return $context;
    }

    private function makeFakeDomains(): object
    {
        return new class {
            /** @var list<array{uuid:string,host:string}> */
            public array $domains = [];
            public bool $throwOnRelease = false;
        };
    }

    private function domainsAdmin(): TenantDomainAdministration
    {
        return new class ($this->fakeDomains) implements TenantDomainAdministration {
            public function __construct(private readonly object $state)
            {
            }

            public function listDomains(ApplicationContext $c, string $tenantUuid): array
            {
                return array_map(
                    static fn (array $d): array => [
                        'uuid' => $d['uuid'],
                        'host' => $d['host'],
                        'verification_status' => 'verified',
                        'status' => 'enabled',
                        'last_checked_at' => null,
                        'last_check_status' => null,
                        'consecutive_failures' => 0,
                    ],
                    $this->state->domains,
                );
            }

            public function releaseDomain(ApplicationContext $c, string $domainUuid): void
            {
                if ($this->state->throwOnRelease) {
                    throw new \RuntimeException('release blew up');
                }
                $this->state->domains = array_values(array_filter(
                    $this->state->domains,
                    static fn (array $d): bool => $d['uuid'] !== $domainUuid,
                ));
            }

            public function addDomain(ApplicationContext $c, string $tenantUuid, string $host): array
            {
                throw new \LogicException('unused');
            }

            public function verifyDomain(ApplicationContext $c, string $domainUuid): string
            {
                throw new \LogicException('unused');
            }

            public function reverifyDomain(ApplicationContext $c, string $domainUuid): DomainReverificationResult
            {
                throw new \LogicException('unused');
            }

            public function disableDomain(ApplicationContext $c, string $domainUuid): void
            {
                throw new \LogicException('unused');
            }

            public function enableDomain(ApplicationContext $c, string $domainUuid): void
            {
                throw new \LogicException('unused');
            }

            public function removeDomain(ApplicationContext $c, string $domainUuid): void
            {
                throw new \LogicException('unused');
            }

            public function overrideCooldownAndClaim(ApplicationContext $c, string $tenantUuid, string $host): array
            {
                throw new \LogicException('unused');
            }

            public function getDomain(ApplicationContext $c, string $domainUuid): ?array
            {
                throw new \LogicException('unused');
            }

            public function addPreverifiedDomain(ApplicationContext $c, string $tenantUuid, string $host): string
            {
                throw new \LogicException('unused');
            }
        };
    }
}
