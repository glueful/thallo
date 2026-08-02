<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Preview\PreviewMinter;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Thallo\Render\Http\Controllers\RenderController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Visual-canvas spec §2/§3 + the preview surface split: blocks() annotation fires
 * ONLY for the design canvas (the stage iframe declares itself with ?canvas=1 on
 * the direct /_preview/{token} entry point — which does NOT pass
 * PreviewSessionMiddleware — and carries the thallo_preview_canvas cookie across
 * in-session navigation). The plain token URL is the REVIEW surface: rendered
 * clean, so the theme runtime behaves exactly as live (autoplay, arrows,
 * lightboxes). Live renders never annotate, and nothing leaks between renders on
 * the shared singletons. Bridge/css injection still fires on every preview render.
 */
final class PreviewAnnotationTest extends AppTestCase
{
    private string $type;

    protected function tearDown(): void
    {
        $this->container()->get(\Glueful\Cache\CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Thallo\Seo\Cache\SitemapCache::class)->forgetAll();
        parent::tearDown();
    }

    private function seedBlockPage(string $slug): string
    {
        // `rich_text` matches the default theme's blocks/rich_text.twig — a rendered
        // (not missing-template) instance is what annotation wraps.
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'rich_text',
            'label' => 'Rich text',
            'schema' => [['name' => 'body', 'type' => 'text']],
        ]);
        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'S', 'body' => [
            ['id' => 'blockone0001', 'type' => 'rich_text', 'data' => ['body' => '<p>Hello card</p>']],
        ]], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $this->type, 'en', $slug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');
        return $entry;
    }

    public function testOnlyCanvasPreviewsAnnotateBlocks(): void
    {
        $entry = $this->seedBlockPage('source');

        // LIVE render: no wrapper, no data-thallo-block, no bridge injection.
        $live = $this->handle(Request::create('/page/source', 'GET'));
        self::assertSame(200, $live->getStatusCode());
        $liveHtml = (string) $live->getContent();
        self::assertStringContainsString('Hello card', $liveHtml);
        self::assertStringNotContainsString('data-thallo-block', $liveHtml);

        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        // REVIEW surface: the plain token URL renders WITHOUT carriers so the
        // theme runtime enhances it like a live page.
        $review = $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        );
        $reviewHtml = (string) $review->getContent();
        self::assertStringContainsString('Hello card', $reviewHtml);
        self::assertStringNotContainsString('data-thallo-block', $reviewHtml);
        self::assertStringNotContainsString('class="thallo-preview-block"', $reviewHtml);

        // CANVAS surface (spec §2 P1: preview() does NOT pass the session
        // middleware — annotation keys off the stage's own ?canvas=1).
        $canvas = $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}?canvas=1", 'GET'),
            $token,
        );
        $html = (string) $canvas->getContent();
        self::assertStringContainsString('class="thallo-preview-block"', $html);
        self::assertStringContainsString('data-thallo-block="blockone0001"', $html);

        // And the flag does not leak: the NEXT live render is clean again.
        $liveAgain = $this->handle(Request::create('/page/source', 'GET'));
        self::assertStringNotContainsString('data-thallo-block', (string) $liveAgain->getContent());
    }

    public function testCanvasCookieCarriesAnnotationAcrossInSessionNavigation(): void
    {
        $entry = $this->seedBlockPage('walk');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $controller = $this->container()->get(RenderController::class);

        // The canvas load starts the session AND sets the companion canvas cookie.
        $canvas = $controller->preview(Request::create("/_preview/{$token}?canvas=1", 'GET'), $token);
        $set = [];
        foreach ($canvas->headers->getCookies() as $cookie) {
            $set[$cookie->getName()] = $cookie;
        }
        self::assertSame($token, $set['thallo_preview']->getValue());
        self::assertSame('1', ($set['thallo_preview_canvas'] ?? null)?->getValue());

        // A review load does NOT — and actively expires a stale canvas cookie, so
        // switching Design -> review in the same browser drops the annotations.
        $review = $controller->preview(Request::create("/_preview/{$token}", 'GET'), $token);
        $reviewCanvasCookie = null;
        foreach ($review->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'thallo_preview_canvas') {
                $reviewCanvasCookie = $cookie;
            }
        }
        self::assertNotNull($reviewCanvasCookie);
        self::assertLessThan(time(), $reviewCanvasCookie->getExpiresTime());

        // Cookie-backed in-session navigation annotates ONLY under the canvas cookie.
        $inCanvas = $this->handle(Request::create(
            '/page/walk',
            'GET',
            [],
            ['thallo_preview' => $token, 'thallo_preview_canvas' => '1'],
        ));
        self::assertStringContainsString('data-thallo-block', (string) $inCanvas->getContent());

        $inReview = $this->handle(Request::create('/page/walk', 'GET', [], ['thallo_preview' => $token]));
        $inReviewHtml = (string) $inReview->getContent();
        self::assertStringContainsString('Hello card', $inReviewHtml);
        self::assertStringNotContainsString('data-thallo-block', $inReviewHtml);

        // Exit clears BOTH cookies.
        $exit = $this->handle(Request::create('/_preview/exit', 'GET', [], ['thallo_preview' => $token]));
        $clearedNames = [];
        foreach ($exit->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() < time()) {
                $clearedNames[] = $cookie->getName();
            }
        }
        self::assertContains('thallo_preview', $clearedNames);
        self::assertContains('thallo_preview_canvas', $clearedNames);
    }

    public function testBridgeInjectionOnPreviewHtmlOnly(): void
    {
        $entry = $this->seedBlockPage('inject');

        // Preview HTML: exactly one stylesheet link + one bridge script.
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $preview = $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        );
        $html = (string) $preview->getContent();
        // Exactly one stylesheet link + one bridge script, each with the mtime
        // cache-buster (the assets serve max-age=86400 — without ?v=, bridge
        // changes would ship a day late to any browser that already previewed).
        self::assertSame(1, (int) preg_match_all('#<link rel="stylesheet" href="/_preview\.css\?v=\d+">#', $html));
        self::assertSame(
            1,
            (int) preg_match_all('#<script src="/_preview-bridge\.js\?v=\d+" defer></script>#', $html),
        );
        // Injected BEFORE </body>, not appended after the document.
        self::assertSame(1, (int) preg_match_all('#defer></script></body>#', $html));

        // Live HTML: neither.
        $live = $this->handle(Request::create('/page/inject', 'GET'));
        self::assertStringNotContainsString('/_preview.css', (string) $live->getContent());
        self::assertStringNotContainsString('/_preview-bridge.js', (string) $live->getContent());
    }

    public function testInjectionAppendsWhenBodyTagIsAbsentAndSkipsNonHtml(): void
    {
        $controller = $this->container()->get(RenderController::class);
        $m = new \ReflectionMethod($controller, 'withPreviewBridge');

        // Bare HTML without </body>: appended at end-of-document, render never fails.
        $bare = new \Glueful\Http\Response('<h1>bare</h1>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
        $out = $m->invoke($controller, $bare);
        self::assertStringEndsWith('defer></script>', (string) $out->getContent());

        // Non-HTML (redirect/asset shapes): byte-untouched.
        $redirect = new \Glueful\Http\Response('', 302, ['Location' => '/']);
        $before = (string) $redirect->getContent();
        self::assertSame($before, (string) $m->invoke($controller, $redirect)->getContent());
        self::assertStringNotContainsString('_preview-bridge', (string) $redirect->getContent());
    }

    public function testStaticSupportRoutesServeCacheableAssets(): void
    {
        $css = $this->handle(Request::create('/_preview.css', 'GET'));
        self::assertSame(200, $css->getStatusCode());
        self::assertStringContainsString('text/css', (string) $css->headers->get('Content-Type'));
        self::assertStringContainsString('max-age=86400', (string) $css->headers->get('Cache-Control'));
        self::assertStringContainsString('.thallo-preview-block { display: contents; }', (string) $css->getContent());

        $js = $this->handle(Request::create('/_preview-bridge.js', 'GET'));
        self::assertSame(200, $js->getStatusCode());
        self::assertStringContainsString('javascript', (string) $js->headers->get('Content-Type'));
        self::assertStringContainsString('thallo:canvas-hello', (string) $js->getContent());
    }
}
