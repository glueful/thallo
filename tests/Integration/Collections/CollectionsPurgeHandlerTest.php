<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use Thallo\Collections\CollectionManager;
use Thallo\Collections\Purge\CollectionsPurgeHandler;
use Thallo\Collections\Schema\CollectionPhysicalName;

final class CollectionsPurgeHandlerTest extends CollectionsTestCase
{
    public function testPreparedTablesAreDroppedAndMalformedCrossTenantArtifactIsRejected(): void
    {
        $definition = $this->container()->get(CollectionManager::class)->create([
            'name' => 'purge_products',
            'fields' => [['name' => 'title', 'type' => 'collections.string']],
        ], 'admin', 'purge-test');
        $handler = $this->container()->get(CollectionsPurgeHandler::class);
        $artifacts = $handler->prepare($this->appContext(), $this->collectionTenantUuid);

        $corrupt = $artifacts;
        $corrupt['collections'][0]['table_name'] = CollectionPhysicalName::generate('otherTenant1');
        try {
            $handler->purge($this->appContext(), $this->collectionTenantUuid, $corrupt);
            self::fail('A cross-tenant physical table artifact must be refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Unsafe collection', $e->getMessage());
        }
        self::assertNotNull($this->connection()->getPDO()->query(
            "SELECT to_regclass('{$definition->tableName}')",
        )->fetchColumn());

        $handler->purge($this->appContext(), $this->collectionTenantUuid, $artifacts);
        self::assertTrue($handler->verify($this->appContext(), $this->collectionTenantUuid, $artifacts));
        self::assertNull($this->connection()->getPDO()->query(
            "SELECT to_regclass('{$definition->tableName}')",
        )->fetchColumn());
    }
}
