<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Schema\ContentTypeReader;
use Thallo\Search\Engine\SearchBackend;
use Thallo\Search\Http\SearchController;
use Thallo\Search\Query\Hit;
use Thallo\Search\Query\SearchRequest;
use Thallo\Search\Query\SearchResults;
use Thallo\Search\Query\VisibilityResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * The public search endpoint. The kernel has no runtime service-swap, so (like SEO's
 * SitemapEndpointTest) the controller is constructed directly with a FAKE SearchBackend
 * over the real ContentTypeReader/test DB. Route registration is asserted separately.
 */
final class SearchEndpointTest extends AppTestCase
{
    /**
     * @param list<Hit> $hits
     */
    private function fakeBackend(bool $healthy = true, array $hits = [], int $total = 0): SearchBackend
    {
        return new class ($healthy, $hits, $total) implements SearchBackend {
            /** @param list<Hit> $hits */
            public function __construct(private bool $healthy, private array $hits, private int $total)
            {
            }
            public function ensureIndex(): void
            {
            }
            public function upsert(iterable $documents): void
            {
            }
            public function deleteEntry(string $entryUuid, ?string $locale = null): void
            {
            }
            public function search(SearchRequest $r): SearchResults
            {
                // An unreachable/misconfigured Meilisearch surfaces as a thrown SDK
                // exception from search() — the controller maps it to 503.
                if (!$this->healthy) {
                    throw new \RuntimeException('connection refused');
                }
                return new SearchResults($this->hits, $this->total, $r->limit, $r->offset);
            }
            public function health(): bool
            {
                return $this->healthy;
            }
        };
    }

    private function controller(SearchBackend $backend): SearchController
    {
        /** @var ContentTypeReader $types */
        $types = $this->container()->get(ContentTypeReader::class);
        return new SearchController($backend, new VisibilityResolver($types), $types, 20, 50);
    }

    /** @param array<string,mixed> $query */
    private function request(array $query): Request
    {
        return Request::create('/v1/search', 'GET', $query, [], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    public function testRouteAbsentByDefaultBecauseCapabilityIsOff(): void
    {
        // The thallo-search module always loads, but the `thallo.search` capability is disabled
        // in config/thallo.php by default, so the route is NOT registered in the standard boot.
        // Switching the capability on wires it up — see SearchEnablementTest.
        self::assertNull($this->findRoute('GET', '/v1/search'), '/v1/search must be opt-in, not default');
    }

    public function testHappyPathMapsHitsToDataEnvelope(): void
    {
        $hit = new Hit('e-1', 'blog', 'en', '/en/blog/x', 'Title', 'a <mark>climate</mark> b', 0.9);
        $resp = $this->controller($this->fakeBackend(hits: [$hit], total: 1))
            ->search($this->request(['q' => 'climate', 'locale' => 'en']));

        self::assertSame(200, $resp->getStatusCode());
        // Response::success() nests the payload under `data`.
        $body = json_decode((string) $resp->getContent(), true);
        self::assertSame(1, $body['data']['total']);
        self::assertSame('e-1', $body['data']['hits'][0]['uuid']);
        self::assertSame('blog', $body['data']['hits'][0]['type']);
        self::assertSame('en', $body['data']['hits'][0]['locale']);
        self::assertSame('/en/blog/x', $body['data']['hits'][0]['href']);
        self::assertSame('Title', $body['data']['hits'][0]['title']);
        self::assertStringContainsString('<mark>climate</mark>', $body['data']['hits'][0]['snippet']);
        self::assertSame(0.9, $body['data']['hits'][0]['score']);
    }

    public function testMissingQueryReturns422(): void
    {
        $c = $this->controller($this->fakeBackend());
        self::assertSame(422, $c->search($this->request(['locale' => 'en']))->getStatusCode());
        self::assertSame(422, $c->search($this->request(['q' => '', 'locale' => 'en']))->getStatusCode());
    }

    public function testMissingLocaleReturns422(): void
    {
        self::assertSame(
            422,
            $this->controller($this->fakeBackend())->search($this->request(['q' => 'hello']))->getStatusCode(),
        );
    }

    public function testUnknownTypeReturns404(): void
    {
        $resp = $this->controller($this->fakeBackend())
            ->search($this->request(['q' => 'hi', 'locale' => 'en', 'type' => 'no-such-type']));
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testProvidedInaccessibleTypeReturns403ForAnonymous(): void
    {
        // A NON-public content type; anonymous request provides it → 403 (delivery parity).
        (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'secret',
            'name' => 'Secret',
            'public_delivery' => false,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);

        $resp = $this->controller($this->fakeBackend())
            ->search($this->request(['q' => 'hi', 'locale' => 'en', 'type' => 'secret']));
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testUnhealthyBackendReturns503(): void
    {
        $resp = $this->controller($this->fakeBackend(healthy: false))
            ->search($this->request(['q' => 'hi', 'locale' => 'en']));
        self::assertSame(503, $resp->getStatusCode());
    }

    public function testArrayValuedQueryParamsDoNotThrow500(): void
    {
        // ?q[]=a&locale[]=en&limit[]=1 — this route is public (optional_api_key). Reading these via
        // InputBag::get() would throw a BadRequestException (→ unhandled 500); array params must be
        // treated as absent, so a missing/array `q` lands on the normal 422, never a 500.
        $resp = $this->controller($this->fakeBackend())->search($this->request([
            'q' => ['climate'],
            'locale' => ['en'],
            'type' => ['blog'],
            'limit' => ['5'],
            'offset' => ['2'],
        ]));

        self::assertSame(422, $resp->getStatusCode());
    }

    public function testArrayValuedLimitOffsetFallBackToDefaultsWithScalarQuery(): void
    {
        // Scalar q/locale but array limit/offset: the request still succeeds (limit/offset fall back
        // to their defaults) rather than 500ing on the InputBag guard.
        $resp = $this->controller($this->fakeBackend(hits: [], total: 0))->search($this->request([
            'q' => 'climate',
            'locale' => 'en',
            'limit' => ['999'],
            'offset' => ['bad'],
        ]));

        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getContent(), true);
        // limit defaulted to 20 (array ignored, not clamped from 999), offset defaulted to 0.
        self::assertSame(20, $body['data']['limit']);
        self::assertSame(0, $body['data']['offset']);
    }
}
