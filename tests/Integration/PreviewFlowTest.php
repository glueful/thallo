<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\Preview\PreviewToken;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\RouteRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Helpers\Utils;
use Glueful\Permissions\PermissionManager;
use Glueful\Testing\InMemoryPermissionProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * End-to-end preview flow through the REAL application kernel.
 *
 * The preview door is the ONLY way to see a draft. This test drives genuine HTTP requests
 * through {@see AppTestCase::handle()} (the same entry point as public/index.php) so the
 * whole pipeline runs for both halves of the door:
 *
 *  - MINT  POST /v1/admin/entries/{uuid}/preview/{locale} — auth-gated + `content_permission`
 *    gated. We authenticate as an API-key admin and satisfy the RBAC permission with the
 *    framework's {@see InMemoryPermissionProvider} (the test-only provider the framework
 *    ships precisely for this), granting our seeded user `content.view`. With a
 *    provider active and `provider_mode` defaulting to 'replace', PermissionManager::can()
 *    short-circuits to the provider — a clean GRANT for the seeded uuid, deny for anyone else.
 *
 *  - SHOW  GET /v1/preview/{token} — UNAUTHENTICATED by design (the signed token IS the
 *    capability). The public requests below carry NO auth header at all, proving the door
 *    opens on the token alone. Every fault fails CLOSED: tamper -> 403, expired -> 410.
 *
 * The leak-proof crux is also pinned here: an entry that is only ever a draft (never
 * published) is INVISIBLE to the public delivery API (404) but visible through preview.
 * Preview is the only draft door; delivery can't see drafts.
 *
 * Unlike the controller-level {@see \App\Tests\Integration\Http\PreviewApiTest} (which news
 * up the controller directly), this is the genuine kernel round-trip: routing, the `auth`
 * middleware + API-key provider, the `content_permission` RBAC gate, and the public rate-limited
 * read path all run.
 */
final class PreviewFlowTest extends AppTestCase
{
    private string $type;
    private string $userUuid;

    protected function setUp(): void
    {
        parent::setUp();

        // AppTestCase only truncates the Lemma content tables; the users/api_keys rows
        // we seed for the kernel auth chain — and the singleton permission provider — are
        // ours to clean up.
        $this->purgeAuthFixtures();

        $this->userUuid = $this->seedUser();

        // Grant the admin principal the permission the mint route requires. The provider is
        // installed on the process-singleton PermissionManager the middleware resolves, so
        // the kernel's `content_permission` gate sees the grant. Cleared in tearDown.
        $this->permissionManager()->setProvider(new InMemoryPermissionProvider([
            $this->userUuid => ['content.view'],
        ]));

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
        // Drop the active provider so other suites fall back to the default gate path.
        $this->permissionManager()->clearProvider();
        $this->purgeAuthFixtures();
        parent::tearDown();
    }

    // ── 1. The full happy path: authorized mint -> public read -> draft ────────

    public function testMintThenPublicReadReturnsDraftThroughKernel(): void
    {
        $uuid = $this->seedDraft('Secret draft', 'visible-in-preview');

        // MINT through the real kernel as an authenticated + permissioned admin.
        $mint = $this->handle($this->adminPost("/v1/admin/entries/{$uuid}/preview/en"));
        self::assertSame(200, $mint->getStatusCode(), $mint->getContent());

        $data = $this->json($mint)['data'];
        self::assertArrayHasKey('token', $data);
        self::assertArrayHasKey('expires_at', $data);
        self::assertArrayHasKey('expires_in', $data);
        $token = $data['token'];
        self::assertIsString($token);
        // theme_url is SERVER-decided (preview spec §4): the suite runs with
        // thallo.render enabled, so it must be present and token-bound.
        self::assertSame('/_preview/' . $token, $data['theme_url']);

        // READ through the kernel with NO auth header at all — the token is the capability.
        $show = $this->handle($this->publicGet('/v1/preview/' . $token));
        self::assertSame(200, $show->getStatusCode(), $show->getContent());

        $preview = $this->json($show)['data']['preview'];
        self::assertSame($uuid, $preview['entry_uuid']);
        self::assertSame('en', $preview['locale']);
        self::assertSame('Secret draft', $preview['fields']['title']);
    }

