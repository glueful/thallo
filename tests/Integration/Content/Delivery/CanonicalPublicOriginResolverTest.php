<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Delivery;

use App\Content\Delivery\ThalloCanonicalPublicOriginResolver;
use App\Content\Media\TenantBlobPublicUrlProvider;
use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 6 — the shared {@see \Thallo\Contracts\Delivery\CanonicalPublicOriginResolver} contract.
 * {@see ThalloCanonicalPublicOriginResolver::originForTenant()} reproduces, verbatim,
 * {@see TenantBlobPublicUrlProvider}'s pre-refactor host-selection precedence (default tenant ->
 * first configured default host; else first verified+active custom domain; else active tenant
 * slug + base domain; else throw). Exercised as a unit-style suite with a fake
 * {@see CurrentTenantResolver} (mirroring {@see \App\Tests\Integration\Commerce\TenantResolutionModesTest}'s
 * mode-c pattern) — no THALLO_TENANCY_DEV_LINK needed; every assertion here runs in the default
 * `composer test` suite.
 */
final class CanonicalPublicOriginResolverTest extends AppTestCase
{
    // --- currentOrigin(): single-store fallback (enforcement not active) -----------------------

    public function testSingleStoreFallsBackToTheConfiguredAppBaseUrl(): void
    {
        $context = $this->freshContext();
        $context->mergeConfigDefaults('app', ['urls' => ['base' => 'http://localhost']]);
        $resolver = $this->resolver($context, null, null, null);

        self::assertSame('http://localhost', $resolver->currentOrigin($context));
    }

    public function testSingleStoreBasePreservesAnExplicitNonDefaultPort(): void
    {
        $context = $this->freshContext();
        $context->mergeConfigDefaults('app', ['urls' => ['base' => 'http://localhost:8080']]);
        $resolver = $this->resolver($context, null, null, null);

        self::assertSame('http://localhost:8080', $resolver->currentOrigin($context));
    }

    public function testSingleStoreBasePreservesSchemeAndDropsPathAndTrailingSlash(): void
    {
        $context = $this->freshContext();
        $context->mergeConfigDefaults('app', ['urls' => ['base' => 'https://example.test/some/path/']]);
        $resolver = $this->resolver($context, null, null, null);

        self::assertSame('https://example.test', $resolver->currentOrigin($context));
    }

    public function testSingleStoreBaseOmitsTheDefaultPortWhenNoneIsConfigured(): void
    {
        $context = $this->freshContext();
        $context->mergeConfigDefaults('app', ['urls' => ['base' => 'https://example.test']]);
        $resolver = $this->resolver($context, null, null, null);

        self::assertSame('https://example.test', $resolver->currentOrigin($context));
    }

    public function testSingleStoreIgnoresAHostileRequestHost(): void
    {
        $context = $this->freshContext();
        $context->mergeConfigDefaults('app', ['urls' => ['base' => 'http://localhost']]);
        $resolver = $this->resolver($context, null, null, null);

        $this->withHostileHost(function () use ($resolver, $context): void {
            self::assertSame('http://localhost', $resolver->currentOrigin($context));
        });
    }

    // --- originForTenant(): host-selection precedence (pre-refactor media behavior) ------------

    public function testDefaultTenantUsesFirstConfiguredHost(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001');
        $resolver = $this->resolver(
            $context,
            $flags,
            $this->createMock(TenantAdministration::class),
            $this->createMock(TenantDomainAdministration::class),
        );

        self::assertSame('https://sites.test', $resolver->originForTenant($context, 'tenant000001'));
    }

    public function testVerifiedCustomDomainPrecedesSubdomainFallback(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001');
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('listDomains')->willReturn([[
            'uuid' => 'domain000001',
            'host' => 'www.customer.test',
            'verification_status' => 'verified',
            'status' => 'active',
        ]]);

        $resolver = $this->resolver($context, $flags, $this->createMock(TenantAdministration::class), $domains);

        self::assertSame('https://www.customer.test', $resolver->originForTenant($context, 'tenant000002'));
    }

