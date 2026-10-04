<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\SearchOnApp;

/**
 * `GET /_search/suggest` (search block spec §3.4): public results whoever asks, never cached, a
 * distinct state for each outcome, and no server error for any input.
 */
final class SuggestEndpointTest extends AppTestCase
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

    public function testPublicOnlyEvenForAFullyPrivilegedCaller(): void
    {
        $this->site->publish('post', 'rose-post', 'Rose post');
        $this->site->publish('memo', 'rose-memo', 'Rose memo', '', false);
        $this->site->reconcile();

        $data = SearchOnApp::data($this->site->get('/_search/suggest?q=rose', ['api_key_scopes' => []]));
        self::assertSame(['Rose post'], array_column($data['items'], 'title'));
    }

    public function testNoStoreAndShape(): void
    {
        $this->site->publish('post', 'rose', 'Rose garden');
        $this->site->reconcile();
        $res = $this->site->get('/_search/suggest?q=ros');
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $data = SearchOnApp::data($res);
        self::assertSame('results', $data['state']);
        self::assertSame(
            ['kind', 'kind_label', 'title', 'href', 'image', 'price', 'snippet'],
            array_keys($data['items'][0]),
        );
        self::assertSame('/search?q=ros&scope=&locale=en', $data['see_all']);
    }

    public function testDistinctStates(): void
    {
        $this->site->publish('post', 'rose', 'Rose garden');
        $this->site->reconcile();
        self::assertSame('no_matches', SearchOnApp::data($this->site->get('/_search/suggest?q=zzzz'))['state']);
        self::assertSame('no_query', SearchOnApp::data($this->site->get('/_search/suggest?q='))['state']);
        self::assertSame(
            'scope_unavailable',
            SearchOnApp::data($this->site->get('/_search/suggest?q=rose&scope=reviews'))['state'],
        );
    }

    public function testMalformedInputsNeverFail(): void
    {
        $this->site->reconcile();
        foreach (['q[]=a', 'scope[]=x&q=a', 'locale[]=en&q=a', 'q=' . urlencode(str_repeat('é', 250))] as $query) {
            self::assertSame(200, $this->site->get('/_search/suggest?' . $query)->getStatusCode(), $query);
        }
    }
}
