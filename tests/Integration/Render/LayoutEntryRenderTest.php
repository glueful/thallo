<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\PublishedReferenceRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;
use Thallo\Core\Tests\Support\ThemeFixture;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\ThemeLocator;
use Thallo\Render\TwigFactory;

/**
 * Entries rendered through their type's layout (type layouts spec §7): selection before the theme's
 * templates, the homepage route never asked, the opt-out, the frame's precedence, the surface tag
 * on every eligible page, a first save and a removal reaching cached pages, and each form keeping
 * its own source — a layout's form one form across the type, the header's and footer's one form
 * across the site, a body's its page's.
 */
final class LayoutEntryRenderTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    /** @var array<string,string> type slug => type uuid */
    private array $types = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Thallo\Seo\Cache\SitemapCache::class)->forgetAll();
        foreach (['post', 'lpage'] as $slug) {
            $this->container()->get(LayoutResolver::class)->forget('entry', $slug);
        }
        $this->removeTheme();
        parent::tearDown();
    }

    private function type(string $slug): string
    {
        if (isset($this->types[$slug])) {
            return $this->types[$slug];
        }
        $repo = new ContentTypeRepository($this->connection());
        $schema = [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'blocks'],
        ];
        if ($slug === 'post') {
            $this->types['category'] = $repo->create(['slug' => 'category', 'name' => 'Categories',
                'public_delivery' => true, 'schema' => [
                    ['name' => 'title', 'type' => 'string', 'required' => true],
                    ['name' => 'slug', 'type' => 'string', 'required' => true],
                ]]);
            $schema[] = ['name' => 'categories', 'type' => 'reference', 'multiple' => true, 'filterable' => true,
                'reference_type' => 'category', 'reference_slug_field' => 'slug'];
        }
        return $this->types[$slug] = $repo->create([
            'slug' => $slug,
            'name' => $slug === 'post' ? 'Posts' : 'Pages',
            'public_delivery' => true,
            'schema' => $schema,
        ]);
    }

    /**
     * One published version in one locale, written as rows (the shape PostPageTest seeds).
     *
     * @param array<string,mixed> $fields
     */
    private function publishRow(
        string $uuid,
        string $type,
        string $locale,
        array $fields,
        string $at,
        ?string $slug,
    ): void {
        $db = $this->connection();
        if ($db->table('entries')->where('uuid', '=', $uuid)->first() === null) {
            $db->table('entries')->insert(['uuid' => $uuid, 'content_type_uuid' => $this->type($type),
                'status' => 'active', 'created_at' => $at, 'updated_at' => $at]);
        }
        $version = substr('v' . $locale . $uuid, 0, 12);
        $db->table('entry_versions')->insert(['uuid' => $version, 'entry_uuid' => $uuid, 'locale' => $locale,
            'version' => 1, 'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'schema_version' => 1,
            'created_at' => $at]);
        $db->table('entry_publications')->insert(['entry_uuid' => $uuid, 'locale' => $locale,
            'version_uuid' => $version, 'published_at' => $at]);
        if ($slug !== null) {
            (new RouteRepository($db))->assign($uuid, $this->type($type), $locale, $slug);
            $this->container()->get(PublishedReferenceRepository::class)
                ->projectFromPublished($uuid, $this->type($type), $locale);
        }
    }

    /**
     * @param list<array<string,mixed>> $body
     * @param array<string,mixed>|null $presentation
     */
    private function publish(
        string $type,
        string $slug,
        string $title,
        array $body = [],
        ?array $presentation = null,
    ): string {
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $typeUuid = $this->type($type);
        $entry = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $fields = ['title' => $title, 'body' => $body];
        if ($presentation !== null) {
            $fields['_presentation'] = $presentation;
        }
        $entries->saveDraft($entry, 'en', $fields, 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $typeUuid, 'en', $slug);
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

    /**
     * Save a layout as the editor's save does, less the session: under its lock, then forgotten.
     *
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $settings
     */
    private function saveLayout(string $type, array $blocks, array $settings = []): void
    {
        $repo = $this->container()->get(LayoutRepository::class);
        $this->container()->get(LayoutWriteLock::class)->within(
            'entry',
            $type,
            fn (): int => $repo->saveExpected('entry', $type, $blocks, $settings, $repo->version('entry', $type), null),
        );
        $this->container()->get(LayoutResolver::class)->forget('entry', $type);
    }

    private function removeLayout(string $type): void
    {
        $repo = $this->container()->get(LayoutRepository::class);
        $this->container()->get(LayoutWriteLock::class)->within(
            'entry',
            $type,
            fn (): int => $repo->tombstone('entry', $type, $repo->version('entry', $type), null),
        );
        $this->container()->get(LayoutResolver::class)->forget('entry', $type);
    }

    /** @return list<array<string,mixed>> the post design, in short */
    private static function postLayout(array $extra = []): array
    {
        return [
            ['id' => 'laytitle0001', 'type' => 'entry_title', 'data' => ['level' => 'h1'], 'settings' => []],
            ['id' => 'layhead00001', 'type' => 'heading', 'data' => ['text' => 'LAYOUT-MARKER'], 'settings' => []],
            ['id' => 'laybody00001', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
            ...$extra,
        ];
    }

    /** @return array<string,mixed> */
    private static function text(string $id, string $words): array
    {
        return ['id' => $id, 'type' => 'rich_text', 'data' => ['body' => "<p>{$words}</p>"], 'settings' => []];
    }

    private function get(string $path): \Symfony\Component\HttpFoundation\Response
    {
        return $this->handle(Request::create($path, 'GET'));
    }

    public function testAnEntryRendersThroughItsTypesLayoutAndOthersAsBefore(): void
    {
        $this->publish('post', 'hello', 'Hello world', [self::text('bodytext0001', 'BODY-WORDS')]);
        $this->publish('lpage', 'about', 'About us', [self::text('bodytext0002', 'PAGE-WORDS')]);
        $pageBefore = (string) $this->get('/lpage/about')->getContent();
        $this->container()->get(CacheStore::class)->deletePattern('render:*');

        $this->saveLayout('post', self::postLayout());

        $post = (string) $this->get('/post/hello')->getContent();
        self::assertStringContainsString('LAYOUT-MARKER', $post);
        self::assertMatchesRegularExpression(
            '~<h1 class="thallo-block thallo-block-entry_title[^"]*"[^>]*>Hello world</h1>~',
            $post,
        );
        self::assertStringContainsString('BODY-WORDS', $post);
        self::assertStringContainsString('thallo-layout--entry', $post);
        // The body comes after the layout's heading: the layout places it.
        self::assertLessThan(strpos($post, 'BODY-WORDS'), strpos($post, 'LAYOUT-MARKER'));

        // A type without a layout renders as it did, byte for byte.
        self::assertSame($pageBefore, (string) $this->get('/lpage/about')->getContent());
    }

    public function testTheHomepageRouteNeverUsesALayout(): void
    {
        $home = $this->publish('lpage', 'home', 'Home page', [self::text('bodytext0003', 'HOME-WORDS')]);
        $this->publish('lpage', 'about', 'About us', [self::text('bodytext0004', 'ABOUT-WORDS')]);
        $this->saveLayout('lpage', self::postLayout());

        $app = self::bootAppWithConfigOverride('render', ['homepage_entry' => $home]);
        $controller = $app->getContainer()->get(\Thallo\Render\Http\Controllers\RenderController::class);
        $response = $controller->home(Request::create('/', 'GET'));
        $homeHtml = (string) $response->getContent();
        self::assertStringContainsString('HOME-WORDS', $homeHtml);
        self::assertStringNotContainsString('LAYOUT-MARKER', $homeHtml);
        self::assertStringNotContainsString('thallo:layout:entry:lpage', (string) $response->headers->get('Cache-Tag'));

        $about = (string) $this->get('/lpage/about')->getContent();
        self::assertStringContainsString('LAYOUT-MARKER', $about);
        self::assertStringContainsString('ABOUT-WORDS', $about);
    }

    public function testUseLayoutOffRendersTheThemeTemplate(): void
    {
        $own = [self::text('bodytext0005', 'OWN-WORDS')];
        $this->publish('post', 'own', 'Its own way', $own, ['use_layout' => false]);
        $this->saveLayout('post', self::postLayout());

        $html = (string) $this->get('/post/own')->getContent();
        self::assertStringContainsString('OWN-WORDS', $html);
        self::assertStringNotContainsString('LAYOUT-MARKER', $html);
        self::assertStringNotContainsString('thallo-layout--entry', $html);
    }

    public function testFramePrecedence(): void
    {
        $this->publish('post', 'plain', 'Plain', [self::text('bodytext0006', 'PLAIN-WORDS')]);
        $footed = [self::text('bodytext0007', 'FOOTED-WORDS')];
        $this->publish('post', 'footed', 'Footed', $footed, ['footer' => 'default']);
        $titled = [self::text('bodytext0008', 'TITLED-WORDS')];
        $this->publish('post', 'titled', 'Titled', $titled, ['show_title' => true]);
        $this->saveLayout('post', self::postLayout(), ['footer' => 'hidden', 'width' => 'full']);

        $plain = (string) $this->get('/post/plain')->getContent();
        self::assertStringNotContainsString('<footer', $plain);
        self::assertStringContainsString('layout--full', $plain);

        // The entry's own page setting wins over the layout's.
        self::assertStringContainsString('<footer', (string) $this->get('/post/footed')->getContent());

        // Show page title has no effect under a layout: the layout places the title, once.
        $titled = (string) $this->get('/post/titled')->getContent();
        self::assertSame(1, substr_count($titled, '>Titled</h1>'));
    }

    public function testEveryEligiblePageCarriesTheSurfaceTag(): void
    {
        $this->publish('post', 'tagged', 'Tagged', [self::text('bodytext0009', 'TAG-WORDS')]);
        $theme = $this->get('/post/tagged');
        self::assertStringContainsString('thallo:layout:entry:post', (string) $theme->headers->get('Cache-Tag'));

        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $this->saveLayout('post', self::postLayout());
        $layout = $this->get('/post/tagged');
        self::assertStringContainsString('LAYOUT-MARKER', (string) $layout->getContent());
        self::assertStringContainsString('thallo:layout:entry:post', (string) $layout->headers->get('Cache-Tag'));
    }

    public function testCachedThemePageThenFirstSaveThenRemoval(): void
    {
        $this->publish('post', 'cached', 'Cached', [self::text('bodytext0010', 'CACHED-WORDS')]);
        $purge = $this->container()->get(RenderedPageCachePurge::class);

        self::assertStringNotContainsString('LAYOUT-MARKER', (string) $this->get('/post/cached')->getContent());
        // Cached: a layout saved without a purge is not seen yet.
        $this->saveLayout('post', self::postLayout());
        self::assertStringNotContainsString('LAYOUT-MARKER', (string) $this->get('/post/cached')->getContent());

        $purge->purge(['thallo:layout:entry:post']);
        self::assertStringContainsString('LAYOUT-MARKER', (string) $this->get('/post/cached')->getContent());

        $this->removeLayout('post');
        $purge->purge(['thallo:layout:entry:post']);
        $html = (string) $this->get('/post/cached')->getContent();
        self::assertStringNotContainsString('LAYOUT-MARKER', $html);
        self::assertStringContainsString('CACHED-WORDS', $html);
    }

    public function testEveryFormKeepsItsOwnSource(): void
    {
        $form = static fn (string $id): array => ['id' => $id, 'type' => 'form', 'settings' => [], 'data' => [
            'recipient' => 'owner@site.test', 'form_name' => $id,
        ]];
        $a = $this->publish('post', 'a', 'Post A', [$form('bodyforma001')]);
        $b = $this->publish('post', 'b', 'Post B', [$form('bodyformb001')]);
        $this->saveLayout('post', self::postLayout([$form('layoutform01')]));
        $regions = $this->container()->get(\Thallo\Core\Content\Regions\RegionRepository::class);
        $regions->save('header', [$form('headerform01')], [], null);
        $regions->save('footer', [$form('footerform01')], [], null);

        $key = static fn (string $source, string $block): string => hash('sha256', "{$source}|{$block}");
        foreach (['a' => $a, 'b' => $b] as $slug => $uuid) {
            $html = (string) $this->get("/post/{$slug}")->getContent();
            preg_match_all('~data-form-key="([0-9a-f]{64})"~', $html, $m);
            $keys = $m[1];
            self::assertContains($key('layout:entry:post', 'layoutform01'), $keys, "layout form on {$slug}");
            self::assertContains($key('region:header', 'headerform01'), $keys, "header form on {$slug}");
            self::assertContains($key('region:footer', 'footerform01'), $keys, "footer form on {$slug}");
            self::assertContains($key("entry:{$uuid}", "bodyform{$slug}001"), $keys, "body form on {$slug}");
            self::assertCount(4, $keys, $slug);
            // Four forms with the same fields: each field's id is its own form's, and each label
            // points at its own field (review of 29cabb70 — the ids were the field key alone).
            preg_match_all('~ id="(ff-[^"]+)"~', $html, $ids);
            preg_match_all('~ for="(ff-[^"]+)"~', $html, $fors);
            self::assertNotEmpty($ids[1]);
            self::assertCount(count($ids[1]), array_unique($ids[1]), "every field id once on {$slug}");
            self::assertSame([], array_diff($fors[1], $ids[1]), "every label points at a field on {$slug}");
        }
    }

    public function testFormsIssuedBeforeTheFixStillSubmit(): void
    {
        // A footer form on a page cached before this release: sealed with the page's identity.
        $sealer = $this->container()->get(\Thallo\Contracts\Content\FormSealer::class);
        $old = $sealer->describe(
            ['id' => 'footerform01', 'type' => 'form', 'data' => ['recipient' => 'owner@site.test']],
            ['uuid' => 'oldentry0001'],
            '/post/a',
            null,
        );
        self::assertNotNull($old);
        $oldKey = hash('sha256', 'entry:oldentry0001|footerform01');
        self::assertSame($oldKey, $old->descriptor->formKey);

        $request = Request::create('/_forms/submit', 'POST', [
            '_form' => $old->token, '_t' => (string) (time() - 5),
            'name' => 'Ada', 'email' => 'ada@old.test', 'message' => 'still here',
        ]);
        $request->server->set('REMOTE_ADDR', '10.0.9.' . random_int(1, 250));
        $request->headers->set('Accept', 'application/json');
        $response = $this->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // Stored under the key it was issued with: its submissions keep their grouping.
        $stored = array_values(array_filter(
            $this->container()->get(\Thallo\Core\Content\Forms\FormSubmissionRepository::class)->list(),
            static fn ($s): bool => ($s->values['email'] ?? null) === 'ada@old.test',
        ));
        self::assertCount(1, $stored);
        self::assertSame($oldKey, $stored[0]->formKey);
    }

    public function testAnEntryInAnotherLocaleShowsItsLocalizedFields(): void
    {
        // Locale-variant paths consult the i18n registry; the harness ships none.
        $this->connection()->getPDO()->exec("DELETE FROM i18n_locales WHERE code IN ('en', 'fr')");
        foreach ([['en', true], ['fr', false]] as [$code, $isDefault]) {
            $this->connection()->table('i18n_locales')->insert([
                'uuid' => \Glueful\Helpers\Utils::generateNanoID(), 'code' => $code, 'name' => strtoupper($code),
                'enabled' => true, 'is_default' => $isDefault, 'fallback_locale' => $isDefault ? null : 'en',
                'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
        $this->type('post');
        $at = '2026-06-01 00:00:00';
        $this->publishRow('catnews00001', 'category', 'en', ['title' => 'News', 'slug' => 'news'], $at, 'news');
        $actualites = ['title' => 'Actualités', 'slug' => 'actualites'];
        $this->publishRow('catnews00001', 'category', 'fr', $actualites, $at, 'actualites');
        $this->publishRow('postfr000001', 'post', 'en', ['title' => 'Hello', 'categories' => ['catnews00001'],
            'body' => [self::text('bodytexten01', 'EN-WORDS')]], '2026-06-02 09:00:00', 'hello');
        $this->publishRow('postfr000001', 'post', 'fr', ['title' => 'Bonjour', 'categories' => ['catnews00001'],
            'body' => [self::text('bodytextfr01', 'FR-WORDS')]], '2026-06-05 09:00:00', 'bonjour');
        $this->saveLayout('post', self::postLayout([
            ['id' => 'laydate00001', 'type' => 'entry_date', 'data' => ['format' => 'long'], 'settings' => []],
            ['id' => 'layterms0001', 'type' => 'entry_terms', 'data' => ['field' => 'categories'], 'settings' => []],
        ]));

        $fr = $this->get('/fr/post/bonjour');
        self::assertSame(200, $fr->getStatusCode());
        $html = (string) $fr->getContent();
        self::assertMatchesRegularExpression(
            '~<h1 class="thallo-block thallo-block-entry_title[^"]*"[^>]*>Bonjour</h1>~',
            $html,
        );
        self::assertStringContainsString('FR-WORDS', $html);
        self::assertStringNotContainsString('EN-WORDS', $html);
        // The French version's own publish date and its own terms, under the French paths.
        self::assertStringContainsString('datetime="2026-06-05"', $html);
        self::assertStringContainsString('href="/fr/post/categories/actualites">Actualités</a>', $html);
    }

    /**
     * A post whose body holds a block type since removed (Review Focus 5): the layout renders around
     * the missing-template fallback on the site and on the post's own Design view, and the rest of
     * the body still shows.
     */
    public function testAMissingBlockTypeInTheBodyLeavesTheLayoutOnTheSiteAndTheDesignView(): void
    {
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $typeUuid = $this->type('post');
        $uuid = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $fields = ['title' => 'Broken', 'body' => [
            ['id' => 'goneblock001', 'type' => 'gone_block', 'data' => [], 'settings' => []],
            self::text('bodytext0011', 'STILL-HERE'),
        ]];
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        // Published as rows: a save would refuse the removed type, which is the point.
        $db = $this->connection();
        $at = '2026-06-02 09:00:00';
        $db->table('entry_versions')->insert(['uuid' => 'vbroken00001', 'entry_uuid' => $uuid, 'locale' => 'en',
            'version' => 1, 'fields' => json_encode($fields), 'schema_version' => 1, 'created_at' => $at]);
        $db->table('entry_publications')->insert(['entry_uuid' => $uuid, 'locale' => 'en',
            'version_uuid' => 'vbroken00001', 'published_at' => $at]);
        (new RouteRepository($db))->assign($uuid, $typeUuid, 'en', 'broken');
        $this->saveLayout('post', self::postLayout());
        $fallback = '~<!-- thallo: no template for block "gone_block" -->'
            . '|Missing block template: blocks/gone_block\.twig~';

        $site = $this->get('/post/broken');
        self::assertSame(200, $site->getStatusCode());
        $html = (string) $site->getContent();
        self::assertStringContainsString('LAYOUT-MARKER', $html);
        self::assertStringContainsString('STILL-HERE', $html);
        self::assertMatchesRegularExpression($fallback, $html);

        $token = $this->container()->get(\Thallo\Core\Content\Preview\PreviewMinter::class)->mint($uuid, 'en');
        $stage = $this->container()->get(\Thallo\Render\Http\Controllers\RenderController::class)
            ->preview(Request::create("/_preview/{$token}?canvas=1", 'GET'), $token);
        self::assertSame(200, $stage->getStatusCode());
        $canvas = (string) $stage->getContent();
        self::assertStringContainsString('LAYOUT-MARKER', $canvas);
        self::assertMatchesRegularExpression($fallback, $canvas);
        // The body stays editable around the gap; the layout's own blocks are inert here.
        self::assertStringContainsString('data-thallo-block="bodytext0011"', $canvas);
        self::assertStringNotContainsString('data-thallo-block="layhead00001"', $canvas);
    }

    /**
     * Review Focus 3 through a real request: a theme with its own `layout.twig` and no `layouts/`
     * folder, previewed as the site's theme, serves the default frame inside its own shell.
     */
    public function testAThemeWithoutAFrameServesTheDefaultFrameOnARequest(): void
    {
        $base = $this->appContext()->getBasePath() . '/themes/noframe';
        ThemeFixture::write($base, 'noframe');
        file_put_contents(
            $base . '/templates/layout.twig',
            '<main class="NOFRAME-SHELL">{% block content %}{% endblock %}</main>',
        );
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $uuid = $entries->createEntry($this->type('post'), 'en', 1, 'user00000001');
        $framed = ['title' => 'Framed', 'body' => [self::text('bodytext0012', 'FRAMED-WORDS')]];
        $entries->saveDraft($uuid, 'en', $framed, 1, 0, 'user00000001');
        $this->saveLayout('post', self::postLayout());

        $token = $this->container()->get(\Thallo\Core\Content\Preview\PreviewMinter::class)
            ->mint($uuid, 'en', null, 'noframe');
        $response = $this->handle(Request::create("/_preview/{$token}", 'GET'));
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('NOFRAME-SHELL', $html, 'the theme\'s own shell');
        self::assertStringContainsString('thallo-layout--entry', $html, 'the default frame, by per-file fallback');
        self::assertStringContainsString('LAYOUT-MARKER', $html);
        self::assertStringContainsString('FRAMED-WORDS', $html);
    }

    public function testAThemeWithoutAFrameFallsBackToTheDefaultFrame(): void
    {
        $base = $this->appContext()->getBasePath() . '/themes/noframe';
        ThemeFixture::write($base, 'noframe');
        file_put_contents(
            $base . '/templates/layout.twig',
            '<main class="NOFRAME-SHELL">{% block content %}{% endblock %}</main>',
        );
        $env = (new TwigFactory(
            new ThemeLocator('noframe', $this->appContext()->getBasePath() . '/themes'),
            $this->container()->get(RenderContextExtension::class),
            $this->appContext()->getBasePath() . '/storage/cache/twig',
        ))->environment();
        $this->container()->get(RenderContextExtension::class)->resetPerRenderState();
        $html = $env->render('layouts/entry.twig', [
            'entry' => ['uuid' => 'entryframe01', 'fields' => ['title' => 'Framed', 'body' => []]],
            'layout' => ['surface' => 'entry', 'target' => 'post', 'settings' => [],
                'blocks' => [['id' => 'laytitle0002', 'type' => 'entry_title', 'data' => [], 'settings' => []]]],
            'type' => 'post',
        ]);
        self::assertStringContainsString('NOFRAME-SHELL', $html);
        self::assertStringContainsString('thallo-layout--entry', $html);
        self::assertStringContainsString('>Framed</h1>', $html);
    }

    private function removeTheme(): void
    {
        $dir = $this->appContext()->getBasePath() . '/themes/noframe';
        if (!is_dir($dir)) {
            return;
        }
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $f
        ) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
