<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Delivery;

use App\Content\Delivery\DeliveryItemShaper;
use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\ReferenceFilterResolver;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Delivery\SortCompiler;
use App\Content\Http\Controllers\TaxonomyController;
use App\Content\Http\DeliveryEtag;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryFacetsQuery;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryListQuery;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Seo\CanonicalProjector;
use App\Content\Seo\PathRenderer;
use App\Content\Seo\RedirectRepository;
use App\Tests\Support\FakeLocaleManager;
use App\Tests\Support\AppTestCase;
use Glueful\Support\FieldSelection\Projector;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Term facets + archive endpoints (term-archives/facets spec §2–§6): projection-backed
 * counts and membership, fail-closed target-type visibility, DeliveryListQuery-mirrored
 * pagination envelopes, and the facets-before-show route precedence.
 */
final class TermFacetsArchiveTest extends AppTestCase
{
    private const CAT_TYPE_UUID = 'cattypefct00';
    private string $postType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection()->table('content_types')->insert([
            'uuid' => self::CAT_TYPE_UUID,
            'slug' => 'category',
            'name' => 'Category',
            'description' => null,
            'cache_ttl' => null,
            'public_delivery' => true,
            'status' => 'active',
            'schema' => json_encode(
                [['name' => 'slug', 'type' => 'string', 'required' => true]],
                JSON_THROW_ON_ERROR,
            ),
            'schema_version' => 1,
            'created_by' => null,
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-06-01 00:00:00',
        ]);
        $this->postType = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                [
                    'name' => 'category',
                    'type' => 'reference',
                    'reference_type' => 'category',
                    'reference_slug_field' => 'slug',
                    'multiple' => true,
                    'filterable' => true,
                ],
            ],
        ]);
    }

    // ---- helpers -------------------------------------------------------------------

    private function controller(): TaxonomyController
    {
        $conn = $this->connection();
        $repo = new DeliveryRepository($conn);
        $types = new ContentTypeRepository($conn);
        $routes = new RouteRepository($conn, new RedirectRepository($conn));
        $paths = new PathRenderer('/{locale}/{type}/{slug}', null, 'en');
        $references = new ReferenceResolver($repo);
        $projector = new Projector();
        $canonical = new CanonicalProjector(
            $repo,
            $routes,
            $types,
            new \App\Content\Seo\CanonicalPathBuilder(
                $paths,
                $this->container()->get(\Glueful\Extensions\I18n\Contracts\LocaleManagerInterface::class),
            ),
            'en',
        );

        return new TaxonomyController(
            $this->appContext(),
            $repo,
            $types,
            $this->container()->get(PublishedReferenceRepository::class),
            $this->container()->get(FilterCompiler::class),
            new SortCompiler(),
            $references,
            new ReferenceFilterResolver($conn, $types),
            $projector,
            new DeliveryEtag(),
            new FakeLocaleManager(),
            $canonical,
            null,
            new DeliveryItemShaper($types, $references, $projector, $canonical, null),
        );
    }

    private function seedCategory(string $entryUuid, string $versionUuid, string $slug): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => self::CAT_TYPE_UUID, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['slug' => $slug], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => 'en', 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
    }

    /** Seed a published post + its projection rows (the projection is the read source). */
    private function seedMemberPost(
        string $entryUuid,
        string $versionUuid,
        array $categoryUuids,
        string $title = 'P',
    ): void {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => $title, 'category' => $categoryUuids], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => 'en', 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
        $this->container()->get(PublishedReferenceRepository::class)
            ->projectFromPublished($entryUuid, $this->postType, 'en');
    }

    /**
     * @return array{
     *     status: int,
     *     body: array<string,mixed>,
     *     headers: \Symfony\Component\HttpFoundation\ResponseHeaderBag
     * }
     */
    private function facets(string $type, string $fields, ?int $limit = null): array
    {
        $res = $this->controller()->facets(
            Request::create('/v1/content/' . $type . '/facets', 'GET', ['fields' => $fields]),
            new DeliveryFacetsQuery(fields: $fields, limit: $limit),
            $type,
        );
        return [
            'status' => $res->getStatusCode(),
            'body' => (array) json_decode((string) $res->getContent(), true),
            'headers' => $res->headers,
        ];
    }

    // ---- facets ---------------------------------------------------------------------

    public function testFacetCountsGroupDistinctSourcesPerTerm(): void
    {
        $this->seedCategory('cathw0000001', 'vcathw000001', 'php');
        $this->seedCategory('cathw0000002', 'vcathw000002', 'laravel');
        $this->seedMemberPost('fpost0000001', 'vfpost000001', ['cathw0000001']);
        $this->seedMemberPost('fpost0000002', 'vfpost000002', ['cathw0000001', 'cathw0000002']);

        $r = $this->facets('post', 'category');
        self::assertSame(200, $r['status']);
        $cats = $r['body']['data']['category'];
        self::assertSame(
            [['uuid' => 'cathw0000001', 'slug' => 'php', 'count' => 2],
             ['uuid' => 'cathw0000002', 'slug' => 'laravel', 'count' => 1]],
            $cats, // count DESC, slug ASC
        );
        // Surrogate tags: source AND target type (zero new purge code rides these).
        self::assertStringContainsString('lemma:type:post', (string) $r['headers']->get('Cache-Tag'));
        self::assertStringContainsString('lemma:type:category', (string) $r['headers']->get('Cache-Tag'));
    }

    public function testUnpublishedTermDropsOutOfFacetsWhileProjectionRowsRemain(): void
    {
        // THE read-time-join guard (spec §1): a term can be unpublished without deletion.
        $this->seedCategory('catlive00001', 'vcatlive0001', 'live');
        $this->seedCategory('catdead00001', 'vcatdead0001', 'dead');
        $this->seedMemberPost('fpost0000003', 'vfpost000003', ['catlive00001', 'catdead00001']);

        $this->connection()->table('entry_publications')
            ->where('entry_uuid', '=', 'catdead00001')->delete(); // unpublish the term only

        $rows = $this->connection()->table('published_entry_references')
            ->where('target_entry_uuid', '=', 'catdead00001')->get();
        self::assertNotSame([], $rows, 'precondition: projection rows for the dead term still exist');

        $r = $this->facets('post', 'category');
        $uuids = array_column($r['body']['data']['category'], 'uuid');
        self::assertContains('catlive00001', $uuids);
        self::assertNotContains('catdead00001', $uuids);
    }

    public function testNonFilterableOrUnknownFieldIsRejected(): void
    {
        self::assertSame(422, $this->facets('post', 'title')['status']);   // not a reference field
        self::assertSame(422, $this->facets('post', 'nope')['status']);    // unknown field
    }

    public function testFacetLimitCapsPerFieldResults(): void
    {
        $this->seedCategory('catlim000001', 'vcatlim00001', 'aaa');
        $this->seedCategory('catlim000002', 'vcatlim00002', 'bbb');
        $this->seedMemberPost('fpost0000004', 'vfpost000004', ['catlim000001', 'catlim000002']);

        $r = $this->facets('post', 'category', 1);
        self::assertCount(1, $r['body']['data']['category']);
    }

    public function testNonPublicTargetTypeFailsClosedWithNoEnumeration(): void
    {
        // The P1 enumeration guard (spec §2/§4): whole-set term enumeration must not
        // leak when the TARGET type is not visible to the caller.
        $this->seedCategory('catpriv00001', 'vcatpriv0001', 'secret');
        $this->seedMemberPost('fpost0000005', 'vfpost000005', ['catpriv00001']);
        $this->connection()->table('content_types')
            ->where('uuid', '=', self::CAT_TYPE_UUID)->update(['public_delivery' => false]);

        $r = $this->facets('post', 'category'); // anonymous (no api_key_scopes attribute)
        self::assertSame(404, $r['status']);
        self::assertStringNotContainsString('secret', (string) json_encode($r['body']));
        self::assertStringNotContainsString('catpriv00001', (string) json_encode($r['body']));
    }

    public function testFacetsRouteWinsOverShowRoute(): void
    {
        // Kernel-level characterization: /{type}/facets is registered before
        // /{type}/{slugOrUuid}, so `facets` is a reserved word on this surface.
        $res = $this->handle(Request::create('/v1/content/post/facets?fields=category', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getContent(), true);
        self::assertArrayHasKey('category', $body['data']);
    }

    // ---- archive --------------------------------------------------------------------

    /**
     * @return array{
     *     status: int,
     *     body: array<string,mixed>,
     *     headers: \Symfony\Component\HttpFoundation\ResponseHeaderBag
     * }
     */
    private function archive(string $type, string $field, string $term, array $queryParams = []): array
    {
        $dto = (new RequestDataHydrator())->hydrate(DeliveryListQuery::class, [], [], $queryParams);
        $req = new Request($queryParams);
        $res = $this->controller()->archive($req, $dto, $type, $field, $term);
        return [
            'status' => $res->getStatusCode(),
            'body' => (array) json_decode((string) $res->getContent(), true),
            'headers' => $res->headers,
        ];
    }

    public function testArchiveResolvesTermBySlugAndUuidWithEnvelope(): void
    {
        $this->seedCategory('catarc000001', 'vcatarc00001', 'news');
        $this->seedMemberPost('apost0000001', 'vapost000001', ['catarc000001'], 'A1');
        $this->seedMemberPost('apost0000002', 'vapost000002', [], 'A2'); // not a member

        foreach (['news', 'catarc000001'] as $termInput) {
            $r = $this->archive('post', 'category', $termInput);
            self::assertSame(200, $r['status']);
            self::assertSame('catarc000001', $r['body']['data']['term']['uuid']);
            $memberUuids = array_column($r['body']['data']['items'], 'uuid');
            self::assertSame(['apost0000001'], $memberUuids);
            self::assertArrayHasKey('next_cursor', $r['body']['data']);
        }
        // Surrogate tags: member entries + term entry + both types.
        $r = $this->archive('post', 'category', 'news');
        $cacheTag = (string) $r['headers']->get('Cache-Tag');
        self::assertStringContainsString('lemma:entry:apost0000001', $cacheTag);
        self::assertStringContainsString('lemma:entry:catarc000001', $cacheTag);
        self::assertStringContainsString('lemma:type:post', $cacheTag);
        self::assertStringContainsString('lemma:type:category', $cacheTag);
    }

    public function testArchiveMembershipComesFromTheProjectionNotJsonb(): void
    {
        // Seed a deliberate divergence (spec §3 pin): the stored JSONB claims membership,
        // the projection does not — the projection must win.
        $this->seedCategory('catdiv000001', 'vcatdiv00001', 'div');
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'divpost00001', 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => 'vdivpost0001', 'entry_uuid' => 'divpost00001', 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => 'Div', 'category' => ['catdiv000001']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'divpost00001', 'locale' => 'en', 'version_uuid' => 'vdivpost0001',
            'published_at' => '2026-06-01 01:00:00',
        ]);
        // NO projection row inserted.

        $r = $this->archive('post', 'category', 'div');
        self::assertSame(200, $r['status']);
        self::assertSame([], $r['body']['data']['items']); // JSONB says member; projection says no
    }

    public function testUnknownTermIs404AndEmptyArchiveIs200(): void
    {
        $this->seedCategory('catempty0001', 'vcatempty001', 'empty');
        self::assertSame(404, $this->archive('post', 'category', 'no-such-term')['status']);

        $r = $this->archive('post', 'category', 'empty');
        self::assertSame(200, $r['status']);
        self::assertSame([], $r['body']['data']['items']);
    }

    public function testArchiveOffsetModeUsesFlattenedEnvelopeWithTopLevelTerm(): void
    {
        $this->seedCategory('catpage00001', 'vcatpage0001', 'paged');
        $this->seedMemberPost('ppost0000001', 'vppost000001', ['catpage00001'], 'P1');
        $this->seedMemberPost('ppost0000002', 'vppost000002', ['catpage00001'], 'P2');

        $r = $this->archive('post', 'category', 'paged', ['page' => '1', 'perPage' => '1']);
        self::assertSame(200, $r['status']);
        self::assertSame('catpage00001', $r['body']['term']['uuid']); // top-level in offset mode
        self::assertCount(1, $r['body']['data']);
        self::assertSame(2, $r['body']['total']);
        self::assertSame(2, $r['body']['total_pages']);
        self::assertTrue($r['body']['has_next_page']);
    }

    public function testArchiveComposesExtraFiltersAndRejectsBadField(): void
    {
        $this->seedCategory('catflt000001', 'vcatflt00001', 'flt');
        $this->seedMemberPost('xpost0000001', 'vxpost000001', ['catflt000001'], 'Keep');

        // Extra filter[...] on a non-filterable field → 422 through the same compiler.
        $r = $this->archive('post', 'category', 'flt', ['filter' => ['title' => ['eq' => 'Keep']]]);
        self::assertSame(422, $r['status']);

        // Bad {field} segment → 422 (same gate as facets).
        self::assertSame(422, $this->archive('post', 'title', 'flt')['status']);
    }

    public function testArchiveNonPublicTermTypeFailsClosed(): void
    {
        $this->seedCategory('catpriv00002', 'vcatpriv0002', 'hidden');
        $this->seedMemberPost('hpost0000001', 'vhpost000001', ['catpriv00002']);
        $this->connection()->table('content_types')
            ->where('uuid', '=', self::CAT_TYPE_UUID)->update(['public_delivery' => false]);

        $r = $this->archive('post', 'category', 'hidden'); // anonymous
        self::assertSame(404, $r['status']);
        self::assertStringNotContainsString('hidden', (string) json_encode($r['body']));
    }

    public function testArchiveRouteResolvesThroughTheKernel(): void
    {
        $this->seedCategory('catkern00001', 'vcatkern0001', 'kern');
        $this->seedMemberPost('kpost0000001', 'vkpost000001', ['catkern00001'], 'K1');

        $res = $this->handle(Request::create('/v1/content/post/archive/category/kern', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getContent(), true);
        self::assertSame('catkern00001', $body['data']['term']['uuid']);
    }

    public function testNonPublicSourceTypeIsDeniedOnBothEndpointsThroughTheKernel(): void
    {
        // Review P2: SOURCE-type visibility is enforced by the lemma_delivery_access
        // route middleware (same as the existing delivery routes) — controller-direct
        // tests bypass it, so prove the route wiring at kernel level for both endpoints.
        // The middleware denies with 403 ("requires a scoped API key"), identical to the
        // existing list/show routes for a non-public type.
        $this->seedCategory('catsrc000001', 'vcatsrc00001', 'src');
        $this->seedMemberPost('spost0000001', 'vspost000001', ['catsrc000001'], 'S1');
        $this->connection()->table('content_types')
            ->where('uuid', '=', $this->postType)->update(['public_delivery' => false]);

        $facets = $this->handle(Request::create('/v1/content/post/facets?fields=category', 'GET'));
        self::assertSame(403, $facets->getStatusCode());
        self::assertStringNotContainsString('catsrc000001', (string) $facets->getContent());

        $archive = $this->handle(Request::create('/v1/content/post/archive/category/src', 'GET'));
        self::assertSame(403, $archive->getStatusCode());
        self::assertStringNotContainsString('catsrc000001', (string) $archive->getContent());
    }
}
