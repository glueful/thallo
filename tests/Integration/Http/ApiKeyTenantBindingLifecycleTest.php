<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Http\Controllers\ApiKeyAdminController;
use App\Http\DTOs\RotateApiKeyData;
use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Thallo\Tenancy\ApiKeyBinding\TenantApiKeyBindingRepository;

final class ApiKeyTenantBindingLifecycleTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->getPDO()->exec(
            'TRUNCATE TABLE thallo_tenant_api_key_bindings, api_keys, tenant_memberships, tenants CASCADE',
        );
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec(
            'TRUNCATE TABLE thallo_tenant_api_key_bindings, api_keys, tenant_memberships, tenants CASCADE',
        );
        parent::tearDown();
    }

    public function testRotationCopiesBindingAndRevocationRemovesIt(): void
    {
        $tenantUuid = 'tenantKeys01';
        $this->connection()->table('tenants')->insert([
            'uuid' => $tenantUuid,
            'slug' => 'tenant-keys',
            'name' => 'Tenant keys',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $created = ApiKeyService::create($this->appContext(), [
            'user_uuid' => 'keyOwner0001',
            'name' => 'Bound key',
            'scopes' => ['collections.products.read'],
        ]);
        $oldUuid = (string) $created['key']->uuid;
        $bindings = $this->container()->get(TenantApiKeyBindingRepository::class);
        $bindings->bind($oldUuid, $tenantUuid);

        $response = $this->container()->get(ApiKeyAdminController::class)
            ->rotate(new RotateApiKeyData(grace_hours: 1), $oldUuid);
        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        $newUuid = (string) ($payload['data']['api_key']['uuid'] ?? '');
        self::assertNotSame('', $newUuid);
        self::assertSame($tenantUuid, $bindings->tenantFor($newUuid));

        $destroyed = $this->container()->get(ApiKeyAdminController::class)->destroy($newUuid);
        self::assertSame(200, $destroyed->getStatusCode());
        self::assertNull($bindings->tenantFor($newUuid));
    }
}
