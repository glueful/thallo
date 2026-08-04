<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Content\Authorization\CapabilityCatalog;
use App\Content\Authorization\EffectiveRoleMatrix;
use App\Content\Authorization\OperatorBypass;
use App\Content\Authorization\AuthenticatedPrincipalResolver;
use App\Content\Authorization\PermissionAuthority;
use App\Content\Authorization\TenantMembershipRoleReader;
use App\Content\Authorization\TenantRoleOverrideRepository;
use App\Http\Controllers\TenancyAccessController;
use App\Tests\Support\AppTestCase;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

/**
 * Task 14 (Phase C, workspace self-serve checkout plan): the `billing.manage` workspace
 * capability and its `manage_billing` tenancy-access flag (spec §5.1).
 *
 * `tenancy.manage`/`tenancy.access_any` (platform) and `billing.manage` (workspace) are
 * disjoint authorities (spec §1): {@see OperatorBypass}'s CAPABILITY_MAP maps only the three
 * `tenant.{members,domains,roles}.manage` capabilities onto `tenancy.manage` under operator
 * mode -- `billing.manage` is deliberately absent from that map, so a platform-only operator
 * (holding `tenancy.manage` + `tenancy.access_any` but no membership/delegation in the target
 * workspace) falls through to a literal `billing.manage` permission check that no role grants,
 * with or without the operator-mode header.
 *
 * The access-endpoint cases build {@see TenancyAccessController} directly from the SHARED
 * `AppTestCase` boot's real `PermissionAuthority`/`EffectiveRoleMatrix`/`OperatorBypass` (all
 * process-wired, real Aegis + real role-matrix/override storage against the app_test DB --
 * the same idiom {@see \App\Tests\Integration\Commerce\AdminAuthorizationMatrixTest} and
 * {@see \App\Tests\Integration\Subscriptions\CapabilityEngineTruthTableTest} use), paired with
 * a hand-built {@see TenantMembershipRoleReader} fed a fake {@see CurrentTenantResolver} fixed
 * to a per-test synthetic tenant uuid -- mirroring
 * {@see \App\Tests\Unit\Tenancy\Authorization\TenantMembershipRoleReaderTest}'s established
 * convention. This avoids the heavier, opt-in `THALLO_TENANCY_DEV_LINK=1` retrofit harness
 * ({@see \App\Tests\Support\RetrofittedTenantTestCase}) entirely -- that harness is skipped by
 * default and unnecessary here since nothing under test needs real query-scoping enforcement.
 */
final class BillingManageCapabilityTest extends AppTestCase
{
    /** @var list<string> */
    private array $tenantUuids = [];
    /** @var list<string> */
    private array $userUuids = [];
    /** @var list<string> */
    private array $roleUuids = [];

