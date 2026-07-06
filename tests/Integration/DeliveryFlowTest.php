<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Seo\RedirectRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * End-to-end delivery flow through the REAL application kernel.
 *
 * Unlike the controller-level {@see \App\Tests\Integration\Http\DeliveryApiTest} (which
 * news up the controller with the repositories), this test drives a genuine HTTP request
 * through {@see AppTestCase::handle()} (the same entry point as public/index.php) so the
 * full pipeline is exercised: routing (routes/content.php), optional API-key
 * authentication (which sets the `api_key_scopes` attribute when a key is present), and the
 * fail-closed delivery access gate — none of which the controller test touches.
 *
 * The data layer is seeded with the same real repositories/PublishService the admin path
 * uses, so the leak-proof publication spine is genuinely published-through, not faked.
 */
final class DeliveryFlowTest extends AppTestCase
{
    private string $type;
    private string $userUuid;

    protected function setUp(): void
    {
        parent::setUp();

        // AppTestCase only truncates the Lemma content tables; the users/api_keys rows
        // we seed for the kernel auth chain are ours to clean up.
        $this->purgeAuthFixtures();

        $this->userUuid = $this->seedUser();
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'text'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->purgeAuthFixtures();
        parent::tearDown();
    }

    // ── 1. Published single entry is served (200 + fields) ────────────────────

    public function testPublishedEntryIsServedThroughKernel(): void
    {
        $this->publish(['title' => 'Hello kernel', 'body' => 'visible'], 'hello-kernel');

        $response = $this->handle($this->scopedGet('/v1/content/post/hello-kernel'));

        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $data = $this->json($response)['data'];
        self::assertSame('Hello kernel', $data['fields']['title']);
        self::assertSame('visible', $data['fields']['body']);
    }

    // ── 2. LEAK TEST: an unpublished entry is absent from the list ────────────

    public function testUnpublishedEntryIsAbsentFromListing(): void
    {
        $this->publish(['title' => 'Published'], 'published');
        // Draft only — created + saved but never published.
        $entries = $this->entries();
        $draftUuid = $entries->createEntry($this->type, 'en', 1, $this->userUuid);
        $entries->saveDraft($draftUuid, 'en', ['title' => 'Draft leak'], 1, 0, $this->userUuid);

        $response = $this->handle($this->scopedGet('/v1/content/post'));

        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        $titles = $this->listTitles($response);
        self::assertContains('Published', $titles);
        self::assertNotContains('Draft leak', $titles);
    }

    // ── 3. KERNEL ACCESS GATE: scopes + public opt-in ────────────────────────

    public function testRequestWithoutReadContentScopeIsForbidden(): void
    {
        $this->publish(['title' => 'Gated'], 'gated');

        // A non-empty scope set that does NOT satisfy read:content. (An empty scope set
        // means "full access" per ApiKeyService::scopeSatisfies, so it must be non-empty.)
        $plain = $this->mintKey(['read:other']);

        $response = $this->handle($this->scopedGet('/v1/content/post/gated', $plain));

        self::assertSame(403, $response->getStatusCode(), $response->getContent());
    }

    public function testTypeScopedApiKeyCanReadOnlyThatContentType(): void
    {
        $this->publish(['title' => 'Typed'], 'typed');
        $key = $this->mintKey(['read:content:post']);

        $allowed = $this->handle($this->scopedGet('/v1/content/post/typed', $key));
        self::assertSame(200, $allowed->getStatusCode(), $allowed->getContent());

        $this->createContentType('page', 'Page');
        $blocked = $this->handle($this->scopedGet('/v1/content/page', $key));
        self::assertSame(403, $blocked->getStatusCode(), $blocked->getContent());
    }

    public function testPublicContentTypeCanBeReadWithoutApiKey(): void
    {
        $this->setPublicDelivery('post', true);
        $this->publish(['title' => 'Public'], 'public');

        $response = $this->handle(Request::create('/v1/content/post/public', 'GET'));

        self::assertSame(200, $response->getStatusCode(), $response->getContent());
        self::assertSame('Public', $this->json($response)['data']['fields']['title']);
    }

    public function testPrivateContentTypeStillRejectsAnonymousDelivery(): void
    {
        $this->publish(['title' => 'Private'], 'private');

        $response = $this->handle(Request::create('/v1/content/post/private', 'GET'));

        self::assertSame(403, $response->getStatusCode(), $response->getContent());
    }