    // ── 1b. theme_url is null when rendered delivery is off (preview spec §4) ───

    public function testThemeUrlIsNullWhenRenderCapabilityDisabled(): void
    {
        $uuid = $this->seedDraft('No theme', 'no-theme-preview');
        // Override boots lose extension routes (loadRoutesFrom latch) — drive the
        // controller from the override container directly (established precedent).
        $app = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.render' => false]]);
        $controller = $app->getContainer()
            ->get(\App\Content\Http\Controllers\PreviewController::class);
        $res = $controller->mint(
            new \App\Content\Http\DTOs\MintPreviewData(),
            \Symfony\Component\HttpFoundation\Request::create('/'),
            $uuid,
            'en',
        );
        self::assertSame(200, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true)['data'];
        self::assertIsString($data['token']); // the JSON preview URL is unaffected
        self::assertNull($data['theme_url']);
    }

    // ── 2. The mint gate: an UNauthenticated mint is rejected (401), not served ─

    public function testUnauthenticatedMintIsRejected(): void
    {
        $uuid = $this->seedDraft('Gated draft', 'gated-draft');

        // No auth header -> the `auth` middleware rejects before mint() ever runs.
        $resp = $this->handle(Request::create(
            "/v1/admin/entries/{$uuid}/preview/en",
            'POST',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
        ));

        self::assertSame(401, $resp->getStatusCode(), $resp->getContent());
    }

    // ── 2b. The permission gate DISCRIMINATES: authenticated but unpermissioned ─
    //        admin is denied (403) — proving content_permission runs on the real
    //        post-auth principal (the `'user'` array) and fails closed on a missing grant.

    public function testAuthenticatedButUnpermissionedMintIsForbidden(): void
    {
        $uuid = $this->seedDraft('Permission-gated draft', 'perm-gated-draft');

        // Same authenticated admin, but the provider now grants only an UNRELATED
        // permission — so content.view is NOT held. The principal resolves
        // (auth succeeds), reaches PermissionManager::can(), and is denied.
        $this->permissionManager()->setProvider(new InMemoryPermissionProvider([
            $this->userUuid => ['content.edit'],
        ]));

        $resp = $this->handle($this->adminPost("/v1/admin/entries/{$uuid}/preview/en"));

        self::assertSame(403, $resp->getStatusCode(), $resp->getContent());
    }

    // ── 3. Tampered token fails CLOSED at the kernel: 403 ──────────────────────

    public function testTamperedTokenIsForbiddenThroughKernel(): void
    {
        $uuid = $this->seedDraft('Tamper me', 'tamper-me');
        $mint = $this->handle($this->adminPost("/v1/admin/entries/{$uuid}/preview/en"));
        $token = $this->json($mint)['data']['token'];

        // Flip the final character of the signature -> the signature check must reject it.
        $tampered = substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A');

        $resp = $this->handle($this->publicGet('/v1/preview/' . $tampered));

        self::assertSame(403, $resp->getStatusCode(), $resp->getContent());
        self::assertFalse($this->json($resp)['success']);
    }

    // ── 4. Expired token fails CLOSED at the kernel with the DISTINCT 410 ──────

    public function testExpiredTokenIsGoneThroughKernel(): void
    {
        $uuid = $this->seedDraft('Stale draft', 'stale-draft');

        // Mint an already-expired token signed with the SAME key the minter derives
        // (config app.key, base64: prefix decoded to raw bytes), so it verifies but is past.
        $token = PreviewToken::mint($uuid, 'en', null, time() - 1, $this->previewSigningKey());

        $resp = $this->handle($this->publicGet('/v1/preview/' . $token));

        self::assertSame(410, $resp->getStatusCode(), $resp->getContent());
    }

