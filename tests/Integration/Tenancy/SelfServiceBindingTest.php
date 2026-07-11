<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Contracts\Tenancy\DomainReverificationResult;
use Thallo\Tenancy\Cache\TenantHostCachePurger;
use Thallo\Tenancy\Http\Controllers\TenantDomainController;
use Thallo\Tenancy\Http\Controllers\TenantMembershipController;

final class SelfServiceBindingTest extends AppTestCase
{
    public function testTenantRouteMustMatchResolvedTenantBeforeServiceUse(): void
    {
        $resolver = $this->resolver('tenant000001');
        $controller = new TenantMembershipController($this->appContext(), null, $resolver);

        self::assertSame(403, $controller->index('tenant000002')->getStatusCode());
        self::assertSame(503, $controller->index('tenant000001')->getStatusCode());
    }

    public function testForeignAndUnknownDomainUseTheSameNonRevealingResponse(): void
    {
        $controller = new TenantDomainController(
            $this->appContext(),
            $this->container()->get(TenantHostCachePurger::class),
            $this->domains('tenant000002'),
            $this->resolver('tenant000001'),
        );

        $foreign = $controller->verify('domain000001');
        $unknown = $controller->verify('missing000001');
        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame($unknown->getContent(), $foreign->getContent());
    }

    private function resolver(string $tenantUuid): CurrentTenantResolver
    {
        return new class ($tenantUuid) implements CurrentTenantResolver {
            public function __construct(private readonly string $tenantUuid)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->tenantUuid;
            }
        };
    }

    private function domains(string $ownerUuid): TenantDomainAdministration
    {
        return new class ($ownerUuid) implements TenantDomainAdministration {
            public function __construct(private readonly string $ownerUuid)
            {
            }

            public function addDomain(ApplicationContext $c, string $tenantUuid, string $host): array
            {
                return ['uuid' => 'domain000001', 'token' => 'token'];
            }

            public function verifyDomain(ApplicationContext $c, string $domainUuid): string
            {
                return 'verified';
            }

            public function reverifyDomain(
                ApplicationContext $c,
                string $domainUuid
            ): DomainReverificationResult {
                return new DomainReverificationResult('verified', 'verified', 'none', 0, 'now');
            }

            public function disableDomain(ApplicationContext $c, string $domainUuid): void
            {
            }

            public function enableDomain(ApplicationContext $c, string $domainUuid): void
            {
            }

            public function removeDomain(ApplicationContext $c, string $domainUuid): void
            {
            }

            public function releaseDomain(ApplicationContext $c, string $domainUuid): void
            {
            }

            public function overrideCooldownAndClaim(
                ApplicationContext $c,
                string $tenantUuid,
                string $host,
            ): array {
                return ['uuid' => 'domain000001', 'token' => 'token'];
            }

            public function listDomains(ApplicationContext $c, string $tenantUuid): array
            {
                return [];
            }

            public function getDomain(ApplicationContext $c, string $domainUuid): ?array
            {
                if ($domainUuid !== 'domain000001') {
                    return null;
                }
                return [
                    'uuid' => $domainUuid,
                    'tenant_uuid' => $this->ownerUuid,
                    'host' => 'foreign.test',
                    'verification_status' => 'pending',
                    'status' => 'disabled',
                    'last_checked_at' => null,
                    'last_check_status' => null,
                    'consecutive_failures' => 0,
                ];
            }

            public function addPreverifiedDomain(
                ApplicationContext $c,
                string $tenantUuid,
                string $host,
            ): string {
                return 'domain000001';
            }
        };
    }
}