    public function testPrivateContentTypeDeniesAnonymousRedirectAndGoneResolution(): void
    {
        $routes = new RouteRepository($this->connection(), new RedirectRepository($this->connection()));

        $moved = $this->publish(['title' => 'Moved private'], 'old-private');
        $routes->assign($moved, $this->type, 'en', 'new-private');

        $entries = $this->entries();
        $draftOnly = $entries->createEntry($this->type, 'en', 1, $this->userUuid);
        $routes->assign($draftOnly, $this->type, 'en', 'draft-target');
        (new RedirectRepository($this->connection()))->create([
            'content_type_uuid' => $this->type,
            'locale' => 'en',
            'source_slug' => 'broken-private',
            'target_content_type_uuid' => $this->type,
            'target_locale' => 'en',
            'target_entry_uuid' => $draftOnly,
            'status' => 301,
            'origin' => 'manual',
        ]);

        $anonymousMoved = $this->handle(Request::create('/v1/content/post/old-private', 'GET'));
        $anonymousGone = $this->handle(Request::create('/v1/content/post/broken-private', 'GET'));
        self::assertSame(403, $anonymousMoved->getStatusCode(), $anonymousMoved->getContent());
        self::assertSame(403, $anonymousGone->getStatusCode(), $anonymousGone->getContent());

        $key = $this->mintKey(['read:content:post']);
        $authorizedMoved = $this->handle($this->scopedGet('/v1/content/post/old-private', $key));
        $authorizedGone = $this->handle($this->scopedGet('/v1/content/post/broken-private', $key));

        self::assertSame(200, $authorizedMoved->getStatusCode(), $authorizedMoved->getContent());
        self::assertArrayHasKey('redirect', $this->json($authorizedMoved)['data']);
        self::assertSame(404, $authorizedGone->getStatusCode(), $authorizedGone->getContent());
    }

    public function testInvalidApiKeyDoesNotFallThroughToPublicDelivery(): void
    {
        $this->setPublicDelivery('post', true);
        $this->publish(['title' => 'Public'], 'public');

        $response = $this->handle($this->scopedGet('/v1/content/post/public', 'not-a-real-key'));

        self::assertSame(401, $response->getStatusCode(), $response->getContent());
    }

    // ── 4. CONDITIONAL GET: matching If-None-Match returns 304 ────────────────

    public function testMatchingEtagReturns304(): void
    {
        $this->publish(['title' => 'Etag kernel'], 'etag-kernel');

        $first = $this->handle($this->scopedGet('/v1/content/post/etag-kernel'));
        self::assertSame(200, $first->getStatusCode());
        $etag = $first->headers->get('ETag');
        self::assertNotNull($etag);

        $conditional = $this->handle(
            $this->scopedGet('/v1/content/post/etag-kernel', null, ['If-None-Match' => $etag])
        );
        self::assertSame(304, $conditional->getStatusCode());
        self::assertSame('', (string) $conditional->getContent());
    }

    public function testScopedResponseIsPrivateAndVariesFromAnonymous(): void
    {
        // A public type: readable both anonymously and with a key. The keyed response can
        // expand references an anonymous caller can't see, so it must be Cache-Control:
        // private + Vary: X-API-Key, and carry a DIFFERENT validator than the anonymous one.
        $this->setPublicDelivery('post', true);
        $this->publish(['title' => 'Cacheable'], 'cacheable');

        $anon = $this->handle(Request::create('/v1/content/post/cacheable', 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]));
        self::assertSame(200, $anon->getStatusCode(), $anon->getContent());
        self::assertStringContainsString('public', (string) $anon->headers->get('Cache-Control'));

        $scoped = $this->handle($this->scopedGet('/v1/content/post/cacheable'));
        self::assertSame(200, $scoped->getStatusCode(), $scoped->getContent());
        self::assertStringContainsString('private', (string) $scoped->headers->get('Cache-Control'));
        self::assertStringContainsString('X-API-Key', (string) $scoped->headers->get('Vary'));

