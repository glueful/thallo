<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Preview\PreviewMinter;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\AppTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Events\EventService;
use Thallo\Render\Templates\TemplateRepository;
use Thallo\Render\Templates\TemplateUpdated;
use Symfony\Component\HttpFoundation\Request;

final class DbTemplatesPipelineTest extends AppTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    private function repo(): TemplateRepository
    {
        return new TemplateRepository($this->connection());
    }

    private function saveAndAnnounce(string $theme, string $path, string $source): void
    {
        $this->repo()->save($theme, $path, $source, null);
        $this->container()->get(EventService::class)->dispatch(new TemplateUpdated($theme, $path));
    }

    public function testSaveIsLiveOnTheVeryNextRequestWithoutRestart(): void
    {
        $this->seedBilingualPublishedEntry();
        $before = (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent();
        self::assertStringNotContainsString('DBLIVE', $before);

        $this->saveAndAnnounce('default', 'entry.twig', 'DBLIVE:{{ entry.fields.title }}');
        $after = (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent();
        self::assertStringContainsString('DBLIVE:', $after);
    }

    public function testActiveThemeSavePurgesCachedPagesAndInactiveThemeSaveDoesNot(): void
    {
        // Observable cached-vs-fresh sentinel: the CACHED body says DBLIVE1; a NEWER
        // pending override (saved WITHOUT its event) would render DBLIVE2 on any
        // re-render — so "still DBLIVE1" proves the cache was NOT purged, and
        // "DBLIVE2" proves it WAS. A body assertion alone can't distinguish
        // cached-served from re-rendered-identical.
        $this->seedBilingualPublishedEntry();
        $this->saveAndAnnounce('default', 'entry.twig', 'DBLIVE1:{{ entry.fields.title }}');
        self::assertStringContainsString(
            'DBLIVE1:',
            (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent(), // primes cache
        );

        // Newer override, NO event: visible only if something re-renders the page.
        $this->repo()->save('default', 'entry.twig', 'DBLIVE2:{{ entry.fields.title }}', null);
        self::assertStringContainsString(
            'DBLIVE1:',
            (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent(), // cache intact
        );

        // INACTIVE theme mutation: must NOT purge — the DBLIVE1 body is still served
        // even though a re-render would say DBLIVE2.
        $this->saveAndAnnounce('othertheme', 'entry.twig', 'OTHER:{{ entry.fields.title }}');
        self::assertStringContainsString(
            'DBLIVE1:',
            (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent(),
        );

        // ACTIVE theme mutation purges: the very next request re-renders → DBLIVE2.
        $this->saveAndAnnounce('default', 'entry.twig', 'DBLIVE2:{{ entry.fields.title }}');
        self::assertStringContainsString(
            'DBLIVE2:',
            (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent(),
        );
    }

    public function testFixed404BodyRefreshesAfterA404TemplateOverride(): void
    {
        $this->seedBilingualPublishedEntry();
        // Prime the SHARED fixed 404 body (RenderErrorCache).
        $this->handle(Request::create('/no-such-page', 'GET'));

        $this->saveAndAnnounce('default', '404.twig', 'CUSTOM404BODY');
        $res = $this->handle(Request::create('/no-such-page', 'GET'));
        self::assertSame(404, $res->getStatusCode());
        self::assertStringContainsString('CUSTOM404BODY', (string) $res->getContent());
    }

    public function testBrokenErrorTemplateOverrideDegradesToPlainText500(): void
    {
        $this->seedBilingualPublishedEntry();
        // error.twig override that FAILS the compile-time policy (inserted via SQL
        // around the lint) → LoaderError at render → error.twig retry ALSO fails →
        // the recursion guard's plain-text 500.
        $this->repo()->save('default', 'entry.twig', 'ok {{ entry.fields.title }}', null);
        $this->repo()->save('default', 'error.twig', 'placeholder', null);
        $map = $this->repo()->overrideMap('default');
        $this->connection()->table('lemma_render_template_versions')
            ->where('uuid', '=', $map['entry.twig'])
            ->update(['source' => "{{ constant('X') }}"]);
        $this->connection()->table('lemma_render_template_versions')
            ->where('uuid', '=', $map['error.twig'])
            ->update(['source' => "{{ constant('X') }}"]);
        $this->container()->get(EventService::class)
            ->dispatch(new TemplateUpdated('default', 'entry.twig'));

        $res = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertSame(500, $res->getStatusCode());
        self::assertSame('Internal Server Error', (string) $res->getContent());
    }

    public function testThemedPreviewSessionRendersThatThemesOverrides(): void
    {
        $this->seedBilingualPublishedEntry();
        $this->makeAltTheme();
        try {
            // Override FOR THE ALT THEME only.
            $this->repo()->save('altprev', 'entry.twig', 'ALTDB:{{ entry.fields.title }}', null);
            $entry = $this->seedDraftEntry('Session draft');
            $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', null, 'altprev');

            $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
            self::assertSame(200, $res->getStatusCode());
            self::assertStringContainsString('ALTDB:Session draft', (string) $res->getContent());

            // The boot environment stays unpoisoned AND default-theme pages don't see
            // the alt theme's override.
            $plain = (string) $this->handle(Request::create('/blog/hello', 'GET'))->getContent();
            self::assertStringNotContainsString('ALTDB:', $plain);
        } finally {
            $this->removeAltTheme();
        }
    }

    // --- fixtures (altprev theme + draft seeding: same shapes as PreviewSessionTest) ---

    private function makeAltTheme(): void
    {
        $base = $this->appContext()->getBasePath() . '/themes/altprev';
        mkdir($base . '/templates', 0777, true);
        file_put_contents($base . '/theme.json', (string) json_encode(['name' => 'altprev']));
        // No entry.twig on disk: the DB override + pack-default fallback do the work.
        file_put_contents($base . '/templates/layout.twig', "{% block content %}{% endblock %}");
    }

    private function removeAltTheme(): void
    {
        $alt = $this->appContext()->getBasePath() . '/themes/altprev';
        if (!is_dir($alt)) {
            return;
        }
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($alt, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $f
        ) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($alt);
    }

    private function seedDraftEntry(string $title): string
    {
        $types = new ContentTypeRepository($this->connection());
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $uuid = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, 'user00000001');
        return $uuid;
    }
}
