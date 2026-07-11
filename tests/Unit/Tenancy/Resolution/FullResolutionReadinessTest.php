<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Resolution;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Thallo\Tenancy\Resolution\ThalloFullResolutionReadiness;
use Thallo\Tenancy\System\SystemFlags;

final class FullResolutionReadinessTest extends AppTestCase
{
    public function testRequiresEnabledFlagDefaultTenantAndEveryRequiredHost(): void
    {
        $context = $this->contextWithHosts(['sites.test', 'www.sites.test']);
        $flags = new SystemFlags($context);
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('listDomains')->willReturn([
            [
                'uuid' => 'domain000001',
                'host' => 'sites.test',
                'verification_status' => 'verified',
                'status' => 'active',
            ],
            [
                'uuid' => 'domain000002',
                'host' => 'www.sites.test',
                'verification_status' => 'verified',
                'status' => 'active',
            ],
        ]);
        $readiness = new ThalloFullResolutionReadiness($flags, $domains);

        self::assertFalse($readiness->isReady($context));
        $flags->put('tenancy.enabled', '1');
        $flags->put('tenancy.default_tenant_uuid', 'tenant000001');
        $flags->put('tenancy.resolution', 'full');
        self::assertTrue($readiness->isReady($context));
    }

    public function testMissingRequiredHostFailsClosed(): void
    {
        $context = $this->contextWithHosts(['sites.test', 'www.sites.test']);
        $flags = new SystemFlags($context);
        $flags->put('tenancy.enabled', '1');
        $flags->put('tenancy.default_tenant_uuid', 'tenant000001');
        $flags->put('tenancy.resolution', 'full');
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('listDomains')->willReturn([[
            'uuid' => 'domain000001',
            'host' => 'sites.test',
            'verification_status' => 'verified',
            'status' => 'active',
        ]]);

        self::assertFalse((new ThalloFullResolutionReadiness($flags, $domains))->isReady($context));
    }

    /** @param list<string> $hosts */
    private function contextWithHosts(array $hosts): ApplicationContext
    {
        $context = new ApplicationContext($this->appContext()->getBasePath(), 'testing');
        $context->setContainer($this->container());
        $context->mergeConfigDefaults('tenancy', [
            'public_origin' => ['default_hosts' => $hosts],
        ]);

        return $context;
    }
}
