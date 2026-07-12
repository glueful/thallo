<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Thallo\Tenancy\System\SystemFlags;

final class CleanInstallIdentityPlaneTest extends AppTestCase
{
    public function testIdentityServicesExistWhileEnforcementIsOff(): void
    {
        self::assertTrue($this->container()->has(\Thallo\Tenancy\Tenant\SingleStoreTenant::class));
        self::assertFalse($this->container()->has(TenantContextRunner::class));
        self::assertFalse($this->container()->has(CurrentTenantResolver::class));
        self::assertTrue($this->connection()->getSchemaBuilder()->hasTable('tenants'));
        self::assertFalse($this->container()->get(SystemFlags::class)->tenancyEnabled());
        self::assertSame([], TenantTableRegistry::all());

        $row = Connection::applyInsertHooks('collection_definitions', ['name' => 'probe']);
        self::assertArrayNotHasKey('tenant_uuid', $row);
    }
}
