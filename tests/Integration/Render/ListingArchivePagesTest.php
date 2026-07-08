<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Events\EntryPublished;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Content\Repositories\RouteRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Events\EventService;
use Glueful\Cache\CacheStore;
use Thallo\Contracts\Delivery\PublicRouteResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Rendered listing/archive pages (listing spec §1–§5): archive resolution over the
 * published-reference projection, kernel pipeline through the catch-all, template
 * fallback, and the broad type-tag cache purge.
 *
 * Suite env: RENDER_LISTING_TYPES=blog,post, RENDER_LISTING_PER_PAGE=2.
 */
final class ListingArchivePagesTest extends AppTestCase
{
    private const CAT_TYPE_UUID = 'cattyperlst0';
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
                [['name' => 'slug', 'type' => 'string', 'required' => true],
                 ['name' => 'title', 'type' => 'string']],
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

    protected function tearDown(): void
    {
        $this->cache()->deletePattern('render:*');
        parent::tearDown();
    }

    private function cache(): CacheStore
    {
        return $this->container()->get(CacheStore::class);
    }

    private function resolver(): PublicRouteResolver
    {
        return $this->container()->get(PublicRouteResolver::class);
    }

    private function seedTerm(string $entryUuid, string $versionUuid, string $slug, string $title): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => self::CAT_TYPE_UUID, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['slug' => $slug, 'title' => $title], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => 'en', 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
    }

    /** Published post with route + projection rows — a full archive member. */
    private function seedMemberPost(
        string $entryUuid,
        string $versionUuid,
        string $slug,
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
        (new RouteRepository($db))->assign($entryUuid, $this->postType, 'en', $slug);
        $this->container()->get(PublishedReferenceRepository::class)
            ->projectFromPublished($entryUuid, $this->postType, 'en');
    }

    public function testArchiveResolvesTermAndProjectionMembers(): void
    {
        $this->seedTerm('term00000001', 'vterm0000001', 'php', 'PHP');
        $this->seedMemberPost('lpost0000001', 'vlpost000001', 'in-php', ['term00000001'], 'In PHP');
        $this->seedMemberPost('lpost0000002', 'vlpost000002', 'no-cat', [], 'No cat');

        $r = $this->resolver()->resolvePath('/post/category/php');
        self::assertSame('archive', $r['kind']);
        self::assertSame('post', $r['type']);
        self::assertSame('category', $r['field']);
        self::assertSame('category', $r['term_type']);
        self::assertSame('term00000001', $r['term']['uuid']);
        self::assertArrayHasKey('seo', $r['term']); // the term IS shapePublic (show shape)
        self::assertSame(1, $r['listing']['total']);
        self::assertSame('https://site.test/post/in-php', $r['listing']['items'][0]['href']);
    }

    public function testArchiveMembershipComesFromTheProjection(): void
    {
        // JSONB claims membership; the projection does not — the projection wins (§2 pin).
        $this->seedTerm('term00000002', 'vterm0000002', 'div', 'Div');
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'ldiv00000001', 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => 'vldiv0000001', 'entry_uuid' => 'ldiv00000001', 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => 'D', 'category' => ['term00000002']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'ldiv00000001', 'locale' => 'en', 'version_uuid' => 'vldiv0000001',
            'published_at' => '2026-06-01 01:00:00',
        ]);
        // NO projection row.
        $r = $this->resolver()->resolvePath('/post/category/div');
        self::assertSame('archive', $r['kind']);
        self::assertSame([], $r['listing']['items']);
        self::assertSame(1, $r['listing']['total_pages']); // empty page 1 valid (max(1,…))
    }

    public function testArchiveGrammarEdges(): void
    {
        $this->seedTerm('term00000003', 'vterm0000003', 'edge', 'Edge');
        $this->seedMemberPost('lpost0000003', 'vlpost000003', 'edge-post', ['term00000003']);

        // page/1 → 301 to the archive base.
        $r = $this->resolver()->resolvePath('/post/category/edge/page/1');
        self::assertSame('redirect', $r['kind']);
        self::assertSame('/post/category/edge', $r['redirect']['location']);
        // Unknown term / non-filterable field / unlisted type → not_found.
        self::assertSame('not_found', $this->resolver()->resolvePath('/post/category/nope')['kind']);
        self::assertSame('not_found', $this->resolver()->resolvePath('/post/title/edge')['kind']);
        self::assertSame('not_found', $this->resolver()->resolvePath('/category/category/edge')['kind']);
        // Beyond total_pages → not_found (per_page=2, 1 member → 1 page).
        self::assertSame('not_found', $this->resolver()->resolvePath('/post/category/edge/page/2')['kind']);
    }

    public function testFieldNamedPageIsShadowedByPagination(): void
    {
        // The reserved-word cost (§1, characterized): a reference field literally named
        // `page` cannot have rendered archives when the term is numeric — and any
        // /post/page/{digits} parses as pagination.
        self::assertSame('not_found', $this->resolver()->resolvePath('/post/page/7')['kind']);
        // Non-numeric third segment falls through to archive parsing (field `page`
        // doesn't exist on `post`) → not_found either way.
        self::assertSame('not_found', $this->resolver()->resolvePath('/post/page/seven')['kind']);
    }

    // ---- kernel pipeline + caching (listing spec §4–§5) ------------------------------

    public function testListingRendersThroughTheKernelWithLinks(): void
    {
        $this->seedTerm('term00000004', 'vterm0000004', 'k1', 'K1');
        $this->seedMemberPost('kpost0000001', 'vkpost000001', 'kernel-post', ['term00000004'], 'Kernel post');

        $res = $this->handle(Request::create('/post', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('Kernel post', $html);
        // Ready hrefs, no path() loop (absolute here: suite sets PUBLIC_URL_BASE).
        self::assertStringContainsString('href="https://site.test/post/kernel-post"', $html);
        // The broad type tag is on the response (the §4 purge pin).
        self::assertStringContainsString('thallo:type:post', (string) $res->headers->get('Cache-Tag'));
        self::assertStringContainsString('thallo:entry:kpost0000001', (string) $res->headers->get('Cache-Tag'));
    }

    public function testArchiveRendersTermAndMembersWithTags(): void
    {
        $this->seedTerm('term00000005', 'vterm0000005', 'tagged', 'Tagged');
        $this->seedMemberPost('kpost0000002', 'vkpost000002', 'tagged-post', ['term00000005'], 'Tagged post');

        $res = $this->handle(Request::create('/post/category/tagged', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('Tagged', $html);       // term heading
        self::assertStringContainsString('Tagged post', $html);  // member
        $cacheTag = (string) $res->headers->get('Cache-Tag');
        self::assertStringContainsString('thallo:type:post', $cacheTag);
        self::assertStringContainsString('thallo:type:category', $cacheTag);   // term type
        self::assertStringContainsString('thallo:entry:term00000005', $cacheTag); // the term
    }

    public function testPaginationPathsAndDistinctCacheEntries(): void
    {
        $this->seedTerm('term00000006', 'vterm0000006', 'p', 'P');
        $this->seedMemberPost('kpost0000003', 'vkpost000003', 'p1', ['term00000006'], 'Post one');
        $this->seedMemberPost('kpost0000004', 'vkpost000004', 'p2', ['term00000006'], 'Post two');
        $this->seedMemberPost('kpost0000005', 'vkpost000005', 'p3', ['term00000006'], 'Post three');

        $one = $this->handle(Request::create('/post', 'GET'));
        $two = $this->handle(Request::create('/post/page/2', 'GET'));
        self::assertSame(200, $one->getStatusCode());
        self::assertSame(200, $two->getStatusCode());
        self::assertNotSame((string) $one->getContent(), (string) $two->getContent());
        // Distinct cache entries (path-based pagination pin).
        self::assertIsArray($this->cache()->get('render:default:blue-slate:%2Fpost'));
        self::assertIsArray($this->cache()->get('render:default:blue-slate:%2Fpost%2Fpage%2F2'));
        // Page 2's prev is the BARE path (canonical), rendered by the pagination partial.
        self::assertStringContainsString('href="/post"', (string) $two->getContent());
        // /page/1 through the kernel → 301 to the bare path.
        $canonical = $this->handle(Request::create('/post/page/1', 'GET'));
        self::assertSame(301, $canonical->getStatusCode());
        self::assertSame('/post', $canonical->headers->get('Location'));
    }

    public function testNewPublishPurgesCachedListingViaTheBroadTypeTag(): void
    {
        // The §4 pin proven STRICTLY: the cached page must NOT contain the newly
        // published entry, so its per-item entry tags cannot explain the purge — only
        // the broad thallo:type:post tag can. Cache page 2 (per_page=2: it holds only
        // the third-oldest post), then publish a brand-new entry that was never
        // rendered anywhere.
        $this->seedMemberPost('kpost0000006', 'vkpost000006', 'one', [], 'One');
        $this->seedMemberPost('kpost0000007', 'vkpost000007', 'two', [], 'Two');
        $this->seedMemberPost('kpost0000008', 'vkpost000008', 'three', [], 'Three');
        $two = $this->handle(Request::create('/post/page/2', 'GET'));
        self::assertSame(200, $two->getStatusCode());
        self::assertIsArray($this->cache()->get('render:default:blue-slate:%2Fpost%2Fpage%2F2'));
        self::assertStringNotContainsString(
            'kpostnew0001',
            (string) $two->headers->get('Cache-Tag'),
            'precondition: the soon-to-publish entry is not tagged on the cached page',
        );

        $this->seedMemberPost('kpostnew0001', 'vkpostnew001', 'brand-new', [], 'Brand new');
        $this->container()->get(EventService::class)
            ->dispatch(new EntryPublished('kpostnew0001', $this->postType, 'en'));

        self::assertNull(
            $this->cache()->get('render:default:blue-slate:%2Fpost%2Fpage%2F2'),
            'page 2 must purge via thallo:type:post — no per-entry tag links it to the new entry',
        );
    }

    public function testEmptyListingRendersPageOne(): void
    {
        // 'blog' is allowlisted but has no entries in this test → 200, empty items,
        // and /blog/page/2 is beyond total_pages (=1) → themed 404.
        $this->connection()->table('content_types')->insert([
            'uuid' => 'blogtypelst0', 'slug' => 'blog', 'name' => 'Blog',
            'description' => null, 'cache_ttl' => null, 'public_delivery' => true,
            'status' => 'active',
            'schema' => json_encode([['name' => 'title', 'type' => 'string']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_by' => null,
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $res = $this->handle(Request::create('/blog', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $miss = $this->handle(Request::create('/blog/page/2', 'GET'));
        self::assertSame(404, $miss->getStatusCode());
        self::assertStringContainsString('text/html', (string) $miss->headers->get('Content-Type'));
    }
}
