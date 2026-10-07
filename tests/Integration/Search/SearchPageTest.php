<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Contracts\Search\SearchSourceRegistry;
use Thallo\Search\Query\KindAvailability;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\SearchOnApp;

/**
 * The `/search` results page (search block spec §3.7): a themed page that works without
 * JavaScript, a distinct message for each outcome, scope and locale carried by every link and form,
 * never cached and never indexed, and safe for any query.
 */
final class SearchPageTest extends AppTestCase
{
    private SearchOnApp $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new SearchOnApp(self::bootAppWithConfigOverride(
            'thallo',
            ['capabilities' => ['thallo.search' => true]],
        ));
    }

    public function testNoQueryShowsTheForm(): void
    {
        $res = $this->site->page('/search?scope=entries&locale=en');
        $html = (string) $res->getContent();
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('name="q"', $html);
        self::assertStringContainsString('name="scope" value="entries"', $html);
        self::assertStringContainsString('name="locale" value="en"', $html);
        self::assertStringNotContainsString('data-search-results', $html);
        self::assertStringContainsString('<title>Search — ', $html);
    }

    public function testResults(): void
    {
        foreach (['a', 'b', 'c'] as $slug) {
            $this->site->publish('post', 'rose-' . $slug, 'Rose ' . $slug, 'A rose garden');
        }
        $this->site->reconcile();
        $html = (string) $this->site->page('/search?q=rose&locale=en')->getContent();
        self::assertStringContainsString('Results for “rose”', $html);
        self::assertStringContainsString('About 3 matches', $html);
        self::assertSame(3, substr_count($html, '<article'));
        self::assertStringContainsString('<mark>', $html);
        self::assertStringContainsString('<title>Search results for “rose” — ', $html);
    }

    public function testTabsAreTheKindsSearchableNowAsScopeLinks(): void
    {
        $this->site->publish('post', 'rose', 'Rose', 'A rose garden');
        $this->site->reconcile();
        $container = $this->site->app->getContainer();
        $availability = $container->get(KindAvailability::class);
        $kinds = array_values(array_filter(
            array_keys($container->get(SearchSourceRegistry::class)->all()),
            static fn (string $kind): bool => $availability->isAvailable($kind),
        ));
        $html = (string) $this->site->page('/search?q=rose&locale=en')->getContent();
        if (count($kinds) < 2) {
            // One kind to search: nothing to choose between, so no tabs.
            self::assertStringNotContainsString('thallo-search-page__tabs', $html);
            return;
        }
        // All first, current while every kind is searched; then each kind, a scope link keeping the query.
        self::assertSame(1, preg_match('~<nav class="thallo-search-page__tabs"[^>]*>(.*?)</nav>~s', $html, $nav));
        $tab = '~<a class="thallo-search-page__tab" href="([^"]+)"( aria-current="page")?>([^<]+)</a>~';
        preg_match_all($tab, $nav[1], $tabs);
        self::assertSame('All', $tabs[3][0]);
        self::assertSame(' aria-current="page"', $tabs[2][0]);
        self::assertCount(count($kinds) + 1, $tabs[1]);
        foreach ($kinds as $i => $kind) {
            self::assertStringContainsString('scope=' . $kind, html_entity_decode($tabs[1][$i + 1]));
            self::assertStringContainsString('q=rose', html_entity_decode($tabs[1][$i + 1]));
            self::assertSame('', $tabs[2][$i + 1]);
        }
        // On a kind's own scope, its tab is the current one.
        $scoped = (string) $this->site->page('/search?q=rose&scope=' . $kinds[0] . '&locale=en')->getContent();
        $current = '~<a class="thallo-search-page__tab" href="[^"]*scope=' . $kinds[0] . '[^"]*" aria-current="page">~';
        self::assertSame(1, preg_match($current, $scoped));
    }

    public function testNoMatchesAndSearchEverything(): void
    {
        $this->site->publish('post', 'rose', 'Rose');
        $this->site->reconcile();
        $html = (string) $this->site->page('/search?q=zzz&scope=entries&locale=en')->getContent();
        self::assertStringContainsString('No results for “zzz”', $html);
        self::assertStringContainsString('href="/search?q=zzz&amp;scope=&amp;locale=en"', $html);
        self::assertStringContainsString('Search everything', $html);
    }

    public function testScopeUnavailable(): void
    {
        $this->site->reconcile();
        $html = (string) $this->site->page('/search?q=rose&scope=reviews&locale=en')->getContent();
        self::assertStringContainsString("search isn't available right now", $html);
        self::assertStringContainsString('Search everything instead', $html);
    }

    public function testMoreResultsCarriesScopeLocaleAndAValidCursor(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->site->publish('post', 'rose-' . $i, 'Rose ' . $i);
        }
        $this->site->reconcile();
        $html = (string) $this->site->page('/search?q=rose&scope=entries&locale=en')->getContent();
        self::assertSame(1, preg_match('~href="(/search\?[^"]*cursor=[^"]+)"[^>]*>More results~', $html, $m));
        $more = html_entity_decode($m[1]);
        self::assertStringContainsString('scope=entries', $more);
        self::assertStringContainsString('locale=en', $more);
        $next = (string) $this->site->page($more)->getContent();
        self::assertSame(2, substr_count($next, '<article'), 'the second page has the rest');
    }

    public function testATamperedCursorShowsPageOne(): void
    {
        $this->site->publish('post', 'rose', 'Rose');
        $this->site->reconcile();
        $res = $this->site->page('/search?q=rose&locale=en&cursor=forged.token');
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(1, substr_count((string) $res->getContent(), '<article'));
    }

    public function testAnEngineThatCannotAnswerIs503WithTheForm(): void
    {
        // Meilisearch asked for, at a host nothing answers: no engine can answer. (A host set in
        // the local .env may well answer, so the test names one that cannot.)
        $env = ['SEARCH_ENGINE' => 'meilisearch', 'MEILISEARCH_HOST' => 'http://127.0.0.1:9'];
        $saved = [];
        foreach ($env as $key => $value) {
            $saved[$key] = getenv($key);
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
        try {
            $broken = new SearchOnApp(self::bootAppWithConfigOverride(
                'thallo',
                ['capabilities' => ['thallo.search' => true]],
            ));
        } finally {
            foreach ($saved as $key => $value) {
                putenv($value === false ? $key : "{$key}={$value}");
                if ($value === false) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value;
                }
            }
        }
        $res = $broken->page('/search?q=rose&locale=en');
        self::assertSame(503, $res->getStatusCode());
        self::assertSame('120', $res->headers->get('Retry-After'));
        self::assertStringContainsString('Search is temporarily unavailable.', (string) $res->getContent());
        self::assertStringContainsString('name="q"', (string) $res->getContent());
    }

    public function testRateLimited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->site->page('/search?locale=en', '10.0.0.9');
        }
        $res = $this->site->page('/search?locale=en', '10.0.0.9');
        self::assertSame(429, $res->getStatusCode());
        self::assertNotNull($res->headers->get('Retry-After'));
        self::assertStringContainsString('Too many searches', (string) $res->getContent());
        self::assertStringContainsString('name="q"', (string) $res->getContent());
        self::assertSame(200, $this->site->page('/search?locale=en', '10.0.0.10')->getStatusCode(), 'per client');
    }

    public function testOffShowsTheThemed404(): void
    {
        $res = (new SearchOnApp(self::bootAppWithConfigOverride('thallo', [])))->page('/search?q=rose');
        self::assertSame(404, $res->getStatusCode());
        self::assertStringContainsString('<html', (string) $res->getContent());
    }

    public function testHeaders(): void
    {
        $this->site->reconcile();
        foreach (['/search', '/search?q=rose&locale=en', '/search?q=x&scope=reviews'] as $uri) {
            $res = $this->site->page($uri);
            self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'), $uri);
            self::assertSame('noindex', $res->headers->get('X-Robots-Tag'), $uri);
            $html = (string) $res->getContent();
            self::assertStringContainsString('<meta name="robots" content="noindex">', $html, $uri);
        }
    }

    public function testAHostileQueryIsEscapedEverywhere(): void
    {
        $this->site->publish('post', 'rose', 'Rose');
        $this->site->reconcile();
        $hostile = urlencode('"><script>x</script>&amp;');
        $html = (string) $this->site->page('/search?q=' . $hostile . '&locale=en')->getContent();
        self::assertStringNotContainsString('<script>x', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;x&lt;/script&gt;&amp;amp;', $html);
    }

    public function testBeforeTheMigrationsRunSearchIsUnavailableNotAnError(): void
    {
        // Between `composer update` and `thallo:provision` the lifecycle tables do not exist yet.
        $pdo = $this->site->app->getContainer()->get(\Glueful\Database\Connection::class)->getPDO();
        $pdo->exec('ALTER TABLE search_index_state RENAME TO search_index_state_hidden');
        try {
            self::assertSame(503, $this->site->page('/search?q=rose&locale=en')->getStatusCode());
            $suggest = $this->site->get('/_search/suggest?q=rose&locale=en');
            self::assertSame(200, $suggest->getStatusCode(), (string) $suggest->getContent());
            self::assertSame('unavailable', SearchOnApp::data($suggest)['state']);
            self::assertSame(503, $this->site->get('/v1/search?q=rose&locale=en')->getStatusCode());
        } finally {
            $pdo->exec('ALTER TABLE search_index_state_hidden RENAME TO search_index_state');
        }
    }

    public function testMalformedInputsNeverFail(): void
    {
        $this->site->reconcile(); // built, so a status measures the input, not the index
        foreach (
            [
            'q[]=a',
            'scope[]=x&q=a',
            'locale[]=en&q=a',
            'cursor[]=x&q=a',
            'q=' . urlencode(str_repeat('é', 250)),
            ] as $query
        ) {
            self::assertLessThan(500, $this->site->page('/search?' . $query)->getStatusCode(), $query);
        }
    }
}