        self::assertNotSame(
            $anon->headers->get('ETag'),
            $scoped->headers->get('ETag'),
            'a scoped response must not share an ETag with the anonymous one (would 304 across access levels)'
        );
    }

    // ── 5. CARRY-FORWARD: unpublish-after-publish drops the entry ─────────────

    public function testUnpublishAfterPublishRemovesEntryFromListing(): void
    {
        $uuid = $this->publish(['title' => 'Now you see me'], 'toggle');

        $before = $this->handle($this->scopedGet('/v1/content/post'));
        self::assertContains('Now you see me', $this->listTitles($before));

        // Unpublish through the real service (drops the entry_publications pin).
        $this->publishService()->unpublish($uuid, 'en');

        $after = $this->handle($this->scopedGet('/v1/content/post'));
        self::assertSame(200, $after->getStatusCode(), $after->getContent());
        self::assertNotContains(
            'Now you see me',
            $this->listTitles($after),
            'an unpublished entry must vanish from the read path (publication state, not a snapshot)'
        );
    }

    // ── 6. CARRY-FORWARD: keyset paging across a sort-key tie ─────────────────

    public function testKeysetPagingAcrossTiedSortKeyVisitsEveryEntryOnce(): void
    {
        // Four entries published in the same test run share `published_at` to the second
        // (VersionRepository::pin uses date('Y-m-d H:i:s')). The default sort is
        // `published_at DESC, v.id DESC`; without the v.id tiebreaker a naive cursor would
        // skip or duplicate rows at the page boundary.
        //
        // The cursor/keyset branch is the one we want to exercise — so we must NOT send
        // ?page/?perPage (those force the offset-pagination branch). The cursor branch's
        // page size is `thallo.delivery.default_per_page`; force it to 2 for this test so a
        // 4-row dataset spans multiple pages and the boundary tiebreaker is actually hit.
        $restore = $this->forceDefaultPerPage(2);

        try {
            $expected = [];
            foreach (['tie-a', 'tie-b', 'tie-c', 'tie-d'] as $i => $slug) {
                $title = 'Tie ' . $i;
                $this->publish(['title' => $title], $slug);
                $expected[] = $title;
            }

            $seen = [];
            $cursor = null;
            $guard = 0;
            do {
                $path = '/v1/content/post' . ($cursor !== null ? '?cursor=' . rawurlencode($cursor) : '');
                $response = $this->handle($this->scopedGet($path));
                self::assertSame(200, $response->getStatusCode(), $response->getContent());

                $payload = $this->json($response)['data'];
                self::assertLessThanOrEqual(2, count($payload['items']), 'cursor page must honour the limit');
                foreach ($payload['items'] as $item) {
                    $seen[] = $item['fields']['title'];
                }
                $cursor = $payload['next_cursor'] ?? null;
            } while ($cursor !== null && ++$guard < 10);

            // Paged in >1 request (proves the boundary was crossed), every entry once.
            self::assertGreaterThan(1, $guard, 'the dataset must span more than one cursor page');
            sort($seen);
            sort($expected);
            self::assertSame($expected, $seen, 'every tied entry must appear exactly once across pages');
        } finally {
            $restore();
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Prime the (process-shared) ApplicationContext config cache so the delivery cursor
     * branch uses a small page size, then return a closure that restores the prior state.
     * Reflection is the surgical option: there is no query param for the keyset limit and no
     * public config setter, and the context is a static singleton across the whole suite.
     */
    private function forceDefaultPerPage(int $perPage): \Closure
    {
        $context = $this->appContext();
        $ref = new \ReflectionProperty($context, 'configCache');
        $ref->setAccessible(true);
        /** @var array<string,mixed> $previous */
        $previous = $ref->getValue($context);

        $patched = $previous;
        $patched['thallo.delivery.default_per_page'] = $perPage;
        $ref->setValue($context, $patched);

        return static function () use ($ref, $context, $previous): void {
            $ref->setValue($context, $previous);
        };
    }

    /** @return list<string> */
    private function listTitles(HttpResponse $response): array
    {
        $items = $this->json($response)['data']['items'];
        return array_values(array_map(static fn(array $i): string => (string) $i['fields']['title'], $items));
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true);
        return $decoded;
    }

    /**
     * A GET request authenticated as an API-key holder. When $plainKey is null a key with
     * the `read:content` scope is minted on the fly. Both an `X-API-Key` header (read by the
     * API-key provider) and an `Authorization: Bearer` header (which satisfies AuthMiddleware's
     * credential-extraction gate) are set — the JWT provider rejects the bearer value, then
     * the API-key provider authenticates from X-API-Key.
     *
     * @param array<string,string> $headers
     */
    private function scopedGet(string $path, ?string $plainKey = null, array $headers = []): Request
    {
        $plainKey ??= $this->mintKey(['read:content']);

        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => $plainKey,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plainKey,
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create($path, 'GET', [], [], [], $server);
    }

    /** Mint an API key for the seeded user with the given scopes; returns the plaintext key. */
    private function mintKey(array $scopes): string
    {
        $result = ApiKeyService::create($this->appContext(), [
            'user_uuid' => $this->userUuid,
            'name' => 'delivery-test',
            'scopes' => $scopes,
        ]);
        return $result['plain'];
    }

    /** Seed a minimal user row so the API-key provider's findByUuid() resolves an identity. */
    private function seedUser(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'delivery_' . substr($uuid, 0, 6),
            'email' => $uuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    private function purgeAuthFixtures(): void
    {
        $this->connection()->table('api_keys')->where('id', '>', 0)->delete();
        $this->connection()->table('users')->where('id', '>', 0)->delete();
    }

    private function createContentType(string $slug, string $name): string
    {
        return (new ContentTypeRepository($this->connection()))->create([
            'slug' => $slug,
            'name' => $name,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
            ],
        ]);
    }

    private function setPublicDelivery(string $slug, bool $public): void
    {
        $this->connection()->table('content_types')
            ->where('slug', '=', $slug)
            ->update(['public_delivery' => $public]);
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    private function publishService(): PublishService
    {
        return new PublishService(
            $this->appContext(),
            $this->entries(),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
    }

    /** Create + save a draft + assign a slug route + publish; returns the entry uuid. */
    private function publish(array $fields, string $slug): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, $this->userUuid);
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, $this->userUuid);
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', $slug);
        $this->publishService()->publish($uuid, 'en', $this->userUuid);
        return $uuid;
    }
}
