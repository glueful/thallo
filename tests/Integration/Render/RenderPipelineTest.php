<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;
use Thallo\Navigation\MenuRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drives the render pipeline through the REAL kernel (Application::handle) — the router
 * bucket order (static → literal buckets → '*' catch-all) is itself the subject.
 */
final class RenderPipelineTest extends AppTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        // Hygiene: the render page cache and any sitemap entry cached during a render
        // request must not leak into later tests (the store is process-shared; sitemap
        // entries carry no TTL, and cached pages would serve earlier tests' seeds).
        $this->container()->get(\Glueful\Cache\CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Thallo\Seo\Cache\SitemapCache::class)->forgetAll();
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
            ->get(\Thallo\Render\Http\Controllers\RenderController::class);
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
        // loadRoutesFrom latch (see AppTestCase), so drive the CONTROLLER from the
        // override container directly — GET / routing itself is covered by
        // testHomepageStandaloneMode through the shared kernel.
        $entry = $this->seedBilingualPublishedEntry();

        $app = self::bootAppWithConfigOverride('render', ['homepage_entry' => $entry]);
        $controller = $app->getContainer()
            ->get(\Thallo\Render\Http\Controllers\RenderController::class);
        $res = $controller->home(Request::create('/', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('<h1>Hello</h1>', (string) $res->getContent());

        $bad = self::bootAppWithConfigOverride('render', ['homepage_entry' => 'nope00000000']);
        $controller = $bad->getContainer()
            ->get(\Thallo\Render\Http\Controllers\RenderController::class);
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
            'thallo:entry:' . $target,
            (string) $response->headers->get('Cache-Tag'),
        );
        self::assertStringNotContainsString('cache_tags', (string) $response->getContent());
    }

    // ---- Presentation layer (modern-default-theme spec §5a) ------------------------

    /**
     * Seed a `pages` type (title + blocks body), publish one entry with the
     * given fields at /pages/{slug}, and return its uuid. `_presentation` in
     * $fields exercises the reserved system key end-to-end.
     *
     * @param array<string,mixed> $fields
     */
    private function seedPresentationEntry(array $fields, string $slug): string
    {
        (new \App\Content\Blocks\BlockTypeRepository($this->connection()))->create([
            'slug' => 'quote',
            'label' => 'Quote',
            'schema' => [['name' => 'text', 'type' => 'text']],
        ]);
        $types = $this->container()->get(\App\Content\Repositories\ContentTypeRepository::class);
        $type = $types->create([
            'slug' => 'pages',
            'name' => 'Pages',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
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
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', $fields, 1, 0, 'user00000001');
        (new \App\Content\Repositories\RouteRepository($this->connection()))->assign($entry, $type, 'en', $slug);
        $publish->publish($entry, 'en', 'user00000001');
        return $entry;
    }

    public function testCurrentPathIsInTemplateContextNormalized(): void
    {
        // Unit: the shared normalizer (HTTP-path hygiene ONLY — no canonical
        // routing decisions; the P2 scope pin).
        $normalize = \Thallo\Render\Http\Middleware\RenderPageCache::normalizePath(...);
        self::assertSame('/pages/ctx', $normalize('/pages//ctx/'));
        self::assertSame('/pages/ctx', $normalize('/pages/ctx?x=1'));
        self::assertSame('/', $normalize('/'));
        self::assertSame('/', $normalize('//'));

        // End-to-end: a TEST-ONLY minimal DB layout override prints the probe —
        // the production layout gains no debug artifact (nav-v2 review P1); the
        // override row lives in the test DB and truncates with the test. (A
        // verbatim layout copy can't be the fixture: the filesystem layout uses
        // constructs the DB sandbox deliberately excludes.)
        $this->seedPresentationEntry(['title' => 'Ctx'], 'ctx');
        (new \Thallo\Render\Templates\TemplateRepository($this->connection()))->save(
            'default',
            'layout.twig',
            '<!doctype html><html><body><!-- test-probe:{{ current_path }} -->'
                . '{% block content %}{% endblock %}</body></html>',
            null,
        );
        // Non-canonical forms 301 BEFORE render (the resolver's job — exactly
        // why exact-matching against canonical item urls is sound):
        self::assertSame(301, $this->handle(Request::create('/pages//ctx/', 'GET'))->getStatusCode());

        $res = $this->handle(Request::create('/pages/ctx?utm=x', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('test-probe:/pages/ctx', (string) $res->getContent());
    }

    public function testPresentationHiddenSuppressesChromeAndItsFallback(): void
    {
        // Chrome keys (global-regions spec §7/§12): 'hidden' suppresses BOTH the
        // region render and the hardcoded fallback — per page, no region needed.
        $entry = $this->seedPresentationEntry([
            'title' => 'No Chrome',
            '_presentation' => ['header' => 'hidden', 'footer' => 'hidden'],
        ], 'nochrome');
        $res = $this->handle(Request::create('/pages/nochrome', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringNotContainsString('class="site-name"', $html);   // no fallback header
        self::assertStringNotContainsString('lemma-region-header', $html); // no region header
        self::assertStringNotContainsString('<footer', $html);             // no footer at all
        self::assertStringContainsString('No Chrome', $html);              // the page itself renders
    }

    public function testPresentationOverrideHidesTitleAndSetsLayout(): void
    {
        $entry = $this->seedPresentationEntry([
            'title' => 'Hidden Title Page',
            '_presentation' => ['show_title' => false, 'layout' => 'full'],
        ], 'hidden');
        $res = $this->handle(Request::create('/pages/hidden', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        // Attribute-resilient (plan-review note).
        self::assertDoesNotMatchRegularExpression('/<h1[^>]*>\s*Hidden Title Page/', $html);
        self::assertStringContainsString('layout--full', $html);

        // Homepage honors the same override (override-app controller pattern).
        $app = self::bootAppWithConfigOverride('render', ['homepage_entry' => $entry]);
        $controller = $app->getContainer()
            ->get(\Thallo\Render\Http\Controllers\RenderController::class);
        $home = $controller->home(Request::create('/', 'GET'));
        self::assertSame(200, $home->getStatusCode());
        self::assertDoesNotMatchRegularExpression(
            '/<h1[^>]*>\s*Hidden Title Page/',
            (string) $home->getContent(),
        );
    }

    public function testPresentationBuiltInsApplyWithoutOverride(): void
    {
        $this->seedBilingualPublishedEntry();
        $plain = $this->handle(Request::create('/blog/hello', 'GET'));
        $html = (string) $plain->getContent();
        self::assertStringContainsString('<h1>Hello</h1>', $html);      // show_title built-in: true
        self::assertStringContainsString('layout--centered', $html);    // layout built-in: centered
        self::assertStringContainsString('entry-content', $html);
    }

    public function testThemeJsonSettingsBlockIsStrictlyValidated(): void
    {
        // Build a throwaway app theme on disk; settings validate at construction
        // (modern-default-theme spec §5a: loud rejection, fixed vocabulary).
        $dir = sys_get_temp_dir() . '/lemma-theme-settings-' . uniqid();
        mkdir($dir . '/settingstheme/templates', 0777, true);
        try {
            $write = function (array $json) use ($dir): void {
                file_put_contents(
                    $dir . '/settingstheme/theme.json',
                    json_encode(['name' => 'settingstheme'] + $json),
                );
            };
            // Valid settings resolve, per-type included.
            $write(['settings' => [
                'layout' => 'full',
                'types' => ['pages' => ['show_title' => false]],
            ]]);
            $locator = new \Thallo\Render\ThemeLocator('settingstheme', $dir);
            self::assertSame('full', $locator->settings()['layout']);
            self::assertFalse($locator->settings()['types']['pages']['show_title']);

            // Unknown key -> loud ThemeConfigError.
            $write(['settings' => ['sparkles' => true]]);
            try {
                new \Thallo\Render\ThemeLocator('settingstheme', $dir);
                self::fail('expected ThemeConfigError');
            } catch (\Thallo\Render\ThemeConfigError) {
            }

            // Bad enum value -> loud too.
            $write(['settings' => ['layout' => 'sideways']]);
            $this->expectException(\Thallo\Render\ThemeConfigError::class);
            new \Thallo\Render\ThemeLocator('settingstheme', $dir);
        } finally {
            @unlink($dir . '/settingstheme/theme.json');
            @rmdir($dir . '/settingstheme/templates');
            @rmdir($dir . '/settingstheme');
            @rmdir($dir);
        }
    }

    // ---- DB-backed homepage setting (homepage-setting spec §0) ---------------------

    public function testHomepageDbSettingWinsAndClearFallsBackToEnv(): void
    {
        $dbHome = $this->seedPresentationEntry(['title' => 'DB Home'], 'db-home');
        $envHome = $this->seedBilingualPublishedEntry(); // 'Hello' at /blog/hello

        // Env configured, DB set: DB wins.
        $app = self::bootAppWithConfigOverride('render', ['homepage_entry' => $envHome]);
        $app->getContainer()->get(\App\Settings\SettingsStore::class)
            ->putMany(['homepage_entry' => $dbHome]);
        $controller = $app->getContainer()
            ->get(\Thallo\Render\Http\Controllers\RenderController::class);
        $home = fn(): string => (string) $controller->home(Request::create('/', 'GET'))->getContent();
        self::assertStringContainsString('DB Home', $home());

        // Clear (forget the row): env fallback ACTUALLY changes the render.
        $app->getContainer()->get(\App\Settings\GeneralSettings::class)
            ->save(['homepage_entry' => '']);
        self::assertStringContainsString('Hello', $home());

        // Both empty: the standalone index.
        $bare = self::bootAppWithConfigOverride('render', ['homepage_entry' => '']);
        $bareController = $bare->getContainer()
            ->get(\Thallo\Render\Http\Controllers\RenderController::class);
        self::assertStringContainsString(
            'powered by Lemma',
            (string) $bareController->home(Request::create('/', 'GET'))->getContent(),
        );
    }

    public function testBrokenDbHomepageFallsBackWithoutA500(): void
    {
        // Valid-at-write, broken later (spec pin): the provider re-validates per
        // request, logs, and falls back — never a runtime 500.
        $envHome = $this->seedBilingualPublishedEntry();
        $app = self::bootAppWithConfigOverride('render', ['homepage_entry' => $envHome]);
        $app->getContainer()->get(\App\Settings\SettingsStore::class)
            ->putMany(['homepage_entry' => 'gone00000000']); // simulates a later-deleted entry
        $controller = $app->getContainer()
            ->get(\Thallo\Render\Http\Controllers\RenderController::class);
        $res = $controller->home(Request::create('/', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('Hello', (string) $res->getContent()); // env fallback

        // The env-invalid posture is UNCHANGED: loud 500 (deploy config error).
        $bad = self::bootAppWithConfigOverride('render', ['homepage_entry' => 'nope00000000']);
        $badController = $bad->getContainer()
            ->get(\Thallo\Render\Http\Controllers\RenderController::class);
        self::assertSame(500, $badController->home(Request::create('/', 'GET'))->getStatusCode());
    }

    public function testHomepageSettingWriteTimeValidation(): void
    {
        $published = $this->seedPresentationEntry(['title' => 'Settable'], 'settable');
        $controller = $this->container()->get(\App\Http\Controllers\GeneralSettingsController::class);
        $hydrate = fn(array $body) => (new \Glueful\Validation\RequestDataHydrator())
            ->hydrate(\App\Http\DTOs\UpdateGeneralSettingsData::class, $body, [], []);

        // Unknown uuid -> 422.
        $bad = $controller->update($hydrate(['homepage_entry' => 'missing000000']));
        self::assertSame(422, $bad->getStatusCode());

        // Published public entry -> saved; effective settings echo it.
        $ok = $controller->update($hydrate(['homepage_entry' => $published]));
        self::assertSame(200, $ok->getStatusCode());
        $settings = json_decode((string) $ok->getContent(), true)['data']['settings'];
        self::assertSame($published, $settings['homepage_entry']);

        // Explicit '' clears the row -> env fallback ('' here).
        $cleared = $controller->update($hydrate(['homepage_entry' => '']));
        self::assertSame(200, $cleared->getStatusCode());
        self::assertSame(
            '',
            json_decode((string) $cleared->getContent(), true)['data']['settings']['homepage_entry'],
        );
        self::assertNull(
            $this->container()->get(\App\Settings\SettingsStore::class)->get('homepage_entry'),
        );
    }

    public function testIdentitySettingsRoundTrip(): void
    {
        // Site-identity spec §1: site_favicon + site_logo_dark thread through the
        // DTO, controller save map, and effective settings.
        $controller = $this->container()->get(\App\Http\Controllers\GeneralSettingsController::class);
        $hydrate = fn(array $body) => (new \Glueful\Validation\RequestDataHydrator())
            ->hydrate(\App\Http\DTOs\UpdateGeneralSettingsData::class, $body, [], []);

        $res = $controller->update($hydrate([
            'site_favicon' => 'favic0000001',
            'site_logo_dark' => 'dark00000001',
        ]));
        self::assertSame(200, $res->getStatusCode());
        $settings = json_decode((string) $res->getContent(), true)['data']['settings'];
        self::assertSame('favic0000001', $settings['site_favicon']);
        self::assertSame('dark00000001', $settings['site_logo_dark']);

        $general = $this->container()->get(\App\Settings\GeneralSettings::class);
        self::assertSame('favic0000001', $general->siteFavicon());
        self::assertSame('dark00000001', $general->siteLogoDark());
    }

    public function testAssetUrlsCarryTheThemeBuster(): void
    {
        // Theme-setting spec §3 P1: browser caches don't see page-cache purges —
        // asset() appends ?t={theme} so a switch re-fetches assets immediately.
        $this->seedBilingualPublishedEntry();
        $html = $this->renderHello();
        self::assertMatchesRegularExpression('#/theme-assets/site\.css\?t=default#', $html);
        self::assertMatchesRegularExpression('#/theme-assets/blocks\.css\?t=default#', $html);
    }

    public function testThemeSettingRoundTripAndValidation(): void
    {
        $controller = $this->container()->get(\App\Http\Controllers\GeneralSettingsController::class);
        $hydrate = fn(array $body) => (new \Glueful\Validation\RequestDataHydrator())
            ->hydrate(\App\Http\DTOs\UpdateGeneralSettingsData::class, $body, [], []);

        // Unknown theme -> 422 (the validator is bound; only 'default' exists here).
        self::assertSame(422, $controller->update($hydrate(['theme' => 'nope']))->getStatusCode());

        // 'default' is always valid; round-trips as the STORED override.
        $ok = $controller->update($hydrate(['theme' => 'default']));
        self::assertSame(200, $ok->getStatusCode());
        $general = $this->container()->get(\App\Settings\GeneralSettings::class);
        self::assertSame('default', $general->themeOverride());

        // Explicit '' clears the row -> env fallback; the RAW override reads null.
        $controller->update($hydrate(['theme' => '']));
        self::assertNull($general->themeOverride());
        self::assertSame('default', $general->theme()); // effective falls back
    }

    /** Insert a blobs row directly (the framework table; uploads are out of scope here). */
    private function seedBlob(string $visibility = 'public'): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => 'pic.png',
            'mime_type' => 'image/png',
            'size' => 123,
            'url' => 'uploads/pic.png',
            'visibility' => $visibility,
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    /** Render /blog/hello through the real kernel with a cold page cache. */
    private function renderHello(): string
    {
        $this->container()->get(\Glueful\Cache\CacheStore::class)->deletePattern('render:*');
        $res = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        return (string) $res->getContent();
    }

    public function testCustomCssLinkRendersOnlyWhenARowExists(): void
    {
        $this->seedBilingualPublishedEntry();

        // Fresh install: no link at all.
        self::assertStringNotContainsString('/custom.css', $this->renderHello());

        // Saved custom CSS: the layout links the versioned URL.
        $save = function (string $source): void {
            $req = Request::create(
                '/x',
                'PUT',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                (string) json_encode(['source' => $source]),
            );
            $req->attributes->set('user', ['uuid' => 'user00000001']);
            $res = $this->container()
                ->get(\Thallo\Render\Http\Controllers\TemplatesAdminController::class)
                ->save($req, 'custom.css');
            self::assertSame(200, $res->getStatusCode());
        };
        $save('.x { color: red; }');
        $html = $this->renderHello();
        self::assertMatchesRegularExpression('#/custom\.css\?v=[A-Za-z0-9_-]+#', $html);
        preg_match('#/custom\.css\?v=([A-Za-z0-9_-]+)#', $html, $m1);

        // A new save changes the cache-buster (immutable caching stays honest).
        $save('.y { color: blue; }');
        preg_match('#/custom\.css\?v=([A-Za-z0-9_-]+)#', $this->renderHello(), $m2);
        self::assertNotSame($m1[1], $m2[1]);
    }

    public function testFaviconLinkObeysTheMediaPredicate(): void
    {
        $this->seedBilingualPublishedEntry();
        $store = $this->container()->get(\App\Settings\SettingsStore::class);

        // Unset: no link tag at all.
        self::assertStringNotContainsString('rel="icon"', $this->renderHello());

        // Public blob: the link renders with the blob-route URL.
        $public = $this->seedBlob();
        $store->putMany(['site_favicon' => $public]);
        $html = $this->renderHello();
        self::assertStringContainsString('rel="icon"', $html);
        self::assertStringContainsString('/blobs/' . $public, $html);

        // P1 proof: a PRIVATE blob yields NO link tag — favicon fetches are
        // anonymous; a 401ing link is worse than a missing one.
        $store->putMany(['site_favicon' => $this->seedBlob(visibility: 'private')]);
        self::assertStringNotContainsString('rel="icon"', $this->renderHello());
    }

    public function testDarkLogoPairRendersOnlyWhenTheVariantIsSet(): void
    {
        $this->seedBilingualPublishedEntry();
        $store = $this->container()->get(\App\Settings\SettingsStore::class);
        $light = $this->seedBlob();
        $store->putMany(['site_logo' => $light]);

        // Light-only regression: the single un-suffixed img, no modifier, no dark twin.
        $html = $this->renderHello();
        self::assertStringContainsString('<img class="site-logo" src="', $html);
        self::assertStringContainsString('/blobs/' . $light, $html);
        self::assertStringNotContainsString('site-name--has-dark', $html);
        self::assertStringNotContainsString('site-logo--dark', $html);

        // Dark set: the modifier + the light/dark pair.
        $dark = $this->seedBlob();
        $store->putMany(['site_logo_dark' => $dark]);
        $html = $this->renderHello();
        self::assertStringContainsString('site-name--has-dark', $html);
        self::assertStringContainsString('class="site-logo site-logo--light"', $html);
        self::assertStringContainsString('class="site-logo site-logo--dark"', $html);
        self::assertStringContainsString('/blobs/' . $dark, $html);
    }

    public function testUnderscoreFieldNamesAreRejectedInSchemas(): void
    {
        // Reserved system keys (spec §5a): [a-z][a-z0-9_]* already forbids a
        // leading underscore — this test PINS that as the reservation policy.
        $types = $this->container()->get(\App\Content\Repositories\ContentTypeRepository::class);
        $this->expectException(\App\Content\Schema\SchemaParseException::class);
        \App\Content\Schema\ContentTypeSchema::fromArray([
            ['name' => '_presentation', 'type' => 'string'],
        ]);
    }

    public function testInvalidPresentationValuesFailValidation(): void
    {
        $validator = new \App\Content\Validation\FieldValidator(
            $this->connection(),
            $this->appContext(),
            new \App\Content\Blocks\BlockTypeRepository($this->connection()),
        );
        $schema = \App\Content\Schema\ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string'],
        ]);
        // Valid vocabulary passes and is PRESERVED in the cleaned payload.
        $clean = $validator->validate($schema, [
            'title' => 'T',
            '_presentation' => ['show_title' => false, 'layout' => 'centered'],
        ]);
        self::assertSame(['show_title' => false, 'layout' => 'centered'], $clean['_presentation']);
        // Unknown subkey and bad enum value both fail loudly.
        try {
            $validator->validate($schema, ['title' => 'T', '_presentation' => ['layout' => 'sideways']]);
            self::fail('expected ValidationException');
        } catch (\App\Content\Validation\ValidationException) {
        }
        try {
            $validator->validate($schema, ['title' => 'T', '_presentation' => ['sparkles' => true]]);
            self::fail('expected ValidationException');
        } catch (\App\Content\Validation\ValidationException) {
        }

        // Chrome keys (global-regions spec §7): 'default' | 'hidden' only —
        // variants are FUTURE vocabulary and must fail loudly today.
        $clean = $validator->validate($schema, [
            'title' => 'T',
            '_presentation' => ['header' => 'hidden', 'footer' => 'default'],
        ]);
        self::assertSame(['header' => 'hidden', 'footer' => 'default'], $clean['_presentation']);
        try {
            $validator->validate($schema, ['title' => 'T', '_presentation' => ['footer' => 'variant:mini']]);
            self::fail('expected ValidationException');
        } catch (\App\Content\Validation\ValidationException) {
        }
    }
}
