<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Extensions\Commerce\Catalog\CatalogService;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Http\ProductLinkController;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Task 8: admin product<->entry linkage API (design spec §5.3). Mirrors the established
 * convention in this codebase (NavigationApiTest/WorkflowApiTest): resolve the controller
 * directly from the container and drive it with hand-built Request objects, rather than going
 * through the full HTTP auth/tenant middleware pipeline — the middleware WIRING itself (auth,
 * admin_tenant_binding, content_permission) is checked structurally, mirroring
 * AdminRouteBindingTest/AdminRoutesGatedTest, and the disabled-capability 404 case mirrors
 * WorkflowRemovabilityTest's bootAppWithConfigOverride convention.
 */
final class ProductLinkApiTest extends AppTestCase
{
    private const TENANT_A = 'plapitenanta';

    private static int $seq = 0;

    /** Lazily seeded once per test method by {@see self::searchableContentTypeUuid()}. */
    private ?string $searchableContentTypeUuid = null;
    private ?string $searchableContentTypeSlug = null;

    /**
     * Task 7's entry-search tests raw-insert into content_types/entry_drafts/entry_versions/
     * entry_publications in addition to `entries` -- all four are registered tenant-owned tables
     * (ThalloTenantTables), and the process-global compat-write insert hook this class's own
     * `tenancy.schema_state=widened` flag (re-)arms (see testRoutesAbsentWhenCapabilityDisabled's
     * docblock: "never scoped per-boot, never auto-cleared") stamps `tenant_uuid` onto every
     * insert targeting them -- so each needs the SAME ad hoc column ALTER `entries` already gets.
     */
    private const TENANT_ALTERED_TABLES = ['entries', 'content_types', 'entry_drafts', 'entry_versions',
        'entry_publications'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        $this->flags()->put('tenancy.schema_state', 'widened');
        $this->flags()->put('tenancy.default_tenant_uuid', self::TENANT_A);
        foreach (self::TENANT_ALTERED_TABLES as $table) {
            $this->connection()->getPDO()->exec(
                "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(191) NOT NULL DEFAULT ''"
            );
        }

        // searchEntries' locale resolution reads enabled locales from i18n_locales; the
        // fr-row assertion needs fr ENABLED, so this class owns its locale setup instead
        // of leaning on rows a sibling suite happens to leave behind (mirrors
        // RootMountGuardTest / RegionRenderingTest — on a fresh database no earlier test
        // seeds fr and `?locale=fr` silently falls back to the workspace default).
        $this->connection()->getPDO()->exec("DELETE FROM i18n_locales WHERE code IN ('en', 'fr')");
        $seedNow = gmdate('Y-m-d H:i:s');
        foreach ([['en', true], ['fr', false]] as [$code, $isDefault]) {
            $this->connection()->table('i18n_locales')->insert([
                'uuid' => \Glueful\Helpers\Utils::generateNanoID(),
                'code' => $code,
                'name' => strtoupper($code),
                'enabled' => true,
                'is_default' => $isDefault,
                'fallback_locale' => $isDefault ? null : 'en',
                'created_at' => $seedNow,
                'updated_at' => $seedNow,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->connection()->getPDO()->exec('DELETE FROM commerce_products');
        foreach (self::TENANT_ALTERED_TABLES as $table) {
            $this->connection()->getPDO()->exec("ALTER TABLE {$table} DROP COLUMN IF EXISTS tenant_uuid");
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // PUT .../link — 201 create / 200 relink / 404 / 422 / 409
    // ------------------------------------------------------------------

    public function testLinkReturns201OnFirstLinkAnd200OnRelink(): void
    {
        $product = $this->seedProduct('api-link-201');
        $entryOne = $this->seedEntry();
        $entryTwo = $this->seedEntry();

        $res = $this->admin()->link($this->req(['entry_uuid' => $entryOne]), $product);
        self::assertSame(201, $res->getStatusCode());
        self::assertSame($entryOne, $this->data($res)['entry_uuid']);

        $res = $this->admin()->link(
            $this->req(['entry_uuid' => $entryTwo, 'expected_entry_uuid' => $entryOne]),
            $product,
        );
        self::assertSame(200, $res->getStatusCode());
        self::assertSame($entryTwo, $this->data($res)['entry_uuid']);
    }

    public function testLinkReturns404ForUnknownProduct(): void
    {
        $entry = $this->seedEntry();

        $res = $this->admin()->link($this->req(['entry_uuid' => $entry]), 'noSuchProduct');
        self::assertSame(404, $res->getStatusCode());
    }

    public function testLinkReturns404ForUnknownEntry(): void
    {
        $product = $this->seedProduct('api-link-404-entry');

        $res = $this->admin()->link($this->req(['entry_uuid' => 'noSuchEntry01']), $product);
        self::assertSame(404, $res->getStatusCode());
    }

    public function testLinkReturns422ForMissingEntryUuid(): void
    {
        $product = $this->seedProduct('api-link-422');

        $this->expectException(\Glueful\Validation\ValidationException::class);
        $this->admin()->link($this->req([]), $product);
    }

    public function testLinkReturns422ForNonStringEntryUuid(): void
    {
        $product = $this->seedProduct('api-link-422b');

        $this->expectException(\Glueful\Validation\ValidationException::class);
        $this->admin()->link($this->req(['entry_uuid' => ['not' => 'a string']]), $product);
    }

    public function testLinkReturns409WhenAlreadyLinkedWithoutExpectation(): void
    {
        $product = $this->seedProduct('api-link-409');
        $entryOne = $this->seedEntry();
        $entryTwo = $this->seedEntry();

        self::assertSame(201, $this->admin()->link($this->req(['entry_uuid' => $entryOne]), $product)->getStatusCode());

        $res = $this->admin()->link($this->req(['entry_uuid' => $entryTwo]), $product);
        self::assertSame(409, $res->getStatusCode());
    }

    // ------------------------------------------------------------------
    // DELETE .../link
    // ------------------------------------------------------------------

    public function testUnlinkReturns200AndRemovesTheLink(): void
    {
        $product = $this->seedProduct('api-unlink-200');
        $entry = $this->seedEntry();
        $this->admin()->link($this->req(['entry_uuid' => $entry]), $product);

        $res = $this->admin()->unlink(Request::create('/x', 'DELETE'), $product);
        self::assertSame(200, $res->getStatusCode());

        // Task 7: unlinking never tombstones the product -- it stays accessible, so the lookup
        // is a 200 projection with a null link, not a 404.
        $show = $this->admin()->showByProduct(Request::create('/x', 'GET'), $product);
        self::assertSame(200, $show->getStatusCode());
        self::assertNull($this->data($show)['link']);
    }

    public function testUnlinkOfAnUnlinkedProductIsAlsoAnIdempotent200(): void
    {
        $product = $this->seedProduct('api-unlink-noop');

        $res = $this->admin()->unlink(Request::create('/x', 'DELETE'), $product);
        self::assertSame(200, $res->getStatusCode());
    }

    // ------------------------------------------------------------------
    // GET .../link (by product / by entry)
    // ------------------------------------------------------------------

    public function testShowByProductAndByEntryReturn200ThenTheRow(): void
    {
        $product = $this->seedProduct('api-show-both');
        $entry = $this->seedEntry();
        $this->admin()->link($this->req(['entry_uuid' => $entry]), $product);

        $byProduct = $this->admin()->showByProduct(Request::create('/x', 'GET'), $product);
        self::assertSame(200, $byProduct->getStatusCode());
        $byProductData = $this->data($byProduct);
        self::assertSame($product, $byProductData['product_uuid']);
        self::assertIsString($byProductData['storefront_url']);
        self::assertSame($entry, $byProductData['link']['entry_uuid']);

        $byEntry = $this->admin()->showByEntry(Request::create('/x', 'GET'), $entry);
        self::assertSame(200, $byEntry->getStatusCode());
        self::assertSame($product, $this->data($byEntry)['product_uuid']);
    }

    /**
     * Task 7: showByProduct's contract changed -- an accessible product with NO active link is
     * now a 200 projection with `link: null`, not a 404. 404 is reserved for a product that
     * doesn't resolve at all (unknown/cross-tenant/tombstoned). showByEntry is unchanged.
     */
    public function testShowByProductReturns404OnlyForAnUnresolvableProduct(): void
    {
        self::assertSame(
            404,
            $this->admin()->showByProduct(Request::create('/x', 'GET'), 'noSuchProduct')->getStatusCode(),
        );
    }

    public function testShowByProductReturnsTheProjectionWithNullLinkForAnAccessibleUnlinkedProduct(): void
    {
        $product = $this->seedProduct('api-show-unlinked');

        $response = $this->admin()->showByProduct(Request::create('/x', 'GET'), $product);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->data($response);
        self::assertSame(['product_uuid', 'storefront_url', 'link'], array_keys($data));
        self::assertSame($product, $data['product_uuid']);
        self::assertIsString($data['storefront_url']);
        self::assertNotSame('', $data['storefront_url']);
        self::assertNull($data['link']);
    }

    public function testShowByEntryReturns404WhenAbsent(): void
    {
        $entry = $this->seedEntry();

        self::assertSame(404, $this->admin()->showByEntry(Request::create('/x', 'GET'), $entry)->getStatusCode());
    }

    // ------------------------------------------------------------------
    // GET .../link — storefront_url composition (task 7)
    // ------------------------------------------------------------------

    /**
     * This class's setUp() always runs under tenancy mode (b) (widened schema + a persisted
     * default tenant, TENANT_A) -- the "workspace" mode. Enforcement is never active in this
     * harness (see StorefrontPreviewUrlTest's class docblock), so `storefront_url` still composes
     * from `app.urls.base` regardless of which tenancy-resolution mode owns the product/link
     * lookup itself -- proving the URL builder's origin composition is orthogonal to tenant mode.
     */
    public function testShowByProductStorefrontUrlIsAbsoluteInWorkspaceMode(): void
    {
        $slug = 'workspace-preview-' . (++self::$seq);
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug,
            'name' => 'Workspace Preview',
            'status' => 'active',
            'type' => 'external',
            'metadata' => ['external_url' => 'https://example.test/workspace-preview'],
        ]);
        $productUuid = (string) $product['uuid'];

        $response = $this->admin()->showByProduct(Request::create('/x', 'GET'), $productUuid);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->data($response);
        self::assertSame($this->expectedOrigin() . '/shop/products/' . $slug, $data['storefront_url']);
        self::assertNull($data['link']);
    }

    /**
     * Same assertion, reverted to mode (a) (clean sentinel tenant, "single-store" mode) for the
     * duration of this one test -- reuses Task 5's `StorefrontPreviewUrlTest::expectedOrigin()`
     * derivation to prove the SAME origin composition holds in both modes.
     */
    public function testShowByProductStorefrontUrlIsAbsoluteInSingleStoreModeToo(): void
    {
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $slug = 'single-store-preview-' . (++self::$seq);
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug,
            'name' => 'Single Store Preview',
            'status' => 'active',
            'type' => 'external',
            'metadata' => ['external_url' => 'https://example.test/single-store-preview'],
        ]);
        $productUuid = (string) $product['uuid'];

        $response = $this->admin()->showByProduct(Request::create('/x', 'GET'), $productUuid);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->data($response);
        self::assertSame($this->expectedOrigin() . '/shop/products/' . $slug, $data['storefront_url']);
        self::assertNull($data['link']);
    }

    // ------------------------------------------------------------------
    // Route wiring — structural checks (mirrors AdminRouteBindingTest/AdminRoutesGatedTest)
    // ------------------------------------------------------------------

    public function testRoutesAreRegisteredBehindAdminTenantBindingAndContentPermission(): void
    {
        // Task 7: the two link GETs (+ the new entry-search GET) are regraded to admit
        // view-only operators where read-only; PUT/DELETE stay manage-only.
        $routes = [
            ['PUT', '/v1/admin/commerce/products/{productUuid}/link', 'content_permission:commerce.manage'],
            ['DELETE', '/v1/admin/commerce/products/{productUuid}/link', 'content_permission:commerce.manage'],
            [
                'GET',
                '/v1/admin/commerce/products/{productUuid}/link',
                'content_permission:commerce.view,commerce.manage',
            ],
            [
                'GET',
                '/v1/admin/commerce/entries/{entryUuid}/link',
                'content_permission:commerce.view,commerce.manage',
            ],
            ['GET', '/v1/admin/commerce/entries', 'content_permission:commerce.manage'],
        ];
        foreach ($routes as [$method, $path, $expectedPermission]) {
            $route = $this->findRoute($method, $path);
            self::assertNotNull($route, "{$method} {$path} must be registered");
            $middleware = $route['middleware'] ?? [];
            self::assertContains('admin_tenant_binding', $middleware, "{$method} {$path} must bind the workspace");
            self::assertContains(
                $expectedPermission,
                $middleware,
                "{$method} {$path} must require {$expectedPermission}",
            );
        }
    }

    /**
     * Task 7: `GET /entries` (the search endpoint, static path) and
     * `GET /entries/{entryUuid}/link` (dynamic, one more path segment) share the same first
     * segment bucket -- prove the router resolves each to its OWN controller action rather than
     * one shadowing the other.
     */
    public function testEntriesSearchRouteIsNotShadowedByTheEntryLinkRoute(): void
    {
        $searchMatch = $this->router()->match(Request::create('/v1/admin/commerce/entries?q=ab', 'GET'));
        self::assertNotNull($searchMatch, 'GET /entries must resolve to a registered route');
        self::assertSame([ProductLinkController::class, 'searchEntries'], $searchMatch['route']->getHandler());

        $linkMatch = $this->router()->match(Request::create('/v1/admin/commerce/entries/someuuid001/link', 'GET'));
        self::assertNotNull($linkMatch, 'GET /entries/{entryUuid}/link must resolve to a registered route');
        self::assertSame([ProductLinkController::class, 'showByEntry'], $linkMatch['route']->getHandler());
        self::assertSame('someuuid001', $linkMatch['params']['entryUuid'] ?? null);
    }

    public function testRoutesAbsentWhenCapabilityDisabled(): void
    {
        // This test triggers a SECOND full app boot via bootAppWithConfigOverride().
        // TenancyServiceProvider::boot() reads LIVE SystemFlags at THAT boot to decide whether
        // to register its process-GLOBAL, static compat-write insert hook
        // (Connection::addInsertHook() — never scoped per-boot, never auto-cleared). This
        // class's setUp() left `schema_state=widened` — reset it back to 'none' BEFORE the
        // second boot so it never sees compat mode and never adds a hook that would outlive
        // this test and corrupt every later test's inserts for the rest of the phpunit process
        // (mirrors TenantOracleTestCase::tearDownAfterClass()'s own explicit-cleanup discipline
        // for the same static registries).
        $this->flags()->forget('tenancy.schema_state');
        $this->flags()->forget('tenancy.default_tenant_uuid');

        $disabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.commerce' => false],
        ]);

