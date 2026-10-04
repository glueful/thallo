<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\SearchOnApp;

/**
 * `GET /v1/search` over the shared query path (search block spec §3.4): its entries-only default,
 * `kind`, the `type` filter with its 404/403, the 422s, fixed offset windows that never duplicate,
 * cursors bound to the whole query (type included), and `total_approximate` on every answer.
 */
final class SearchEndpointTest extends AppTestCase
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

    public function testRouteAbsentByDefaultBecauseCapabilityIsOff(): void
    {
        $res = $this->handle(Request::create('/v1/search?q=a&locale=en', 'GET'));
        self::assertSame(404, $res->getStatusCode());
    }

    public function testDefaultIsEntriesOnlyAndTheEnvelope(): void
    {
        $entry = $this->site->publish('post', 'rose', 'Rose garden', 'Roses in the morning');
        $this->site->reconcile();

        $data = SearchOnApp::data($res = $this->site->get('/v1/search?q=rose&locale=en'));
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame(1, $data['total']);
        self::assertTrue($data['total_approximate']);
        self::assertArrayHasKey('next', $data);
        $hit = $data['hits'][0];
        self::assertSame(
            ['entries', $entry, 'post', 'en', 'Rose garden'],
            [$hit['kind'], $hit['uuid'], $hit['type'], $hit['locale'], $hit['title']],
        );
        self::assertStringContainsString('<mark>', $hit['snippet']);
    }

    public function testTypeNarrowsToThatTypeOnly(): void
    {
        $this->site->publish('post', 'rose-post', 'Rose post');
        $this->site->publish('page', 'rose-page', 'Rose page');
        $this->site->reconcile();

        $data = SearchOnApp::data($this->site->get('/v1/search?q=rose&locale=en&type=post'));
        self::assertSame(['post'], array_values(array_unique(array_column($data['hits'], 'type'))));
        self::assertCount(1, $data['hits']);
        self::assertCount(2, SearchOnApp::data($this->site->get('/v1/search?q=rose&locale=en&kind=all'))['hits']);
    }

    public function testUnknownAndInaccessibleTypesKeepTheirResponses(): void
    {
        $this->site->publish('memo', 'rose-memo', 'Rose memo', '', false);
        $this->site->reconcile();
        self::assertSame(404, $this->site->get('/v1/search?q=rose&locale=en&type=nope')->getStatusCode());
        self::assertSame(403, $this->site->get('/v1/search?q=rose&locale=en&type=memo')->getStatusCode());
        $keyed = $this->site->get('/v1/search?q=rose&locale=en&type=memo', ['api_key_scopes' => ['read:content:memo']]);
        self::assertSame(200, $keyed->getStatusCode());
        self::assertCount(1, SearchOnApp::data($keyed)['hits']);
    }

    public function testOffsetWindowsAreFixedAndNeverDuplicate(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $slug) {
            $this->site->publish('post', 'rose-' . $slug, 'Rose ' . $slug);
        }
        $this->site->reconcile();
        $first = array_column(
            SearchOnApp::data($this->site->get('/v1/search?q=rose&locale=en&limit=2&offset=0'))['hits'],
            'uuid',
        );
        $second = array_column(
            SearchOnApp::data($this->site->get('/v1/search?q=rose&locale=en&limit=2&offset=2'))['hits'],
            'uuid',
        );
        self::assertCount(2, $first);
        self::assertCount(2, $second);
        self::assertSame([], array_intersect($first, $second));
    }

    public function testCursorBindingIncludesType(): void
    {
        foreach (['a', 'b', 'c'] as $slug) {
            $this->site->publish('post', 'rose-' . $slug, 'Rose ' . $slug);
        }
        $this->site->publish('page', 'rose-page', 'Rose page');
        $this->site->reconcile();
        $next = SearchOnApp::data($this->site->get('/v1/search?q=rose&locale=en&type=post&limit=1'))['next'];
        self::assertIsString($next);

        $ok = $this->site->get('/v1/search?q=rose&locale=en&type=post&limit=1&cursor=' . urlencode($next));
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getContent());
        self::assertSame(
            400,
            $this->site->get('/v1/search?q=rose&locale=en&type=page&limit=1&cursor=' . urlencode($next))
                ->getStatusCode(),
        );
        self::assertSame(
            400,
            $this->site->get('/v1/search?q=rose&locale=en&limit=1&cursor=' . urlencode($next))->getStatusCode(),
        );
    }

    public function testErrorsAreClientErrorsNeverServerErrors(): void
    {
        $this->site->publish('post', 'rose', 'Rose');
        $this->site->reconcile();
        $expect = [
            '/v1/search?locale=en' => 422,
            '/v1/search?q=&locale=en' => 422,
            '/v1/search?q=rose' => 422,
            '/v1/search?q=rose&locale=en&kind=reviews' => 422,
            '/v1/search?q=rose&locale=en&type=post&kind=all' => 422,
            '/v1/search?q=rose&locale=en&offset=2&cursor=abc' => 422,
            '/v1/search?q=rose&locale=en&cursor=abc.def' => 400,
            '/v1/search?q[]=rose&locale=en' => 422,
            '/v1/search?q=rose&locale[]=en' => 422,
            '/v1/search?q=rose&locale=en&kind[]=all' => 422,
            '/v1/search?q=rose&locale=en&cursor[]=x' => 200,
        ];
        foreach ($expect as $uri => $status) {
            $res = $this->site->get($uri);
            self::assertSame($status, $res->getStatusCode(), $uri . ' → ' . $res->getContent());
        }
    }
}
