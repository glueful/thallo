<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Authorization\PermissionImplicationSource;
use App\Content\Authorization\PermissionRequirementAuthority;
use App\Content\Http\RequirePermission;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * The any-of candidate contract (spec §4.2): a route may require a permission satisfiable
 * by ANY of the expanded satisfiers; API-key requests must satisfy the SAME required
 * candidate with both a key scope and a live RBAC grant (candidate-wise intersection —
 * unrelated alternatives can never cross-match). Implications are declarative data from a
 * PermissionImplicationSource; identity when no source is bound.
 *
 * Integration-level with real seeded Aegis RBAC: PermissionAuthority and
 * AuthenticatedPrincipalResolver are final (unmockable) — same convention as
 * TenantAuthorizationTruthTableTest / LocaleRbacApiTest.
 */
final class RequirePermissionAnyOfTest extends AppTestCase
{
    public const P_VIEW = 'anyoftest.view';
    public const P_MANAGE = 'anyoftest.manage';
    public const P_A = 'anyoftest.alpha';
    public const P_B = 'anyoftest.beta';

    /** @var list<string> */
    private array $userUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];

    protected function tearDown(): void
    {
        // Roles/assignments are per-test (unique slugs); the namespaced anyoftest.*
        // PERMISSION rows deliberately persist for the class — Aegis caches permission
        // lookups by slug, so delete+reinsert between tests would hand role_permissions
        // a stale uuid (FK violation). Nothing outside this class grants them.
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

    // -- the contract ---------------------------------------------------------------

    public function testJwtImplicationGrantSatisfiesViewRequirement(): void
    {
        $user = $this->userGranted([self::P_MANAGE]);

        self::assertTrue(
            $this->passes($this->jwtRequest($user), [self::P_VIEW]),
            'manage grant must satisfy a view requirement through the implication source',
        );
    }

    public function testJwtWithNeitherGrantIsForbidden(): void
    {
        $user = $this->userGranted([]);

        self::assertFalse($this->passes($this->jwtRequest($user), [self::P_VIEW, self::P_MANAGE]));
    }

    public function testApiKeyScopeAndRbacMaySatisfyViaDifferentImpliedGrants(): void
    {
        // Scope carries view directly; RBAC holds manage (satisfies view via implication).
        $user = $this->userGranted([self::P_MANAGE]);
        self::assertTrue($this->passes($this->apiKeyRequest($user, [self::P_VIEW]), [self::P_VIEW]));

        // Inverse: scope carries manage (satisfies view via implication); RBAC holds view.
        $other = $this->userGranted([self::P_VIEW]);
        self::assertTrue($this->passes($this->apiKeyRequest($other, [self::P_MANAGE]), [self::P_VIEW]));
    }

    public function testApiKeyViewOnlyIsDeniedForManageRequirement(): void
    {
        $user = $this->userGranted([self::P_VIEW]);

        self::assertFalse($this->passes($this->apiKeyRequest($user, [self::P_VIEW]), [self::P_MANAGE]));
    }

    public function testUnrelatedAlternativesCannotCrossMatch(): void
    {
        // Key scope satisfies only alpha; RBAC satisfies only beta — no single required
        // candidate is satisfied by BOTH factors, so the request is denied.
        $user = $this->userGranted([self::P_B]);

        self::assertFalse($this->passes($this->apiKeyRequest($user, [self::P_A]), [self::P_A, self::P_B]));
    }

    public function testApiKeyWithEmptyScopesIsDeniedDespiteRbac(): void
    {
        $user = $this->userGranted([self::P_MANAGE]);

        self::assertFalse($this->passes($this->apiKeyRequest($user, []), [self::P_VIEW]));
    }

    public function testWildcardScopeSatisfiesCandidates(): void
    {
        $user = $this->userGranted([self::P_MANAGE]);

        self::assertTrue($this->passes($this->apiKeyRequest($user, ['anyoftest.*']), [self::P_VIEW]));
    }

    public function testEmptyRequirementListIsForbidden(): void
    {
        $user = $this->userGranted([self::P_MANAGE]);

        self::assertFalse($this->passes($this->jwtRequest($user), []));
    }

    // -- drivers --------------------------------------------------------------------

    /** @param list<string> $requirements */
    private function passes(Request $request, array $requirements): bool
    {
        $authority = new PermissionRequirementAuthority($this->appContext(), $this->implications());
        $middleware = new RequirePermission($this->appContext(), $authority);

        $reached = false;
        $response = $middleware->handle(
            $request,
            function (Request $r) use (&$reached): Response {
                $reached = true;
                return Response::success(['ok' => true], 'ok');
            },
            ...$requirements,
        );
        if (!$reached) {
            self::assertSame(403, $response->getStatusCode());
        }

        return $reached;
    }

    private function implications(): PermissionImplicationSource
    {
        return new class implements PermissionImplicationSource {
            public function satisfiersFor(string $required): array
            {
                return $required === RequirePermissionAnyOfTest::P_VIEW
                    ? [RequirePermissionAnyOfTest::P_VIEW, RequirePermissionAnyOfTest::P_MANAGE]
                    : [$required];
            }
        };
    }

    private function jwtRequest(string $userUuid): Request
    {
        $request = Request::create('/v1/admin/commerce/products', 'GET');
        $request->attributes->set('user', ['uuid' => $userUuid, 'roles' => [], 'scopes' => []]);

        return $request;
    }

    /** @param list<string> $scopes */
    private function apiKeyRequest(string $userUuid, array $scopes): Request
    {
        $request = $this->jwtRequest($userUuid);
        $request->attributes->set('auth_method', 'api_key');
        $request->attributes->set('api_key_scopes', $scopes);

        return $request;
    }

    // -- seeding --------------------------------------------------------------------

    /** @param list<string> $grantedPermissionSlugs */
    private function userGranted(array $grantedPermissionSlugs): string
    {
        $userUuid = Utils::generateNanoID(12);
        $this->userUuids[] = $userUuid;

        foreach ([self::P_VIEW, self::P_MANAGE, self::P_A, self::P_B] as $slug) {
            $this->ensurePermission($slug);
        }

        if ($grantedPermissionSlugs !== []) {
            $roleSlug = 'anyof_' . strtolower(Utils::generateNanoID(6));
            $roleUuid = Utils::generateNanoID(12);
            $this->roleUuids[] = $roleUuid;
            $this->connection()->table('roles')->insert([
                'uuid' => $roleUuid,
                'name' => $roleSlug,
                'slug' => $roleSlug,
                'description' => 'any-of contract test role',
                'level' => 30,
                'is_system' => false,
                'status' => 'active',
            ]);

            $permissions = new PermissionRepository($this->connection());
            $rolePermissions = new RolePermissionRepository($this->connection());
            foreach ($grantedPermissionSlugs as $slug) {
                $permission = $permissions->findPermissionBySlug($slug);
                self::assertNotNull($permission, "test permission {$slug} must exist");
                $rolePermissions->assignPermissionToRole($roleUuid, $permission->getUuid(), []);
            }

            self::assertTrue($this->provider()->assignRole($userUuid, $roleSlug));
        }

        $this->provider()->invalidateAllCache();

        return $userUuid;
    }

    private function ensurePermission(string $slug): void
    {
        $existing = $this->connection()->table('permissions')
            ->select(['uuid'])->where('slug', '=', $slug)->first();
        if ($existing !== null) {
            return;
        }
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('permissions')->insert([
            'uuid' => $uuid,
            'slug' => $slug,
            'name' => $slug,
            'description' => 'any-of contract test permission',
            'category' => 'anyoftest',
            'is_system' => false,
        ]);
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }
}
