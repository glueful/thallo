<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\ApiKeyBinding\TenantApiKeyBindingRepository;

final class TenantApiKeyBindingTest extends AppTestCase
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

    public function testBindingCopyAndUnbindLifecycle(): void
    {
        $tenant = 'tenantBind01';
        $this->seedTenant($tenant);
        $this->seedKey('apiKeyBind01');
        $this->seedKey('apiKeyBind02');
        $repo = $this->container()->get(TenantApiKeyBindingRepository::class);

        $repo->bind('apiKeyBind01', $tenant);
        self::assertSame($tenant, $repo->tenantFor('apiKeyBind01'));
        $repo->copyBinding('apiKeyBind01', 'apiKeyBind02');
        self::assertSame($tenant, $repo->tenantFor('apiKeyBind02'));
        self::assertSame(['apiKeyBind01', 'apiKeyBind02'], $repo->bindingsForTenant($tenant));
        $repo->unbind('apiKeyBind01');
        self::assertNull($repo->tenantFor('apiKeyBind01'));
    }

    private function seedTenant(string $uuid): void
    {
        $this->connection()->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => strtolower($uuid),
            'name' => $uuid,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function seedKey(string $uuid): void
    {
        $this->connection()->table('api_keys')->insert([
            'uuid' => $uuid,
            'user_uuid' => 'bindingUser1',
            'name' => $uuid,
            'key_prefix' => $uuid,
            'key_hash' => hash('sha256', $uuid),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