        $hit = static fn (string $method, string $path): int => (new Application($disabledApp))->handle(
            Request::create($path, $method, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ]),
        )->getStatusCode();

        self::assertSame(404, $hit('PUT', '/v1/admin/commerce/products/p-1/link'));
        self::assertSame(404, $hit('DELETE', '/v1/admin/commerce/products/p-1/link'));
        self::assertSame(404, $hit('GET', '/v1/admin/commerce/products/p-1/link'));
        self::assertSame(404, $hit('GET', '/v1/admin/commerce/entries/e-1/link'));
        self::assertSame(404, $hit('GET', '/v1/admin/commerce/entries?q=ab'));

        self::resetSharedRepositoryConnection();
    }

    // ------------------------------------------------------------------
    // GET /entries — search (task 7)
    // ------------------------------------------------------------------

    public function testSearchEntriesThrowsValidationExceptionForAQueryShorterThanTwoCharacters(): void
    {
        $this->expectException(\Glueful\Validation\ValidationException::class);
        $this->admin()->searchEntries(Request::create('/x?q=a', 'GET'));
    }

    public function testSearchEntriesReturnsExactlyTheFiveProjectedFields(): void
    {
        $entryUuid = $this->seedSearchableEntry(self::TENANT_A, 'About Our Company');

        $rows = $this->rows($this->admin()->searchEntries(Request::create('/x?q=abo', 'GET')));

        self::assertCount(1, $rows);
        self::assertEqualsCanonicalizing(['uuid', 'title', 'content_type', 'status', 'locale'], array_keys($rows[0]));
        self::assertSame($entryUuid, $rows[0]['uuid']);
        self::assertSame('About Our Company', $rows[0]['title']);
        self::assertSame($this->searchableContentTypeSlug, $rows[0]['content_type']);
        self::assertSame('draft', $rows[0]['status']);
        self::assertSame('en', $rows[0]['locale']);
    }

    public function testSearchEntriesReportsPublishedStatusOncePublished(): void
    {
        $entryUuid = $this->seedSearchableEntry(self::TENANT_A, 'Published Report');
        $this->publishSearchableEntry($entryUuid, 'en');

        $rows = $this->rows($this->admin()->searchEntries(Request::create('/x?q=publish', 'GET')));

        self::assertCount(1, $rows);
        self::assertSame('published', $rows[0]['status']);
    }

    public function testSearchEntriesIsTenantScoped(): void
    {
        $ownTenantEntry = $this->seedSearchableEntry(self::TENANT_A, 'Widget Scoping Alpha');
        $this->seedSearchableEntry('plapiothertenant', 'Widget Scoping Beta');

        $rows = $this->rows($this->admin()->searchEntries(Request::create('/x?q=widget+scoping', 'GET')));

        self::assertCount(1, $rows);
        self::assertSame($ownTenantEntry, $rows[0]['uuid']);
    }

    public function testSearchEntriesResolvesOneDeterministicLocaleRowPerEntry(): void
    {
        $entryUuid = $this->seedSearchableEntry(self::TENANT_A, 'Blue Widget', 'en');
        $this->addSearchableDraft($entryUuid, 'fr', 'Widget Bleu');

        $default = $this->rows($this->admin()->searchEntries(Request::create('/x?q=widget', 'GET')));
        self::assertCount(1, $default, 'one row per entry, never a locale fan-out');
        self::assertSame('en', $default[0]['locale']);
        self::assertSame('Blue Widget', $default[0]['title']);

        $french = $this->rows($this->admin()->searchEntries(Request::create('/x?q=widget&locale=fr', 'GET')));
        self::assertCount(1, $french);
        self::assertSame('fr', $french[0]['locale']);
        self::assertSame('Widget Bleu', $french[0]['title']);

        // A disabled/unknown locale falls back to the workspace default rather than 404ing or
        // returning an empty result.
        $unknownLocale = $this->rows($this->admin()->searchEntries(Request::create('/x?q=widget&locale=de', 'GET')));
        self::assertCount(1, $unknownLocale);
        self::assertSame('en', $unknownLocale[0]['locale']);
    }

    public function testSearchEntriesHardCapsAtTwenty(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->seedSearchableEntry(self::TENANT_A, 'Cap Test Item ' . $i);
        }

        $rows = $this->rows($this->admin()->searchEntries(Request::create('/x?q=cap+test', 'GET')));

        self::assertCount(20, $rows);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function admin(): ProductLinkController
    {
        return $this->container()->get(ProductLinkController::class);
    }

    private function flags(): SystemFlags
    {
        return $this->container()->get(SystemFlags::class);
    }

    /** @param array<string,mixed> $body */
    private function req(array $body): Request
    {
        return Request::create(
            '/x',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body),
        );
    }

    /** @return array<string,mixed> */
    private function data(Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true)['data'];
    }

    /** @return list<array<string,mixed>> */
    private function rows(Response $res): array
    {
        return (array) json_decode((string) $res->getContent(), true)['data'];
    }

    /**
     * Independently re-derives the expected single-store origin from `app.urls.base` -- mirrors
     * {@see \App\Tests\Integration\Commerce\StorefrontPreviewUrlTest}'s identical helper (that
     * class's own tenancy-mode-free setup makes it unreachable from here without duplicating it).
     */
    private function expectedOrigin(): string
    {
        $base = (string) config($this->appContext(), 'app.urls.base', 'http://localhost');
        $parts = parse_url($base);
        self::assertIsArray($parts, 'app.urls.base must be an absolute URL');
        self::assertArrayHasKey('scheme', $parts);
        self::assertArrayHasKey('host', $parts);

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private function seedProduct(string $slug): string
    {
        $product = $this->container()->get(CatalogService::class)->createProduct($this->appContext(), [
            'slug' => $slug . '-' . (++self::$seq),
            'name' => ucfirst($slug),
            'status' => 'active',
            'type' => 'external',
            'metadata' => ['external_url' => 'https://example.test/' . $slug],
        ]);

        return (string) $product['uuid'];
    }

    /** Raw-seeds `entries` directly (see ProductLinkServiceTest's identical helper docblock). */
    private function seedEntry(): string
    {
        self::$seq++;
        $uuid = 'plapie' . str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('entries')->insert([
            'uuid' => $uuid,
            'content_type_uuid' => 'plapitype001',
            'status' => 'active',
            'tenant_uuid' => self::TENANT_A,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $uuid;
    }

    // ------------------------------------------------------------------
    // entry-search seeding (task 7) — raw-seeds entries/entry_drafts/entry_versions/
    // entry_publications directly (mirrors seedEntry()'s + EntryLocaleSummaryTest's identical
    // raw-insert convention), NOT through EntryRepository/PublishService: this pack's tenant
    // scoping needs the `tenant_uuid` column stamped explicitly (no ambient tenancy write-hook is
    // active in this harness — see class docblock), which a repository-layer insert would not do.
    // ------------------------------------------------------------------

    private function seedSearchableEntry(string $tenant, string $title, string $locale = 'en'): string
    {
        self::$seq++;
        $uuid = 'plseent' . str_pad((string) self::$seq, 5, '0', STR_PAD_LEFT);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('entries')->insert([
            'uuid' => $uuid,
            'content_type_uuid' => $this->searchableContentTypeUuid(),
            'status' => 'active',
            'tenant_uuid' => $tenant,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->addSearchableDraft($uuid, $locale, $title);

        return $uuid;
    }

    private function addSearchableDraft(string $entryUuid, string $locale, string $title): void
    {
        $this->connection()->table('entry_drafts')->insert([
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'fields' => json_encode(['title' => $title], JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'lock_version' => 0,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function publishSearchableEntry(string $entryUuid, string $locale): void
    {
        $versionUuid = Utils::generateNanoID(12);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('entry_versions')->insert([
            'uuid' => $versionUuid,
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'version' => 1,
            'fields' => json_encode([], JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'created_at' => $now,
        ]);
        $this->connection()->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'version_uuid' => $versionUuid,
            'published_at' => $now,
        ]);
    }

    /** One content type, lazily seeded and cached for the lifetime of a single test method. */
    private function searchableContentTypeUuid(): string
    {
        if ($this->searchableContentTypeUuid === null) {
            $uuid = Utils::generateNanoID(12);
            $slug = 'plse-type-' . (++self::$seq);
            $now = gmdate('Y-m-d H:i:s');
            $this->connection()->table('content_types')->insert([
                'uuid' => $uuid,
                'slug' => $slug,
                'name' => 'PLSE Search Type',
                'description' => null,
                'cache_ttl' => null,
                'public_delivery' => false,
                'status' => 'active',
                'schema' => json_encode([], JSON_THROW_ON_ERROR),
                'schema_version' => 1,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->searchableContentTypeUuid = $uuid;
            $this->searchableContentTypeSlug = $slug;
        }

        return $this->searchableContentTypeUuid;
    }
}
