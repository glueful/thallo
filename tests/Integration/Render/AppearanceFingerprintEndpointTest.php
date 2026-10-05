<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Glueful\Application;
use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;
use Glueful\Permissions\PermissionManager;
use Glueful\Testing\InMemoryPermissionProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Core\Content\Http\Controllers\PreviewController;
use Thallo\Core\Content\Http\DTOs\MintPreviewData;
use Thallo\Core\Content\Preview\PreviewWorkingCopyStore;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Render\Http\Controllers\RenderController;
use Thallo\Render\RenderContextExtension;

/**
 * What an open stage asks to know when to reload (block typeface plan Task 11):
 * `GET /v1/admin/render/appearance-fingerprint`, through the real kernel. It answers whenever the
 * renderer is on — template editing switched off included — to any of the three stage editors'
 * permissions; its value is the one a stage render carries; a preview token's overrides never reach
 * it; and it mints nothing and moves no session.
 */
final class AppearanceFingerprintEndpointTest extends AppTestCase
{
    private const PATH = '/v1/admin/render/appearance-fingerprint';

    private string $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeAuth();
        $this->user = Utils::generateNanoID();
        $this->connection()->table('users')->insert([
            'uuid' => $this->user, 'username' => 'stage_' . substr($this->user, 0, 6),
            'email' => $this->user . '@example.test', 'password' => 'x', 'status' => 'active',
            'two_factor_enabled' => false, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        // A stage render leaves the shared extension annotating: later tests render live pages.
        $this->container()->get(RenderContextExtension::class)->setAnnotationScope('none');
        self::permissions($this->appContext())->clearProvider();
        $this->purgeAuth();
        parent::tearDown();
    }

    private function purgeAuth(): void
    {
        $this->connection()->table('api_keys')->where('id', '>', 0)->delete();
        $this->connection()->table('users')->where('id', '>', 0)->delete();
    }

    private static function permissions(ApplicationContext $context): PermissionManager
    {
        /** @var PermissionManager $manager */
        $manager = $context->getContainer()->get('permission.manager');
        return $manager;
    }

    /** @param list<string> $permissions */
    private function grant(ApplicationContext $context, array $permissions): void
    {
        self::permissions($context)->setProvider(new InMemoryPermissionProvider([$this->user => $permissions]));
    }

    /** @param array<string,string> $cookies */
    private function get(ApplicationContext $context, bool $authenticated = true, array $cookies = []): Response
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($authenticated) {
            $key = ApiKeyService::create(
                $context,
                ['user_uuid' => $this->user, 'name' => 'stage', 'scopes' => ['*']],
            )['plain'];
            $server += ['HTTP_X_API_KEY' => $key, 'HTTP_AUTHORIZATION' => 'Bearer ' . $key];
        }
        return (new Application($context))->handle(Request::create(self::PATH, 'GET', [], $cookies, [], $server));
    }

    private static function fingerprint(Response $response): string
    {
        $body = json_decode((string) $response->getContent(), true);
        return (string) ($body['data']['appearance_fingerprint'] ?? '');
    }

    /** A page with a draft, and the token of a stage session on it. */
    private function stageSession(?string $accent = null): array
    {
        $types = $this->container()->get(ContentTypeRepository::class);
        $type = $types->findBySlug('page');
        $typeUuid = $type !== null ? (string) $type['uuid'] : $types->create([
            'slug' => 'page', 'name' => 'Page', 'schema' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $type = $types->findByUuid($typeUuid);
        $entries = $this->container()->get(EntryRepository::class);
        $entry = $entries->createEntry($typeUuid, 'en', (int) $type['schema_version'], null);
        $draft = $entries->findDraft($entry, 'en');
        $entries->saveDraft(
            $entry,
            'en',
            ['title' => 'Stage', 'body' => []],
            (int) $type['schema_version'],
            (int) ($draft['lock_version'] ?? 0),
            null,
        );
        $mint = $this->container()->get(PreviewController::class)->mint(
            new MintPreviewData(accent: $accent),
            Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}'),
            $entry,
            'en',
        );
        $token = (string) (json_decode((string) $mint->getContent(), true)['data']['token'] ?? '');
        self::assertNotSame('', $token, (string) $mint->getContent());
        return [$entry, $token];
    }

    public function testItAnswersWithTemplateEditingSwitchedOff(): void
    {
        $off = self::bootAppWithConfigOverride('render', ['db_templates' => false]);
        try {
            $this->grant($off, ['content.edit']);
            $templates = (new Application($off))->handle(Request::create('/v1/admin/render/templates', 'GET'));
            self::assertSame(404, $templates->getStatusCode(), 'template editing is off in this boot');

            self::assertSame(401, $this->get($off, authenticated: false)->getStatusCode());
            $ok = $this->get($off);
            self::assertSame(200, $ok->getStatusCode(), (string) $ok->getContent());
            self::assertNotSame('', self::fingerprint($ok));
            $this->grant($off, []);
            self::assertSame(403, $this->get($off)->getStatusCode());
        } finally {
            self::permissions($off)->clearProvider();
            self::resetSharedRepositoryConnection();
        }
    }

    public function testAnyStageEditorsPermissionReadsItAndNoneDoesNot(): void
    {
        foreach (['content.edit', 'content.manage', 'templates.manage'] as $permission) {
            $this->grant($this->appContext(), [$permission]);
            $res = $this->get($this->appContext());
            self::assertSame(200, $res->getStatusCode(), $permission . ': ' . $res->getContent());
            self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        }
        $this->grant($this->appContext(), ['content.view']);
        self::assertSame(403, $this->get($this->appContext())->getStatusCode());
        self::assertSame(401, $this->get($this->appContext(), authenticated: false)->getStatusCode());
    }

    public function testItIsTheValueAStageRenderCarries(): void
    {
        [, $token] = $this->stageSession();
        $stage = $this->container()->get(RenderController::class)->preview(
            Request::create('/_preview/' . $token . '?canvas=1', 'GET'),
            $token,
        );
        self::assertSame(200, $stage->getStatusCode());
        $html = (string) $stage->getContent();
        self::assertSame(1, preg_match('~data-thallo-appearance-fingerprint="([^"]+)"~', $html, $m));

        $this->grant($this->appContext(), ['content.edit']);
        self::assertSame(html_entity_decode($m[1]), self::fingerprint($this->get($this->appContext())));
    }

    public function testAPreviewTokensOverridesNeverReachIt(): void
    {
        $this->grant($this->appContext(), ['content.edit']);
        $saved = self::fingerprint($this->get($this->appContext()));
        [, $token] = $this->stageSession(accent: 'rose');
        $cookies = ['thallo_preview' => $token, 'thallo_preview_canvas' => '1'];
        $withToken = $this->get($this->appContext(), cookies: $cookies);
        self::assertSame($saved, self::fingerprint($withToken));
        self::assertStringNotContainsString('rose', self::fingerprint($withToken));
    }

    public function testItMintsNothingAndMovesNoSession(): void
    {
        [$entry] = $this->stageSession();
        $copies = $this->container()->get(PreviewWorkingCopyStore::class);
        $copies->accept($entry, 'en', null, null, ['title' => 'Edited'], [], 600);
        $before = $copies->record($entry, 'en');
        self::assertNotNull($before);

        $this->grant($this->appContext(), ['content.edit']);
        $res = $this->get($this->appContext());
        self::assertSame(200, $res->getStatusCode());
        self::assertSame([], array_map(static fn (Cookie $c): string => $c->getName(), $res->headers->getCookies()));
        self::assertSame($before, $copies->record($entry, 'en'), 'the open session keeps its baseline');
    }
}
