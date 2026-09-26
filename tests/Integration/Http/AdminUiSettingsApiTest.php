<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Http;

use Glueful\Auth\PasswordHasher;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Http\Controllers\AccountAdminController;
use Thallo\Core\Http\Controllers\AdminUiSettingsController;
use Thallo\Core\Http\DTOs\UpdateAdminUiSettingsData;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The admin's menus and landing page, set per role and per user (Users & Access): which sidebar
 * items someone sees and where signing in takes them. Tidying only — a hidden page is still
 * theirs to open if their permissions allow it. A user's own setting beats their roles'; a role
 * only hides; an item is hidden when any of the user's roles hides it; the landing page is the
 * user's own, else the one set by their highest-level role that sets one.
 */
final class AdminUiSettingsApiTest extends AppTestCase
{
    /** @var list<string> */
    private array $users = [];
    /** @var list<string> */
    private array $roles = [];

    protected function tearDown(): void
    {
        // Users, roles and assignments are not truncated between tests: leave none behind.
        $db = $this->connection();
        foreach ($this->users as $uuid) {
            $db->table('user_roles')->where('user_uuid', '=', $uuid)->delete();
            $db->table('profiles')->where('user_uuid', '=', $uuid)->delete();
            $db->table('users')->where('uuid', '=', $uuid)->delete();
        }
        foreach ($this->roles as $uuid) {
            $db->table('roles')->where('uuid', '=', $uuid)->delete();
        }
        parent::tearDown();
    }

