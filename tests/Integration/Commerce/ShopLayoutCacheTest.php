<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Events\EventService;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Extensions\Commerce\Catalog\CategoryRepository;
use Glueful\Extensions\Commerce\Tenancy\CommerceTenantResolution;
use Glueful\Validation\RequestDataHydrator;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Commerce\Http\Shop\ShopCatalogController;
use Thallo\Commerce\Layouts\ShopLayoutTags;
use Thallo\Commerce\Shop\Listeners\PurgeShopCacheOnLayoutChange;
use Thallo\Commerce\Shop\ShopPageCache;
use Thallo\Contracts\Layouts\LayoutChanged;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Http\Controllers\LayoutAdminController;
use Thallo\Core\Http\Controllers\LayoutPreviewController;
use Thallo\Core\Http\DTOs\LayoutSessionData;
use Thallo\Core\Http\DTOs\RemoveLayoutData;
use Thallo\Core\Http\DTOs\SaveLayoutData;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Tenancy\System\SystemFlags;

/**
 * A shop layout change refreshes one workspace's pages of one surface (type layouts plan C2, S3;
 * Review Focus 4 in the default suite), and a render that straddles the change never puts the old
 * layout back in the cache.
 *
 * The shop cache keys every page of a layout surface by that workspace's and surface's layout
 * generation — a random token read before the page renders, created atomically when missing, and
 * replaced by every change (`LayoutChanged`). So a render that read the old layout stores under a
 * token the change has already replaced, and the next request misses and renders the new layout —
 * on a tag-capable driver and on one without tag invalidation alike. The tag purge (or the tag-less
 * fallback) still frees the old entries; it never deletes a token.
 *
 * Two workspaces as the storefront tests make them: the widened schema's default tenant, switched.
 */
