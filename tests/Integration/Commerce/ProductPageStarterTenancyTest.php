<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\RetrofittedTenantTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Commerce\Starter\ProductPageContributor;
use Thallo\Tenancy\Console\TenantSyncCommand;
use Thallo\Tenancy\Contracts\TenantSeedRepair;

/**
 * Task 11 — the tenant-provisioning/sync half of the starter "Product page" content-type
 * contribution (design spec §9); {@see \App\Tests\Integration\Commerce\ProductPageStarterTest}
 * covers the capability gate, boot() write-safety, and the end-to-end linkage, none of which
 * need real multi-tenant infrastructure. Mirrors
 * {@see \App\Tests\Integration\Content\Starter\StarterContributorTenancyTest} (Task 5's own
 * equivalent split) with ONE structural difference: T5's stub 'event' contributor has no
 * production wiring, so that test registers it manually in `setUpBeforeClass()`. This pack's
 * real {@see \Thallo\Commerce\CommerceIntegrationServiceProvider::boot()} ALREADY registers
 * {@see ProductPageContributor} with the shared registry as part of this harness's normal full
 * boot (Commerce + thallo-commerce are enabled providers, thallo.commerce defaults on) — a
 * second manual registration here would collide on sourceId. Nothing to wire; the RED cases are
 * proven purely by the real provider having already run.
 *
 * The sync case exercises `--all --kind=content_type` (design spec §9's documented install/
 * enable step for pre-existing workspaces — see this pack's README), not a single tenant uuid —
 * the one deliberate divergence from StarterContributorTenancyTest's `--kind` invocation.
 */
final class ProductPageStarterTenancyTest extends RetrofittedTenantTestCase
{
    public function testFreshTenantProvisioningCreatesProductPage(): void
    {
        $this->container()->get(TenantSeedRepair::class)->repair(self::$tenantAUuid);

        $slugs = $this->runAsTenant(
            self::$tenantAUuid,
            fn() => array_column(
                $this->connection()->table('content_types')->orderBy('slug', 'ASC')->get(),
                'slug',
            ),
        );

        self::assertSame(['category', 'pages', 'post', ProductPageContributor::SLUG], $slugs);
    }

    public function testTenantSyncAllWithKindContentTypeAdoptsProductPageIdempotently(): void
    {
        $first = $this->syncAllContentTypeKind();
        self::assertSame(
            'added',
            $first[self::$tenantBUuid][ProductPageContributor::SOURCE_ID] ?? null,
            'first --all sync of a pre-existing (unseeded) tenant must add product_page',
        );

        $second = $this->syncAllContentTypeKind();
        self::assertSame(
            'unchanged',
            $second[self::$tenantBUuid][ProductPageContributor::SOURCE_ID] ?? null,
            'a second --all sync run must be a no-op for the already-adopted product_page type',
        );
    }

    /** @return array<string,array<string,string>> tenant_uuid => (source_id => action) */
    private function syncAllContentTypeKind(): array
    {
        // TenantSyncCommand is auto-discovered (TenancyServiceProvider::boot() ->
        // discoverCommands()), not bound as a resolvable container service — construct it
        // directly with the container + context, mirroring StarterContributorTenancyTest's
        // identical convention for the single-tenant form.
        $command = new TenantSyncCommand($this->container(), $this->appContext());
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--all' => true, '--kind' => 'content_type']);
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
