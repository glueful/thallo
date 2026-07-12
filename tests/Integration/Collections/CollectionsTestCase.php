<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\Tenant\SingleStoreTenant;
use Thallo\Collections\Schema\CollectionPhysicalName;

abstract class CollectionsTestCase extends AppTestCase
{
    protected string $collectionTenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection()->getPDO()->exec('TRUNCATE TABLE tenant_memberships, tenants CASCADE');
        $this->connection()->getPDO()->exec("DELETE FROM thallo_system_flags WHERE key LIKE 'tenancy.%'");
        $this->container()->get(SystemFlags::class)->clearCache();

        $email = 'collections-owner@example.com';
        $users = $this->container()->get(UserRepository::class);
        $statement = $this->connection()->getPDO()->prepare('SELECT uuid FROM users WHERE username = ?');
        $statement->execute([$email]);
        $owner = $statement->fetchColumn();
        $ownerUuid = is_string($owner) && $owner !== '' ? $owner : $users->create([
            'username' => $email,
            'email' => $email,
            'password' => password_hash('test-password', PASSWORD_DEFAULT),
            'status' => 'active',
        ]);

        $this->collectionTenantUuid = $this->container()->get(SingleStoreTenant::class)
            ->ensure('collections-test', 'Collections Test', $ownerUuid);
        $this->dropTenantCollectionTables();
    }

    protected function tearDown(): void
    {
        $this->dropTenantCollectionTables();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM tenant_memberships');
        $pdo->exec('DELETE FROM tenants');
        $pdo->exec("DELETE FROM thallo_system_flags WHERE key LIKE 'tenancy.%'");
        $this->container()->get(SystemFlags::class)->clearCache();

        parent::tearDown();
    }

    protected function physicalTable(string $collectionName): string
    {
        $statement = $this->connection()->getPDO()->prepare(
            'SELECT table_name FROM collection_definitions WHERE tenant_uuid = ? AND name = ?',
        );
        $statement->execute([$this->collectionTenantUuid, $collectionName]);
        $table = $statement->fetchColumn();

        return is_string($table) && $table !== '' ? $table : 'coll_' . $collectionName;
    }

    private function dropTenantCollectionTables(): void
    {
        if (!isset($this->collectionTenantUuid)) {
            return;
        }
        $prefix = 'tc_' . CollectionPhysicalName::tenantToken($this->collectionTenantUuid) . '_';
        $statement = $this->connection()->getPDO()->prepare(
            "SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE ?",
        );
        $statement->execute([$prefix . '%']);
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            if (is_string($table) && CollectionPhysicalName::belongsToTenant($table, $this->collectionTenantUuid)) {
                $this->connection()->getPDO()->exec('DROP TABLE IF EXISTS "' . $table . '"');
            }
        }
    }
}
