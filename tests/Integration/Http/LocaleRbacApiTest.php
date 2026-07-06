<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\RequirePermission;
use App\Tests\Support\AppTestCase;
use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Exercises per-locale authorization through the real Aegis provider. The test harness
 * cannot mint bearer JWTs, so it invokes the middleware directly with auth attributes and
 * real Aegis grants against the booted PermissionManager.
 */
final class LocaleRbacApiTest extends AppTestCase
{
    /** @var list<string> */
    private array $createdUserUuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createdUserUuids = [];
        $this->scrubAegisGrants();
    }

    protected function tearDown(): void
    {
        $this->scrubAegisGrants();
        parent::tearDown();
    }

    public function testGlobalRoleCanPublishAnyLocale(): void
    {
        $user = $this->newUser();
        self::assertTrue($this->provider()->assignRole($user, 'editor'));

        self::assertTrue($this->allows($user, 'content.publish', '/entries/{uuid}/publish/{locale}', [
            'locale' => 'fr',
        ]));
        self::assertTrue($this->allows($user, 'content.publish', '/entries/{uuid}/publish/{locale}', [
            'locale' => 'de',
        ]));
    }

    public function testLocaleScopedPublishAllowsTargetLocale(): void
    {
        $user = $this->newUser();
        $this->assignLocaleRole($user, 'editor_fr', ['content.publish'], 'fr');

        self::assertTrue($this->allows($user, 'content.publish', '/entries/{uuid}/publish/{locale}', [
            'locale' => 'fr',
        ]));
    }

    public function testLocaleScopedPublishDeniesOtherLocale(): void
    {
        $user = $this->newUser();
        $this->assignLocaleRole($user, 'editor_fr', ['content.publish'], 'fr');

        self::assertFalse($this->allows($user, 'content.publish', '/entries/{uuid}/publish/{locale}', [
            'locale' => 'de',
        ]));
    }

    public function testLocaleScopedReadAllowsOwnLocaleDraft(): void
    {
        $user = $this->newUser();
        $this->assignLocaleRole($user, 'editor_fr', ['content.view'], 'fr');

        self::assertTrue($this->allows($user, 'content.view', '/entries/{uuid}/draft/{locale}', [
            'locale' => 'fr',
        ]));
        self::assertFalse($this->allows($user, 'content.view', '/entries/{uuid}/draft/{locale}', [
            'locale' => 'de',
        ]));
    }

    public function testLocaleScopedReadCannotDiscoverCoarseInventory(): void
    {
        $user = $this->newUser();
        $this->assignLocaleRole($user, 'editor_fr', ['content.view'], 'fr');

        self::assertFalse($this->allows($user, 'content.view', '/entries/{uuid}/locales', []));
        self::assertFalse($this->allows($user, 'content.view', '/entries/{uuid}', []));
    }

    public function testCoarseReadRestoresDiscovery(): void
    {
        $user = $this->newUser();
        $this->assignLocaleRole($user, 'reader_global', ['content.view'], '*');

        self::assertTrue($this->allows($user, 'content.view', '/entries/{uuid}/locales', []));
        self::assertTrue($this->allows($user, 'content.view', '/entries/{uuid}', []));
    }

    public function testLocaleOnlyUserIsDeniedLocaleAgnosticDestroy(): void
    {
        $user = $this->newUser();
        $this->assignLocaleRole($user, 'editor_fr', ['content.edit'], 'fr');

        self::assertFalse($this->allows($user, 'content.edit', '/entries/{uuid}', []));
    }

    public function testCoarseUserCanDestroy(): void
    {
        $user = $this->newUser();
        self::assertTrue($this->provider()->assignRole($user, 'editor'));

        self::assertTrue($this->allows($user, 'content.edit', '/entries/{uuid}', []));
    }

    public function testGlobalGrantOverridesLocaleScopeOnOtherLocale(): void
    {
        $user = $this->newUser();
        self::assertTrue($this->provider()->assignRole($user, 'editor'));
        $this->assignLocaleRole($user, 'editor_fr', ['content.publish'], 'fr');

        self::assertTrue($this->allows($user, 'content.publish', '/entries/{uuid}/publish/{locale}', [
            'locale' => 'de',
        ]));
    }

    public function testNoGrantsStillDenies(): void
    {
        $user = $this->newUser();

        self::assertFalse($this->allows($user, 'content.publish', '/entries/{uuid}/publish/{locale}', [
            'locale' => 'fr',
        ]));
    }

    public function testRouterPopulatesLocaleRouteParamForRealRoutes(): void
    {
        $localeMatch = $this->router()->match(
            Request::create('/v1/admin/entries/abcd1234efgh/draft/fr', 'GET')
        );
        self::assertNotNull($localeMatch);
        self::assertSame('fr', $localeMatch['params']['locale'] ?? null);

        $agnosticMatch = $this->router()->match(
            Request::create('/v1/admin/entries/abcd1234efgh/locales', 'GET')
        );
        self::assertNotNull($agnosticMatch);
        self::assertArrayNotHasKey('locale', $agnosticMatch['params'] ?? []);
    }

    /** @param array<string,string> $routeParams */
    private function allows(string $userUuid, string $permission, string $path, array $routeParams): bool
    {
        $middleware = new RequirePermission($this->appContext());

        $request = Request::create($path, 'POST');
        $request->attributes->set('_route_params', $routeParams);
        $request->attributes->set('auth.user', new UserIdentity(
            uuid: $userUuid,
            roles: [],
            username: 'tester',
        ));

        $reached = false;
        $response = $middleware->handle($request, function (Request $request) use (&$reached): Response {
            $reached = true;
            return Response::success(['ok' => true], 'ok');
        }, $permission);

        if ($reached) {
            return true;
        }

        self::assertSame(403, $response->getStatusCode());
        return false;
    }

    private function newUser(): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->createdUserUuids[] = $uuid;
        return $uuid;
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }

    /** @param list<string> $permissionSlugs */
    private function assignLocaleRole(
        string $userUuid,
        string $roleSlug,
        array $permissionSlugs,
        string $resource,
    ): void {
        $roleUuid = $this->ensureRole($roleSlug);
        $permissions = new PermissionRepository($this->connection());
        $rolePermissions = new RolePermissionRepository($this->connection());

        foreach ($permissionSlugs as $slug) {
            $permission = $permissions->findPermissionBySlug($slug);
            self::assertNotNull($permission, "seeded permission {$slug} must exist");
            $options = $resource === '*' ? [] : ['resource_filter' => ['resource' => "locale:{$resource}"]];
            $rolePermissions->assignPermissionToRole($roleUuid, $permission->getUuid(), $options);
        }

        self::assertTrue($this->provider()->assignRole($userUuid, $roleSlug));
        $this->provider()->invalidateAllCache();
    }

    private function ensureRole(string $slug): string
    {
        $existing = $this->connection()->table('roles')
            ->select(['uuid'])
            ->where('slug', '=', $slug)
            ->first();
        if ($existing !== null) {
            return (string) $existing['uuid'];
        }

        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('roles')->insert([
            'uuid' => $uuid,
            'name' => $slug,
            'slug' => $slug,
            'description' => 'per-locale RBAC test role',
            'level' => 30,
            'is_system' => false,
            'status' => 'active',
        ]);

        return $uuid;
    }

    private function scrubAegisGrants(): void
    {
        $db = $this->connection();

        if ($this->createdUserUuids !== []) {
            $this->deleteWhereIn('user_roles', 'user_uuid', $this->createdUserUuids);
        }

        $testRoles = $db->table('roles')->select(['uuid'])
            ->whereIn('slug', ['editor_fr', 'editor_de', 'reader_global'])
            ->get();
        $roleUuids = array_map(static fn (array $row): string => (string) $row['uuid'], $testRoles);

        if ($roleUuids !== []) {
            $this->deleteWhereIn('role_permissions', 'role_uuid', $roleUuids);
            $this->deleteWhereIn('roles', 'uuid', $roleUuids);
        }

        $this->provider()->invalidateAllCache();
    }

    /** @param non-empty-list<string> $values */
    private function deleteWhereIn(string $table, string $column, array $values): void
    {
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $stmt = $this->connection()->getPDO()->prepare(
            sprintf('DELETE FROM %s WHERE %s IN (%s)', $table, $column, $placeholders)
        );
        $stmt->execute($values);
    }
}
