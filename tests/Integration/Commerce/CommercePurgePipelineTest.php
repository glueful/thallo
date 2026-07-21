<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\RetrofittedTenantTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Helpers\Utils;
use Thallo\Tenancy\Purge\PurgeCoordinator;
use Thallo\Tenancy\Purge\PurgeJob;
use Thallo\Tenancy\Purge\PurgeRunRepository;

/**
 * Commerce-Slice-1 Task 10: {@see \Thallo\Commerce\Purge\CommercePurgeHandler} driven through the
 * REAL purge pipeline — `PurgeResourceRegistry` (aggregated by
 * `Thallo\Tenancy\TenancyServiceProvider::makePurgeResourceRegistry()`, which this task taught to
 * pick up the pack's aliased handler), `PurgeCoordinator`, and `PurgeJob` — exactly the path
 * `TenantDeletionHostRetentionAcceptanceTest` exercises for the generic tables handler. Needs the
 * glueful/tenancy enforcement extension + two provisioned tenants, so it extends
 * {@see RetrofittedTenantTestCase} and is opt-in (`THALLO_TENANCY_DEV_LINK=1`), mirroring every
 * other acceptance test in `tests/Integration/Tenancy/Retrofit/`.
 */
final class CommercePurgePipelineTest extends RetrofittedTenantTestCase
{
    public function testFullPurgeRunRemovesLinkAndCommerceRowsForTheTargetTenantOnlyAndVerifiesGreen(): void
    {
        $context = $this->appContext();
        $connection = $this->connection();
        $target = self::$tenantAUuid;
        $survivor = self::$tenantBUuid;

        $this->runAsTenant($target, function () use ($connection): void {
            $connection->table('commerce_products')->insert([
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '', // stamped by the tenant write-scope hook on insert
                'slug' => 'purge-pipeline-target',
                'name' => 'Purge Pipeline Target Product',
            ]);
            $connection->table('thallo_commerce_product_links')->insert([
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'product_uuid' => Utils::generateNanoID(),
                'entry_uuid' => Utils::generateNanoID(),
            ]);
        });
        $this->runAsTenant($survivor, function () use ($connection): void {
            $connection->table('commerce_products')->insert([
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'slug' => 'purge-pipeline-survivor',
                'name' => 'Purge Pipeline Survivor Product',
            ]);
            $connection->table('thallo_commerce_product_links')->insert([
                'uuid' => Utils::generateNanoID(),
                'tenant_uuid' => '',
                'product_uuid' => Utils::generateNanoID(),
                'entry_uuid' => Utils::generateNanoID(),
            ]);
        });

        self::assertSame(1, (int) $connection->table('commerce_products')->where('tenant_uuid', $target)->count());
        self::assertSame(
            1,
            (int) $connection->table('thallo_commerce_product_links')->where('tenant_uuid', $target)->count(),
        );

        $tenants = $this->container()->get(TenantAdministration::class);
        $tenants->deleteTenant($context, $target);

        $runUuid = $this->container()->get(PurgeCoordinator::class)->request($target, 'user00000001');
        (new PurgeJob(['run_uuid' => $runUuid], $context))->handle();

        self::assertSame(
            'completed',
            $this->container()->get(PurgeRunRepository::class)->find($context, $runUuid)['status'],
        );

        // Target tenant: link + commerce rows both gone.
        self::assertSame(0, (int) $connection->table('commerce_products')->where('tenant_uuid', $target)->count());
        self::assertSame(
            0,
            (int) $connection->table('thallo_commerce_product_links')->where('tenant_uuid', $target)->count(),
        );

        // Survivor tenant: untouched.
        self::assertSame(
            1,
            (int) $connection->table('commerce_products')->where('tenant_uuid', $survivor)->count(),
        );
        self::assertSame(
            1,
            (int) $connection->table('thallo_commerce_product_links')->where('tenant_uuid', $survivor)->count(),
        );
    }
}
