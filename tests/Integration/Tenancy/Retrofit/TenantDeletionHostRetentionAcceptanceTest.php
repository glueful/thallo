<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Tests\Support\RetrofittedTenantTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;
use Glueful\Helpers\Utils;
use Thallo\Tenancy\Purge\PurgeCoordinator;
use Thallo\Tenancy\Purge\PurgeJob;
use Thallo\Tenancy\Purge\PurgeRunRepository;

final class TenantDeletionHostRetentionAcceptanceTest extends RetrofittedTenantTestCase
{
    public function testPurgeRemovesOnlyTargetAndRetainsReleasedHostCooldown(): void
    {
        $context = $this->appContext();
        $connection = $this->connection();
        $target = self::$tenantAUuid;
        $survivor = self::$tenantBUuid;
        $targetType = Utils::generateNanoID(12);
        $survivorType = Utils::generateNanoID(12);

        $this->runAsTenant($target, function () use ($connection, $targetType): void {
            $connection->table('content_types')->insert([
                'uuid' => $targetType,
                'slug' => 'target-only',
                'name' => 'Target only',
                'schema' => '{}',
                'status' => 'active',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        });
        $this->runAsTenant($survivor, function () use ($connection, $survivorType): void {
            $connection->table('content_types')->insert([
                'uuid' => $survivorType,
                'slug' => 'survivor-only',
                'name' => 'Survivor only',
                'schema' => '{}',
                'status' => 'active',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        });

        $host = strtolower(Utils::generateNanoID(8)) . '.purge.test';
        $domains = $this->container()->get(TenantDomainAdministration::class);
        $domains->addPreverifiedDomain($context, $target, $host);
        $tenants = $this->container()->get(TenantAdministration::class);
        $tenants->deleteTenant($context, $target);

        $runUuid = $this->container()->get(PurgeCoordinator::class)->request($target, 'user00000001');
        (new PurgeJob(['run_uuid' => $runUuid], $context))->handle();

        self::assertNull($tenants->getTenantLifecycle($context, $target));
        self::assertNotNull($tenants->getTenantLifecycle($context, $survivor));
        self::assertFalse($this->rowExists('content_types', 'uuid', $targetType));
        self::assertTrue($this->rowExists('content_types', 'uuid', $survivorType));
        self::assertSame(
            'completed',
            $this->container()->get(PurgeRunRepository::class)->find($context, $runUuid)['status']
        );
        self::assertNotNull(
            $this->container()->get(ReleasedHostRepository::class)
                ->activeTombstone($context, $host, gmdate('Y-m-d H:i:s'))
        );
    }

    private function rowExists(string $table, string $column, string $value): bool
    {
        $statement = $this->connection()->getPDO()->prepare(
            "SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1"
        );
        $statement->execute([$value]);
        return $statement->fetchColumn() !== false;
    }
}