final class ShopLayoutCacheTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private const TENANT_A = 'shopcachea01';
    private const TENANT_B = 'shopcacheb01';

    /** surface => path of its page in the fixture shop */
    private const PAGES = [
        'shop_index' => '/shop',
        'shop_category' => '/shop/categories/lamps',
        'product' => '/shop/products/lamp',
    ];

    /** @var array<string,int> surface => the next lock version */
    private array $version = ['shop_index' => 0, 'shop_category' => 0, 'product' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->clearCatalog();
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);
    }

    protected function tearDown(): void
    {
        // The flags first: nothing below may leave the widened schema persisted for later tests.
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');
        $this->cache()->deletePattern('shop:*');
        foreach (ShopLayoutTags::SURFACES as $surface) {
            foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
                $this->cache()->delete(ShopLayoutTags::generationKey($surface, $tenant));
            }
            $this->container()->get(LayoutResolver::class)->forget($surface, '@site');
        }
        $this->clearCatalog();
        parent::tearDown();
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    private function cache(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    private function clearCatalog(): void
    {
        $pdo = $this->connection()->getPDO();
        $tables = [
            'commerce_cart_lines', 'commerce_carts', 'commerce_stock', 'commerce_product_categories',
            'commerce_categories', 'commerce_variants', 'commerce_products',
        ];
        foreach ($tables as $t) {
            $pdo->exec("DELETE FROM {$t}");
        }
    }

    private function asTenant(string $tenant, callable $fn): mixed
    {
        $this->flags()->put('tenancy.default_tenant_uuid', $tenant);
        try {
            return $fn();
        } finally {
            $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);
        }
    }

    /** The key a page is cached under now: its workspace's and surface's current token. */
    private function key(string $tenant, string $surface): string
    {
        $token = $this->cache()->get(ShopLayoutTags::generationKey($surface, $tenant));
        self::assertIsString($token, "{$tenant}'s {$surface} token exists");
        return 'shop:' . $tenant . ':en:default:' . $this->appearanceFingerprint() . ':1:'
            . $surface . 'g' . $token . ':' . rawurlencode(self::PAGES[$surface]);
    }

    /** A lamp in a Lamps category in both workspaces, and each of their three pages cached. */
    private function primeBoth(): void
    {
        foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
            $this->asTenant($tenant, function () use ($tenant): void {
                $own = $this->connection()->table('commerce_products')->where('tenant_uuid', '=', $tenant)->count();
                if ($own === 0) {
                    $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
                        'slug' => 'lamp', 'name' => 'Lamp of ' . $tenant, 'status' => 'active', 'type' => 'digital',
                        'variants' => [['sku' => 'lamp-' . $tenant, 'price' => 1000, 'currency' => 'USD',
                            'option_values' => []]],
                    ]);
                    $category = 'cat' . substr($tenant, -9);
                    (new CategoryRepository())->insert($this->appContext(), [
                        'uuid' => $category, 'tenant_uuid' => $tenant, 'slug' => 'lamps', 'name' => 'Lamps',
                        'position' => 0,
                    ]);
                    (new CategoryRepository())
                        ->attachProduct($this->appContext(), (string) $product['uuid'], $category);
                }
                foreach (self::PAGES as $path) {
                    self::assertSame(200, $this->handle(Request::create($path, 'GET'))->getStatusCode(), $path);
                }
            });
            foreach (array_keys(self::PAGES) as $surface) {
                self::assertIsArray(
                    $this->cache()->get($this->key($tenant, $surface)),
                    "precondition: {$tenant}'s {$surface} page is cached",
                );
            }
        }
    }

    /** @return list<array<string,mixed>> a layout of the surface showing `$marker` */
    private static function blocks(string $surface, string $marker): array
    {
        $required = $surface === 'product'
            ? ['id' => 'cachebuy0001', 'type' => 'product_buy', 'data' => [], 'settings' => []]
            : ['id' => 'cacheloop001', 'type' => 'product_loop', 'data' => ['card' => []], 'settings' => []];
        return [
            ['id' => 'cachemarker1', 'type' => 'heading', 'data' => ['text' => $marker], 'settings' => []],
            $required,
        ];
    }

    /** @return array{token: string} a session of the surface, minted in workspace A */
    private function session(string $surface): array
    {
        return json_decode((string) $this->container()->get(LayoutPreviewController::class)->session(
            (new RequestDataHydrator())->hydrate(
                LayoutSessionData::class,
                ['surface' => $surface, 'target' => '@site'],
            ),
        )->getContent(), true)['data'];
    }

    /** First save and edit (`$marker`), or removal (null), in workspace A, as the editor does. */
    private function change(string $surface, ?string $marker): void
    {
        $session = $this->session($surface);
        $response = $marker === null
            ? $this->container()->get(LayoutAdminController::class)->destroy(
                (new RequestDataHydrator())->hydrate(RemoveLayoutData::class, [
                    'token' => $session['token'], 'expected_lock_version' => $this->version[$surface],
                ]),
                Request::create('/x', 'DELETE'),
                $surface,
                '@site',
            )
            : $this->container()->get(LayoutAdminController::class)->save(
                (new RequestDataHydrator())->hydrate(SaveLayoutData::class, [
                    'token' => $session['token'],
                    'layout' => ['blocks' => self::blocks($surface, $marker), 'settings' => []],
                    'expected_lock_version' => $this->version[$surface],
                ]),
                Request::create('/x', 'PUT'),
                $surface,
                '@site',
            );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->version[$surface]++;
    }

    /** The three changes in order, and what the page shows after each. */
    private const CHANGES = [['FIRST-LAYOUT', 'FIRST-LAYOUT'], ['EDITED-LAYOUT', 'EDITED-LAYOUT'], [null, null]];

    private static function themeMarker(string $surface): string
    {
        return match ($surface) {
            'shop_index' => '<section class="shop-index" data-shop-scope=',
            'shop_category' => '<section class="shop-category" data-shop-scope=',
            'product' => '<article class="shop-product" data-shop-scope=',
        };
    }

    public function testEachChangeRefreshesOnlyItsSurfaceInTheSavingWorkspace(): void
    {
        foreach (['shop_index', 'shop_category'] as $surface) {
            foreach (self::CHANGES as [$marker, $expect]) {
                $this->primeBoth();
                $kept = [];
                foreach (array_keys(self::PAGES) as $other) {
                    foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
                        if ($other === $surface && $tenant === self::TENANT_A) {
                            continue;
                        }
                        $key = $this->key($tenant, $other);
                        $kept["{$tenant} {$other}"] = [$key, $this->cache()->get($key)];
                    }
                }
                $before = $this->key(self::TENANT_A, $surface);

                $this->change($surface, $marker);
                $step = "{$surface} " . ($marker ?? 'removal');

                self::assertNotSame($before, $this->key(self::TENANT_A, $surface), "{$step}: A's token was replaced");
                $html = (string) $this->handle(Request::create(self::PAGES[$surface], 'GET'))->getContent();
                self::assertStringContainsString(
                    $expect ?? self::themeMarker($surface),
                    $html,
                    "{$step}: A renders the new state",
                );
                foreach ($kept as $what => [$key, $entry]) {
                    self::assertSame($key, $this->key(...explode(' ', $what)), "{$step}: {$what} keeps its token");
                    self::assertSame($entry, $this->cache()->get($key), "{$step}: {$what} is still a hit");
                }
            }
        }
    }

    /**
     * The pinned sequence, for every commerce surface and every change: a request's render reads the
     * old layout; the change commits and purges; the old render finishes and stores. The next
     * request misses and renders the new layout, and the one after hits it.
     */
    public function testARenderStraddlingAChangeNeverRepopulatesTheOldLayout(): void
    {
        $this->primeBoth();
        foreach (array_keys(self::PAGES) as $surface) {
            $previous = null;
            foreach (self::CHANGES as [$marker, $expect]) {
                $step = "{$surface} " . ($marker ?? 'removal');
                // A miss: the request renders (the page the last step cached is dropped).
                $this->cache()->delete($this->key(self::TENANT_A, $surface));
                $ran = false;
                $request = Request::create(self::PAGES[$surface], 'GET');
                $old = $this->container()->get(ShopPageCache::class)->handle(
                    $request,
                    function (Request $request) use ($surface, $marker, &$ran): Response {
                        $ran = true;
                        $response = $this->render($surface, $request); // reads the old layout
                        $this->change($surface, $marker);            // commits and purges
                        return $response;                             // the old render finishes
                    },
                    $surface,
                );
                self::assertTrue($ran, "{$step}: the straddling request rendered");
                $oldHtml = (string) $old->getContent();
                self::assertStringContainsString(
                    $previous ?? self::themeMarker($surface),
                    $oldHtml,
                    "{$step}: the straddling render shows the old state",
                );

                $next = (string) $this->handle(Request::create(self::PAGES[$surface], 'GET'))->getContent();
                self::assertStringContainsString(
                    $expect ?? self::themeMarker($surface),
                    $next,
                    "{$step}: the next request renders the new state",
                );
                if ($previous !== null) {
                    self::assertStringNotContainsString($previous, $next, "{$step}: never the old layout");
                }
                self::assertSame(
                    $next,
                    (string) $this->handle(Request::create(self::PAGES[$surface], 'GET'))->getContent(),
                    "{$step}: the third request hits the new state",
                );
                $previous = $expect;
            }
        }
    }

    /** The controller's own render of a surface's page, with no cache in front of it. */
    private function render(string $surface, Request $request): Response
    {
        $controller = $this->container()->get(ShopCatalogController::class);
        return match ($surface) {
            'shop_index' => $controller->index($request),
            'shop_category' => $controller->category($request, 'lamps'),
            'product' => $controller->product($request, 'lamp'),
        };
    }

    /** A driver without tag invalidation: the token is replaced first, then only A's shop keys go. */
    public function testATaglessDriverReplacesTheTokenAndFallsBackToTheWorkspacesOwnShopKeys(): void
    {
        $calls = [];
        $cache = $this->createMock(CacheStore::class);
        $cache->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$calls): bool {
            $calls[] = ['set', $key, $value];
            return true;
        });
        $cache->method('invalidateTags')->willReturnCallback(static function (array $tags) use (&$calls): bool {
            $calls[] = ['invalidateTags', $tags];
            return false;
        });
        $cache->method('deletePattern')->willReturnCallback(static function (string $pattern) use (&$calls): bool {
            $calls[] = ['deletePattern', $pattern];
            return true;
        });
        (new PurgeShopCacheOnLayoutChange($this->withCache($cache)))
            ->onLayoutChanged(new LayoutChanged('shop_category', '@site', self::TENANT_A));

        self::assertCount(4, $calls);
        self::assertSame(
            ['set', ShopLayoutTags::generationKey('shop_category', self::TENANT_A)],
            array_slice($calls[0], 0, 2),
        );
        self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', (string) $calls[0][2], 'a fresh random token');
        self::assertSame(['invalidateTags', [ShopLayoutTags::tenantTag('shop_category', self::TENANT_A)]], $calls[1]);
        self::assertSame(['deletePattern', 'shop:' . self::TENANT_A . ':*'], $calls[2]);
        self::assertSame(['deletePattern', 'tenant:*:shop:' . self::TENANT_A . ':*'], $calls[3]);
    }

    /** The token lives outside both fallback patterns: a tag-less purge never deletes one. */
    public function testTheFallbackPurgeNeverDeletesAGenerationToken(): void
    {
        $glob = static fn (string $pattern, string $key): bool => fnmatch($pattern, $key);
        foreach (ShopLayoutTags::SURFACES as $surface) {
            foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
                $key = ShopLayoutTags::generationKey($surface, $tenant);
                foreach ([self::TENANT_A, self::TENANT_B] as $purged) {
                    foreach (['shop:' . $purged . ':*', 'tenant:*:shop:' . $purged . ':*'] as $pattern) {
                        self::assertFalse($glob($pattern, $key), "{$pattern} never matches {$key}");
                        self::assertFalse($glob($pattern, 'tenant:' . $tenant . ':' . $key), "{$pattern}, segmented");
                    }
                }
            }
        }

        $this->primeBoth();
        $tokens = [];
        foreach (ShopLayoutTags::SURFACES as $surface) {
            $tokens[$surface] = $this->cache()->get(ShopLayoutTags::generationKey($surface, self::TENANT_A));
        }
        $this->cache()->deletePattern('shop:' . self::TENANT_A . ':*');
        $this->cache()->deletePattern('tenant:*:shop:' . self::TENANT_A . ':*');
        foreach (ShopLayoutTags::SURFACES as $surface) {
            self::assertSame(
                $tokens[$surface],
                $this->cache()->get(ShopLayoutTags::generationKey($surface, self::TENANT_A)),
                "{$surface}'s token survives the fallback purge",
            );
        }
    }

    /** A lost token — evicted, expired or deleted — never serves an entry stored under it. */
    public function testALostTokenNeverServesItsEntries(): void
    {
        $this->primeBoth();
        $lostKey = $this->key(self::TENANT_A, 'shop_index');
        $lost = $this->cache()->get(ShopLayoutTags::generationKey('shop_index', self::TENANT_A));
        self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', (string) $lost, 'a real token, read from the store');

        $this->cache()->delete(ShopLayoutTags::generationKey('shop_index', self::TENANT_A));
        $stale = $this->cache()->get($lostKey);
        $stale['body'] = str_replace('</body>', '<p>STALE-ENTRY</p></body>', (string) $stale['body']);
        $this->cache()->set($lostKey, $stale, 3600);

        $html = (string) $this->handle(Request::create('/shop', 'GET'))->getContent();
        self::assertStringNotContainsString('STALE-ENTRY', $html, 'the old entry is not served');
        $fresh = $this->cache()->get(ShopLayoutTags::generationKey('shop_index', self::TENANT_A));
        self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', (string) $fresh);
        self::assertNotSame($lost, $fresh, 'a new token, never the lost one');
        self::assertIsArray(
            $this->cache()->get($this->key(self::TENANT_A, 'shop_index')),
            'stored under the new token',
        );

        // Expired: a store whose token read comes back empty once, as a lapsed TTL does.
        $expired = $this->cache()->get(ShopLayoutTags::generationKey('shop_index', self::TENANT_A));
        $hidden = false;
        $store = $this->decorated(function (string $method, array $args) use ($expired, &$hidden): mixed {
            $gen = ShopLayoutTags::generationKey('shop_index', self::TENANT_A);
            if ($method === 'get' && $args[0] === $gen && !$hidden) {
                $hidden = true;
                $this->cache()->delete($args[0]);
                return null;
            }
            return $this->cache()->{$method}(...$args);
        });
        $response = $this->cacheWith($store)->handle(
            Request::create('/shop', 'GET'),
            fn (Request $r): Response => $this->render('shop_index', $r),
            'shop_index',
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame($expired, $this->cache()->get(ShopLayoutTags::generationKey('shop_index', self::TENANT_A)));
    }

    /** Initialization racing a save, both orders; and two initializers agree on one token. */
    public function testInitializationRacingASave(): void
    {
        $this->primeBoth();
        $gen = ShopLayoutTags::generationKey('shop_index', self::TENANT_A);

        // (a) The save lands before the initializer's setNx: the setNx loses, the request re-reads the
        // save's token and renders the new layout.
        $this->cache()->delete($gen);
        $store = $this->decorated(function (string $method, array $args) use ($gen): mixed {
            if ($method === 'setNx' && $args[0] === $gen) {
                $this->change('shop_index', 'SAVED-DURING-INIT');
            }
            return $this->cache()->{$method}(...$args);
        });
        $html = (string) $this->cacheWith($store)->handle(
            Request::create('/shop', 'GET'),
            fn (Request $r): Response => $this->render('shop_index', $r),
            'shop_index',
        )->getContent();
        self::assertStringContainsString('SAVED-DURING-INIT', $html, 'the request rendered the new layout');
        $next = (string) $this->handle(Request::create('/shop', 'GET'))->getContent();
        self::assertStringContainsString('SAVED-DURING-INIT', $next);

        // (b) The initializer's setNx wins, then a save lands before the render stores: the stored
        // entry sits under a replaced token and the next request renders the newer layout.
        $this->cache()->delete($gen);
        $html = (string) $this->container()->get(ShopPageCache::class)->handle(
            Request::create('/shop', 'GET'),
            function (Request $r): Response {
                $response = $this->render('shop_index', $r);
                $this->change('shop_index', 'SAVED-AFTER-INIT');
                return $response;
            },
            'shop_index',
        )->getContent();
        self::assertStringContainsString('SAVED-DURING-INIT', $html, 'the straddling render shows the older layout');
        $next = (string) $this->handle(Request::create('/shop', 'GET'))->getContent();
        self::assertStringContainsString('SAVED-AFTER-INIT', $next);

        // Two initializers at once: the second runs inside the first's setNx and wins it; the first's
        // setNx then loses, it reads back the second's token, and it hits the page the second stored
        // under that one token.
        $this->cache()->delete($gen);
        $inner = null;
        $innerBody = null;
        $nested = false;
        $store = $this->decorated(
            function (string $method, array $args) use ($gen, &$inner, &$innerBody, &$nested, &$store): mixed {
                if ($method === 'setNx' && $args[0] === $gen && !$nested) {
                    $nested = true;
                    $innerBody = (string) $this->cacheWith($store)->handle(
                        Request::create('/shop', 'GET'),
                        function (Request $r) use (&$inner): Response {
                            $inner = $this->cache()->get(ShopLayoutTags::generationKey('shop_index', self::TENANT_A));
                            return $this->render('shop_index', $r);
                        },
                        'shop_index',
                    )->getContent();
                }
                return $this->cache()->{$method}(...$args);
            },
        );
        $outerRendered = false;
        $outerBody = (string) $this->cacheWith($store)->handle(
            Request::create('/shop', 'GET'),
            function (Request $r) use (&$outerRendered): Response {
                $outerRendered = true;
                return $this->render('shop_index', $r);
            },
            'shop_index',
        )->getContent();
        self::assertIsString($inner, 'the inner initializer rendered, under the token it created');
        self::assertSame($inner, $this->cache()->get($gen), 'the one token the store keeps');
        self::assertFalse($outerRendered, 'the outer initializer hit the inner one\'s page: the same token');
        self::assertSame($innerBody, $outerBody);
    }

    /** A request whose token cannot be read back is served, never cached under an empty token. */
    public function testAnUnreadableTokenBypassesTheCache(): void
    {
        $this->primeBoth();
        $gen = ShopLayoutTags::generationKey('shop_index', self::TENANT_A);
        $this->cache()->delete($gen);
        $writes = [];
        $store = $this->decorated(function (string $method, array $args) use ($gen, &$writes): mixed {
            if ($method === 'get' && $args[0] === $gen) {
                return null; // never readable
            }
            if ($method === 'set') {
                $writes[] = $args[0];
            }
            return $this->cache()->{$method}(...$args);
        });
        $response = $this->cacheWith($store)->handle(
            Request::create('/shop', 'GET'),
            fn (Request $r): Response => $this->render('shop_index', $r),
            'shop_index',
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $writes, 'nothing stored');
    }

    public function testEntryAndListingChangesLeaveTheShopAlone(): void
    {
        $this->primeBoth();
        $keys = [];
        foreach (array_keys(self::PAGES) as $surface) {
            $keys[$surface] = $this->key(self::TENANT_A, $surface);
        }
        $events = $this->container()->get(EventService::class);
        $events->dispatch(new LayoutChanged('entry', 'post', null));
        $events->dispatch(new LayoutChanged('listing', 'post', null));
        foreach ($keys as $surface => $key) {
            self::assertSame($key, $this->key(self::TENANT_A, $surface), "{$surface} keeps its token");
            self::assertIsArray($this->cache()->get($key), "{$surface} is still cached");
        }
        // No workspace named (tenancy off): the shop's own resolution — the default tenant, A.
        self::assertSame(
            self::TENANT_A,
            $this->container()->get(CommerceTenantResolution::class)
                ->tenantUuid($this->container()->get(ApplicationContext::class)),
        );
        $events->dispatch(new LayoutChanged('shop_index', '@site', null));
        self::assertNotSame($keys['shop_index'], $this->key(self::TENANT_A, 'shop_index'));
        self::assertSame($keys['shop_category'], $this->key(self::TENANT_A, 'shop_category'));
    }

    /**
     * A CacheStore that routes the calls ShopPageCache makes through `$route($method, $args)`.
     *
     * @param callable(string, array<int,mixed>): mixed $route
     */
    private function decorated(callable $route): CacheStore
    {
        $store = $this->createMock(CacheStore::class);
        foreach (['get', 'set', 'setNx', 'addTags', 'delete', 'invalidateTags', 'deletePattern'] as $method) {
            $store->method($method)->willReturnCallback(
                static fn (mixed ...$args): mixed => $route($method, $args),
            );
        }
        return $store;
    }

    /** The shop page cache as the application builds it, over another store. */
    private function cacheWith(CacheStore $store): ShopPageCache
    {
        return \Thallo\Commerce\CommerceIntegrationServiceProvider::makeShopPageCache($this->withCache($store));
    }

    private function withCache(CacheStore $cache): ContainerInterface
    {
        $real = $this->container();
        return new class ($real, $cache) implements ContainerInterface {
            public function __construct(private readonly ContainerInterface $real, private readonly CacheStore $cache)
            {
            }

            public function get(string $id): mixed
            {
                return $id === CacheStore::class ? $this->cache : $this->real->get($id);
            }

            public function has(string $id): bool
            {
                return $id === CacheStore::class || $this->real->has($id);
            }
        };
    }
}
