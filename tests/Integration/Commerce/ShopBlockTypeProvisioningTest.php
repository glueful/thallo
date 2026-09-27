<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Commerce\Starter\ShopBlockTypesContributor;
use Thallo\Tenancy\Console\TenantSyncCommand;
use Thallo\Tenancy\Contracts\TenantSeedRepair;

/**
 * Task 11 (storefront-rendering spec §5.2/§10) — the tenant-provisioning/sync half of the 4
 * starter shop block types; {@see \Thallo\Core\Tests\Integration\Commerce\ShopBlocksTest} covers the
 * capability gate, boot() write-safety, schema/definition shape, and everything else that needs
 * no real multi-tenant retrofit harness. Mirrors
 * {@see \Thallo\Core\Tests\Integration\Commerce\ProductStoryStarterTenancyTest}'s identical split for the
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

        // The product page's field blocks (type layouts plan C1) come with them, in the same repair
        // (the harness truncates block_types between tests; a workspace's seed repair runs once).
        $fields = $this->runAsTenant(
            self::$tenantAUuid,
            fn () => array_column(
                $this->connection()->table('block_types')
                    ->whereIn('slug', \Thallo\Commerce\Starter\ProductFieldBlocksContributor::SLUGS)
                    ->get(),
                'slug',
            ),
        );
        $want = \Thallo\Commerce\Starter\ProductFieldBlocksContributor::SLUGS;
        sort($want);
        sort($fields);
        self::assertSame($want, $fields);
    }

    public function testTenantSyncAllWithKindBlockTypeAdoptsTheFourShopBlockTypesIdempotently(): void
    {
        $this->workspaceBWithout(self::SLUGS);

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

    /**
     * A workspace that has not had the product blocks yet (type layouts plan C1): its product layout
     * is closed with the reason, and the documented sync adds all nine and opens it.
     */
    public function testTenantSyncAllGivesAnExistingWorkspaceTheProductBlocksAndOpensItsLayout(): void
    {
        $surface = $this->container()->get(\Thallo\Contracts\Layouts\LayoutSurfaceRegistry::class)->get('product');
        $target = fn (): ?array => $this->runAsTenant(self::$tenantBUuid, fn () => $this->container()
            ->get(\Thallo\Core\Content\Layouts\LayoutTargets::class)->find($surface, '@site'));
        // Provisioned before this release: neither the rows nor any record of having seeded them (a
        // recorded block whose row is gone is one the site deleted, and a sync leaves it deleted).
        $this->runAsTenant(self::$tenantBUuid, function (): void {
            $slugs = \Thallo\Commerce\Starter\ProductFieldBlocksContributor::SLUGS;
            $this->connection()->table('block_types')->whereIn('slug', $slugs)->delete();
            $this->connection()->table('starter_provenance')->where('definition_kind', '=', 'block_type')
                ->whereIn('source_id', array_map(static fn (string $s): string => 'thallo-commerce:' . $s, $slugs))
                ->delete();
        });

        $closed = $target();
        self::assertFalse($closed['enabled'] ?? null);
        self::assertSame(\Thallo\Core\Content\Layouts\LayoutTargets::NOT_PROVISIONED, $closed['reason']);

        $this->syncAllBlockTypeKind();
        $fields = $this->runAsTenant(self::$tenantBUuid, fn () => array_column(
            $this->connection()->table('block_types')
                ->whereIn('slug', \Thallo\Commerce\Starter\ProductFieldBlocksContributor::SLUGS)->get(),
            'slug',
        ));
        $want = \Thallo\Commerce\Starter\ProductFieldBlocksContributor::SLUGS;
        sort($want);
        sort($fields);
        self::assertSame($want, $fields);
        self::assertTrue($target()['enabled'] ?? null);
    }

    /**
     * A workspace synced before 1.0.0-beta.68 recorded its block types in the fingerprint of the day,
     * which left out the starter content a definition did not carry: every later sync read them as
     * edited and never updated them. Untouched rows recorded that way rejoin — and a row still as an
     * earlier definition left it takes the definition as it is now.
     */
    public function testBlockTypesRecordedBeforeBeta68RejoinAndTakeTheirDefinitionsUpdates(): void
    {
        $this->workspaceBWithout(self::SLUGS);
        $this->syncAllBlockTypeKind();
        $legacy = function (array $row): string {
            $shape = [
                'label' => (string) $row['label'], 'icon' => $row['icon'], 'category' => $row['category'],
                'description' => $row['description'], 'schema' => (array) $row['schema'],
                'active' => (bool) $row['active'], 'style_capabilities' => $row['style_capabilities'] ?? null,
                'style_targets' => $row['style_targets'] ?? null, 'flags' => $row['flags'] ?? null,
            ];
            return \Thallo\Core\Content\Starter\Fingerprint::of($shape);
        };
        $this->runAsTenant(self::$tenantBUuid, function () use ($legacy): void {
            $repo = $this->container()->get(\Thallo\Core\Content\Blocks\BlockTypeRepository::class);
            // mini-cart untouched; product-grid as an earlier definition labelled it, untouched since.
            $this->connection()->table('block_types')->where('slug', '=', 'product-grid')
                ->update(['label' => 'Product list']);
            foreach (['mini-cart', 'product-grid'] as $slug) {
                $this->connection()->table('starter_provenance')
                    ->where('source_id', '=', 'thallo-commerce:' . $slug)
                    ->update(['fingerprint' => $legacy($repo->findBySlug($slug)), 'state' => 'customized']);
            }
        });

        $healed = $this->syncAllBlockTypeKind()[self::$tenantBUuid];
        self::assertSame('rejoined_applied', $healed['thallo-commerce:mini-cart'] ?? null);
        self::assertSame('updated', $healed['thallo-commerce:product-grid'] ?? null);
        self::assertSame('Product grid', $this->runAsTenant(self::$tenantBUuid, fn () => $this->container()
            ->get(\Thallo\Core\Content\Blocks\BlockTypeRepository::class)->findBySlug('product-grid')['label']));
        $again = $this->syncAllBlockTypeKind()[self::$tenantBUuid];
        self::assertSame(['unchanged', 'unchanged'], [
            $again['thallo-commerce:mini-cart'] ?? null, $again['thallo-commerce:product-grid'] ?? null,
        ]);
    }

    /**
     * Tenant B as a workspace from before these blocks: neither the rows nor any record of having
     * seeded them. (The harness provisioned it with them, then empties block_types between tests —
     * which, with the records kept, reads as blocks the site deleted, and a sync leaves those deleted.)
     *
     * @param list<string> $slugs
     */
    private function workspaceBWithout(array $slugs): void
    {
        $this->runAsTenant(self::$tenantBUuid, function () use ($slugs): void {
            $this->connection()->table('block_types')->whereIn('slug', $slugs)->delete();
            $this->connection()->table('starter_provenance')->where('definition_kind', '=', 'block_type')
                ->whereIn('source_id', array_map(static fn (string $s): string => 'thallo-commerce:' . $s, $slugs))
                ->delete();
        });
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
