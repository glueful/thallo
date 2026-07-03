<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;
use Glueful\Lemma\Navigation\MenuRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drives the render pipeline through the REAL kernel (Application::handle) — the router
 * bucket order (static → literal buckets → '*' catch-all) is itself the subject.
 */
final class RenderPipelineTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        // Hygiene: the render page cache and any sitemap entry cached during a render
        // request must not leak into later tests (the store is process-shared; sitemap
        // entries carry no TTL, and cached pages would serve earlier tests' seeds).
        $this->container()->get(\Glueful\Cache\CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Glueful\Lemma\Seo\Cache\SitemapCache::class)->forgetAll();
        parent::tearDown();
    }

    public function testPublishedEntryRendersHtmlWithMenu(): void
    {
        $this->seedBilingualPublishedEntry();
        $menus = $this->container()->get(MenuRepository::class);
        $menu = $menus->createMenu('main', 'Main');
        $menus->replaceTree((string) $menu['uuid'], 0, [[
            'uuid' => Utils::generateNanoID(),
            'parent_uuid' => null,
            'position' => 0,
            'kind' => 'url',
            'entry_uuid' => null,
            'url' => '/about-us',
            'labels' => json_encode(['en' => 'About us']),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]]);

        $res = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/html', (string) $res->headers->get('Content-Type'));
        $html = (string) $res->getContent();
        self::assertStringContainsString('<h1>Hello</h1>', $html);
        self::assertStringContainsString('About us', $html);            // menu() with real navigation data
        self::assertStringContainsString('/theme-assets/site.css', $html); // asset()
    }

    public function testNormalizationRedirect(): void
    {
        $this->seedBilingualPublishedEntry();
        $res = $this->handle(Request::create('/blog//hello', 'GET'));
        self::assertSame(301, $res->getStatusCode());
        self::assertSame('/blog/hello', $res->headers->get('Location'));
    }

    public function testThemed404(): void
    {
        $res = $this->handle(Request::create('/no/such-page', 'GET'));
        self::assertSame(404, $res->getStatusCode());
        self::assertStringContainsString('text/html', (string) $res->headers->get('Content-Type'));
        self::assertStringContainsString('Page not found', (string) $res->getContent());
    }

    public function testReservedPathsReturnStandardJson404(): void
    {
        // Prefix semantics through the REAL kernel: unmatched /v1/* stays a standard JSON 404.
        $res = $this->handle(Request::create('/v1/nonexistent-endpoint', 'GET'));
        self::assertSame(404, $res->getStatusCode());
        self::assertStringContainsString('json', (string) $res->headers->get('Content-Type'));
        $body = json_decode((string) $res->getContent(), true);
        self::assertFalse($body['success']);
        self::assertSame('Not Found', $body['message']);
        self::assertSame(404, $body['error']['code']);

        // Exact semantics via the controller directly: driving GET /sitemap.xml through the
        // kernel would hit the live seo route and poison its no-TTL sitemap cache with an
        // empty build (cross-suite pollution) — the guard itself is what's under test here.
        $controller = $this->container()
            ->get(\Glueful\Lemma\Render\Http\Controllers\RenderController::class);
        $res = $controller->page(Request::create('/x', 'GET'), 'sitemap.xml');
        self::assertSame(404, $res->getStatusCode());
        self::assertStringContainsString('json', (string) $res->headers->get('Content-Type'));

        // NOT reserved: /sitemap-history renders the themed 404 (exact ≠ prefix).
        $res = $this->handle(Request::create('/sitemap-history', 'GET'));
        self::assertSame(404, $res->getStatusCode());
        self::assertStringContainsString('text/html', (string) $res->headers->get('Content-Type'));
    }

    public function testHomepageStandaloneMode(): void
    {
        $res = $this->handle(Request::create('/', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('powered by Lemma', (string) $res->getContent());
    }

    public function testHomepageEntryAndBadConfigModes(): void
    {
        // Config-override boots lose extension ROUTES to the framework's process-global
        // loadRoutesFrom latch (see LemmaTestCase), so drive the CONTROLLER from the
        // override container directly — GET / routing itself is covered by
        // testHomepageStandaloneMode through the shared kernel.
        $entry = $this->seedBilingualPublishedEntry();

        $app = self::bootAppWithConfigOverride('lemma_render', ['homepage_entry' => $entry]);
        $controller = $app->getContainer()
            ->get(\Glueful\Lemma\Render\Http\Controllers\RenderController::class);
        $res = $controller->home(Request::create('/', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('<h1>Hello</h1>', (string) $res->getContent());

        $bad = self::bootAppWithConfigOverride('lemma_render', ['homepage_entry' => 'nope00000000']);
        $controller = $bad->getContainer()
            ->get(\Glueful\Lemma\Render\Http\Controllers\RenderController::class);
        $res = $controller->home(Request::create('/', 'GET'));
        self::assertSame(500, $res->getStatusCode());
        self::assertStringNotContainsString('Page not found', (string) $res->getContent());
    }

    public function testHeadRequestServesGetHeaders(): void
    {
        $this->seedBilingualPublishedEntry();
        $res = $this->handle(Request::create('/blog/hello', 'HEAD'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('text/html', (string) $res->headers->get('Content-Type'));
    }

    public function testGoneRendersErrorTemplateWith410(): void
    {
        $this->seedBilingualPublishedEntry();
        $types = $this->container()->get(\App\Content\Repositories\ContentTypeRepository::class);
        $entries = $this->container()->get(\App\Content\Repositories\EntryRepository::class);
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $draft = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($draft, 'en', ['title' => 'Draft'], 1, 0, 'user00000001');
        (new \App\Content\Seo\RedirectRepository($this->connection()))->create([
            'content_type_uuid' => $typeUuid,
            'locale' => 'en',
            'source_slug' => 'moved-away',
            'target_content_type_uuid' => $typeUuid,
            'target_locale' => 'en',
            'target_entry_uuid' => $draft,
            'status' => 301,
        ]);

        $res = $this->handle(Request::create('/blog/moved-away', 'GET'));
        self::assertSame(410, $res->getStatusCode());
        self::assertStringContainsString('Something went wrong', (string) $res->getContent());
    }

    public function testRenderedPageCarriesExpansionTargetTag(): void
    {
        // A `page` source embedding a block reference to a target: the rendered
        // page's Cache-Tag must carry the TARGET's entry tag, byte-identical to
        // InvalidateCacheTagsListener's purge string, so a republish of the target
        // reaches this cached page (spec §4).
        (new \App\Content\Blocks\BlockTypeRepository($this->connection()))->create([
            'slug' => 'related',
            'label' => 'Related',
            'schema' => [['name' => 'post', 'type' => 'reference']],
        ]);
        $types = $this->container()->get(\App\Content\Repositories\ContentTypeRepository::class);
        $type = $types->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'sections', 'type' => 'blocks'],
            ],
        ]);
        $entries = new \App\Content\Repositories\EntryRepository($this->connection(), $this->appContext(), $types);
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

        // The blocks field is named `sections`, NOT `body`: the reference theme's
        // entry.twig echoes fields.body as text, and echoing an array 500s.
        $target = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($target, 'en', ['title' => 'T', 'sections' => []], 1, 0, 'user00000001');
        $routes->assign($target, $type, 'en', 'target');
        $publish->publish($target, 'en', 'user00000001');

        $source = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($source, 'en', ['title' => 'S', 'sections' => [
            ['id' => 'b1', 'type' => 'related', 'data' => ['post' => $target]],
        ]], 1, 0, 'user00000001');
        $routes->assign($source, $type, 'en', 'source');
        $publish->publish($source, 'en', 'user00000001');

        $response = $this->handle(Request::create('/page/source', 'GET'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            'lemma:entry:' . $target,
            (string) $response->headers->get('Cache-Tag'),
        );
        self::assertStringNotContainsString('cache_tags', (string) $response->getContent());
    }
}