    public function testUnverifiedOrInactiveDomainsAreSkippedInFavorOfSubdomainFallback(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001');
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('listDomains')->willReturn([
            ['uuid' => 'd1', 'host' => 'pending.test', 'verification_status' => 'pending', 'status' => 'active'],
            ['uuid' => 'd2', 'host' => 'disabled.test', 'verification_status' => 'verified', 'status' => 'disabled'],
        ]);
        $tenants = $this->createMock(TenantAdministration::class);
        $tenants->method('getTenant')->willReturn([
            'uuid' => 'tenant000003', 'slug' => 'acme', 'name' => 'Acme', 'status' => 'active',
        ]);

        $resolver = $this->resolver($context, $flags, $tenants, $domains);

        self::assertSame('https://acme.sites.test', $resolver->originForTenant($context, 'tenant000003'));
    }

    public function testTenantSubdomainFallbackUsesTheActiveTenantSlugAndBaseDomain(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001');
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('listDomains')->willReturn([]);
        $tenants = $this->createMock(TenantAdministration::class);
        $tenants->method('getTenant')->willReturn([
            'uuid' => 'tenant000003', 'slug' => 'acme', 'name' => 'Acme', 'status' => 'active',
        ]);

        $resolver = $this->resolver($context, $flags, $tenants, $domains);

        self::assertSame('https://acme.sites.test', $resolver->originForTenant($context, 'tenant000003'));
    }

    public function testNoTrustworthyOriginThrows(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001');
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('listDomains')->willReturn([]);
        $tenants = $this->createMock(TenantAdministration::class);
        $tenants->method('getTenant')->willReturn(null); // unknown/missing tenant

        $resolver = $this->resolver($context, $flags, $tenants, $domains);

        $this->expectException(\RuntimeException::class);
        $resolver->originForTenant($context, 'tenant000004');
    }

    public function testInactiveTenantWithNoDomainAlsoThrows(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001');
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('listDomains')->willReturn([]);
        $tenants = $this->createMock(TenantAdministration::class);
        $tenants->method('getTenant')->willReturn([
            'uuid' => 'tenant000005', 'slug' => 'suspended', 'name' => 'Suspended', 'status' => 'suspended',
        ]);

        $resolver = $this->resolver($context, $flags, $tenants, $domains);

        $this->expectException(\RuntimeException::class);
        $resolver->originForTenant($context, 'tenant000005');
    }

    public function testMissingTenantAdministrationContractsThrows(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001');
        $resolver = $this->resolver($context, $flags, null, null);

        $this->expectException(\RuntimeException::class);
        $resolver->originForTenant($context, 'tenant000001');
    }

    // --- currentOrigin(): enforcement active — delegates to CurrentTenantResolver --------------

    public function testEnforcedCurrentOriginDelegatesToTheDefaultTenantAndDefaultHost(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001', enforced: true);
        $resolver = $this->resolver(
            $context,
            $flags,
            $this->createMock(TenantAdministration::class),
            $this->createMock(TenantDomainAdministration::class),
            $this->fakeResolver('tenant000001'),
        );

        self::assertSame('https://sites.test', $resolver->currentOrigin($context));
        self::assertSame(
            $resolver->originForTenant($context, 'tenant000001'),
            $resolver->currentOrigin($context),
            'currentOrigin() and a direct originForTenant() call for the same tenant must agree',
        );
    }

    public function testCurrentOriginSelectsWhicheverTenantTheRequestBoundResolverReturns(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001', enforced: true);
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('listDomains')->willReturn([[
            'uuid' => 'domain000001',
            'host' => 'www.customer.test',
            'verification_status' => 'verified',
            'status' => 'active',
        ]]);

        $resolver = $this->resolver(
            $context,
            $flags,
            $this->createMock(TenantAdministration::class),
            $domains,
            $this->fakeResolver('tenant000002'), // NOT the default tenant
        );

        self::assertSame('https://www.customer.test', $resolver->currentOrigin($context));
    }

    public function testEnforcedCurrentOriginIgnoresAHostileRequestHost(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001', enforced: true);
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('listDomains')->willReturn([[
            'uuid' => 'domain000001',
            'host' => 'www.customer.test',
            'verification_status' => 'verified',
            'status' => 'active',
        ]]);
        $resolver = $this->resolver(
            $context,
            $flags,
            $this->createMock(TenantAdministration::class),
            $domains,
            $this->fakeResolver('tenant000002'),
        );

        $this->withHostileHost(function () use ($resolver, $context): void {
            self::assertSame('https://www.customer.test', $resolver->currentOrigin($context));
        });
    }

