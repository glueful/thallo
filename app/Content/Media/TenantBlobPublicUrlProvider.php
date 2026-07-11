<?php

declare(strict_types=1);

namespace App\Content\Media;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Uploader\Contracts\BlobPublicUrlProvider;
use RuntimeException;
use Thallo\Tenancy\System\SystemFlags;

/** Derives a blob owner's canonical public origin without importing tenancy models. */
final class TenantBlobPublicUrlProvider implements BlobPublicUrlProvider
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly SystemFlags $flags,
        private readonly ?FullTenantResolutionReadiness $readiness,
        private readonly ?TenantAdministration $tenants,
        private readonly ?TenantDomainAdministration $domains,
    ) {
    }

    public function publicBaseUrl(array $blob): ?string
    {
        if ($this->readiness === null || !$this->readiness->isReady($this->context)) {
            return null;
        }
        if ($this->tenants === null || $this->domains === null) {
            throw new RuntimeException('Tenant administration contracts are unavailable.');
        }

        $blobUuid = is_string($blob['uuid'] ?? null) ? $blob['uuid'] : '';
        $owner = $blobUuid === '' ? null : $this->connection->table('media_assets')
            ->where('blob_uuid', $blobUuid)
            ->select(['tenant_uuid'])
            ->first();
        $tenantUuid = is_array($owner) ? (string) ($owner['tenant_uuid'] ?? '') : '';
        if ($tenantUuid === '') {
            throw new RuntimeException('Cannot derive a canonical origin for an ownerless blob.');
        }

        $scheme = (string) config($this->context, 'tenancy.public_origin.scheme', 'https');
        $default = $this->flags->defaultTenantUuid();
        if ($tenantUuid === $default) {
            $hosts = config($this->context, 'tenancy.public_origin.default_hosts', []);
            if (is_array($hosts) && is_string($hosts[0] ?? null) && $hosts[0] !== '') {
                return $scheme . '://' . $hosts[0];
            }
        }

        foreach ($this->domains->listDomains($this->context, $tenantUuid) as $domain) {
            if (
                ($domain['verification_status'] ?? '') === 'verified'
                && ($domain['status'] ?? '') === 'active'
                && is_string($domain['host'] ?? null)
            ) {
                return $scheme . '://' . $domain['host'];
            }
        }

        $tenant = $this->tenants->getTenant($this->context, $tenantUuid);
        $base = config($this->context, 'tenancy.public_origin.base_domain');
        if (
            $tenant !== null
            && ($tenant['status'] ?? '') === 'active'
            && is_string($base)
            && $base !== ''
        ) {
            return $scheme . '://' . $tenant['slug'] . '.' . $base;
        }

        throw new RuntimeException('Cannot derive the tenant blob canonical origin.');
    }
}
