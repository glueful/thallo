<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Preview\PreviewMinter;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Thallo\Render\RenderContextExtension;
use Thallo\Render\Templates\TemplatePolicy;
use Thallo\Seo\Meta\SeoMetaRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Task 5 (seo-head spec §3): the render pipeline threads the composed `seo` context
 * variable into entry renders and `seo_head()` emits the head tag block — full head on
 * live entry pages, nothing on non-entry pages, noindex-only in preview (spec §4), and
 * safe_url discipline on every URL attribute (omit, never emit raw).
 */
final class SeoHeadRenderTest extends AppTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        // Hygiene (the RenderPipelineTest idiom): cached rendered pages must not
        // leak this test's seeds — or its SEO meta — into later tests.
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    /** Render a page through the real kernel with a cold page cache. */
    private function renderPage(string $path): string
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $res = $this->handle(Request::create($path, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        return (string) $res->getContent();
    }

    private function metaRepo(): SeoMetaRepository
    {
        return $this->container()->get(SeoMetaRepository::class);
    }

    public function testEntryPageRendersTheFullHead(): void
    {
        // Origin fixture: the suite env sets PUBLIC_URL_BASE=https://site.test, so the
        // container projector emits absolute hrefs the provider passes through.
        $entry = $this->seedBilingualPublishedEntry();
        $this->metaRepo()->upsert($entry, 'en', ['description' => 'Curated description']);

        $html = $this->renderPage('/blog/hello');

        self::assertStringContainsString(
            '<meta name="description" content="Curated description">',
            $html,
        );
        self::assertStringContainsString(
            '<link rel="canonical" href="https://site.test/blog/hello">',
            $html,
        );
        self::assertStringContainsString(
            '<link rel="alternate" hreflang="en" href="https://site.test/blog/hello">',
            $html,
        );
        self::assertStringContainsString(
            '<link rel="alternate" hreflang="fr" href="https://site.test/fr/blog/bonjour">',
            $html,
        );
        self::assertStringContainsString('hreflang="x-default"', $html);
        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<meta property="og:title" content="Hello — Thallo">', $html);
        self::assertStringContainsString(
            '<meta property="og:description" content="Curated description">',
            $html,
        );
        self::assertStringContainsString(
            '<meta property="og:url" content="https://site.test/blog/hello">',
            $html,
        );
        self::assertStringContainsString('<meta property="og:site_name" content="Thallo">', $html);
        // Emission rules (spec §3): twitter:card only when explicitly overridden;
        // robots only when not plain 'index'.
        self::assertStringNotContainsString('name="twitter:card"', $html);
        self::assertStringNotContainsString('name="robots"', $html);
    }

    public function testTitleValuesAreEscaped(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->metaRepo()->upsert($entry, 'en', ['title' => 'A "quoted" <title> & Co']);

        $html = $this->renderPage('/blog/hello');

        self::assertStringContainsString(
            '<meta property="og:title" content="A &quot;quoted&quot; &lt;title&gt; &amp; Co">',
            $html,
        );
        self::assertStringNotContainsString('A "quoted" <title> & Co', $html);
    }

    public function testTitlePrecedenceOnThePage(): void
    {
        $entry = $this->seedBilingualPublishedEntry();

        // No override: the resolver title with the site title_template applied (spec §2).
        self::assertStringContainsString(
            '<title>Hello — Thallo</title>',
            $this->renderPage('/blog/hello'),
        );

        // Explicit override: verbatim — the editor chose it (spec §2).
        $this->metaRepo()->upsert($entry, 'en', ['title' => 'Chosen Title']);
        self::assertStringContainsString(
            '<title>Chosen Title</title>',
            $this->renderPage('/blog/hello'),
        );
    }

    public function testNonEntryPagesEmitNoSeoTags(): void
    {
        $this->seedBilingualPublishedEntry(); // blog is a listing type (phpunit env)

        $html = $this->renderPage('/blog');

        self::assertStringContainsString('<title>blog — Thallo</title>', $html);
        self::assertStringNotContainsString('rel="canonical"', $html);
        self::assertStringNotContainsString('property="og:', $html);
        self::assertStringNotContainsString('name="description"', $html);
    }

    public function testPreviewEmitsOnlyNoindex(): void
    {
        // The PreviewSessionTest boot idiom: a never-published draft, minted token,
        // rendered through the real kernel's /_preview/{token} route.
        $this->seedBilingualPublishedEntry();
        $types = new ContentTypeRepository($this->connection());
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $draft = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($draft, 'en', ['title' => 'Draft words'], 1, 0, 'user00000001');
        $token = $this->container()->get(PreviewMinter::class)->mint($draft, 'en');

        $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();

        // Spec §4: draft titles must never be canonicalized or socially scrapeable.
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        self::assertStringNotContainsString('rel="canonical"', $html);
        self::assertStringNotContainsString('property="og:', $html);
    }

    public function testUnsafeUrlsAreOmittedNeverEmitted(): void
    {
        $extension = $this->container()->get(RenderContextExtension::class);
        $extension->setBlockAnnotations(false); // live posture — the preview gate must not trip
        $extension->setPreviewContext(false); // surface split: the SEO noindex gate keys off THIS flag

        $out = $extension->seoHead([
            'site' => ['name' => 'Thallo'],
            'seo' => [
                'title' => 'Safe Title',
                'description' => null,
                'canonical' => 'javascript:alert(1)',
                'alternates' => [],
                'x_default' => null,
                'og' => [
                    'title' => 'Safe Title',
                    'description' => null,
                    'image' => 'data:text/html,x',
                    'url' => null,
                    'type' => 'article',
                ],
                'twitter_card' => null,
                'robots' => 'index',
            ],
        ]);

        // safe_url discipline (spec §3): a URL that fails the filter is OMITTED —
        // there is no path that emits the raw value.
        self::assertStringNotContainsString('rel="canonical"', $out);
        self::assertStringNotContainsString('og:image', $out);
        self::assertStringNotContainsString('javascript:', $out);
        self::assertStringNotContainsString('data:text/html', $out);
        self::assertStringContainsString('<meta property="og:title" content="Safe Title">', $out);
    }

    public function testEntryBackedHomepageRendersItsHeadAtRoot(): void
    {
        // home() renders index.twig directly (never through renderEntry), so it must
        // thread its own seo context (seo-head spec §1/§2: the homepage IS an entry
        // page). The homepage shape: og:type website, and never a canonical pointing
        // at the entry's own path.
        $entry = $this->seedBilingualPublishedEntry();
        $this->metaRepo()->upsert($entry, 'en', ['description' => 'Home description']);
        $this->container()->get(\App\Settings\SettingsStore::class)
            ->putMany(['homepage_entry' => $entry]);

        try {
            $html = $this->renderPage('/');

            self::assertStringContainsString('<meta property="og:type" content="website">', $html);
            self::assertStringContainsString('content="Home description"', $html);
            self::assertStringNotContainsString(
                'rel="canonical" href="https://site.test/blog/hello"',
                $html,
                'the homepage must never canonicalize to the entry path',
            );
        } finally {
            $this->container()->get(\App\Settings\SettingsStore::class)->forget('homepage_entry');
        }
    }

    public function testPolicyAllowsSeoHeadAndBumpedVersion(): void
    {
        self::assertContains('seo_head', TemplatePolicy::FUNCTIONS);
        self::assertGreaterThanOrEqual(13, TemplatePolicy::CACHE_VERSION);
    }
}
