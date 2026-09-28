<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Cache\CacheStore;
use Glueful\Helpers\Utils;
use Glueful\Permissions\PermissionManager;
use Glueful\Testing\InMemoryPermissionProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ListingPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Listing and archive layouts' admin routes through the real kernel (type layouts plan B, Review
 * Focus 1): an archive's `{type}:{field}` target routes as the admin's client sends it,
 * percent-encoded, and as a proxy may pass it, with the colon literal; the `auth` middleware and the
 * `templates.manage` gate hold. The target in the layout cache key carries no character the Redis
 * driver refuses.
 */
final class ListingLayoutRoutesTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private string $userUuid = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->purgeAuthFixtures();
        $this->userUuid = Utils::generateNanoID();
        $this->connection()->table('users')->insert([
            'uuid' => $this->userUuid,
            'username' => 'listlay_' . substr($this->userUuid, 0, 6),
            'email' => $this->userUuid . '@example.test',
            'password' => 'x',
            'status' => 'active',
            'two_factor_enabled' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->permissions()->clearProvider();
        $this->purgeAuthFixtures();
        foreach ([['listing', 'post'], ['archive', 'post:categories']] as [$surface, $target]) {
            $this->container()->get(LayoutResolver::class)->forget($surface, $target);
        }
        $this->container()->get(CacheStore::class)->deletePattern('render:*');
        parent::tearDown();
    }

    public function testAnEditorOpensSavesAndRemovesAnArchiveLayoutOverHttp(): void
    {
        // Seeded first: the grant replaces the permission provider the seed's publish consults.
        $seeded = (new ListingPageSeed($this->container(), $this->appContext()))->seed();
        $this->grant(['content.view', 'templates.manage']);

        $session = $this->call('POST', '/v1/admin/layouts/preview/session', [
            'surface' => 'archive', 'target' => 'post:categories',
        ]);
        self::assertSame(200, $session->getStatusCode(), (string) $session->getContent());
        $token = self::data($session)['token'];
        self::assertSame('Pottery', self::data($session)['sample']['label']);

        $samples = $this->call('GET', '/v1/admin/layouts/archive/post%3Acategories/samples');
        self::assertSame(200, $samples->getStatusCode(), (string) $samples->getContent());
        self::assertSame([$seeded['pottery']], array_column(self::data($samples)['samples'], 'id'));

        $blocks = [
            ['id' => 'routemark001', 'type' => 'heading', 'data' => ['text' => 'ROUTED'], 'settings' => []],
            ['id' => 'routeloop001', 'type' => 'entry_loop', 'data' => ['card' => [
                ['id' => 'routetitle01', 'type' => 'entry_title', 'data' => [], 'settings' => []],
            ]], 'settings' => []],
        ];
        $saved = $this->call('PUT', '/v1/admin/layouts/archive/post%3Acategories', [
            'token' => $token, 'layout' => ['blocks' => $blocks, 'settings' => []], 'expected_lock_version' => 0,
        ]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getContent());
        self::assertSame(1, self::data($saved)['layout']['lock_version']);
        self::assertStringContainsString('ROUTED', (string) $this->handle(
            Request::create('/post/categories/pottery', 'GET'),
        )->getContent(), 'the archive renders through it');

        // Decoded by a proxy on the way in: the same route, the same layout.
        $again = $this->call('PUT', '/v1/admin/layouts/archive/post:categories', [
            'token' => self::data($this->call('POST', '/v1/admin/layouts/preview/session', [
                'surface' => 'archive', 'target' => 'post:categories',
            ]))['token'],
            'layout' => ['blocks' => $blocks, 'settings' => []], 'expected_lock_version' => 1,
        ]);
        self::assertSame(200, $again->getStatusCode(), (string) $again->getContent());
        self::assertSame(2, self::data($again)['layout']['lock_version']);

        $rows = array_column(array_values(array_filter(
            self::data($this->call('GET', '/v1/admin/layouts'))['layouts'],
            static fn (array $row): bool => in_array($row['surface'], ['listing', 'archive'], true),
        )), null, 'target');
        self::assertSame(['custom', true], [$rows['post:categories']['state'], $rows['post:categories']['enabled']]);
        self::assertSame(['theme', true], [$rows['post']['state'], $rows['post']['enabled']]);

        $removed = $this->call('DELETE', '/v1/admin/layouts/archive/post%3Acategories', [
            'token' => $token, 'expected_lock_version' => 2,
        ]);
        self::assertSame(200, $removed->getStatusCode(), (string) $removed->getContent());
        self::assertSame(3, self::data($removed)['lock_version']);
    }

    public function testWithoutTemplatesManageEveryWriteIsRefused(): void
    {
        $this->grant(['content.view']);
        $session = $this->call('POST', '/v1/admin/layouts/preview/session', [
            'surface' => 'listing', 'target' => 'post',
        ]);
        self::assertSame(403, $session->getStatusCode(), (string) $session->getContent());
        foreach (['/v1/admin/layouts/listing/post', '/v1/admin/layouts/archive/post%3Acategories'] as $path) {
            $saved = $this->call('PUT', $path, [
                'token' => 'x', 'layout' => ['blocks' => [], 'settings' => []], 'expected_lock_version' => 0,
            ]);
            self::assertSame(403, $saved->getStatusCode(), (string) $saved->getContent());
        }
        self::assertSame(0, $this->connection()->table('layouts')
            ->where('surface', '=', 'listing')->orWhere('surface', '=', 'archive')->count());
    }

    public function testTheArchiveTargetMakesAValidCacheKey(): void
    {
        foreach (['', 'tenant:tnta:'] as $prefix) {
            $key = LayoutResolver::cacheKey($prefix, 'archive', 'post:categories');
            self::assertFalse(strpbrk($key, '{}()/\\@'), $key);
            self::assertStringStartsWith($prefix . 'thallo:layout:archive:', $key);
        }
    }

    /** @param list<string> $permissions */
    private function grant(array $permissions): void
    {
        $this->permissions()->setProvider(new InMemoryPermissionProvider([$this->userUuid => $permissions]));
    }

    /** @param array<string,mixed>|null $body */
    private function call(string $method, string $path, ?array $body = null): Response
    {
        $key = ApiKeyService::create($this->appContext(), [
            'user_uuid' => $this->userUuid, 'name' => 'listing-layouts-test', 'scopes' => ['*'],
        ])['plain'];
        return $this->handle(Request::create($path, $method, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_API_KEY' => $key,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $key,
        ], $body === null ? null : (string) json_encode($body)));
    }

    /** @return array<string,mixed> */
    private static function data(Response $response): array
    {
        return json_decode((string) $response->getContent(), true)['data'];
    }

    private function permissions(): PermissionManager
    {
        /** @var PermissionManager $manager */
        $manager = $this->container()->get('permission.manager');
        return $manager;
    }

    /** This test's own user and keys, removed for good (a plain delete soft-deletes a user). */
    private function purgeAuthFixtures(): void
    {
        if ($this->userUuid === '') {
            return;
        }
        $this->connection()->table('api_keys')->where('user_uuid', '=', $this->userUuid)->forceDelete();
        $this->connection()->table('users')->where('uuid', '=', $this->userUuid)->forceDelete();
    }
}
