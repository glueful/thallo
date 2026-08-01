<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Settings\SettingsStore;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Thallo\Render\EntryBlocksRenderer;
use Thallo\Render\RenderContextExtension;
use Symfony\Component\HttpFoundation\Request;

/**
 * Storefront-performance spec §4: the priority-image claim resets at every REAL render
 * boundary — RenderController::render() for full pages and EntryBlocksRenderer for
 * route-less fragments. These tests never call resetPerRenderState() themselves: the
 * production callers own the boundary, and a missing reset at either site fails here.
 *
 * NOTE (Task 2 scope): no template emits fetchpriority yet, so the boundary is proven
 * through the shared extension's observable claim STATE (true-then-false after each
 * render). Task 3's ImageDisciplineRenderTest upgrades this to rendered-markup
 * assertions on fetchpriority="high".
 */
final class PriorityClaimRenderBoundaryTest extends AppTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        // Hygiene (mirrors RenderPipelineTest): the render page cache and any sitemap
        // entry cached during a kernel render must not leak into later tests.
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Thallo\Seo\Cache\SitemapCache::class)->forgetAll();
        // The logo-vs-body test's site logo and blobs must not bleed into other
        // full-render tests (a configured site_logo changes every layout header).
        $this->container()->get(SettingsStore::class)->forget('site_logo');
        $this->connection()->table('blobs')->where('name', 'LIKE', 'pcbt-blob-%')->forceDelete();
        parent::tearDown();
    }

    private function extension(): RenderContextExtension
    {
        return $this->container()->get(RenderContextExtension::class);
    }

    public function testConsecutiveFullControllerRendersEachGetAFreshClaim(): void
    {
        // Two distinct routed pages: distinct page-cache keys, so BOTH requests go
        // through RenderController::render() (a cache hit would bypass the boundary).
        $this->seedPublishedEntryInType('pcbta', true, 'en', 'first', 'First page');
        $this->seedPublishedEntryInType('pcbtb', true, 'en', 'second', 'Second page');

        $ext = $this->extension();
        // Dirty the shared claim BEFORE the render: only the controller's own
        // boundary reset can explain a fresh claim afterwards.
        $ext->claimPriorityImage([]);

        $one = $this->handle(Request::create('/pcbta/first', 'GET'));
        self::assertSame(200, $one->getStatusCode());
        self::assertTrue($ext->claimPriorityImage([]), 'render #1 reset the claim at its boundary');
        self::assertFalse($ext->claimPriorityImage([]), 'at most one claim per render');

        // The claim is consumed again (dirty) — render #2 must independently reset it.
        $two = $this->handle(Request::create('/pcbtb/second', 'GET'));
        self::assertSame(200, $two->getStatusCode());
        self::assertTrue($ext->claimPriorityImage([]), 'render #2 reset the claim at its boundary');
        self::assertFalse($ext->claimPriorityImage([]), 'at most one claim per render');
    }

    public function testFragmentRenderAndFollowingFullRenderEachOwnTheirBoundary(): void
    {
        $entryUuid = $this->seedRouteLessEntryWithHeadingBlock();
        $this->seedPublishedEntryInType('pcbtc', true, 'en', 'after-fragment', 'After fragment');

        $ext = $this->extension();
        // Consume the claim first: the fragment must not inherit the dirty state.
        $ext->claimPriorityImage([]);

        $html = $this->container()->get(EntryBlocksRenderer::class)
            ->renderPublishedBlocks($this->appContext(), '', $entryUuid);
        self::assertNotNull($html);
        self::assertStringContainsString('PCBT-FRAGMENT-MARKER', (string) $html);
        self::assertTrue($ext->claimPriorityImage([]), 'the fragment render reset the claim at its boundary');
        self::assertFalse($ext->claimPriorityImage([]), 'at most one claim per fragment render');

        // The fragment left the claim consumed — a following FULL render must still
        // get a fresh claim from the controller's own boundary (both sides of
        // fragment isolation).
        $res = $this->handle(Request::create('/pcbtc/after-fragment', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($ext->claimPriorityImage([]), 'the full render after a fragment reset the claim');
        self::assertFalse($ext->claimPriorityImage([]), 'at most one claim per render');
    }

    public function testFullPageClaimGoesToTheBodyImageNeverTheSiteLogo(): void
    {
        // Spec §9: the site logo renders FIRST (layout.twig header, before any body
        // block) yet never claims the at-most-one fetchpriority slot — the first
        // eligible BODY image must still win it on a real end-to-end render.
        $this->seedPublicBlob('pcbtlogo0001');
        $this->seedPublicBlob('pcbtbody0001');
        $this->container()->get(SettingsStore::class)->putMany(['site_logo' => 'pcbtlogo0001']);
        $this->seedRoutedEntryWithImageBlockBody('pcbtd', 'logo-vs-body', 'pcbtbody0001');

        $res = $this->handle(Request::create('/pcbtd/logo-vs-body', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();

        self::assertSame(1, substr_count($html, 'fetchpriority="high"'), 'exactly one priority image per page');
        // The single claim sits on the body image…
        self::assertSame(1, preg_match('/<img[^>]*fetchpriority="high"[^>]*>/', $html, $priority));
        self::assertStringContainsString('pcbtbody0001', $priority[0]);
        // …while the site logo (rendered first) stays eager-but-unclaimed.
        self::assertSame(1, preg_match('/<img class="site-logo"[^>]*>/', $html, $logo));
        self::assertStringContainsString('pcbtlogo0001', $logo[0]);
        self::assertStringContainsString('loading="eager"', $logo[0]);
        self::assertStringNotContainsString('fetchpriority', $logo[0]);
    }

    private function seedPublicBlob(string $uuid): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'pcbt-blob-' . $uuid,
            'mime_type' => 'image/jpeg',
            'size' => 123,
            'url' => 'uploads/' . $uuid . '.bin',
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * A ROUTED published entry whose `body` holds one image block — the full-page
     * shape the logo-vs-body test renders through RenderController (routed twin of
     * seedRouteLessEntryWithHeadingBlock below).
     */
    private function seedRoutedEntryWithImageBlockBody(string $typeSlug, string $routeSlug, string $blobUuid): void
    {
        $blockTypes = $this->container()->get(BlockTypeRepository::class);
        if ($blockTypes->findBySlug('image') === null) {
            $blockTypes->create([
                'slug' => 'image',
                'label' => 'Image',
                'schema' => [
                    ['name' => 'image', 'type' => 'asset', 'required' => true],
                    ['name' => 'alt', 'type' => 'string'],
                ],
            ]);
        }

        $types = new ContentTypeRepository($this->connection());
        $typeUuid = $types->create([
            'slug' => $typeSlug,
            'name' => ucfirst($typeSlug),
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', [
            'title' => 'Logo vs body',
            'body' => [['id' => 'b1', 'type' => 'image', 'data' => ['image' => $blobUuid, 'alt' => 'Body image']]],
        ], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $typeUuid, 'en', $routeSlug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator($this->connection()),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');
    }

    /**
     * A published entry with NO entry_routes row whose `body` holds one heading block —
     * the route-less shape EntryBlocksRenderer exists for (mirrors
     * PublishedEntryBlocksReaderTest's fixture).
     */
    private function seedRouteLessEntryWithHeadingBlock(): string
    {
        $blockTypes = $this->container()->get(BlockTypeRepository::class);
        if ($blockTypes->findBySlug('heading') === null) {
            $blockTypes->create([
                'slug' => 'heading',
                'label' => 'Heading',
                'schema' => [['name' => 'text', 'type' => 'string']],
            ]);
        }

        $types = new ContentTypeRepository($this->connection());
        $typeUuid = $types->create([
            'slug' => 'pcbt_fragment',
            'name' => 'PCBT Fragment',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', [
            'title' => 'Fragment entry',
            'body' => [['id' => 'b1', 'type' => 'heading', 'data' => ['text' => 'PCBT-FRAGMENT-MARKER']]],
        ], 1, 0, 'user00000001');
        // Deliberately NO RouteRepository::assign() — this entry never gets a route.
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator($this->connection()),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');

        return $entry;
    }
}
