<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Events\EntryPublished;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Content\Repositories\RouteRepository;
use App\Tests\Support\LemmaTestCase;
use Glueful\Events\EventService;
use Glueful\Cache\CacheStore;
use Glueful\Lemma\Contracts\Delivery\PublicRouteResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Taxonomy term index pages (term-index spec §1–§4): the reserved `terms` segment,
 * the thin kind, the reader-invariant 404/200 dispatch, controller-built archive
 * hrefs, and structural cache purging.
 *
 * Suite env: RENDER_LISTING_TYPES=blog,post.
 */
final class TermIndexPagesTest extends LemmaTestCase
{
    private const CAT_TYPE_UUID = 'cattypetidx0';
    private string $postType;

    protected function setUp(): void
    {
        parent::setUp();
        // Locale-prefixed grammar consults the i18n registry — seed en (default) + fr,
        // exactly like PublicRouteResolverTest.
        $this->connection()->getPDO()->exec("DELETE FROM i18n_locales WHERE code IN ('en', 'fr')");
        $now = gmdate('Y-m-d H:i:s');
        foreach ([['en', true], ['fr', false]] as [$code, $isDefault]) {
            $this->connection()->table('i18n_locales')->insert([
                'uuid' => \Glueful\Helpers\Utils::generateNanoID(),
                'code' => $code,
                'name' => strtoupper($code),
                'enabled' => true,
                'is_default' => $isDefault,
                'fallback_locale' => $isDefault ? null : 'en',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->connection()->table('content_types')->insert([
            'uuid' => self::CAT_TYPE_UUID, 'slug' => 'category', 'name' => 'Category',
            'description' => null, 'cache_ttl' => null, 'public_delivery' => true,
            'status' => 'active',
            'schema' => json_encode([['name' => 'slug', 'type' => 'string']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_by' => null,
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $this->postType = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Post', 'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
                 'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function resolver(): PublicRouteResolver
    {
        return $this->container()->get(PublicRouteResolver::class);
    }

    private function seedTerm(
        string $entryUuid,
        string $versionUuid,
        ?string $slug,
        string $locale = 'en',
    ): void {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => self::CAT_TYPE_UUID, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => $locale, 'version' => 1,
            'fields' => json_encode($slug === null ? [] : ['slug' => $slug], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => $locale, 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
    }

    private function seedMemberPost(
        string $entryUuid,
        string $versionUuid,
        array $categoryUuids,
        string $locale = 'en',
    ): void {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => $locale, 'version' => 1,
            'fields' => json_encode(['title' => 'P', 'category' => $categoryUuids], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => $locale, 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-01 01:00:00',
        ]);
        $this->container()->get(PublishedReferenceRepository::class)
            ->projectFromPublished($entryUuid, $this->postType, $locale);
    }

    // ---- grammar ---------------------------------------------------------------------

    public function testTermsPathResolvesThinTermsKind(): void
    {
        $r = $this->resolver()->resolvePath('/post/terms/category');
        self::assertSame('terms', $r['kind']);
        self::assertSame('post', $r['type']);
        self::assertSame('category', $r['field']);
        self::assertSame('en', $r['locale']);
        self::assertNull($r['listing']);   // THIN kind: no payload
        self::assertNull($r['content']);
        self::assertFalse($r['preview']);
    }

    public function testLocalePrefixedTermsPath(): void
    {
        // Review P2: the terms branch's locale shift is pinned DIRECTLY (fr seeded the
        // same way PublicRouteResolverTest does — see setUp).
        $r = $this->resolver()->resolvePath('/fr/post/terms/category');
        self::assertSame('terms', $r['kind']);
        self::assertSame('fr', $r['locale']);
        self::assertSame('post', $r['type']);
        self::assertSame('category', $r['field']);
    }

    public function testUnlistedTypeAndPaginationStayNotFound(): void
    {
        // 'category' is not allowlisted → grammar dormant for it.
        self::assertSame('not_found', $this->resolver()->resolvePath('/category/terms/x')['kind']);
        // No pagination: the explicit 5-segment `terms` special-case (review P1).
        self::assertSame('not_found', $this->resolver()->resolvePath('/post/terms/category/page/2')['kind']);
        // page/1 hits the shared canonical 301 — a harmless redirect to the index
        // (characterized, term-index spec §1).
        $r = $this->resolver()->resolvePath('/post/terms/category/page/1');
        self::assertSame('redirect', $r['kind']);
        self::assertSame('/post/terms/category', $r['redirect']['location']);
    }

    public function testFieldLiterallyNamedTermsHasNoRenderedArchiveAtAnyPage(): void
    {
        // Review P1 regression: WITHOUT the 5-segment special-case, a type with a REAL
        // filterable reference field named `terms` would leak rendered archive pages at
        // page ≥ 2 (while its page-1 paths render term indexes) — the reservation must
        // hold at every segment count.
        $this->connection()->table('content_types')
            ->where('uuid', '=', $this->postType)
            ->update(['schema' => json_encode([
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'category', 'type' => 'reference', 'reference_type' => 'category',
                 'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
                ['name' => 'terms', 'type' => 'reference', 'reference_type' => 'category',
                 'reference_slug_field' => 'slug', 'multiple' => true, 'filterable' => true],
            ], JSON_THROW_ON_ERROR)]);
        $this->seedTerm('tidxrsrv0001', 'vtidxrsrv001', 'php');
        // A member referencing the term through the `terms` field, projected.
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'tidxrsrvpst1', 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => 'vtidxrsrvps1', 'entry_uuid' => 'tidxrsrvpst1', 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => 'R', 'terms' => ['tidxrsrv0001']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'tidxrsrvpst1', 'locale' => 'en', 'version_uuid' => 'vtidxrsrvps1',
            'published_at' => '2026-06-01 01:00:00',
        ]);
        $this->container()->get(PublishedReferenceRepository::class)
            ->projectFromPublished('tidxrsrvpst1', $this->postType, 'en');

        // /post/terms/php parses as the term INDEX of field `php` (nonexistent field →
        // reader gate → the controller 404s at kernel level) — NEVER the archive of
        // field `terms`.
        self::assertSame('terms', $this->resolver()->resolvePath('/post/terms/php')['kind']);
        // And the paged archive form must be sealed too — the leak the special-case
        // closes:
        self::assertSame(
            'not_found',
            $this->resolver()->resolvePath('/post/terms/php/page/2')['kind'],
        );
    }

    public function testEntrySluggedTermsIsNotShadowed(): void
    {
        // Spec correction: `terms` is reserved ONLY at 3 segments — the 2-segment
        // /post/terms is still an entry path and keeps working.
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'termsentry01', 'content_type_uuid' => $this->postType, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => 'vtermsentry1', 'entry_uuid' => 'termsentry01', 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => 'About terms'], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'termsentry01', 'locale' => 'en', 'version_uuid' => 'vtermsentry1',
            'published_at' => '2026-06-01 01:00:00',
        ]);
        (new RouteRepository($this->connection()))->assign('termsentry01', $this->postType, 'en', 'terms');

        $r = $this->resolver()->resolvePath('/post/terms');
        self::assertSame('content', $r['kind']);
        self::assertSame('About terms', $r['content']['fields']['title']);
    }

    // ---- kernel ----------------------------------------------------------------------

    public function testTermIndexRendersCountsWithArchiveHrefs(): void
    {
        $this->seedTerm('tidxterm0001', 'vtidxterm001', 'php');
        $this->seedTerm('tidxterm0002', 'vtidxterm002', 'laravel');
        $this->seedMemberPost('tidxpost0001', 'vtidxpost001', ['tidxterm0001']);
        $this->seedMemberPost('tidxpost0002', 'vtidxpost002', ['tidxterm0001', 'tidxterm0002']);

        $res = $this->handle(Request::create('/post/terms/category', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('php', $html);
        self::assertStringContainsString('(2)', $html);                       // count
        self::assertStringContainsString('href="/post/category/php"', $html); // controller-built href
        self::assertStringContainsString('href="/post/category/laravel"', $html);
        $cacheTag = (string) $res->headers->get('Cache-Tag');
        self::assertStringContainsString('lemma:type:post', $cacheTag);      // count changes
        self::assertStringContainsString('lemma:type:category', $cacheTag);  // slug/term changes
    }

    public function testValidEmptyIndexIs200AndGateFailureIsThemed404(): void
    {
        // Valid field, zero members → 200 with the empty-state branch.
        $ok = $this->handle(Request::create('/post/terms/category', 'GET'));
        self::assertSame(200, $ok->getStatusCode());
        self::assertStringContainsString('No terms yet', (string) $ok->getContent());

        // Non-filterable field → the reader's gate → themed 404 (ordinary path).
        $bad = $this->handle(Request::create('/post/terms/title', 'GET'));
        self::assertSame(404, $bad->getStatusCode());
        self::assertStringContainsString('text/html', (string) $bad->headers->get('Content-Type'));
    }

    public function testNullSlugTermRendersUnlinked(): void
    {
        $this->seedTerm('tidxnoslug01', 'vtidxnoslug1', null); // no slug field value
        $this->seedMemberPost('tidxpost0003', 'vtidxpost003', ['tidxnoslug01']);

        $res = $this->handle(Request::create('/post/terms/category', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('tidxnoslug01', $html);              // uuid shown
        self::assertStringNotContainsString('href="/post/category/', $html);  // but no archive link
    }

    public function testLocalePrefixedIndexRendersLocaleAwareHrefs(): void
    {
        // Review P2: the fr variant is pinned end-to-end — kind/locale at the resolver
        // (grammar test above) AND locale-prefixed archive hrefs through the kernel.
        $this->seedTerm('tidxfrterm01', 'vtidxfrterm1', 'francais', 'fr');
        $this->seedMemberPost('tidxfrpost01', 'vtidxfrpost1', ['tidxfrterm01'], 'fr');

        $res = $this->handle(Request::create('/fr/post/terms/category', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('francais', $html);
        self::assertStringContainsString('href="/fr/post/category/francais"', $html);
    }

    public function testPublishPurgesCachedIndexThroughTheRealListener(): void
    {
        $this->seedTerm('tidxterm0003', 'vtidxterm003', 'purge');
        $this->handle(Request::create('/post/terms/category', 'GET'));
        self::assertIsArray(
            $this->container()->get(CacheStore::class)->get('render:default:%2Fpost%2Fterms%2Fcategory'),
        );

        // A brand-new post publishes → lemma:type:post purges the index (its counts
        // just changed) with zero new invalidation code.
        $this->seedMemberPost('tidxpostnew1', 'vtidxpostnw1', ['tidxterm0003']);
        $this->container()->get(EventService::class)
            ->dispatch(new EntryPublished('tidxpostnew1', $this->postType, 'en'));

        self::assertNull(
            $this->container()->get(CacheStore::class)->get('render:default:%2Fpost%2Fterms%2Fcategory'),
        );
    }
}
