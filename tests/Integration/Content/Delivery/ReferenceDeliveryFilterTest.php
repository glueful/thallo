<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content\Delivery;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Delivery\SortCompiler;
use App\Content\Http\Controllers\DeliveryController;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryListQuery;
use App\Content\Http\DeliveryEtag;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Seo\CanonicalProjector;
use App\Content\Seo\PathRenderer;
use App\Content\Seo\RedirectRepository;
use App\Content\Seo\RouteResolver;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\FakeLocaleManager;
use App\Tests\Support\AppTestCase;
use Glueful\Support\FieldSelection\Projector;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end integration test: multi-valued + filterable reference/asset fields delivered via
 * the real DeliveryController → FilterCompiler → ReferenceFilterResolver → DeliveryRepository
 * chain. Proves uuid lookup, slug resolution, `in` operator, scalar/array backward compat,
 * absent-field exclusion, ambiguous-slug rejection, and asset-uuid filtering.
 */
final class ReferenceDeliveryFilterTest extends AppTestCase
{
    /** Content type uuid of the `category` type (seeded directly for predictable uuid). */
    private const CAT_TYPE_UUID = 'cattyperef00';

    /** Content type uuid of the `post` type (seeded via repository for convenience). */
    private string $postType;

    protected function setUp(): void
    {
        parent::setUp();

        // Category type — seeded directly so its uuid is stable / predictable.
        $this->connection()->table('content_types')->insert([
            'uuid'           => self::CAT_TYPE_UUID,
            'slug'           => 'category',
            'name'           => 'Category',
            'description'    => null,
            'cache_ttl'      => null,
            'public_delivery' => false,
            'status'         => 'active',
            'schema'         => json_encode(
                [['name' => 'slug', 'type' => 'string', 'required' => true]],
                JSON_THROW_ON_ERROR,
            ),
            'schema_version' => 1,
            'created_by'     => null,
            'created_at'     => '2026-06-01 00:00:00',
            'updated_at'     => '2026-06-01 00:00:00',
        ]);

        // Post type — has a multiple filterable reference field (`category`) and a
        // multiple filterable asset field (`gallery`).
        $this->postType = (new ContentTypeRepository($this->connection()))->create([
            'slug'   => 'post',
            'name'   => 'Post',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                [
                    'name'                => 'category',
                    'type'                => 'reference',
                    'reference_type'      => 'category',
                    'reference_slug_field' => 'slug',
                    'multiple'            => true,
                    'filterable'          => true,
                ],
                [
                    'name'       => 'gallery',
                    'type'       => 'asset',
                    'multiple'   => true,
                    'filterable' => true,
                ],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function controller(): DeliveryController
    {
        $repo   = new DeliveryRepository($this->connection());
        $types  = new ContentTypeRepository($this->connection());
        $routes = new RouteRepository(
            $this->connection(),
            new RedirectRepository($this->connection()),
        );
        $paths = new PathRenderer('/{locale}/{type}/{slug}', null, 'en');

        return new DeliveryController(
            $this->appContext(),
            $repo,
            $types,
            $this->container()->get(FilterCompiler::class),
            new SortCompiler(),
            new ReferenceResolver($repo),
            new Projector(),
            new DeliveryEtag(),
            new FakeLocaleManager(),
            new RouteResolver(
                $repo,
                new RedirectRepository($this->connection()),
                $routes,
                $types,
                new \App\Content\Seo\CanonicalPathBuilder(
                    $paths,
                    $this->container()->get(\Glueful\Extensions\I18n\Contracts\LocaleManagerInterface::class),
                ),
            ),
            new CanonicalProjector(
                $repo,
                $routes,
                $types,
                new \App\Content\Seo\CanonicalPathBuilder(
                    $paths,
                    $this->container()->get(\Glueful\Extensions\I18n\Contracts\LocaleManagerInterface::class),
                ),
                'en',
            ),
        );
    }

    private function publishService(): PublishService
    {
        $entries = new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
        return new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    /**
     * Seed a published category term directly (controlled uuid, no validation layer).
     *
     * @param string $entryUuid  12-char uuid for the category entry
     * @param string $versionUuid 12-char uuid for the version row
     * @param string $slug       The slug value stored in `fields.slug`
     */
    private function seedCategory(string $entryUuid, string $versionUuid, string $slug): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid'              => $entryUuid,
            'content_type_uuid' => self::CAT_TYPE_UUID,
            'status'            => 'active',
            'created_at'        => '2026-06-01 00:00:00',
            'updated_at'        => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid'           => $versionUuid,
            'entry_uuid'     => $entryUuid,
            'locale'         => 'en',
            'version'        => 1,
            'fields'         => json_encode(['slug' => $slug], JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'created_at'     => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid'   => $entryUuid,
            'locale'       => 'en',
            'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
    }

    /**
     * Publish a post via the real PublishService; returns the generated entry uuid.
     *
     * @param array<string,mixed> $fields
     */
    private function publishPost(array $fields): string
    {
        $entries = $this->entries();
        $uuid    = $entries->createEntry($this->postType, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        $this->publishService()->publish($uuid, 'en', 'user00000001');
        return $uuid;
    }

    /**
     * Seed a published post row directly — bypasses FieldValidator so we can store
     * raw scalar or non-conforming field values (e.g. pre-flip scalar uuid strings).
     *
     * @param string             $entryUuid   12-char uuid
     * @param string             $versionUuid 12-char uuid
     * @param array<string,mixed> $rawFields   stored verbatim as JSONB
     */
    private function seedPost(string $entryUuid, string $versionUuid, array $rawFields): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid'              => $entryUuid,
            'content_type_uuid' => $this->postType,
            'status'            => 'active',
            'created_at'        => '2026-06-01 00:00:00',
            'updated_at'        => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid'           => $versionUuid,
            'entry_uuid'     => $entryUuid,
            'locale'         => 'en',
            'version'        => 1,
            'fields'         => json_encode($rawFields, JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'created_at'     => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid'   => $entryUuid,
            'locale'       => 'en',
            'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
    }

    /**
     * Drive the real DeliveryController's `index` action for `post` with a `filter[...]`
     * query and return either the items array (HTTP 200) or a plain-object error envelope
     * (any other status) so callers can assert both shapes.
     *
     * @param array<string,mixed> $filter  e.g. ['category' => ['eq' => 'news']]
     * @return list<array<string,mixed>>|\stdClass
     */
    private function deliverFilter(string $type, array $filter): array|\stdClass
    {
        $queryParams = ['filter' => $filter];
        $dto         = (new RequestDataHydrator())->hydrate(DeliveryListQuery::class, [], [], $queryParams);
        $req         = new Request($queryParams);
        $resp        = $this->controller()->index($req, $dto, $type);
        $status      = $resp->getStatusCode();
        $body        = json_decode((string) $resp->getContent(), true);

        if ($status !== 200) {
            $obj         = new \stdClass();
            $obj->status = $status;
            $obj->body   = $body;
            return $obj;
        }

        return (array) $body['data']['items'];
    }

    // -------------------------------------------------------------------------
    // Case 1: uuid + slug + `in` — three posts, two categories
    // -------------------------------------------------------------------------

    public function testUuidAndSlugAndInFilterReturnExpectedPosts(): void
    {
        $this->seedCategory('catnews00001', 'vcatnews0001', 'news');
        $this->seedCategory('catsport0001', 'vcatsport001', 'sports');

        $p1 = $this->publishPost(['title' => 'P1', 'category' => ['catnews00001']]);
        $p2 = $this->publishPost(['title' => 'P2', 'category' => ['catnews00001', 'catsport0001']]);
        $p3 = $this->publishPost(['title' => 'P3', 'category' => ['catsport0001']]);

        // eq by uuid
        $items = $this->deliverFilter('post', ['category' => ['eq' => 'catnews00001']]);
        self::assertIsArray($items, 'expected 200 for uuid eq filter');
        $uuids = array_column($items, 'uuid');
        self::assertContains($p1, $uuids, 'uuid eq: p1 must match');
        self::assertContains($p2, $uuids, 'uuid eq: p2 must match');
        self::assertNotContains($p3, $uuids, 'uuid eq: p3 must not match');

        // eq by slug
        $items = $this->deliverFilter('post', ['category' => ['eq' => 'news']]);
        self::assertIsArray($items, 'expected 200 for slug eq filter');
        $uuids = array_column($items, 'uuid');
        self::assertContains($p1, $uuids, 'slug eq: p1 must match');
        self::assertContains($p2, $uuids, 'slug eq: p2 must match');
        self::assertNotContains($p3, $uuids, 'slug eq: p3 must not match');

        // in: union over both slugs
        $items = $this->deliverFilter('post', ['category' => ['in' => 'news,sports']]);
        self::assertIsArray($items, 'expected 200 for in filter');
        $uuids = array_column($items, 'uuid');
        self::assertContains($p1, $uuids, 'in: p1 must match');
        self::assertContains($p2, $uuids, 'in: p2 must match');
        self::assertContains($p3, $uuids, 'in: p3 must match');
    }

    // -------------------------------------------------------------------------
    // Case 2: scalar → array backward compat across schema flip
    // -------------------------------------------------------------------------

    public function testScalarAndArrayStoredValuesFilterIdentically(): void
    {
        $this->seedCategory('catnews00001', 'vcatnews0001', 'news');

        // Pre-flip row: category stored as a SCALAR string (old single-valued schema).
        // The CASE expression in FieldSqlExpression::membershipArray() normalises it to
        // a one-element array before applying `@>`, so the filter must still match.
        $this->seedPost('scalarpst001', 'scalarpstv01', [
            'title'    => 'Scalar post',
            'category' => 'catnews00001',    // stored as a plain string, not an array
        ]);

        // Post-flip row: category stored as a JSON array (new multi-valued schema).
        $arrayPost = $this->publishPost(['title' => 'Array post', 'category' => ['catnews00001']]);

        $items = $this->deliverFilter('post', ['category' => ['eq' => 'news']]);
        self::assertIsArray($items, 'expected 200 for scalar/array compat filter');
        $uuids = array_column($items, 'uuid');

        self::assertContains('scalarpst001', $uuids, 'scalar-stored post must match via compat normalisation');
        self::assertContains($arrayPost, $uuids, 'array-stored post must also match');
    }

    // -------------------------------------------------------------------------
    // Case 3: absent field key → no match
    // -------------------------------------------------------------------------

    public function testPostWithAbsentCategoryFieldIsNotReturned(): void
    {
        $this->seedCategory('catnews00001', 'vcatnews0001', 'news');

        // Post without any `category` key in its fields.
        $noCategory  = $this->publishPost(['title' => 'No category']);
        $withCategory = $this->publishPost(['title' => 'With category', 'category' => ['catnews00001']]);

        $items = $this->deliverFilter('post', ['category' => ['eq' => 'news']]);
        self::assertIsArray($items, 'expected 200 for absent-field filter');
        $uuids = array_column($items, 'uuid');

        self::assertNotContains($noCategory, $uuids, 'post without category field must not match');
        self::assertContains($withCategory, $uuids, 'post with matching category must match');
    }

    // -------------------------------------------------------------------------
    // Case 4: ambiguous slug → 422 validation error from the controller
    // -------------------------------------------------------------------------

    public function testAmbiguousSlugYields422ValidationError(): void
    {
        // Two distinct published category entries share slug `dup` in `en` — the
        // ReferenceFilterResolver throws InvalidFilterException; the controller
        // catches it and returns Response::validation (HTTP 422).
        $this->seedCategory('catdup000001', 'catdupv00001', 'dup');
        $this->seedCategory('catdup000002', 'catdupv00002', 'dup');

        $result = $this->deliverFilter('post', ['category' => ['eq' => 'dup']]);
        self::assertInstanceOf(\stdClass::class, $result, 'expected a non-200 error object');
        self::assertSame(422, $result->status, 'ambiguous slug must surface as HTTP 422');
    }

    // -------------------------------------------------------------------------
    // Case 5: asset uuid filter (uuid-only, no slug resolution)
    // -------------------------------------------------------------------------

    public function testAssetUuidFilterReturnsMatchingPost(): void
    {
        // FieldValidator is instantiated without a Connection in publishService(), so
        // blob-existence checks are skipped — publish succeeds without a real `blobs` row.
        $blobUuid    = 'blob00000001';
        $postWithBlob = $this->publishPost(['title' => 'Gallery post', 'gallery' => [$blobUuid]]);
        $postNoBlob   = $this->publishPost(['title' => 'No gallery',   'gallery' => []]);

        $items = $this->deliverFilter('post', ['gallery' => ['eq' => $blobUuid]]);
        self::assertIsArray($items, 'expected 200 for asset uuid filter');
        $uuids = array_column($items, 'uuid');

        self::assertContains($postWithBlob, $uuids, 'post with matching blob must be returned');
        self::assertNotContains($postNoBlob, $uuids, 'post with empty gallery must not be returned');
    }
}