    private function makeUser(): string
    {
        $suffix = bin2hex(random_bytes(4));
        $uuid = $this->container()->get(UserRepository::class)->create([
            'username' => "ui{$suffix}",
            'email' => "ui{$suffix}@example.test",
            'password' => (new PasswordHasher())->hash('a-password-1'),
            'status' => 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);
        $this->users[] = $uuid;
        return $uuid;
    }

    private function makeRole(int $level): string
    {
        $uuid = substr('rl' . bin2hex(random_bytes(6)), 0, 12);
        $this->connection()->table('roles')->insert([
            'uuid' => $uuid,
            'name' => "Role {$uuid}",
            'slug' => "role-{$uuid}",
            'level' => $level,
            'status' => 'active',
        ]);
        $this->roles[] = $uuid;
        return $uuid;
    }

    private function assign(string $user, string $role, ?string $expiresAt = null): void
    {
        $this->connection()->table('user_roles')->insert([
            'uuid' => substr('ur' . bin2hex(random_bytes(6)), 0, 12),
            'user_uuid' => $user,
            'role_uuid' => $role,
            'expires_at' => $expiresAt,
        ]);
    }

    private function request(): Request
    {
        $request = Request::create('https://admin.test/v1/admin/ui-settings', 'PUT');
        $request->attributes->set('user', ['uuid' => 'manager00001']);
        return $request;
    }

    /** @param array<string,mixed> $body @return array{status: int, body: array<string,mixed>} */
    private function put(string $type, string $uuid, array $body): array
    {
        $dto = (new RequestDataHydrator())->hydrate(UpdateAdminUiSettingsData::class, $body);
        $resp = $this->container()->get(AdminUiSettingsController::class)->update($dto, $type, $uuid, $this->request());
        return ['status' => $resp->getStatusCode(), 'body' => json_decode((string) $resp->getContent(), true) ?? []];
    }

    /** @return array{status: int, body: array<string,mixed>} */
    private function get(string $type, string $uuid): array
    {
        $resp = $this->container()->get(AdminUiSettingsController::class)->show($type, $uuid);
        return ['status' => $resp->getStatusCode(), 'body' => json_decode((string) $resp->getContent(), true) ?? []];
    }

    /** @return array{hidden: list<string>, landing: ?string} what the user's own account says */
    private function ui(string $user): array
    {
        $request = Request::create('https://admin.test/v1/admin/account', 'GET');
        $request->attributes->set('user', ['uuid' => $user]);
        $resp = $this->container()->get(AccountAdminController::class)->show($request);
        $ui = (json_decode((string) $resp->getContent(), true) ?? [])['data']['ui'];
        sort($ui['hidden']);
        return $ui;
    }

    public function testNothingSetHidesNothingAndLandsHome(): void
    {
        self::assertSame(['hidden' => [], 'landing' => null], $this->ui($this->makeUser()));
    }

    public function testARoleHidesMenusAndSetsTheLandingPageForEveryoneWithIt(): void
    {
        $role = $this->makeRole(80);
        $alice = $this->makeUser();
        $bob = $this->makeUser();
        $this->assign($alice, $role);
        $this->assign($bob, $role);
        $saved = $this->put('roles', $role, [
            'menus' => ['/analytics' => 'hidden', '/templates' => 'hidden'],
            'landing' => '/content/page',
        ]);
        self::assertSame(200, $saved['status'], json_encode($saved['body']));

        foreach ([$alice, $bob] as $user) {
            self::assertSame(
                ['hidden' => ['/analytics', '/templates'], 'landing' => '/content/page'],
                $this->ui($user),
            );
        }
        $read = $this->get('roles', $role)['body']['data'];
        self::assertSame(['/analytics' => 'hidden', '/templates' => 'hidden'], $read['menus']);
        self::assertSame('/content/page', $read['landing']);
    }

    public function testAUsersOwnSettingBeatsTheirRoles(): void
    {
        $role = $this->makeRole(80);
        $user = $this->makeUser();
        $this->assign($user, $role);
        $this->put('roles', $role, ['menus' => ['/analytics' => 'hidden'], 'landing' => '/content/page']);
        $this->put('users', $user, ['menus' => ['/analytics' => 'shown', '/media' => 'hidden'], 'landing' => '/media']);

        self::assertSame(['hidden' => ['/media'], 'landing' => '/media'], $this->ui($user));
    }

    public function testWithSeveralRolesAnyHidesAndTheHighestRoleSetsTheLandingPage(): void
    {
        $admin = $this->makeRole(80);
        $editor = $this->makeRole(50);
        $user = $this->makeUser();
        $this->assign($user, $admin);
        $this->assign($user, $editor);
        $this->put('roles', $editor, ['menus' => ['/media' => 'hidden'], 'landing' => '/media']);
        $this->put('roles', $admin, ['menus' => ['/analytics' => 'hidden'], 'landing' => '/analytics']);

        self::assertSame(['hidden' => ['/analytics', '/media'], 'landing' => '/analytics'], $this->ui($user));

        // A role that sets no landing page leaves it to the next one down.
        $this->put('roles', $admin, ['menus' => ['/analytics' => 'hidden'], 'landing' => null]);
        self::assertSame('/media', $this->ui($user)['landing']);
    }

    public function testAnExpiredRoleNoLongerCounts(): void
    {
        $role = $this->makeRole(80);
        $user = $this->makeUser();
        $this->assign($user, $role, '2020-01-01 00:00:00');
        $this->put('roles', $role, ['menus' => ['/analytics' => 'hidden'], 'landing' => '/analytics']);

        self::assertSame(['hidden' => [], 'landing' => null], $this->ui($user));
    }

    public function testSavingReplacesAndNothingSetClearsTheRow(): void
    {
        $user = $this->makeUser();
        $this->put('users', $user, ['menus' => ['/media' => 'hidden'], 'landing' => '/media']);
        $this->put('users', $user, ['menus' => [], 'landing' => null]);
        self::assertSame(['hidden' => [], 'landing' => null], $this->ui($user));
        $rows = $this->connection()->table('admin_ui_settings')->where('subject_uuid', '=', $user)->count();
        self::assertSame(0, $rows);
        self::assertSame(['menus' => [], 'landing' => null], $this->get('users', $user)['body']['data']);
    }

    public function testWhatASaveRefuses(): void
    {
        $user = $this->makeUser();
        $bad = [
            ['menus' => ['analytics' => 'hidden']],
            ['menus' => ['//evil.example' => 'hidden']],
            ['menus' => ['/analytics' => 'gone']],
            ['menus' => ['/' . str_repeat('a', 255) => 'hidden']],
            ['landing' => 'https://evil.example'],
            ['landing' => '//evil.example'],
            ['landing' => '/login'],
        ];
        foreach ($bad as $body) {
            self::assertSame(422, $this->put('users', $user, $body + ['menus' => []])['status'], json_encode($body));
        }
        // A role only hides: showing is a user's own call.
        self::assertSame(422, $this->put('roles', $this->makeRole(10), ['menus' => ['/media' => 'shown']])['status']);
        self::assertSame(404, $this->put('users', 'nosuchuser01', ['menus' => []])['status']);
        self::assertSame(404, $this->put('roles', 'nosuchrole01', ['menus' => []])['status']);
        self::assertSame(404, $this->put('groups', $user, ['menus' => []])['status']);
        self::assertSame(404, $this->get('roles', 'nosuchrole01')['status']);
    }

    public function testARolesSettingsNeedRoleManagementAndAUsersNeedUserEditing(): void
    {
        foreach (['GET', 'PUT'] as $method) {
            $role = $this->findRoute($method, '/v1/admin/ui-settings/roles/{uuid}');
            self::assertNotNull($role, "{$method} roles");
            self::assertContains('content_permission:users.roles.manage', $role['middleware']);
            $user = $this->findRoute($method, '/v1/admin/ui-settings/users/{uuid}');
            self::assertNotNull($user, "{$method} users");
            self::assertContains('content_permission:users.edit', $user['middleware']);
        }
    }
}
