<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Helpers\Utils;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Layouts\ProductSurface;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\ApplyLayoutData;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\RemoveLayoutData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ProductPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Save and Remove for the product layout (type layouts plan C1, P5): Release A's contract at the
 * site-wide target — versions, conflicts, tombstones, a retired session — with Add to cart required
 * exactly once, each surface's field blocks refused on the other, and, with workspaces on, one
 * product layout per workspace.
 */
final class ProductLayoutSaveTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private ProductPageSeed $seed;

    /** @var array<string,?string> */
    private array $previousTenant = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $this->syncBlockStyleDeclarations();
        $this->seed = new ProductPageSeed($this->container(), $this->appContext());
        $this->seed->clear();
        $this->previousTenant = $this->seed->useTenant();
    }

    protected function tearDown(): void
    {
        $this->seed->clear();
        $this->seed->restoreTenant($this->previousTenant);
        $this->container()->get(LayoutResolver::class)->forget('product', '@site');
        parent::tearDown();
    }

    /** @return array<string,mixed> */
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

    /** @return array{status: int, body: array<string,mixed>} */
    private function save(string $token, array $blocks, int $expected): array
    {
        $response = $this->container()->get(LayoutAdminController::class)->save(
            (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
                'token' => $token, 'layout' => ['blocks' => $blocks, 'settings' => []],
                'expected_lock_version' => $expected,
            ]),
            Request::create('/x', 'PUT'),
            'product',
            '@site',
        );
        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private function apply(string $token, array $blocks): array
    {
        $response = $this->container()->get(LayoutPreviewController::class)->apply(
            (new RequestDataHydrator())->hydrate(ApplyLayoutData::class, [
                'token' => $token, 'layout' => ['blocks' => $blocks, 'settings' => []],
            ]),
        );
        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private function remove(string $token, int $expected): array
    {
        $response = $this->container()->get(LayoutAdminController::class)->destroy(
            (new RequestDataHydrator())->hydrate(RemoveLayoutData::class, [
                'token' => $token, 'expected_lock_version' => $expected,
            ]),
            Request::create('/x', 'DELETE'),
            'product',
            '@site',
        );
        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true) ?? [],
        ];
    }

    private static function layout(string $marker): array
    {
        return [
            ['id' => 'savemarker01', 'type' => 'heading', 'data' => ['text' => $marker], 'settings' => []],
            ['id' => 'savebuy00001', 'type' => 'product_buy', 'data' => [], 'settings' => []],
        ];
    }

    public function testSaveAndRemoveKeepTheContract(): void
    {
        $a = $this->session();
        self::assertTrue($a['starter']);
        self::assertSame(0, $a['layout']['lock_version']);
        $first = $this->save($a['token'], self::layout('ONE'), 0);
        self::assertSame(200, $first['status'], json_encode($first['body']));
        self::assertSame(1, $first['body']['data']['layout']['lock_version']);

        $stale = $this->save($this->session()['token'], self::layout('STALE'), 0);
        self::assertSame(409, $stale['status']);
        self::assertSame('LAYOUT_VERSION_CONFLICT', $stale['body']['error']['details']['code'] ?? null);
        self::assertSame(
            'ONE',
            $this->container()->get(LayoutResolver::class)->for('product', '@site')['blocks'][0]['data']['text'],
        );

        $removed = $this->remove($a['token'], 1);
        self::assertSame(200, $removed['status'], json_encode($removed['body']));
        self::assertSame(2, $removed['body']['data']['lock_version']);
        self::assertSame(410, $this->apply($a['token'], self::layout('AFTER'))['status'], 'the session is retired');

        $fresh = $this->session();
        self::assertTrue($fresh['starter']);
        self::assertSame(2, $fresh['layout']['lock_version'], 'the tombstone keeps the version');
        self::assertSame(
            3,
            $this->save($fresh['token'], self::layout('AGAIN'), 2)['body']['data']['layout']['lock_version'],
        );
    }

    public function testALayoutWithoutTheBuyBlockIsRefused(): void
    {
        $session = $this->session();
        $none = [['id' => 'savemarker01', 'type' => 'heading', 'data' => ['text' => 'No buy'], 'settings' => []]];
        foreach ([$this->save($session['token'], $none, 0), $this->apply($session['token'], $none)] as $answer) {
            self::assertSame(422, $answer['status']);
            self::assertSame(
                'the layout must show the Add to cart block',
                $answer['body']['error']['details']['blocks'] ?? null,
            );
        }
        $twice = [
            ...self::layout('Twice'),
            ['id' => 'savebuy00002', 'type' => 'product_buy', 'data' => [], 'settings' => []],
        ];
        $answer = $this->save($session['token'], $twice, 0);
        self::assertSame(422, $answer['status']);
        self::assertSame(
            "'product_buy' can appear only once in a layout",
            $answer['body']['error']['details']['blocks.2.type'] ?? null,
        );
    }

    public function testEachSurfacesFieldBlocksAreRefusedOnTheOther(): void
    {
        $validator = $this->container()->get(LayoutValidator::class);
        try {
            $validator->validate('product', '@site', [
                ...self::layout('Entry title here'),
                ['id' => 'entrytitle01', 'type' => 'entry_title', 'data' => [], 'settings' => []],
            ], []);
            self::fail('an entry field block is refused on the product surface');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('blocks.2.type', $e->errors());
        }

        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'post', 'name' => 'Posts', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        try {
            $validator->validate('entry', 'post', [
                ['id' => 'postbody0001', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
                ['id' => 'productname1', 'type' => 'product_name', 'data' => [], 'settings' => []],
            ], []);
            self::fail('a product field block is refused on the entry surface');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('blocks.1.type', $e->errors());
        }
    }

    /**
     * Review Focus 5, end to end with workspaces on (mode (c)): each workspace saves its own product
     * layout at version 1 and renders its own, and each change in A — first save, edit, removal —
     * refreshes A's cached product page while B's stays cached. "Cached" is observed, not assumed:
     * each product is renamed straight in the database (no event, so no purge), and a page still in
     * the cache keeps the old name while a re-rendered one shows the new. The default suite has no
     * enforcement provider and skips this; run it with THALLO_TENANCY_DEV_LINK=1.
     */
    public function testTwoWorkspacesKeepTheirOwnProductLayout(): void
    {
        if (!$this->container()->has(CurrentTenantResolver::class)) {
            self::markTestSkipped('Enforcement provider not bound (default suite): THALLO_TENANCY_DEV_LINK=1.');
        }
        $this->seed->restoreTenant($this->previousTenant);
        $this->previousTenant = [];
        $flags = $this->container()->get(SystemFlags::class);
        $tenants = [];
        foreach (['a', 'b'] as $name) {
            $uuid = Utils::generateNanoID();
            $this->connection()->table('tenants')->insert([
                'uuid' => $uuid, 'slug' => 'layouts-' . $name . '-' . substr($uuid, 0, 6),
                'name' => 'Layouts ' . $name, 'status' => 'active',
            ]);
            $tenants[$name] = $uuid;
        }
        $flags->put('tenancy.enabled', '1');
        $flags->put('tenancy.enable_step', 'on');
        $runner = $this->container()->get(TenantContextRunner::class);
        $page = fn (string $tenant): string => (string) $runner->runAsTenant(
            $tenant,
            fn () => $this->handle(Request::create('/shop/products/lamp', 'GET'))->getContent(),
        );
        $rename = function (string $tenant, string $name): void {
            // Straight to the table: no catalog event, so no shop cache purge.
            $this->connection()->table('commerce_products')->where('tenant_uuid', '=', $tenant)
                ->update(['name' => $name]);
        };
        $version = ['a' => 0, 'b' => 0];
        $save = function (string $name, string $marker) use ($runner, $tenants, &$version): void {
            $runner->runAsTenant($tenants[$name], function () use ($name, $marker, &$version): void {
                $saved = $this->save($this->session()['token'], self::layout($marker), $version[$name]);
                self::assertSame(200, $saved['status'], json_encode($saved['body']));
                $version[$name] = $saved['body']['data']['layout']['lock_version'];
            });
        };
        /** Both pages cached — proven: a rename does not show — then renamed once more for the change. */
        $primeBoth = function (int $round) use ($tenants, $page, $rename): void {
            foreach ($tenants as $name => $tenant) {
                $page($tenant);
                $rename($tenant, "Lamp {$name} cached-{$round}");
                $served = $page($tenant);
                self::assertStringNotContainsString("cached-{$round}", $served, "{$name} is served from the cache");
                $rename($tenant, "Lamp {$name} fresh-{$round}");
            }
        };
        try {
            foreach ($tenants as $name => $tenant) {
                $runner->runAsTenant($tenant, function () use ($name): void {
                    $this->container()->get(\Glueful\Extensions\Commerce\Catalog\CatalogService::class)
                        ->createProduct($this->appContext(), [
                            'slug' => 'lamp', 'name' => 'Lamp ' . $name, 'status' => 'active', 'type' => 'digital',
                            'variants' => [['sku' => 'lamp-' . $name, 'price' => 1000, 'currency' => 'USD',
                                'option_values' => []]],
                        ]);
                });
            }

            // First save in A: A re-renders through its layout; B is still the cached theme page.
            $primeBoth(1);
            $save('a', 'LAYOUT-A');
            self::assertSame(1, $version['a'], 'A saves its own at 1');
            $a = $page($tenants['a']);
            self::assertStringContainsString('LAYOUT-A', $a);
            self::assertStringContainsString('fresh-1', $a, "A's page was re-rendered");
            self::assertStringNotContainsString('fresh-1', $page($tenants['b']), "B's page stayed in the cache");

            // B saves its own, also at 1, and each renders its own.
            $save('b', 'LAYOUT-B');
            self::assertSame(1, $version['b'], 'B saves its own at 1, no conflict with A');
            self::assertStringContainsString('LAYOUT-B', $page($tenants['b']));
            self::assertStringNotContainsString('LAYOUT-A', $page($tenants['b']));
            self::assertStringNotContainsString('LAYOUT-B', $page($tenants['a']));

            // An edit in A.
            $primeBoth(2);
            $save('a', 'LAYOUT-A2');
            $a = $page($tenants['a']);
            self::assertStringContainsString('LAYOUT-A2', $a);
            self::assertStringContainsString('fresh-2', $a);
            self::assertStringNotContainsString('fresh-2', $page($tenants['b']), "B's page stayed in the cache");

            // A removes its layout: A is the theme page again, B keeps its cached layout page.
            $primeBoth(3);
            $runner->runAsTenant($tenants['a'], function () use (&$version): void {
                $removed = $this->remove($this->session()['token'], $version['a']);
                self::assertSame(200, $removed['status'], json_encode($removed['body']));
            });
            $a = $page($tenants['a']);
            self::assertStringNotContainsString('LAYOUT-A2', $a);
            self::assertStringContainsString('<article class="shop-product" data-shop-scope=', $a);
            $b = $page($tenants['b']);
            self::assertStringContainsString('LAYOUT-B', $b);
            self::assertStringNotContainsString('fresh-3', $b, "B's page stayed in the cache");
            self::assertNotNull($this->container()->get(LayoutSurfaceRegistry::class)->get(ProductSurface::KEY));
        } finally {
            $flags->forget('tenancy.enabled');
            $flags->forget('tenancy.enable_step');
            foreach ($tenants as $tenant) {
                $this->connection()->table('tenants')->where('uuid', '=', $tenant)->forceDelete();
            }
        }
    }
}
