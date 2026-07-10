<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Content\Starter\StarterDefinitions;
use App\Content\Starter\StarterProvenanceRepository;
use App\Content\Starter\StarterSeedFailpoint;
use App\Content\Starter\StarterTransaction;
use App\Content\Starter\TenantSeeder;
use App\Settings\GeneralSettings;
use App\Settings\SettingsStore;
use App\Tests\Support\RetrofittedTenantTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioningRunner;
use Glueful\Helpers\Utils;

final class StarterSeedRollbackTest extends RetrofittedTenantTestCase
{
    public function testFailureAfterMarkActiveRollsBackStarterRowsAndLifecycleCas(): void
    {
        $admin = $this->container()->get(TenantAdministration::class);
        $slugSuffix = strtolower(Utils::generateNanoID(8));
        $uuid = $admin->create(
            $this->appContext(),
            'rollback-' . $slugSuffix,
            'Rollback',
            'user00000001',
        );
        $failpoint = new class implements StarterSeedFailpoint {
            public function afterMarkActive(string $tenantUuid): void
            {
                throw new \Error('after mark-active');
            }
        };
        $seeder = new TenantSeeder(
            $this->appContext(),
            $this->container()->get(TenantProvisioningRunner::class),
            $admin,
            $this->container()->get(StarterDefinitions::class),
            $this->container()->get(StarterProvenanceRepository::class),
            $this->container()->get(StarterTransaction::class),
            $this->container()->get(GeneralSettings::class),
            $this->container()->get(SettingsStore::class),
            $failpoint,
        );

        try {
            $seeder->seedAndActivate($uuid, 'user00000001');
            self::fail('Expected the post-activation failpoint to abort the seed.');
        } catch (\Error $e) {
            self::assertSame('after mark-active', $e->getMessage());
        }

        self::assertSame('provisioning', $admin->getTenant($this->appContext(), $uuid)['status']);
        $runner = $this->container()->get(TenantProvisioningRunner::class);
        $counts = $runner->runAsProvisioningTenant($uuid, fn(): array => [
            $this->connection()->table('content_types')->count(),
            $this->connection()->table('starter_provenance')->count(),
        ]);
        self::assertSame([0, 0], $counts);
        $runner->runAsProvisioningTenant($uuid, fn() => $this->connection()->table('settings')->insert([
            'key' => 'post_rollback_probe',
            'value' => '1',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]));
    }
}
