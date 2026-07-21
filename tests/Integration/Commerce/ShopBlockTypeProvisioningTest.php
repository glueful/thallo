<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\RetrofittedTenantTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Commerce\Starter\ShopBlockTypesContributor;
use Thallo\Tenancy\Console\TenantSyncCommand;
use Thallo\Tenancy\Contracts\TenantSeedRepair;

/**
 * Task 11 (storefront-rendering spec §5.2/§10) — the tenant-provisioning/sync half of the 4
 * starter shop block types; {@see \App\Tests\Integration\Commerce\ShopBlocksTest} covers the
 * capability gate, boot() write-safety, schema/definition shape, and everything else that needs
 * no real multi-tenant retrofit harness. Mirrors
 * {@see \App\Tests\Integration\Commerce\ProductPageStarterTenancyTest}'s identical split for the
 * identical reason (Task 6/11 Slice-1 precedent) — opt-in via THALLO_TENANCY_DEV_LINK=1, the
 * WHOLE class self-skips otherwise (RetrofitHarnessTestCase::setUpBeforeClass()).
 *
 * {@see \Thallo\Commerce\CommerceIntegrationServiceProvider::boot()} already registers
 * {@see ShopBlockTypesContributor} with the shared registry as part of this harness's normal
 * full boot (thallo-commerce is an enabled provider, thallo.commerce defaults on) — nothing to
 * wire manually; the RED cases are proven purely by the real provider having already run.
 */
final class ShopBlockTypeProvisioningTest extends RetrofittedTenantTestCase
{
    private const SLUGS = ['product-grid', 'featured-product', 'add-to-cart', 'mini-cart'];

    public function testFreshTenantProvisioningCreatesTheFourShopBlockTypes(): void
    {
        $this->container()->get(TenantSeedRepair::class)->repair(self::$tenantAUuid);

        $slugs = $this->runAsTenant(
            self::$tenantAUuid,
            fn () => array_column(
                $this->connection()->table('block_types')
                    ->whereIn('slug', self::SLUGS)
                    ->orderBy('slug', 'ASC')
                    ->get(),
                'slug',
            ),
        );

        $expected = self::SLUGS;
        sort($expected);
        self::assertSame($expected, $slugs);
    }

    public function testTenantSyncAllWithKindBlockTypeAdoptsTheFourShopBlockTypesIdempotently(): void
    {
        $first = $this->syncAllBlockTypeKind();
        foreach (self::SLUGS as $slug) {
            self::assertSame(
                'added',
                $first[self::$tenantBUuid]['thallo-commerce:' . $slug] ?? null,
                "first --all sync of a pre-existing tenant must add '{$slug}'",
            );
        }

        $second = $this->syncAllBlockTypeKind();
        foreach (self::SLUGS as $slug) {
            self::assertSame(
                'unchanged',
                $second[self::$tenantBUuid]['thallo-commerce:' . $slug] ?? null,
                "a second --all sync run must be a no-op for '{$slug}'",
            );
        }
    }

    /** @return array<string,array<string,string>> tenant_uuid => (source_id => action) */
    private function syncAllBlockTypeKind(): array
    {
        $command = new TenantSyncCommand($this->container(), $this->appContext());
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--all' => true, '--kind' => 'block_type']);
        self::assertSame(0, $exit, $tester->getDisplay());

        /** @var array<string,list<array{kind:string,source_id:string,action:string}>> $report */
        $report = json_decode(trim($tester->getDisplay()), true, flags: JSON_THROW_ON_ERROR);

        $byTenant = [];
        foreach ($report as $tenantUuid => $items) {
            $byTenant[$tenantUuid] = array_column($items, 'action', 'source_id');
        }

        return $byTenant;
    }
}
