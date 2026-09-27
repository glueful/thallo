<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Application;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\RemoveLayoutData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;

/**
 * One product layout per workspace, under real tenancy enforcement (type layouts plan C1, Review
 * Focus 5): a retrofitted schema, the tenancy provider bound, two provisioned workspaces. Each saves
 * its own product layout at version 1 and renders its own; each change in A — first save, edit,
 * removal — refreshes A's cached product page while B's stays cached.
 *
 * "Cached" is observed, not assumed: each product is renamed straight in the database (no catalog
 * event, so no purge); a page still in the cache keeps the old name, a re-rendered one shows the new.
 * Before every change both pages are proven to be served from the cache that way.
 *
 * Opt-in, like every retrofit suite: THALLO_TENANCY_DEV_LINK=1 with glueful/tenancy dev-linked.
 */
final class ProductLayoutTenancyTest extends RetrofittedTenantTestCase
{
    /** @var array{a: int, b: int} */
    private array $versions = ['a' => 0, 'b' => 0];

    private function tenant(string $name): string
    {
        return $name === 'a' ? self::$tenantAUuid : self::$tenantBUuid;
    }

    /** The product page, served through the kernel in the workspace's context. */
    private function page(string $name): string
    {
        return (string) $this->runAsTenant(
            $this->tenant($name),
            fn () => (new Application($this->appContext()))
                ->handle(Request::create('/shop/products/lamp', 'GET'))->getContent(),
        );
    }

    /** Straight to the table: no catalog event, so no shop cache purge. */
    private function rename(string $name, string $to): void
    {
        $this->runAsTenant($this->tenant($name), fn () => $this->connection()->table('commerce_products')
            ->where('slug', '=', 'lamp')->update(['name' => $to]));
    }

    /** @return array<string,mixed> a product layout session in the workspace */
    private function session(): array
    {
        $response = $this->container()->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => 'product', 'target' => '@site'],
            ),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        return json_decode((string) $response->getContent(), true)['data'];
    }

    private function save(string $name, string $marker): void
    {
        $this->runAsTenant($this->tenant($name), function () use ($name, $marker): void {
            $saved = $this->container()->get(LayoutAdminController::class)->save(
                (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
                    'token' => $this->session()['token'],
                    'layout' => ['blocks' => [
                        ['id' => 'tenantmark01', 'type' => 'heading', 'data' => ['text' => $marker], 'settings' => []],
                        ['id' => 'tenantbuy001', 'type' => 'product_buy', 'data' => [], 'settings' => []],
                    ], 'settings' => []],
                    'expected_lock_version' => $this->versions[$name],
                ]),
                Request::create('/x', 'PUT'),
                'product',
                '@site',
            );
            self::assertSame(200, $saved->getStatusCode(), (string) $saved->getContent());
            $this->versions[$name] = json_decode((string) $saved->getContent(), true)['data']['layout']['lock_version'];
        });
    }

    /** Both pages proven cached — a rename does not show — then renamed once more for the change. */
    private function primeBoth(int $round): void
    {
        foreach (['a', 'b'] as $name) {
            $this->page($name);
            $this->rename($name, "Lamp {$name} cached-{$round}");
            $page = $this->page($name);
            self::assertStringNotContainsString("cached-{$round}", $page, "{$name} is served from the cache");
            $this->rename($name, "Lamp {$name} fresh-{$round}");
        }
    }

    public function testEachWorkspaceKeepsItsOwnProductLayout(): void
    {
        foreach (['a', 'b'] as $name) {
            // The harness truncates block_types between tests: seed the workspace as provisioning does.
            $this->runAsTenant($this->tenant($name), fn () => $this->container()->get(StarterBlockTypeSeeder::class)
                ->seedMissing());
            $this->runAsTenant($this->tenant($name), fn () => $this->container()->get(CatalogService::class)
                ->createProduct($this->appContext(), [
                    'slug' => 'lamp', 'name' => 'Lamp ' . $name, 'status' => 'active', 'type' => 'digital',
                    'variants' => [['sku' => 'lamp-' . $name, 'price' => 1000, 'currency' => 'USD',
                        'option_values' => []]],
                ]));
        }

        // First save in A: A re-renders through its layout; B is still the cached theme page.
        $this->primeBoth(1);
        $this->save('a', 'LAYOUT-A');
        self::assertSame(1, $this->versions['a'], 'A saves its own at 1');
        $a = $this->page('a');
        self::assertStringContainsString('LAYOUT-A', $a);
        self::assertStringContainsString('fresh-1', $a, "A's page was re-rendered");
        self::assertStringNotContainsString('fresh-1', $this->page('b'), "B's page stayed in the cache");

        // B saves its own, also at 1: the two workspaces' layouts never conflict.
        $this->save('b', 'LAYOUT-B');
        self::assertSame(1, $this->versions['b'], 'B saves its own at 1');
        self::assertStringContainsString('LAYOUT-B', $this->page('b'));
        self::assertStringNotContainsString('LAYOUT-A', $this->page('b'), 'B renders its own');
        self::assertStringNotContainsString('LAYOUT-B', $this->page('a'), 'A renders its own');

        // An edit in A.
        $this->primeBoth(2);
        $this->save('a', 'LAYOUT-A2');
        $a = $this->page('a');
        self::assertStringContainsString('LAYOUT-A2', $a);
        self::assertStringContainsString('fresh-2', $a);
        self::assertStringNotContainsString('fresh-2', $this->page('b'), "B's page stayed in the cache");

        // A removes its layout: A is the theme page again; B keeps its cached layout page.
        $this->primeBoth(3);
        $this->runAsTenant(self::$tenantAUuid, function (): void {
            $removed = $this->container()->get(LayoutAdminController::class)->destroy(
                (new RequestDataHydrator())->hydrate(RemoveLayoutData::class, [
                    'token' => $this->session()['token'], 'expected_lock_version' => $this->versions['a'],
                ]),
                Request::create('/x', 'DELETE'),
                'product',
                '@site',
            );
            self::assertSame(200, $removed->getStatusCode(), (string) $removed->getContent());
        });
        $a = $this->page('a');
        self::assertStringNotContainsString('LAYOUT-A2', $a);
        self::assertStringContainsString('<article class="shop-product" data-shop-scope=', $a);
        $b = $this->page('b');
        self::assertStringContainsString('LAYOUT-B', $b);
        self::assertStringNotContainsString('fresh-3', $b, "B's page stayed in the cache");
    }
}
