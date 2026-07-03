<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Seo\RedirectRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Delivery\PublicRouteResolver;

final class PublicRouteResolverTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    protected function setUp(): void
    {
        parent::setUp();
        // Locale-variant parsing consults the i18n registry (spec §3: "active locale
        // codes"); the harness DB ships none, so register en (default) + fr.
        $pdo = $this->connection()->getPDO();
        $pdo->exec("DELETE FROM i18n_locales WHERE code IN ('en', 'fr')");
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
    }

    private function resolver(): PublicRouteResolver
    {
        return $this->container()->get(PublicRouteResolver::class);
    }

    public function testPublishedPathResolvesToDeliveryShapedContent(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $r = $this->resolver()->resolvePath('/blog/hello'); // default-locale variant
        self::assertSame('content', $r['kind']);
        self::assertSame('en', $r['locale']);
        self::assertSame($entry, $r['content']['uuid']);
        self::assertArrayHasKey('seo', $r['content']); // stamped like the delivery API
        self::assertSame('Hello', $r['content']['fields']['title']);
    }

    public function testLocaleVariantPath(): void
    {
        $this->seedBilingualPublishedEntry();
        $r = $this->resolver()->resolvePath('/fr/blog/bonjour');
        self::assertSame('content', $r['kind']);
        self::assertSame('fr', $r['locale']);
        self::assertSame('Bonjour', $r['content']['fields']['title']);
    }

    public function testNormalizationRedirectsComeBeforeLookup(): void
    {
        $this->seedBilingualPublishedEntry();
        foreach (['/blog//hello' => '/blog/hello', '/blog/hello/' => '/blog/hello'] as $raw => $canonical) {
            $r = $this->resolver()->resolvePath($raw);
            self::assertSame('redirect', $r['kind'], $raw);
            self::assertSame(['location' => $canonical, 'status' => 301], $r['redirect']);
        }
    }

    public function testArityAndLocaleEdges(): void
    {
        $this->seedBilingualPublishedEntry();
        // /en/blog was not_found before the listing grammar; with `blog` allowlisted in
        // the suite env it is now a locale-prefixed LISTING (listing spec §1).
        self::assertSame('listing', $this->resolver()->resolvePath('/en/blog')['kind']);
        self::assertSame('not_found', $this->resolver()->resolvePath('/only-one')['kind']);
        self::assertSame('not_found', $this->resolver()->resolvePath('/a/b/c/d')['kind']);
    }

    public function testResolveEntryForHomepage(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $r = $this->resolver()->resolveEntry($entry);
        self::assertSame('content', $r['kind']);
        self::assertArrayHasKey('seo', $r['content']);
        self::assertSame('not_found', $this->resolver()->resolveEntry('nope00000000')['kind']);
    }

    public function testResolveEntryRoutelessIsNotFound(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->container()->get(\App\Content\Repositories\RouteRepository::class)->remove($entry, 'en');
        self::assertSame('not_found', $this->resolver()->resolveEntry($entry)['kind']);
    }

    public function testNonPublicTypeIsNotFoundEvenWithARoute(): void
    {
        // render is anonymous; a route existing is not enough (spec §3 visibility pin).
        $this->seedPublishedEntryInType('secret-doc', false, 'en', 'classified', 'Classified');
        self::assertSame('not_found', $this->resolver()->resolvePath('/secret-doc/classified')['kind']);
    }

    public function testExternalRedirectFlowsThrough(): void
    {
        $this->seedBilingualPublishedEntry();
        $typeUuid = (string) $this->container()->get(ContentTypeRepository::class)->findBySlug('blog')['uuid'];
        (new RedirectRepository($this->connection()))->create([
            'content_type_uuid' => $typeUuid,
            'locale' => 'en',
            'source_slug' => 'old-post',
            'target_url' => 'https://elsewhere.test/x',
            'status' => 302,
        ]);

        $r = $this->resolver()->resolvePath('/blog/old-post');
        self::assertSame('redirect', $r['kind']);
        self::assertSame(['location' => 'https://elsewhere.test/x', 'status' => 302], $r['redirect']);
    }

    public function testBrokenInternalRedirectIsGone(): void
    {
        // Internal redirect target that is draft-only → RouteResolver marks it broken → gone.
        $this->seedBilingualPublishedEntry();
        $types = $this->container()->get(ContentTypeRepository::class);
        $entries = $this->container()->get(EntryRepository::class);
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $draft = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($draft, 'en', ['title' => 'Draft'], 1, 0, 'user00000001');

        (new RedirectRepository($this->connection()))->create([
            'content_type_uuid' => $typeUuid,
            'locale' => 'en',
            'source_slug' => 'moved-away',
            'target_content_type_uuid' => $typeUuid,
            'target_locale' => 'en',
            'target_entry_uuid' => $draft,
            'status' => 301,
        ]);

        self::assertSame('gone', $this->resolver()->resolvePath('/blog/moved-away')['kind']);
    }

    // ---- listing grammar + resolution (listing spec §1–§3) ---------------------------

    public function testBareTypePathResolvesListingPageOne(): void
    {
        $this->seedBilingualPublishedEntry();
        $r = $this->resolver()->resolvePath('/blog');
        self::assertSame('listing', $r['kind']);
        self::assertSame('blog', $r['type']);
        self::assertSame('en', $r['locale']);
        self::assertSame(1, $r['listing']['page']);
        self::assertSame(1, $r['listing']['total']);
        self::assertSame(1, $r['listing']['total_pages']); // max(1, ceil) pin
        self::assertCount(1, $r['listing']['items']);
        $item = $r['listing']['items'][0];
        // hrefs are whatever PathRenderer returns — absolute here because the suite
        // sets LEMMA_PUBLIC_URL_BASE (matching path()/canonicals); default-locale
        // collapse (no /en/ segment) is the assertion that matters.
        self::assertSame('https://site.test/blog/hello', $item['href']);
        self::assertArrayNotHasKey('seo', $item);             // LIST shape, not shapePublic
    }

    public function testLocalePrefixedListing(): void
    {
        $this->seedBilingualPublishedEntry();
        $r = $this->resolver()->resolvePath('/fr/blog');
        self::assertSame('listing', $r['kind']);
        self::assertSame('fr', $r['locale']);
        self::assertSame('https://site.test/fr/blog/bonjour', $r['listing']['items'][0]['href']);
    }

    public function testListingPaginationGrammar(): void
    {
        $this->seedBilingualPublishedEntry();
        // /page/1 → 301 to the bare path (canonical).
        $r = $this->resolver()->resolvePath('/blog/page/1');
        self::assertSame('redirect', $r['kind']);
        self::assertSame('/blog', $r['redirect']['location']);
        self::assertSame(301, $r['redirect']['status']);
        // page 0 / non-numeric / beyond total_pages → not_found.
        self::assertSame('not_found', $this->resolver()->resolvePath('/blog/page/0')['kind']);
        self::assertSame('not_found', $this->resolver()->resolvePath('/blog/page/abc')['kind']);
        self::assertSame('not_found', $this->resolver()->resolvePath('/blog/page/9')['kind']);
    }

    public function testListingPageTwoWithPerPageTwo(): void
    {
        // Suite env pins listing_per_page=2; three published entries → 2 pages.
        $this->seedBilingualPublishedEntry();
        $this->seedExtraPublishedPost('extra0000001', 'vextra000001', 'second-post', 'Second');
        $this->seedExtraPublishedPost('extra0000002', 'vextra000002', 'third-post', 'Third');

        $r = $this->resolver()->resolvePath('/blog/page/2');
        self::assertSame('listing', $r['kind']);
        self::assertSame(2, $r['listing']['page']);
        self::assertSame(3, $r['listing']['total']);
        self::assertSame(2, $r['listing']['total_pages']);
        self::assertCount(1, $r['listing']['items']);
    }

    public function testUnlistedTypeStaysNotFound(): void
    {
        // 'category' is not in RENDER_LISTING_TYPES — the grammar is dormant for it.
        $this->connection()->table('content_types')->insert([
            'uuid' => 'cattypelist0', 'slug' => 'category', 'name' => 'Category',
            'description' => null, 'cache_ttl' => null, 'public_delivery' => true,
            'status' => 'active',
            'schema' => json_encode([['name' => 'slug', 'type' => 'string']], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_by' => null,
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        self::assertSame('not_found', $this->resolver()->resolvePath('/category')['kind']);
    }

    public function testRoutelessItemHasNullHref(): void
    {
        $this->seedBilingualPublishedEntry();
        // A published entry with NO entry_routes row (seeded directly, skipping assign()).
        $this->seedExtraPublishedPost('norel0000001', 'vnorel000001', null, 'No route');
        $r = $this->resolver()->resolvePath('/blog');
        $hrefs = array_column($r['listing']['items'], 'href');
        self::assertContains(null, $hrefs);
        self::assertContains('https://site.test/blog/hello', $hrefs);
    }

    public function testHrefIgnoresStaleRouteRowsOfOtherTypes(): void
    {
        // Regression (review P2): entry_routes' identity is (content_type_uuid, locale,
        // slug) and entry_uuid is only indexed — a stale row for the same entry+locale
        // under ANOTHER type uuid must never leak into the listing href.
        $entry = $this->seedBilingualPublishedEntry();
        $this->connection()->table('entry_routes')->insert([
            'entry_uuid' => $entry,
            'content_type_uuid' => 'staletype001', // not the blog type
            'locale' => 'en',
            'slug' => 'stale-slug',
        ]);

        $r = $this->resolver()->resolvePath('/blog');
        $hrefs = array_column($r['listing']['items'], 'href');
        self::assertContains('https://site.test/blog/hello', $hrefs);
        self::assertNotContains('https://site.test/blog/stale-slug', $hrefs);
    }

    /** Seed a published blog entry directly; $slug null = no route row. */
    private function seedExtraPublishedPost(
        string $entryUuid,
        string $versionUuid,
        ?string $slug,
        string $title,
    ): void {
        $db = $this->connection();
        $typeUuid = (string) $db->table('content_types')
            ->select(['uuid'])->where('slug', '=', 'blog')->first()['uuid'];
        $db->table('entries')->insert([
            'uuid' => $entryUuid, 'content_type_uuid' => $typeUuid, 'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_versions')->insert([
            'uuid' => $versionUuid, 'entry_uuid' => $entryUuid, 'locale' => 'en', 'version' => 1,
            'fields' => json_encode(['title' => $title], JSON_THROW_ON_ERROR),
            'schema_version' => 1, 'created_at' => '2026-06-01 00:00:00',
        ]);
        $db->table('entry_publications')->insert([
            'entry_uuid' => $entryUuid, 'locale' => 'en', 'version_uuid' => $versionUuid,
            'published_at' => '2026-06-02 01:00:00',
        ]);
        if ($slug !== null) {
            (new \App\Content\Repositories\RouteRepository($db))
                ->assign($entryUuid, $typeUuid, 'en', $slug);
        }
    }

    /**
     * Seed a `page` type with a blocks `body` + a `related` block type, publish a
     * target and a source embedding it, and return the target uuid. The publish
     * path REQUIRES the full validator (blocks fields reject without the registry).
     *
     * @return string the TARGET entry uuid
     */
    private function seedBlockPagePair(): string
    {
        (new \App\Content\Blocks\BlockTypeRepository($this->connection()))->create([
            'slug' => 'related',
            'label' => 'Related',
            'schema' => [['name' => 'post', 'type' => 'reference']],
        ]);
        $types = new ContentTypeRepository($this->connection());
        $type = $types->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $publish = new \App\Content\Services\PublishService(
            $this->appContext(),
            $entries,
            new \App\Content\Repositories\VersionRepository($this->connection()),
            $types,
            new \App\Content\Validation\FieldValidator(
                $this->connection(),
                $this->appContext(),
                new \App\Content\Blocks\BlockTypeRepository($this->connection()),
            ),
            new \App\Content\Repositories\ReferenceProjectionRepository($this->connection()),
        );
        $routes = new \App\Content\Repositories\RouteRepository($this->connection());

        $target = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($target, 'en', ['title' => 'T', 'body' => []], 1, 0, 'user00000001');
        $routes->assign($target, $type, 'en', 'target');
        $publish->publish($target, 'en', 'user00000001');

        $source = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($source, 'en', ['title' => 'S', 'body' => [
            ['id' => 'b1', 'type' => 'related', 'data' => ['post' => $target]],
        ]], 1, 0, 'user00000001');
        $routes->assign($source, $type, 'en', 'source');
        $publish->publish($source, 'en', 'user00000001');

        return $target;
    }

    public function testContentResultCarriesExpansionTargetCacheTags(): void
    {
        $target = $this->seedBlockPagePair();

        $result = $this->resolver()->resolvePath('/page/source');
        self::assertSame('content', $result['kind']);
        self::assertContains('lemma:entry:' . $target, $result['cache_tags']);
        // Privacy: the tags never ride inside the content payload.
        self::assertArrayNotHasKey('cache_tags', $result['content']);
    }
}