    protected function tearDown(): void
    {
        $db = $this->connection();
        if ($this->tenantUuids !== []) {
            $db->table('tenant_memberships')->whereIn('tenant_uuid', $this->tenantUuids)->forceDelete();
            $db->table('tenant_role_overrides')->whereIn('tenant_uuid', $this->tenantUuids)->forceDelete();
            $db->table('tenant_role_policy')->whereIn('tenant_uuid', $this->tenantUuids)->forceDelete();
            $db->table('tenants')->whereIn('uuid', $this->tenantUuids)->forceDelete();
        }
        if ($this->userUuids !== []) {
            $db->table('user_roles')->whereIn('user_uuid', $this->userUuids)->forceDelete();
        }
        if ($this->roleUuids !== []) {
            $db->table('role_permissions')->whereIn('role_uuid', $this->roleUuids)->forceDelete();
            $db->table('roles')->whereIn('uuid', $this->roleUuids)->forceDelete();
        }
        $this->tenantUuids = [];
        $this->userUuids = [];
        $this->roleUuids = [];
        $this->provider()->invalidateAllCache();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Catalog + role matrix
    // ------------------------------------------------------------------

    public function testCatalogDeclaresBillingManageAsGrantable(): void
    {
        $catalog = new CapabilityCatalog();

        self::assertTrue($catalog->has('billing.manage'));
        self::assertTrue($catalog->isGrantable('billing.manage'));
    }

    public function testOwnerRoleMatrixGrantsBillingManage(): void
    {
        $matrix = (array) config($this->appContext(), 'tenancy.role_matrix', []);

        self::assertContains('billing.manage', (array) ($matrix['owner'] ?? []));
    }

    public function testPlainMemberRoleMatrixDoesNotGrantBillingManageByDefault(): void
    {
        $matrix = (array) config($this->appContext(), 'tenancy.role_matrix', []);

        self::assertNotContains('billing.manage', (array) ($matrix['member'] ?? []));
    }

    public function testPlatformOnlyCapabilitiesAreUntouchedByTheNewGrantableCapability(): void
    {
        $catalog = new CapabilityCatalog();

        self::assertFalse($catalog->has('tenancy.manage'));
        self::assertFalse($catalog->has('tenancy.access_any'));
    }

    public function testDelegatedWorkspaceRoleReceivesBillingManage(): void
    {
        $tenantUuid = $this->seedTenant();
        $repository = $this->container()->get(TenantRoleOverrideRepository::class);
        $matrix = $this->container()->get(EffectiveRoleMatrix::class);
        self::assertInstanceOf(TenantRoleOverrideRepository::class, $repository);
        self::assertInstanceOf(EffectiveRoleMatrix::class, $matrix);

        self::assertFalse($matrix->allows($tenantUuid, 'member', 'billing.manage'));

        $this->connection()->transaction(fn () => $repository->reconcileRoleOverridesInTransaction(
            $tenantUuid,
            'member',
            ['billing.manage'],
            [],
            null,
        ));

        self::assertTrue($matrix->allows($tenantUuid, 'member', 'billing.manage'));
        // The delegation is scoped to the tenant it was granted in.
        self::assertFalse($matrix->allows($this->seedTenant(), 'member', 'billing.manage'));
    }

    // ------------------------------------------------------------------
    // Access endpoint
    // ------------------------------------------------------------------

    public function testAccessEndpointGrantsBillingManageToOwnerAndDelegateButNotAPlainMember(): void
    {
        $tenantUuid = $this->seedTenant();
        $owner = Utils::generateNanoID(12);
        $member = Utils::generateNanoID(12);
        $delegate = Utils::generateNanoID(12);
        $this->membership($tenantUuid, $owner, 'owner');
        $this->membership($tenantUuid, $member, 'member');
        $this->membership($tenantUuid, $delegate, 'viewer');

        $repository = $this->container()->get(TenantRoleOverrideRepository::class);
        self::assertInstanceOf(TenantRoleOverrideRepository::class, $repository);
        $this->connection()->transaction(fn () => $repository->reconcileRoleOverridesInTransaction(
            $tenantUuid,
            'viewer',
            ['billing.manage'],
            [],
            null,
        ));

        self::assertTrue(
            $this->accessFor($tenantUuid, $owner)['manage_billing'],
            'owner must receive manage_billing',
        );
        self::assertFalse(
            $this->accessFor($tenantUuid, $member)['manage_billing'],
            'a plain member must not',
        );
        self::assertTrue(
            $this->accessFor($tenantUuid, $delegate)['manage_billing'],
            'a workspace role delegated billing.manage must receive it',
        );
    }

    public function testAccessEndpointNeverGrantsBillingManageToAPlatformOnlyOperator(): void
    {
        $tenantUuid = $this->seedTenant();
        $operator = $this->platformOperatorUser();

        self::assertFalse(
            $this->accessFor($tenantUuid, $operator, operatorMode: false)['manage_billing'],
            'a platform-only operator without a workspace grant must not receive manage_billing',
        );
        self::assertFalse(
            $this->accessFor($tenantUuid, $operator, operatorMode: true)['manage_billing'],
            'operator mode must not bridge tenancy.manage into billing.manage -- the two authorities'
                . ' are disjoint',
        );
        // Sanity: the SAME operator DOES receive the platform-level flags -- proving nothing else
        // regressed, and the disjointness is specific to billing.manage, not a blanket denial.
        self::assertTrue($this->accessFor($tenantUuid, $operator)['manage_platform']);
        self::assertTrue($this->accessFor($tenantUuid, $operator)['access_any']);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** @return array<string,bool> */
    private function accessFor(string $tenantUuid, string $userUuid, bool $operatorMode = false): array
    {
        $controller = new TenancyAccessController(
            new AuthenticatedPrincipalResolver(),
            $this->container()->get(PermissionAuthority::class),
            $this->container()->get(EffectiveRoleMatrix::class),
            new TenantMembershipRoleReader($this->appContext(), $this->fixedResolver($tenantUuid)),
            $this->container()->get(OperatorBypass::class),
        );

        $request = Request::create('/v1/admin/tenancy/access');
        $request->attributes->set('auth.user', new UserIdentity(uuid: $userUuid));
        if ($operatorMode) {
            $request->headers->set('X-Tenant-Operator-Mode', '1');
        }

        $response = $controller->access($request);
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return (array) $body['data']['access'];
    }

    private function fixedResolver(string $tenantUuid): CurrentTenantResolver
    {
        return new class ($tenantUuid) implements CurrentTenantResolver {
            public function __construct(private readonly string $uuid)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->uuid;
            }
        };
    }

    private function seedTenant(): string
    {
        $tenantUuid = Utils::generateNanoID(12);
        $this->tenantUuids[] = $tenantUuid;
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('tenants')->insert([
            'uuid' => $tenantUuid,
            'slug' => $tenantUuid,
            'name' => $tenantUuid,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $tenantUuid;
    }

    private function membership(string $tenantUuid, string $userUuid, string $role): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('tenant_memberships')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'user_uuid' => $userUuid,
            'role' => $role,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** A real Aegis grant of the two GLOBAL platform capabilities, never `billing.manage` itself. */
    private function platformOperatorUser(): string
    {
        $userUuid = Utils::generateNanoID(12);
        $this->userUuids[] = $userUuid;

        $roleSlug = 'billingmx_' . strtolower(Utils::generateNanoID(6));
        $roleUuid = Utils::generateNanoID(12);
        $this->roleUuids[] = $roleUuid;
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid,
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'description' => 'billing.manage disjointness test role',
            'level' => 90,
            'is_system' => false,
            'status' => 'active',
        ]);

        $permissions = new PermissionRepository($this->connection());
        $rolePermissions = new RolePermissionRepository($this->connection());
        foreach (['tenancy.manage', 'tenancy.access_any'] as $slug) {
            $permission = $permissions->findPermissionBySlug($slug);
            self::assertNotNull($permission, "permission {$slug} must exist (seeded by migration 013)");
            $rolePermissions->assignPermissionToRole($roleUuid, $permission->getUuid(), []);
        }

        self::assertTrue($this->provider()->assignRole($userUuid, $roleSlug));
        $this->provider()->invalidateAllCache();

        return $userUuid;
    }

    private function provider(): AegisPermissionProvider
    {
        return $this->container()->get(AegisPermissionProvider::class);
    }
}
