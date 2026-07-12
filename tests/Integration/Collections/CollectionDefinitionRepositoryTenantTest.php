<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use Thallo\Collections\Repositories\CollectionDefinitionRepository;
use Thallo\Collections\Schema\AccessPolicy;
use Thallo\Collections\Schema\CollectionDefinition;
use Thallo\Collections\Schema\CollectionPhysicalName;
use Thallo\Tenancy\System\SystemFlags;

final class CollectionDefinitionRepositoryTenantTest extends CollectionsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteFixtures();
    }

    protected function tearDown(): void
    {
        $this->deleteFixtures();
        parent::tearDown();
    }

    public function testSameNameIsIsolatedAcrossTenantContexts(): void
    {
        $tenantA = 'tenantAAAAAA';
        $tenantB = 'tenantBBBBBB';
        foreach ([[$tenantA, 'tenant-a'], [$tenantB, 'tenant-b']] as [$uuid, $slug]) {
            $this->connection()->table('tenants')->insert([
                'uuid' => $uuid,
                'slug' => $slug,
                'name' => $slug,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $repo = $this->container()->get(CollectionDefinitionRepository::class);

        $repo->insert($this->definition($tenantA, 'collectionA01'));
        $repo->insert($this->definition($tenantB, 'collectionB01'));
        self::assertSame([$tenantA], array_column($repo->allForTenant($tenantA), 'tenantUuid'));
        self::assertSame([$tenantB], array_column($repo->allForTenant($tenantB), 'tenantUuid'));

        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.default_tenant_uuid', $tenantB);
        self::assertSame($tenantB, $repo->findByName('products')?->tenantUuid);
    }

    private function definition(string $tenantUuid, string $uuid): CollectionDefinition
    {
        return new CollectionDefinition(
            uuid: $uuid,
            name: 'products',
            label: 'Products',
            tableName: CollectionPhysicalName::generate($tenantUuid),
            storageMode: 'table',
            fields: [],
            schemaVersion: 1,
            status: 'active',
            accessPolicy: AccessPolicy::default(),
            tenantUuid: $tenantUuid,
        );
    }

    private function deleteFixtures(): void
    {
        foreach (['collectionA01', 'collectionB01'] as $uuid) {
            $this->connection()->table('collection_definitions')->where('uuid', $uuid)->delete();
        }
    }
}
