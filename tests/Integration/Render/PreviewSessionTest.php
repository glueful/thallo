<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Http\Controllers\PreviewController;
use App\Content\Http\DTOs\MintPreviewData;
use App\Content\Localization\ContentLocaleService;
use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewReader;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\FakeLocaleManager;
use App\Tests\Support\LemmaTestCase;
use Glueful\Cache\CacheStore;
use Glueful\Lemma\Contracts\Delivery\PreviewSessionVerifier;
use Symfony\Component\HttpFoundation\Request;

/**
 * Preview sessions (preview-sessions spec §1–§7): the token-as-cookie session, the
 * verifier VO, the single-draft overlay, session chrome, the cache-bust guard, and
 * per-preview themes with token-scoped assets.
 */
final class PreviewSessionTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    protected function tearDown(): void
    {
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        $alt = $this->appContext()->getBasePath() . '/themes/altprev';
        if (is_dir($alt)) {
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
        parent::tearDown();
    }

    private function verifier(): PreviewSessionVerifier
    {
        return $this->container()->get(PreviewSessionVerifier::class);
    }

    /** Seed a blog entry with a DRAFT (never published); returns its uuid. */
    private function seedDraftEntry(string $title = 'Draft words'): string
    {
        $types = new ContentTypeRepository($this->connection());
        if ($types->findBySlug('blog') === null) {
            $this->seedBilingualPublishedEntry();
        }
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $uuid = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, 'user00000001');
        return $uuid;
    }

    // ---- Task 1: verifier + token theme claim + mint validation ----------------------

    public function testVerifierReturnsSessionVoAndFailsClosed(): void
    {
        $entry = $this->seedDraftEntry();
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        $s = $this->verifier()->verify($token);
        self::assertNotNull($s);
        self::assertSame($token, $s->token);   // the VO carries the ORIGINAL token
        self::assertSame($entry, $s->entry);
        self::assertSame('en', $s->locale);
        self::assertNull($s->theme);           // old-format/no-theme claim
        self::assertGreaterThan(time(), $s->expiresAt);

        self::assertNull($this->verifier()->verify('garbage'));
        self::assertNull($this->verifier()->verify($token . 'x')); // broken signature
    }

    public function testThemeClaimRoundTripsAndReadVerifiedSkipsReverification(): void
    {
        $entry = $this->seedDraftEntry('Themed draft');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', null, 'altprev');

        $s = $this->verifier()->verify($token);
        self::assertNotNull($s);
        self::assertSame('altprev', $s->theme);

        $read = $this->container()->get(PreviewReader::class)->readVerified($s);
        self::assertSame($entry, $read['entry_uuid']);
        self::assertSame('Themed draft', $read['fields']['title']);
    }

    public function testMintValidatesThemeThroughTheContract(): void
    {
        $entry = $this->seedDraftEntry();
        $this->makeAltTheme();

        // Bound validator (render pack) + real theme → 200 with theme signed in.
        $ok = $this->mintDirect($entry, 'altprev', boundValidator: true);
        self::assertSame(200, $ok->getStatusCode());
        $token = (string) json_decode((string) $ok->getContent(), true)['data']['token'];
        self::assertSame('altprev', $this->verifier()->verify($token)?->theme);

        // Unknown theme → 422.
        self::assertSame(422, $this->mintDirect($entry, 'nope', boundValidator: true)->getStatusCode());
        // Theme supplied but NO validator bound (render pack absent) → 422 (removability).
        self::assertSame(422, $this->mintDirect($entry, 'altprev', boundValidator: false)->getStatusCode());
        // No theme at all → 200 regardless of the validator.
        self::assertSame(200, $this->mintDirect($entry, null, boundValidator: false)->getStatusCode());
    }

    /** Direct construction so the validator can be present or absent per case. */
    private function mintDirect(string $entry, ?string $theme, bool $boundValidator): \Glueful\Http\Response
    {
        $validator = $boundValidator
            ? $this->container()->get(\Glueful\Lemma\Contracts\Delivery\PreviewThemeValidator::class)
            : null;
        $controller = new PreviewController(
            $this->container()->get(PreviewMinter::class),
            $this->container()->get(PreviewReader::class),
            new ContentLocaleService($this->appContext(), new FakeLocaleManager()),
            $this->appContext(),
            $validator,
        );
        return $controller->mint(
            new MintPreviewData(theme: $theme),
            Request::create('/'),
            $entry,
            'en',
        );
    }

    // ---- Task 2: session cookie + cache guard -----------------------------------------

    private function sessionRequest(string $uri, string $token): Request
    {
        return Request::create($uri, 'GET', [], ['lemma_preview' => $token]);
    }

    public function testPreviewSetsSessionCookieWithRemainingTtl(): void
    {
        $entry = $this->seedDraftEntry();
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        $cookie = null;
        foreach ($res->headers->getCookies() as $c) {
            if ($c->getName() === 'lemma_preview') {
                $cookie = $c;
            }
        }
        self::assertNotNull($cookie);
        self::assertSame($token, $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        self::assertGreaterThan(time(), $cookie->getExpiresTime());
        self::assertFalse($cookie->isSecure()); // plain-HTTP test request

        // A FAILED preview must not start a session.
        $bad = $this->handle(Request::create('/_preview/garbage', 'GET'));
        self::assertSame([], array_filter(
            $bad->headers->getCookies(),
            static fn($c) => $c->getName() === 'lemma_preview',
        ));
    }

    public function testValidCookieBypassesCacheAndJunkCookieDoesNot(): void
    {
        $this->seedBilingualPublishedEntry();
        $entry = $this->seedDraftEntry();
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        // Prime the cache, then plant a sentinel.
        $this->handle(Request::create('/blog/hello', 'GET'));
        $cache = $this->container()->get(CacheStore::class);
        $key = 'render:default:%2Fblog%2Fhello';
        $cached = $cache->get($key);
        self::assertIsArray($cached);
        $cached['body'] = 'SENTINEL-CACHED';
        $cache->set($key, $cached, 3600);

        // JUNK cookie: normal cached behavior — the sentinel IS served (cache-bust guard).
        $junk = $this->handle($this->sessionRequest('/blog/hello', 'not-a-token'));
        self::assertSame('SENTINEL-CACHED', (string) $junk->getContent());

        // VALID cookie: neither served from cache NOR stored — sentinel untouched.
        $live = $this->handle($this->sessionRequest('/blog/hello', $token));
        self::assertStringNotContainsString('SENTINEL-CACHED', (string) $live->getContent());
        self::assertSame('SENTINEL-CACHED', $cache->get($key)['body']); // no overwrite
    }

    public function testExitClearsTheSessionCookie(): void
    {
        $res = $this->handle(Request::create('/_preview/exit', 'GET'));
        self::assertSame(302, $res->getStatusCode());
        self::assertSame('/', $res->headers->get('Location'));
        $cleared = null;
        foreach ($res->headers->getCookies() as $c) {
            if ($c->getName() === 'lemma_preview') {
                $cleared = $c;
            }
        }
        self::assertNotNull($cleared);
        self::assertLessThan(time(), $cleared->getExpiresTime()); // expired = cleared
    }

    public function testSessionSurvivesCacheDisabled(): void
    {
        // Session state is NOT cache state (spec §4): with cache_enabled=false the
        // middleware still detects the session. Middleware-direct (route latch).
        $entry = $this->seedDraftEntry();
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        $middleware = new \Glueful\Lemma\Render\Http\Middleware\PreviewSessionMiddleware($this->verifier());
        $request = $this->sessionRequest('/blog/hello', $token);
        $middleware->handle(
            $request,
            static fn ($r) => new \Symfony\Component\HttpFoundation\Response('ok'),
        );
        $session = $request->attributes->get('lemma_preview_session');
        self::assertNotNull($session);
        self::assertSame($entry, $session->entry);

        // And a DISABLED RenderPageCache passes session requests through untouched.
        $cacheOff = new \Glueful\Lemma\Render\Http\Middleware\RenderPageCache(
            $this->container()->get(CacheStore::class),
            'default',
            false,
            3600,
        );
        $out = $cacheOff->handle(
            $request,
            static fn ($r) => new \Symfony\Component\HttpFoundation\Response('rendered'),
        );
        self::assertSame('rendered', (string) $out->getContent());
    }

    // ---- Task 3: overlay + chrome ------------------------------------------------------

    /** A draft WITH a published base + route, so it has a canonical URL to overlay. */
    private function seedRoutedEntryWithDraft(): array
    {
        $entry = $this->seedBilingualPublishedEntry(); // published "Hello" at /blog/hello
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entries->saveDraft($entry, 'en', ['title' => 'Draft override'], 1, 1, 'user00000001');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        return [$entry, $token];
    }

    public function testSessionOverlaysTheDraftAtItsCanonicalUrl(): void
    {
        [, $token] = $this->seedRoutedEntryWithDraft();

        // Without the session: published content, cached.
        $published = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertStringContainsString('<h1>Hello</h1>', (string) $published->getContent());

        // With the session: the DRAFT at the same URL, full chrome.
        $res = $this->handle($this->sessionRequest('/blog/hello', $token));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('Draft override', $html);
        self::assertStringContainsString('preview-banner', $html);
        self::assertStringContainsString('/_preview/exit', $html); // Exit link
        self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        self::assertSame('noindex', $res->headers->get('X-Robots-Tag'));
        self::assertNull($res->headers->get('Cache-Tag'));
    }

    public function testSessionShowsPublishedContentInChromeElsewhere(): void
    {
        [, $token] = $this->seedRoutedEntryWithDraft();

        $listing = $this->handle($this->sessionRequest('/blog', $token));
        self::assertSame(200, $listing->getStatusCode());
        $html = (string) $listing->getContent();
        self::assertStringContainsString('Hello', $html);          // PUBLISHED title, not the draft
        self::assertStringNotContainsString('Draft override', $html);
        self::assertStringContainsString('preview-banner', $html); // …but in chrome
        self::assertStringContainsString('no-store', (string) $listing->headers->get('Cache-Control'));
        // And nothing entered the page cache.
        self::assertNull($this->container()->get(CacheStore::class)->get('render:default:%2Fblog'));
    }

    public function testInSessionNotFoundRendersFreshWithChrome(): void
    {
        [, $token] = $this->seedRoutedEntryWithDraft();

        $res = $this->handle($this->sessionRequest('/no/such-page', $token));
        self::assertSame(404, $res->getStatusCode());
        self::assertStringContainsString('preview-banner', (string) $res->getContent());
        self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        // The SHARED fixed 404 body was neither read nor filled by the session.
        self::assertNull($this->container()->get(CacheStore::class)->get('render:default:404'));
    }

    // ---- Task 4: per-preview theme + assets -------------------------------------------

    public function testThemedSessionRendersAltThemeWithoutPoisoningTheBootTheme(): void
    {
        $this->makeAltTheme();
        $this->seedBilingualPublishedEntry();
        $entry2 = $this->seedDraftEntry('Alt themed');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry2, 'en', null, 'altprev');

        // Themed preview renders the ALT template…
        $res = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('ALTPREV:Alt themed', (string) $res->getContent());
        // …with token-scoped asset URLs.
        self::assertStringContainsString('/_preview-assets/' . $token . '/', (string) $res->getContent());

        // The memoized boot environment is NOT poisoned: a plain request right after
        // renders the boot theme with normal asset URLs.
        $plain = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertStringNotContainsString('ALTPREV:', (string) $plain->getContent());
        self::assertStringContainsString('/theme-assets/', (string) $plain->getContent());
        self::assertStringNotContainsString('/_preview-assets/', (string) $plain->getContent());
    }

    public function testPreviewAssetRouteServesOnlyTheSignedThemeSafely(): void
    {
        $this->makeAltTheme();
        $entry = $this->seedDraftEntry();
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', null, 'altprev');

        $ok = $this->handle(Request::create('/_preview-assets/' . $token . '/alt.css', 'GET'));
        self::assertSame(200, $ok->getStatusCode());
        self::assertStringContainsString('no-store', (string) $ok->headers->get('Cache-Control'));

        // Traversal, junk tokens, and theme-less tokens all 404.
        self::assertSame(
            404,
            $this->handle(
                Request::create('/_preview-assets/' . $token . '/%2e%2e/theme.json', 'GET'),
            )->getStatusCode(),
        );
        self::assertSame(
            404,
            $this->handle(Request::create('/_preview-assets/garbage/alt.css', 'GET'))->getStatusCode(),
        );
        $plainToken = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        self::assertSame(
            404,
            $this->handle(Request::create('/_preview-assets/' . $plainToken . '/alt.css', 'GET'))->getStatusCode(),
        );
    }

    public function testAssetBaseResetsAfterAThemedRenderExceptionAndVanishedThemeFallsBack(): void
    {
        $this->makeAltTheme();
        $this->seedBilingualPublishedEntry();
        $entry = $this->seedDraftEntry('Vanish');
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', null, 'altprev');

        // Break the alt theme's template so the themed render throws internally
        // (error.twig fallback renders) — then a NORMAL render must emit normal asset
        // URLs (the reset-before-render guard).
        $base = $this->appContext()->getBasePath() . '/themes/altprev/templates';
        file_put_contents($base . '/entry.twig', '{{ undefined_fn() }}');
        $this->handle(Request::create('/_preview/' . $token, 'GET')); // 500-ish, ignored
        $plain = $this->handle(Request::create('/blog/hello', 'GET'));
        self::assertStringNotContainsString('/_preview-assets/', (string) $plain->getContent());

        // Vanished theme: remove the templates entirely → session falls back to the
        // BOOT theme family (ThemeLocator's ladder or the try/catch — either way no ALT).
        unlink($base . '/entry.twig');
        rmdir($base);
        $fallback = $this->handle(Request::create('/_preview/' . $token, 'GET'));
        self::assertSame(200, $fallback->getStatusCode());
        self::assertStringContainsString('Vanish', (string) $fallback->getContent()); // boot entry.twig
        self::assertStringNotContainsString('ALTPREV:', (string) $fallback->getContent());
    }

    /** A real alt theme in the app themes dir (removed in tearDown). */
    private function makeAltTheme(): void
    {
        $base = $this->appContext()->getBasePath() . '/themes/altprev';
        @mkdir($base . '/templates', 0755, true);
        @mkdir($base . '/assets', 0755, true);
        file_put_contents($base . '/theme.json', json_encode(['name' => 'altprev']));
        file_put_contents(
            $base . '/templates/entry.twig',
            "{% extends 'layout.twig' %}{% block content %}ALTPREV:{{ entry.fields.title }}{% endblock %}",
        );
        file_put_contents($base . '/assets/alt.css', '/* alt theme css */');
    }
}
