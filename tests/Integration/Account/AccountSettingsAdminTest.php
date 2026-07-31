<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Content\Http\RequirePermission;
use App\Settings\SettingsStore;
use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Account\AccountReturnPath;
use Thallo\Account\Http\AccountSettingsController;
use Thallo\Account\Http\DTOs\UpdateAccountSettingsData;
use Thallo\Account\Settings\AccountSettingsStore;
use Thallo\Contracts\Account\AccountNavigationRegistry;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Delivery\PublishedPageDirectory;

/**
 * The admin API for account settings (public-account-surface plan Task 4): capability-gated route
 * load, the `content.manage` authorization gate, and the controller's redirect validation +
 * persistence. Gate decisions are driven against the REAL `content_permission` middleware resolved
 * from the container (the same instance the router uses), matching this codebase's established
 * admin-authorization-matrix convention.
 */
final class AccountSettingsAdminTest extends AppTestCase
{
    private const ROUTE = '/v1/admin/settings/accounts';

    /** @var list<string> */
    private array $userUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];

    protected function tearDown(): void
    {
        $db = $this->connection();
        if ($this->userUuids !== []) {
            $db->table('user_roles')->whereIn('user_uuid', $this->userUuids)->forceDelete();
        }
        if ($this->roleUuids !== []) {
            $db->table('role_permissions')->whereIn('role_uuid', $this->roleUuids)->forceDelete();
            $db->table('roles')->whereIn('uuid', $this->roleUuids)->forceDelete();
        }
        $this->provider()->invalidateAllCache();
        parent::tearDown();
    }

    // --- Structural: registered, gated by content.manage + the admin posture ------------------

    public function testBothMethodsAreRegisteredWithContentManageAndAdminBinding(): void
    {
        foreach (['GET', 'PUT'] as $method) {
            $route = $this->findRoute($method, self::ROUTE);
            self::assertNotNull($route, "{$method} " . self::ROUTE . ' must be registered');
            $mw = array_map('strval', (array) ($route['middleware'] ?? []));
            self::assertContains('content_permission:content.manage', $mw, "{$method} needs content.manage");
            self::assertContains('auth', $mw);
            self::assertContains('admin_tenant_binding', $mw);
        }
    }

    public function testTheRouteIsAbsentWhenTheCapabilityIsOff(): void
    {
        $off = self::bootAppWithConfigOverride('thallo', ['capabilities' => ['thallo.accounts' => false]]);

        $response = (new Application($off))->handle(
            Request::create(self::ROUTE, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
        );

        self::assertSame(404, $response->getStatusCode(), 'capability off must remove the admin route (404, not 401)');
    }

    // --- Gate: auth + content.manage ----------------------------------------------------------

    public function testUnauthenticatedIsRejectedWith401(): void
    {
        $response = $this->handle(
            Request::create(self::ROUTE, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testContentManageGatesTheRoute(): void
    {
        self::assertFalse(
            $this->passes($this->userRequest($this->userGranted([]))),
            'a principal without content.manage is denied',
        );
        self::assertTrue(
            $this->passes($this->userRequest($this->userGranted(['content.manage']))),
            'content.manage reaches the controller',
        );
    }

    // --- Controller: the page inventory + redirect validation/persistence ---------------------

    public function testShowReturnsTheAllowlistedPageInventory(): void
    {
        $data = $this->data($this->controller()->show());

        $paths = array_column($data['pages'], 'path');
        self::assertContains('/account/login', $paths);
        self::assertContains('/account', $paths);
        self::assertArrayHasKey('after_login', $data);
        self::assertArrayHasKey('after_logout', $data);
    }

    public function testSuggestionsAreCuratedPerFieldAndExcludeAuthActionPages(): void
    {
        $data = $this->data($this->controller()->show());
        $login = array_column($data['suggestions']['after_login'], 'path');
        $logout = array_column($data['suggestions']['after_logout'], 'path');

        // after_login: the account dashboard (+ any enabled account sections, none in this env).
        self::assertContains('/account', $login);
        // after_logout: home + sign-in only — the visitor is anonymous, no account sections.
        self::assertSame(['/', '/account/login'], $logout);
        // Transitional auth-action pages are never suggested (no redirect loops / dead flows).
        foreach (['/account/register', '/account/verify', '/account/forgot-password', '/account/logout'] as $bad) {
            self::assertNotContains($bad, $login, "{$bad} must not be an after-login suggestion");
            self::assertNotContains($bad, $logout, "{$bad} must not be an after-logout suggestion");
        }
    }

    public function testPublishedPagesAreAppendedToBothRedirectFields(): void
    {
        // A stand-in delivery layer offering one published page — proves the controller merges it
        // into BOTH after-login and after-logout suggestions (a custom landing/thank-you page).
        $pages = new class implements PublishedPageDirectory {
            /** @return list<array{label: string, path: string}> */
            public function publicPages(): array
            {
                return [['label' => '/pricing', 'path' => '/pricing']];
            }
        };
        $controller = new AccountSettingsController(
            $this->container()->get(AccountSettingsStore::class),
            $this->container()->get(AccountReturnPath::class),
            $this->container()->get(AccountNavigationRegistry::class),
            $this->container()->get(CapabilityRegistry::class),
            $pages,
        );

        $data = $this->data($controller->show());
        self::assertContains('/pricing', array_column($data['suggestions']['after_login'], 'path'));
        self::assertContains('/pricing', array_column($data['suggestions']['after_logout'], 'path'));
    }

    public function testUpdateRejectsAHostileRedirectWith422AndDoesNotMutate(): void
    {
        $before = $this->store()->afterLogin();

        $response = $this->controller()->update(new UpdateAccountSettingsData(after_login: '//evil.example'));

        self::assertSame(422, $response->getStatusCode());
        $this->settings()->clearCache();
        self::assertSame($before, $this->store()->afterLogin(), 'a rejected write must not mutate state');
    }

    public function testUpdatePersistsValidRedirectsAndClearsBlanks(): void
    {
        $ok = $this->controller()->update(
            new UpdateAccountSettingsData(after_login: '/account/orders', after_logout: '/bye'),
        );
        self::assertSame(200, $ok->getStatusCode());
        $this->settings()->clearCache();
        self::assertSame('/account/orders', $this->store()->afterLogin());
        self::assertSame('/bye', $this->store()->afterLogout());

        // A blank field clears ONLY that override; the other is untouched.
        $this->controller()->update(new UpdateAccountSettingsData(after_login: '', after_logout: '/bye'));
        $this->settings()->clearCache();
        self::assertNull($this->store()->afterLogin(), 'blank clears the override');
        self::assertSame('/bye', $this->store()->afterLogout());
    }

    // --- Drivers (trimmed from AdminAuthorizationMatrixTest) -----------------------------------

    private function controller(): AccountSettingsController
    {
        return $this->container()->get(AccountSettingsController::class);
    }

    private function store(): AccountSettingsStore
    {
        return $this->container()->get(AccountSettingsStore::class);
    }

    private function settings(): SettingsStore
    {
        return $this->container()->get(SettingsStore::class);
    }

    /** @return array<string,mixed> */
    private function data(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['data'] ?? null);

        return $decoded['data'];
    }

    private function passes(Request $request): bool
    {
        $middleware = $this->container()->get('content_permission');
        self::assertInstanceOf(RequirePermission::class, $middleware);

        $reached = false;
        $response = $middleware->handle($request, function (Request $r) use (&$reached): Response {
            $reached = true;

            return Response::success(['ok' => true], 'ok');
        }, 'content.manage');
        if (!$reached) {
            self::assertSame(403, $response->getStatusCode());
        }

        return $reached;
    }

    private function userRequest(string $userUuid): Request
    {
        $request = Request::create(self::ROUTE, 'GET');
        $request->attributes->set('user', ['uuid' => $userUuid, 'roles' => [], 'claims' => ['scopes' => []]]);

        return $request;
    }

    /** @param list<string> $permissionSlugs */
    private function userGranted(array $permissionSlugs): string
    {
        $userUuid = Utils::generateNanoID(12);
        $this->userUuids[] = $userUuid;
        if ($permissionSlugs !== []) {
            $this->grantRole($userUuid, $permissionSlugs);
        }
        $this->provider()->invalidateAllCache();

        return $userUuid;
    }

    /** @param list<string> $permissionSlugs */
    private function grantRole(string $userUuid, array $permissionSlugs): void
    {
        $roleSlug = 'acctset_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'account settings test role',
            'level' => 30,
            'is_system' => false,
            'status' => 'active',
        ]);

        $permissions = new PermissionRepository($this->connection());
        $rolePermissions = new RolePermissionRepository($this->connection());
        foreach ($permissionSlugs as $slug) {
            $permission = $permissions->findPermissionBySlug($slug);
            self::assertNotNull($permission, "permission {$slug} must exist");
            $rolePermissions->assignPermissionToRole($roleUuid, $permission->getUuid(), []);
        }

        self::assertTrue($this->provider()->assignRole($userUuid, $roleSlug));
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }
}
