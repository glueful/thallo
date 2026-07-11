<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\DomainReverificationResult;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Tenancy\Events\DomainRevoked;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;
use Thallo\Tenancy\Cache\TenantHostCachePurger;
use Thallo\Tenancy\Http\Controllers\TenantDomainController;
use Thallo\Tenancy\Reverification\DomainReverificationAuditListener;

final class DomainReverifyRouteTest extends AppTestCase
{
    public function testOwnedDomainIsAuditedReverifiedAndCachePurged(): void
    {
        $domain = [
            'uuid' => 'domain000001',
            'tenant_uuid' => 'tenant000001',
            'host' => 'www.example.test',
            'verification_status' => 'verified',
            'status' => 'active',
            'last_checked_at' => null,
            'last_check_status' => null,
            'consecutive_failures' => 0,
        ];
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('getDomain')->willReturn($domain);
        $domains->expects(self::once())->method('reverifyDomain')->willReturn(
            new DomainReverificationResult('verified', 'verified', 'none', 0, '2026-07-11 12:00:00')
        );
        $resolver = $this->createMock(CurrentTenantResolver::class);
        $resolver->method('tenantUuid')->willReturn('tenant000001');
        $audit = $this->createMock(TenancyLifecycleAudit::class);
        $audit->expects(self::once())->method('record')->with(
            'domain.reverification_requested',
            'user00000001',
            'tenant000001',
            ['domain_uuid' => 'domain000001', 'host' => 'www.example.test']
        );
        $cache = $this->createMock(CacheStore::class);
        $cache->expects(self::exactly(2))->method('deletePattern');
        $cache->expects(self::once())->method('invalidateTags');
        $controller = new TenantDomainController(
            $this->appContext(),
            new TenantHostCachePurger($cache),
            $domains,
            $resolver,
            $audit,
        );
        $request = Request::create('/', 'POST');
        $request->attributes->set('user', ['uuid' => 'user00000001']);

        $response = $controller->reverify($request, 'domain000001');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('2026-07-11 12:00:00', (string) $response->getContent());
    }

    public function testAuditListenerUsesSystemActorAndNeverIncludesToken(): void
    {
        $audit = $this->createMock(TenancyLifecycleAudit::class);
        $audit->expects(self::once())->method('record')->with(
            'domain.revoked',
            null,
            'tenant000001',
            self::callback(static function (array $context): bool {
                return $context['domain_uuid'] === 'domain000001'
                    && $context['verification_status'] === 'revoked'
                    && !array_key_exists('token', $context)
                    && !array_key_exists('verification_token', $context);
            })
        );
        $listener = new DomainReverificationAuditListener($audit);

        $listener(new DomainRevoked(
            'domain000001',
            'tenant000001',
            'www.example.test',
            'mismatch',
            3,
            'revoked',
        ));
    }
}
