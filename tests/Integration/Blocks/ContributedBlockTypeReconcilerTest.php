<?php

declare(strict_types=1);

namespace App\Tests\Integration\Blocks;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\ContributedBlockTypeReconciler;
use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Starter\ShopBlockTypesContributor;
use Thallo\Tenancy\System\SystemFlags;

/**
 * A pack's starter blocks appear on the first request after its capability turns on — no
 * provision or seed command needed. A marker in the system flags records which capabilities
 * were seeded, so the seed runs once per switch-on; switching off clears the marker (rows are
 * kept), so switching back on seeds whatever is missing again.
 */
final class ContributedBlockTypeReconcilerTest extends AppTestCase
{
    private function reconciler(): ContributedBlockTypeReconciler
    {
        return $this->container()->get(ContributedBlockTypeReconciler::class);
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function repo(): BlockTypeRepository
    {
        return new BlockTypeRepository($this->connection());
    }

    /** @return list<string> */
    private function seededCapabilities(SystemFlags $flags): array
    {
        $flags->clearCache();
        return json_decode((string) $flags->get(ContributedBlockTypeReconciler::FLAG), true) ?? [];
    }

    public function testNothingHappensBeforeThalloIsInstalled(): void
    {
        self::assertSame([], $this->reconciler()->reconcile());
        self::assertNull($this->repo()->findBySlug(ShopBlockTypesContributor::SLUG_PRODUCT_GRID));
        self::assertNull($this->flags()->get(ContributedBlockTypeReconciler::FLAG));
    }

    public function testTheFirstRunAfterInstallSeedsAnEnabledPackAndRecordsIt(): void
    {
        $this->flags()->put('installed', '1');

        $report = $this->reconciler()->reconcile();

        self::assertContains(ShopBlockTypesContributor::SLUG_PRODUCT_GRID, $report['thallo.commerce']);
        self::assertContains(ShopBlockTypesContributor::SLUG_WISHLIST_LINK, $report['thallo.commerce']);
        self::assertNotNull($this->repo()->findBySlug(ShopBlockTypesContributor::SLUG_MINI_CART));
        self::assertNull($this->repo()->findBySlug('hero'), 'only pack contributions are seeded here');
        self::assertContains('thallo.commerce', $this->seededCapabilities($this->flags()));
    }

    public function testASeededCapabilityIsNotSeededAgainUntilItIsSwitchedOffAndOn(): void
    {
        $this->flags()->put('installed', '1');
        $this->reconciler()->reconcile();
        $this->repo()->deleteBySlug(ShopBlockTypesContributor::SLUG_PRODUCT_GRID);

        self::assertSame([], $this->reconciler()->reconcile(), 'the marker makes this a no-op');
        self::assertNull($this->repo()->findBySlug(ShopBlockTypesContributor::SLUG_PRODUCT_GRID));
    }

    public function testExistingRowsAreNeverTouched(): void
    {
        $this->flags()->put('installed', '1');
        $this->repo()->create([
            'slug' => ShopBlockTypesContributor::SLUG_MINI_CART,
            'label' => 'My cart',
            'schema' => [['name' => 'x', 'type' => 'string']],
        ]);

        $report = $this->reconciler()->reconcile();

        self::assertNotContains(ShopBlockTypesContributor::SLUG_MINI_CART, $report['thallo.commerce']);
        self::assertSame('My cart', $this->repo()->findBySlug(ShopBlockTypesContributor::SLUG_MINI_CART)['label']);
    }

    public function testWorkspacesOnLeavesSeedingToThePerTenantCommand(): void
    {
        $this->flags()->put('installed', '1');
        $this->flags()->put('tenancy.enabled', '1');

        self::assertSame([], $this->reconciler()->reconcile());
        self::assertNull($this->repo()->findBySlug(ShopBlockTypesContributor::SLUG_PRODUCT_GRID));
    }

    public function testADisabledCapabilityClearsItsMarkerAndSeedsNothing(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        $this->flags()->put('installed', '1');
        $this->flags()->put(ContributedBlockTypeReconciler::FLAG, (string) json_encode(['thallo.commerce']));

        $off = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);
        try {
            $report = $off->getContainer()->get(ContributedBlockTypeReconciler::class)->reconcile();

            self::assertArrayNotHasKey('thallo.commerce', $report);
            self::assertNull($this->repo()->findBySlug(ShopBlockTypesContributor::SLUG_PRODUCT_GRID));
            self::assertNotContains(
                'thallo.commerce',
                $this->seededCapabilities($off->getContainer()->get(SystemFlags::class)),
            );
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    public function testAnHttpRequestRunsTheReconcilerAfterBoot(): void
    {
        $this->flags()->put('installed', '1');

        $this->handle(Request::create('/'));

        self::assertNotNull(
            $this->repo()->findBySlug(ShopBlockTypesContributor::SLUG_PRODUCT_GRID),
            'the first request after the capability turned on seeds its starter blocks',
        );
    }
}
