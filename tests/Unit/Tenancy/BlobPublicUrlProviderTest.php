<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use App\Content\Media\TenantBlobPublicUrlProvider;
use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Thallo\Tenancy\System\SystemFlags;

final class BlobPublicUrlProviderTest extends AppTestCase
{
    public function testUnboundPreactivationContractsRetainTheRequestOrigin(): void
    {
        $context = $this->contextWithOrigin();
        $provider = new TenantBlobPublicUrlProvider(
            $context,
            $this->connection(),
            new SystemFlags($context),
            null,
            null,
            null,
        );

        self::assertNull($provider->publicBaseUrl(['uuid' => 'blob00000000']));
    }

    public function testDefaultTenantUsesFirstConfiguredHost(): void
    {
        $context = $this->contextWithOrigin();
        $flags = new SystemFlags($context);
        $flags->put('tenancy.default_tenant_uuid', 'tenant000001');
        $this->seedOwnedBlob('blob00000001', 'tenant000001');

        $provider = new TenantBlobPublicUrlProvider(
            $context,
            $this->connection(),
            $flags,
            $this->ready(),
            $this->createMock(TenantAdministration::class),
            $this->createMock(TenantDomainAdministration::class),
        );

        self::assertSame('https://sites.test', $provider->publicBaseUrl(['uuid' => 'blob00000001']));
    }

    public function testCustomDomainPrecedesSubdomainFallback(): void
    {
        $context = $this->contextWithOrigin();
        $flags = new SystemFlags($context);
        $flags->put('tenancy.default_tenant_uuid', 'tenant000001');
        $this->seedOwnedBlob('blob00000002', 'tenant000002');
        $domains = $this->createMock(TenantDomainAdministration::class);
        $domains->method('listDomains')->willReturn([[
            'uuid' => 'domain000001',
            'host' => 'www.customer.test',
            'verification_status' => 'verified',
            'status' => 'active',
        ]]);

        $provider = new TenantBlobPublicUrlProvider(
            $context,
            $this->connection(),
            $flags,
            $this->ready(),
            $this->createMock(TenantAdministration::class),
            $domains,
        );

        self::assertSame(
            'https://www.customer.test',
            $provider->publicBaseUrl(['uuid' => 'blob00000002'])
        );
    }

    private function contextWithOrigin(): ApplicationContext
    {
        $context = new ApplicationContext($this->appContext()->getBasePath(), 'testing');
        $context->setContainer($this->container());
        $context->mergeConfigDefaults('tenancy', [
            'public_origin' => [
                'scheme' => 'https',
                'base_domain' => 'sites.test',
                'default_hosts' => ['sites.test', 'www.sites.test'],
            ],
        ]);
        return $context;
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