    public function testEnforcedCurrentOriginFailsClosedWhenNoResolverIsBound(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001', enforced: true);
        $resolver = $this->resolver(
            $context,
            $flags,
            $this->createMock(TenantAdministration::class),
            $this->createMock(TenantDomainAdministration::class),
            null,
        );

        $this->expectException(\RuntimeException::class);
        $resolver->currentOrigin($context);
    }

    public function testEnforcedCurrentOriginFailsClosedWhenTheResolverReturnsNoTenant(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001', enforced: true);
        $resolver = $this->resolver(
            $context,
            $flags,
            $this->createMock(TenantAdministration::class),
            $this->createMock(TenantDomainAdministration::class),
            $this->fakeResolver(''),
        );

        $this->expectException(\RuntimeException::class);
        $resolver->currentOrigin($context);
    }

    // --- TenantBlobPublicUrlProvider parity: owner lookup + originForTenant() agree exactly ----

    public function testProviderOwnerLookupAndDirectOriginForTenantCallsAgree(): void
    {
        $context = $this->contextWithOrigin();
        $flags = $this->flagsWith($context, defaultTenantUuid: 'tenant000001');
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('listDomains')->willReturn([[
            'uuid' => 'domain000001',
            'host' => 'www.customer.test',
            'verification_status' => 'verified',
            'status' => 'active',
        ]]);
        $tenants = $this->createMock(TenantAdministration::class);

        $this->seedOwnedBlob('blob00000009', 'tenant000002');

        $provider = new TenantBlobPublicUrlProvider(
            $context,
            $this->connection(),
            $flags,
            $this->ready(),
            $tenants,
            $domains,
        );
        $resolver = $this->resolver($context, $flags, $tenants, $domains);

        $viaProvider = $provider->publicBaseUrl(['uuid' => 'blob00000009']);
        $viaDirectContract = $resolver->originForTenant($context, 'tenant000002');

        self::assertSame('https://www.customer.test', $viaProvider);
        self::assertSame($viaDirectContract, $viaProvider);
    }

    // --- helpers ---------------------------------------------------------------------------

    private function resolver(
        ApplicationContext $context,
        ?SystemFlags $flags,
        ?TenantAdministration $tenants,
        ?TenantDomainAdministration $domains,
        ?CurrentTenantResolver $currentTenant = null,
    ): ThalloCanonicalPublicOriginResolver {
        return new ThalloCanonicalPublicOriginResolver(
            $flags ?? new SystemFlags($context),
            $currentTenant,
            $tenants,
            $domains,
        );
    }

    private function freshContext(): ApplicationContext
    {
        $context = new ApplicationContext($this->appContext()->getBasePath(), 'testing');
        $context->setContainer($this->container());
        return $context;
    }

    private function contextWithOrigin(): ApplicationContext
    {
        $context = $this->freshContext();
        $context->mergeConfigDefaults('tenancy', [
            'public_origin' => [
                'scheme' => 'https',
                'base_domain' => 'sites.test',
                'default_hosts' => ['sites.test', 'www.sites.test'],
            ],
        ]);
        return $context;
    }

    private function flagsWith(
        ApplicationContext $context,
        string $defaultTenantUuid,
        bool $enforced = false,
    ): SystemFlags {
        $flags = new SystemFlags($context);
        $flags->put('tenancy.default_tenant_uuid', $defaultTenantUuid);
        if ($enforced) {
            $flags->put('tenancy.enabled', '1');
            $flags->put('tenancy.enable_step', 'on');
        }
        return $flags;
    }

    private function fakeResolver(string $tenantUuid): CurrentTenantResolver
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

    private function ready(): FullTenantResolutionReadiness
    {
        return new class implements FullTenantResolutionReadiness {
            public function isReady(ApplicationContext $context): bool
            {
                return true;
            }
        };
    }

    /** Simulates a spoofed incoming Host header; proves the resolver never reads it. */
    private function withHostileHost(callable $fn): void
    {
        $previous = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'evil.attacker.test';
        try {
            $fn();
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $previous;
            }
        }
    }

    private function seedOwnedBlob(string $blobUuid, string $tenantUuid): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $blobUuid,
            'name' => $blobUuid . '.png',
            'mime_type' => 'image/png',
            'size' => 1,
            'url' => 'uploads/' . $blobUuid . '.png',
            'storage_type' => 'uploads',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->connection()->table('media_assets')->insert([
            'blob_uuid' => $blobUuid,
            'tenant_uuid' => $tenantUuid,
        ]);
    }
}