    // ── 5. LEAK-PROOF CRUX: a draft-only entry is invisible to delivery, ──────
    //        but readable through its preview token.

    public function testDraftIsServedByPreviewButNeverByDelivery(): void
    {
        // A draft-only entry: created, drafted, given a slug route, but NEVER published.
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, $this->userUuid);
        $entries->saveDraft($uuid, 'en', ['title' => 'Unpublished secret'], 1, 0, $this->userUuid);
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', 'unpublished-secret');

        // The public DELIVERY door cannot see it — there is no publication pin.
        $delivery = $this->handle($this->scopedGet('/v1/content/post/unpublished-secret'));
        self::assertSame(
            404,
            $delivery->getStatusCode(),
            'a never-published entry must not be served by delivery: ' . $delivery->getContent(),
        );

        // The PREVIEW door can: mint (authorized) then read (public, no auth).
        $mint = $this->handle($this->adminPost("/v1/admin/entries/{$uuid}/preview/en"));
        self::assertSame(200, $mint->getStatusCode(), $mint->getContent());
        $token = $this->json($mint)['data']['token'];

        $show = $this->handle($this->publicGet('/v1/preview/' . $token));
        self::assertSame(200, $show->getStatusCode(), $show->getContent());
        self::assertSame(
            'Unpublished secret',
            $this->json($show)['data']['preview']['fields']['title'],
            'preview is the ONLY door to a draft',
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** A POST authenticated as the seeded API-key admin (dual-header trick, see DeliveryFlowTest). */
    private function adminPost(string $path): Request
    {
        $key = $this->mintKey(['*']);
        return Request::create($path, 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_API_KEY' => $key,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
        ], '{}');
    }

    /** A GET with NO auth at all — the public preview door must open on the token alone. */
    private function publicGet(string $path): Request
    {
        return Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    /** A delivery GET authenticated with the `read:content` scope (proves delivery can't see the draft). */
    private function scopedGet(string $path): Request
    {
        $key = $this->mintKey(['read:content']);
        return Request::create($path, 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_API_KEY' => $key,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
        ]);
    }

    /** Mint an API key for the seeded user with the given scopes; returns the plaintext key. */
    private function mintKey(array $scopes): string
    {
        $result = ApiKeyService::create($this->appContext(), [
            'user_uuid' => $this->userUuid,
            'name' => 'preview-test',
            'scopes' => $scopes,
        ]);
        return $result['plain'];
    }

    /**
     * The preview-token signing key, derived exactly as App\Content\Preview\ResolvesPreviewKey
     * does (config app.key, decode a base64: prefix to raw bytes), so a hand-minted token
     * verifies against what PreviewReader expects.
     */
    private function previewSigningKey(): string
    {
        $key = (string) config($this->appContext(), 'app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                $key = $decoded;
            }
        }
        return $key;
    }

    /** Seed a minimal user row so the API-key provider's findByUuid() resolves an identity. */
    private function seedUser(): string
    {
        $uuid = Utils::generateNanoID();
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'preview_' . substr($uuid, 0, 6),
            'email' => $uuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    /** Create an entry + a draft carrying $title + a slug route; return the entry uuid. */
    private function seedDraft(string $title, string $slug): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, $this->userUuid);
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, $this->userUuid);
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', $slug);
        return $uuid;
    }

    private function purgeAuthFixtures(): void
    {
        $this->connection()->table('api_keys')->where('id', '>', 0)->delete();
        $this->connection()->table('users')->where('id', '>', 0)->delete();
    }

    private function permissionManager(): PermissionManager
    {
        /** @var PermissionManager $manager */
        $manager = $this->container()->get('permission.manager');
        return $manager;
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    /** @return array<string,mixed> */
    private function json(HttpResponse $response): array
    {
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true);
        return $decoded;
    }
}
