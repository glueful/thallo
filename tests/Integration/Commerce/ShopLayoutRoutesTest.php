<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Commerce;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Helpers\Utils;
use Glueful\Permissions\PermissionManager;
use Glueful\Testing\InMemoryPermissionProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\ShopPageSeed;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * The shop layouts' admin routes through the real kernel (type layouts plan C2, S4): the shop home's
 * and the category pages' `@site` target, percent-encoded as the admin's client sends it and decoded
 * as a proxy may pass it, behind `auth` and the `templates.manage` gate. Session, samples, the
 * Layouts list, Save and Remove answer an editor; a caller without the permission is refused before
 * any of them runs.
 */
final class ShopLayoutRoutesTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private ShopPageSeed $seed;

    /** @var array<string,?string> */
    private array $previousTenant = [];

    private string $userUuid = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $this->syncBlockStyleDeclarations();
        $this->seed = new ShopPageSeed($this->container(), $this->appContext());
        $this->seed->clear();
        $this->previousTenant = $this->seed->useTenant();
        $this->purgeAuthFixtures();
        $this->userUuid = Utils::generateNanoID();
        $this->connection()->table('users')->insert([
            'uuid' => $this->userUuid,
            'username' => 'layouts_' . substr($this->userUuid, 0, 6),
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
        $this->seed->clear();
        $this->seed->restoreTenant($this->previousTenant);
        foreach (['shop_index', 'shop_category'] as $surface) {
            $this->container()->get(LayoutResolver::class)->forget($surface, '@site');
        }
        parent::tearDown();
    }

    public function testAnEditorOpensSavesAndRemovesTheShopLayoutsOverHttp(): void
    {
        // Seeded first: the grant replaces the permission provider the seed consults.
        $seeded = $this->seed->seed();
        $this->grant(['content.view', 'templates.manage']);

        foreach (['shop_index' => ['Page 1'], 'shop_category' => ['Mugs', 'Bowls']] as $surface => $labels) {
            $session = $this->call('POST', '/v1/admin/layouts/preview/session', [
                'surface' => $surface, 'target' => '@site',
            ]);
            self::assertSame(200, $session->getStatusCode(), (string) $session->getContent());
            $token = self::data($session)['token'];
            self::assertSame($labels[0], self::data($session)['sample']['label']);

            $samples = $this->call('GET', "/v1/admin/layouts/{$surface}/%40site/samples");
            self::assertSame(200, $samples->getStatusCode(), (string) $samples->getContent());
            self::assertSame($labels, array_column(self::data($samples)['samples'], 'label'));

            $blocks = [
                ['id' => 'routemark001', 'type' => 'heading', 'data' => ['text' => 'ROUTED'], 'settings' => []],
                ['id' => 'routeloop001', 'type' => 'product_loop', 'data' => ['card' => []], 'settings' => []],
            ];
            $saved = $this->call('PUT', "/v1/admin/layouts/{$surface}/%40site", [
                'token' => $token, 'layout' => ['blocks' => $blocks, 'settings' => []], 'expected_lock_version' => 0,
            ]);
            self::assertSame(200, $saved->getStatusCode(), (string) $saved->getContent());
            self::assertSame(1, self::data($saved)['layout']['lock_version']);

            // Decoded by a proxy on the way in: the same route, the same layout.
            $again = $this->call('PUT', "/v1/admin/layouts/{$surface}/@site", [
                'token' => self::data($this->call('POST', '/v1/admin/layouts/preview/session', [
                    'surface' => $surface, 'target' => '@site',
                ]))['token'],
                'layout' => ['blocks' => $blocks, 'settings' => []], 'expected_lock_version' => 1,
            ]);
            self::assertSame(200, $again->getStatusCode(), (string) $again->getContent());
            self::assertSame(2, self::data($again)['layout']['lock_version']);

            $listed = array_values(array_filter(
                self::data($this->call('GET', '/v1/admin/layouts'))['layouts'],
                static fn (array $row): bool => $row['surface'] === $surface,
            ));
            self::assertSame(
                ['@site', 'custom', true],
                [$listed[0]['target'], $listed[0]['state'], $listed[0]['enabled']],
            );

            $removed = $this->call('DELETE', "/v1/admin/layouts/{$surface}/%40site", [
                'token' => $token, 'expected_lock_version' => 2,
            ]);
            self::assertSame(200, $removed->getStatusCode(), (string) $removed->getContent());
            self::assertSame(3, self::data($removed)['lock_version']);
        }
        self::assertArrayHasKey('mugs', $seeded['categories']);
    }

    public function testWithoutTemplatesManageEveryWriteIsRefused(): void
    {
        $this->grant(['content.view']);
        foreach (['shop_index', 'shop_category'] as $surface) {
            $session = $this->call('POST', '/v1/admin/layouts/preview/session', [
                'surface' => $surface, 'target' => '@site',
            ]);
            self::assertSame(403, $session->getStatusCode(), (string) $session->getContent());
            $saved = $this->call('PUT', "/v1/admin/layouts/{$surface}/%40site", [
                'token' => 'x', 'layout' => ['blocks' => [], 'settings' => []], 'expected_lock_version' => 0,
            ]);
            self::assertSame(403, $saved->getStatusCode(), (string) $saved->getContent());
            self::assertSame(0, $this->connection()->table('layouts')->where('surface', '=', $surface)->count());
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
            'user_uuid' => $this->userUuid, 'name' => 'layouts-test', 'scopes' => ['*'],
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
