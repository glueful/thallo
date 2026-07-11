# Workspace-Manager Role Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split cross-workspace authority out of `administrator` into a dedicated, superuser-delegated `workspace_manager` role, with a superuser lifecycle, hardened role-assignment policy, authority-continuity protection, break-glass CLIs, and a server-derived role picker.

**Architecture:** No tenancy-request authorization code changes — all workspace access checks are already permission-based (`tenancy.manage`/`tenancy.access_any`). This slice is (1) a consolidated seed migration that moves those two permissions off `administrator` onto a new `workspace_manager` role (level 90) and `superuser`; (2) three support classes — `RoleAuthority` (canonical-superuser identity, active-holder counts, permission-subset), `AuthorityAudit` (best-effort security events), and `AuthorityContinuityGuard` (PostgreSQL advisory-lock + transaction wrapper that blocks removing the last superuser / last cross-workspace holder); (3) a hardened `UserRoleAssignmentPolicy`; (4) `superuser:grant` / `superuser:transfer` console commands; (5) a `GET /assignable-roles` endpoint and SPA picker. Full spec: `docs/superpowers/specs/multi-tenancy/2026-07-11-operator-role-design.md`.

**Tech Stack:** PHP 8.3, Glueful framework, Aegis RBAC (`glueful/aegis`), glueful/users, PostgreSQL (advisory locks), PHPUnit, Vue 3 + Nuxt UI (admin SPA).

## Global Constraints

- **Thallo-only.** No framework/extension/contracts edits. No release chain.
- **COMMITS HELD.** Implement and verify each task green, but do **NOT** run any `git commit` until the user gives explicit go-ahead. Each task's "Commit" step is written for completeness and staged only (`git add`), deferred to one batch at the end.
- **PostgreSQL-only.** Advisory locks and the continuity queries assume pgsql.
- **PHP style:** `declare(strict_types=1)`, `final` classes, constructor DI, `use`-imports (no inline FQCNs). `composer phpcs` must pass (120-char lines, warnings fail).
- **No AI/Anthropic attribution** anywhere.
- **Canonical role slugs:** `superuser` (100), `workspace_manager` (90, **new**), `administrator` (80), `editor` (50), `user` (10). Cross-workspace permissions: `tenancy.manage`, `tenancy.access_any`.
- **Superuser identity is role-bound, never level-derived:** a user is a superuser only if they hold the active `superuser` role.
- **`superuser` is API-immutable** through `UserRoleAssignmentPolicy` (neither add nor remove); it is mutated only by setup / `superuser:grant` / `superuser:transfer`.
- **"Active holder"** = user `status='active'` AND `deleted_at IS NULL`, role `status='active'` AND `deleted_at IS NULL`, assignment `expires_at IS NULL OR expires_at >= now`.
- **Audit is best-effort** (try/catch, never blocks) via
  `Glueful\Extensions\Audit\Contracts\AuditRecorderInterface::record()` and
  `Glueful\Extensions\Audit\Support\AuditEntry`.
- **Test harness:** integration tests extend `App\Tests\Support\AppTestCase`; use `$this->connection()` (a `Glueful\Database\Connection`), `$this->container()`, `Glueful\Helpers\Utils::generateNanoID(12)`. Tests rebuild the DB from scratch, so they exercise the consolidated migration naturally. Run one test: `vendor/bin/phpunit --filter=testName`.

---

## File Structure

**Create:**
- `database/dependent-migrations/013_CreateTenancyAuthorityRoles.php` — consolidated role model (replaces `013_GrantTenancyOperatorToAdministrator.php`).
- `app/Support/RoleAuthority.php` — read helper (canonical-superuser, active-holder counts, permission-subset, cross-workspace roles).
- `app/Support/AuthorityAudit.php` — best-effort audit emitter for authority events.
- `app/Support/AuthorityContinuityViolation.php` — internal violation DTO carried across rollback.
- `app/Support/AuthorityContinuityGuard.php` — advisory-lock + transaction wrapper + last-of-kind assertions.
- `app/Setup/Console/SuperuserGrantCommand.php` — `thallo:superuser:grant`.
- `app/Setup/Console/SuperuserTransferCommand.php` — `thallo:superuser:transfer`.
- `app/Http/Controllers/AssignableRolesController.php` — `GET /v1/admin/users/assignable-roles`.
- Tests under `tests/Integration/Authority/`.

**Modify:**
- `database/dependent-migrations/013_GrantTenancyOperatorToAdministrator.php` — **deleted** (replaced by the consolidated file).
- `app/Support/UserRoleAssignmentPolicy.php` — hardened rules + `mayAdd`/`mayRemove` public API + denial audit.
- `app/Http/Controllers/UserAdminController.php` — continuity guard in `update()`/`destroy()`; authorize-before-write; success audit.
- `app/Setup/SetupService.php` — install user gets `superuser` + `administrator`.
- `app/Providers/ThalloServiceProvider.php` — register `RoleAuthority`, `AuthorityAudit`, `AuthorityContinuityGuard`, `AssignableRolesController`, the two commands (services() + commands()).
- `routes/admin.php` — add the assignable-roles route.
- `admin/src/queries/users.ts` — add the app-owned assignable-role query.
- `admin/src/pages/users/components/UserCreateModal.vue` — create-mode assignable roles.
- `admin/src/pages/users/components/UserDetailsForm.vue` — edit-mode locked-role preservation.
- `admin/src/__tests__/userRolePicker.spec.ts` — picker/query acceptance coverage.

---

## Task 0: Local-only rollback gate — before migration 013 is renamed

**Files:** none (operational ordering gate).

The migration manager resolves rollback files by the original `(source, filename)` ledger pair. The
old `013_GrantTenancyOperatorToAdministrator.php` must therefore still exist when rollback runs.
This task executes before Task 1 deletes/renames it.

- [ ] **Step 1: Verify the local ledger and capture the current admin UUID**

```bash
php glueful migrate:status
```

Confirm `013_GrantTenancyOperatorToAdministrator.php` is the most recently applied migration and
record the current local administrator UUID for Task 11. If any migration follows 013, stop: do not
use `--steps=1` until the actual rollback order is reconciled.

- [ ] **Step 2: Roll back the old 013 while its file still exists**

```bash
php glueful migrate:rollback --steps=1
php glueful migrate:status
```

Verify the old 013 is pending/absent from the ledger and its `down()` removed the two tenancy grants
from `administrator`. This is local-development state only. Do not run against CI or any shared DB.

- [ ] **Step 3: Proceed directly to Task 1**

The local user temporarily has no cross-workspace grant until the consolidated migration and recovery
command are applied in Task 11. Do not rename/delete the old migration before this task completes.

---

## Task 1: Consolidated migration 013 — role model

**Files:**
- Create: `database/dependent-migrations/013_CreateTenancyAuthorityRoles.php`
- Delete: `database/dependent-migrations/013_GrantTenancyOperatorToAdministrator.php`
- Test: `tests/Integration/Authority/AuthorityRolesMigrationTest.php`

**Interfaces:**
- Produces: role `workspace_manager` (level 90) holding `tenancy.manage` + `tenancy.access_any`; `superuser` also holds both; `administrator` holds neither.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Tests\Support\AppTestCase;

final class AuthorityRolesMigrationTest extends AppTestCase
{
    /** @return list<string> permission slugs granted to the role slug */
    private function permsFor(string $roleSlug): array
    {
        $rows = $this->connection()->getPDO()->query(
            "SELECT p.slug FROM roles r
             JOIN role_permissions rp ON rp.role_uuid = r.uuid
             JOIN permissions p ON p.uuid = rp.permission_uuid
             WHERE r.slug = " . $this->connection()->getPDO()->quote($roleSlug)
        )->fetchAll(\PDO::FETCH_COLUMN);
        return array_values($rows);
    }

    public function testWorkspaceManagerRoleExistsAtLevel90WithTheTwoTenancyGrants(): void
    {
        $role = $this->connection()->table('roles')->where('slug', '=', 'workspace_manager')->first();
        self::assertIsArray($role, 'workspace_manager role must be seeded');
        self::assertSame(90, (int) $role['level']);

        $perms = $this->permsFor('workspace_manager');
        sort($perms);
        self::assertSame(['tenancy.access_any', 'tenancy.manage'], $perms);
    }

    public function testSuperuserHoldsTheTenancyGrantsAndAdministratorDoesNot(): void
    {
        self::assertContains('tenancy.access_any', $this->permsFor('superuser'));
        self::assertContains('tenancy.manage', $this->permsFor('superuser'));

        self::assertNotContains('tenancy.access_any', $this->permsFor('administrator'));
        self::assertNotContains('tenancy.manage', $this->permsFor('administrator'));
    }

    public function testUpIsIdempotentAndDownRestoresThePriorShape(): void
    {
        require_once dirname(__DIR__, 3)
            . '/database/dependent-migrations/013_CreateTenancyAuthorityRoles.php';
        $migration = new \CreateTenancyAuthorityRoles();
        $schema = $this->connection()->getSchemaBuilder();
        $pdo = $this->connection()->getPDO();
        $pdo->beginTransaction();
        try {
            $migration->up($schema);
            $migration->up($schema);
            self::assertSame(
                1,
                $this->connection()->table('roles')->where('slug', '=', 'workspace_manager')->count(),
            );

            $migration->down($schema);
            self::assertNull($this->connection()->table('roles')->where('slug', '=', 'workspace_manager')->first());
            self::assertContains('tenancy.access_any', $this->permsFor('administrator'));
            self::assertNotContains('tenancy.access_any', $this->permsFor('superuser'));
        } finally {
            // Preserve the canonical suite role/assignment state even when an assertion fails.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=AuthorityRolesMigrationTest`
Expected: FAIL — `workspace_manager` role does not exist yet (old 013 grants tenancy perms to `administrator`).

- [ ] **Step 3: Create the consolidated migration and delete the old one**

Delete `database/dependent-migrations/013_GrantTenancyOperatorToAdministrator.php`, then create `database/dependent-migrations/013_CreateTenancyAuthorityRoles.php`:

```php
<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

/**
 * Canonical tenancy-authority role model.
 *
 * Cross-workspace power (`tenancy.manage` + `tenancy.access_any`) moves off `administrator` onto a
 * dedicated `workspace_manager` role (level 90) and `superuser`. Idempotent; does NOT promote
 * existing administrator users (that is setup's / the recovery CLI's job).
 */
final class CreateTenancyAuthorityRoles implements MigrationInterface
{
    private const PERMISSIONS = [
        'tenancy.manage' => 'Manage tenants',
        'tenancy.access_any' => 'Access any tenant',
    ];
    private const ROLE_SLUG = 'workspace_manager';
    private const ROLE_NAME = 'Workspace Manager';
    private const ROLE_LEVEL = 90;

    private Connection $db;

    public function up(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();
        $permissions = $this->ensurePermissions();

        $wm = $this->ensureRole(self::ROLE_SLUG, self::ROLE_NAME, self::ROLE_LEVEL);
        $this->grant($wm, $permissions);

        $superuser = $this->roleUuid('superuser');
        if ($superuser !== null) {
            $this->grant($superuser, $permissions);
        }

        $administrator = $this->roleUuid('administrator');
        if ($administrator !== null) {
            $this->revoke($administrator, $permissions);
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();
        $permissions = array_column(
            $this->db->table('permissions')->select(['uuid'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get(),
            'uuid',
        );

        $administrator = $this->roleUuid('administrator');
        if ($administrator !== null && $permissions !== []) {
            $this->grant($administrator, $permissions);
        }
        $superuser = $this->roleUuid('superuser');
        if ($superuser !== null && $permissions !== []) {
            $this->revoke($superuser, $permissions);
        }
        $wm = $this->roleUuid(self::ROLE_SLUG);
        if ($wm !== null) {
            // Local rollback is destructive by definition: remove assignments before the system role.
            $this->db->table('user_roles')->where('role_uuid', '=', $wm)->delete();
            $this->db->table('role_permissions')->where('role_uuid', '=', $wm)->delete();
            $this->db->table('roles')->where('uuid', '=', $wm)->delete();
        }
    }

    public function getDescription(): string
    {
        return 'Create workspace_manager role; move tenancy.manage/access_any to it + superuser; '
            . 'remove from administrator.';
    }

    /** @return array<string,string> slug => permission uuid */
    private function ensurePermissions(): array
    {
        $bySlug = [];
        foreach (
            $this->db->table('permissions')->select(['uuid', 'slug'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get() as $row
        ) {
            $bySlug[(string) $row['slug']] = (string) $row['uuid'];
        }
        $insert = [];
        foreach (self::PERMISSIONS as $slug => $name) {
            if (isset($bySlug[$slug])) {
                continue;
            }
            $uuid = Utils::generateNanoID(12);
            $bySlug[$slug] = $uuid;
            $insert[] = [
                'uuid' => $uuid,
                'slug' => $slug,
                'name' => $name,
                'category' => 'tenancy',
                'description' => $name,
                'is_system' => true,
            ];
        }
        if ($insert !== []) {
            $this->db->table('permissions')->insertBatch($insert);
        }
        return $bySlug;
    }

    private function ensureRole(string $slug, string $name, int $level): string
    {
        $existing = $this->roleUuid($slug);
        if ($existing !== null) {
            return $existing;
        }
        $uuid = Utils::generateNanoID(12);
        $this->db->table('roles')->insert([
            'uuid' => $uuid,
            'name' => $name,
            'slug' => $slug,
            'description' => $name,
            'level' => $level,
            'is_system' => true,
            'status' => 'active',
        ]);
        return $uuid;
    }

    private function roleUuid(string $slug): ?string
    {
        $row = $this->db->table('roles')->select(['uuid'])->where('slug', '=', $slug)->first();
        return is_array($row) ? (string) $row['uuid'] : null;
    }

    /** @param array<string,string> $permissionUuids */
    private function grant(string $roleUuid, array $permissionUuids): void
    {
        $existing = [];
        foreach (
            $this->db->table('role_permissions')->select(['permission_uuid'])
                ->where('role_uuid', '=', $roleUuid)->get() as $row
        ) {
            $existing[(string) $row['permission_uuid']] = true;
        }
        $insert = [];
        foreach ($permissionUuids as $permissionUuid) {
            if (isset($existing[$permissionUuid])) {
                continue;
            }
            $insert[] = [
                'uuid' => Utils::generateNanoID(12),
                'role_uuid' => $roleUuid,
                'permission_uuid' => $permissionUuid,
            ];
        }
        if ($insert !== []) {
            $this->db->table('role_permissions')->insertBatch($insert);
        }
    }

    /** @param array<string,string> $permissionUuids */
    private function revoke(string $roleUuid, array $permissionUuids): void
    {
        foreach ($permissionUuids as $permissionUuid) {
            $this->db->table('role_permissions')
                ->where('role_uuid', '=', $roleUuid)
                ->where('permission_uuid', '=', $permissionUuid)
                ->delete();
        }
    }
}
```

- [ ] **Step 4: Retire the old-013 test and sweep SP3a fixtures**

The old migration's test asserts the now-removed administrator grant and must go:

```bash
git rm tests/Integration/Tenancy/OperatorGrantMigrationTest.php
```

Then sweep for any test that obtained operator power by **assigning the `administrator` role** (that path no longer grants tenancy permissions):

```bash
grep -rln "assignRole.*administrator" tests/ | xargs grep -l "tenancy\|operator\|bypass" 2>/dev/null
```

For each hit, repoint the operator fixture to `workspace_manager` (or `superuser`). Note: tests that stub the permission provider with a permission→bool map (e.g. `TenantAuthorizationTruthTableTest`, which passes `['tenancy.access_any' => true]` to a fake middleware) are **unaffected** — they never relied on the administrator grant. Only real-role-assignment fixtures need repointing.

- [ ] **Step 5: Run the test to verify it passes**

The test database may already have the old 013 recorded/applied. Rebuild it so the renamed
consolidated migration actually executes:

```bash
composer test:reset-db
composer test:migrate
```

Run: `vendor/bin/phpunit --filter=AuthorityRolesMigrationTest`
Expected: PASS (3 tests). The third test invokes `up()` twice and `down()` directly; rerunning a
fresh-DB suite is not treated as proof of method-level idempotence.
Run: `vendor/bin/phpunit tests/Integration/Tenancy` → Expected: green (confirms no SP3a fixture still depends on administrator-as-operator).

- [ ] **Step 6: phpcs + stage (HELD)**

```bash
composer phpcs -- database/dependent-migrations/013_CreateTenancyAuthorityRoles.php tests/Integration/Authority/AuthorityRolesMigrationTest.php
git add database/dependent-migrations tests/Integration/Authority/AuthorityRolesMigrationTest.php tests/Integration/Tenancy
# HELD — do not commit until go-ahead
```

---

## Task 2: `RoleAuthority` + `AuthorityAudit` support helpers

**Files:**
- Create: `app/Support/RoleAuthority.php`, `app/Support/AuthorityAudit.php`
- Test: `tests/Integration/Authority/RoleAuthorityTest.php`

**Interfaces:**
- Produces `RoleAuthority`:
  - `__construct(ApplicationContext $context)`
  - `isCanonicalSuperuser(string $userUuid): bool`
  - `roleLevel(string $roleSlug): ?int`
  - `rolePermissionSlugs(string $roleSlug): array` (list<string>)
  - `actorHoldsAllPermissionsOf(string $actorUuid, string $roleSlug): bool`
  - `crossWorkspaceRoleSlugs(): array` (list<string> — roles granting `tenancy.access_any`)
  - `targetCrossWorkspaceRoleSlugs(string $userUuid): array` (that user's active access-granting roles)
  - `activeSuperuserCount(): int`
  - `activeCrossWorkspaceHolderCount(): int`
  - constants `SUPERUSER='superuser'`, `WORKSPACE_MANAGER='workspace_manager'`, `ACCESS_ANY='tenancy.access_any'`
- Produces `AuthorityAudit`:
  - `__construct(?AuditRecorderInterface $audit = null)`
  - `record(string $action, ?string $actorUuid, ?string $targetUuid, array $context): void`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Support\RoleAuthority;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;

final class RoleAuthorityTest extends AppTestCase
{
    private function makeUser(string $status = 'active'): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid,
            'username' => 'u_' . $uuid,
            'email' => $uuid . '@example.test',
            'password' => 'x',
            'status' => $status,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    private function authority(): RoleAuthority
    {
        return new RoleAuthority($this->appContext());
    }

    public function testCanonicalSuperuserIsRoleBoundNotLevelBound(): void
    {
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $super = $this->makeUser();
        $aegis->assignRole($super, 'superuser');

        $auth = $this->authority();
        self::assertTrue($auth->isCanonicalSuperuser($super));
        self::assertFalse($auth->isCanonicalSuperuser($this->makeUser()));
    }

    public function testWorkspaceManagerGrantsCrossWorkspaceButAdministratorDoesNot(): void
    {
        $auth = $this->authority();
        $cross = $auth->crossWorkspaceRoleSlugs();
        self::assertContains('workspace_manager', $cross);
        self::assertContains('superuser', $cross);
        self::assertNotContains('administrator', $cross);
    }

    public function testActiveHolderCountsIgnoreInactiveUsers(): void
    {
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $active = $this->makeUser();
        $aegis->assignRole($active, 'workspace_manager');
        $inactive = $this->makeUser('inactive');
        $aegis->assignRole($inactive, 'workspace_manager');

        $auth = $this->authority();
        self::assertGreaterThanOrEqual(1, $auth->activeCrossWorkspaceHolderCount());
        // The inactive holder is excluded from the count.
        $before = $auth->activeCrossWorkspaceHolderCount();
        $aegis->assignRole($this->makeUser('inactive'), 'workspace_manager');
        self::assertSame($before, $auth->activeCrossWorkspaceHolderCount());
    }

    public function testCanonicalAndTargetRoleReadsExcludeInactiveDeletedAndExpiredAssignments(): void
    {
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $activeSuper = $this->makeUser();
        $inactiveSuper = $this->makeUser('inactive');
        $deletedSuper = $this->makeUser();
        $aegis->assignRole($activeSuper, 'superuser');
        $aegis->assignRole($inactiveSuper, 'superuser');
        $aegis->assignRole($deletedSuper, 'superuser');
        $this->connection()->table('users')->where('uuid', '=', $deletedSuper)
            ->update(['deleted_at' => gmdate('Y-m-d H:i:s')]);

        $expiredManager = $this->makeUser();
        $aegis->assignRole($expiredManager, 'workspace_manager');
        $role = $this->connection()->table('roles')->select(['uuid'])
            ->where('slug', '=', 'workspace_manager')->first();
        self::assertIsArray($role);
        $this->connection()->table('user_roles')
            ->where('user_uuid', '=', $expiredManager)
            ->where('role_uuid', '=', (string) $role['uuid'])
            ->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $auth = $this->authority();
        self::assertTrue($auth->isCanonicalSuperuser($activeSuper));
        self::assertFalse($auth->isCanonicalSuperuser($inactiveSuper));
        self::assertFalse($auth->isCanonicalSuperuser($deletedSuper));
        self::assertSame([], $auth->targetCrossWorkspaceRoleSlugs($expiredManager));
    }
}
```

> Verified harness API: `AppTestCase::appContext()` is the protected context accessor
> (`tests/Support/AppTestCase.php:157`); use it exactly as shown.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=RoleAuthorityTest`
Expected: FAIL — `App\Support\RoleAuthority` does not exist.

- [ ] **Step 3: Create `app/Support/RoleAuthority.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Glueful\Bootstrap\ApplicationContext;
use function db;

/**
 * Read-only authority facts derived from the Aegis role/permission tables. All "active holder"
 * queries define active as: user status='active' AND deleted_at IS NULL, role status='active' AND
 * deleted_at IS NULL, assignment expires_at IS NULL OR >= now.
 */
final class RoleAuthority
{
    public const SUPERUSER = 'superuser';
    public const WORKSPACE_MANAGER = 'workspace_manager';
    public const ACCESS_ANY = 'tenancy.access_any';

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function isCanonicalSuperuser(string $userUuid): bool
    {
        $pdo = db($this->context)->getPDO();
        $stmt = $pdo->prepare(
            "SELECT 1 FROM user_roles ur
             JOIN roles r ON r.uuid = ur.role_uuid
             JOIN users u ON u.uuid = ur.user_uuid
             WHERE ur.user_uuid = :u AND r.slug = 'superuser'
               AND u.status = 'active' AND u.deleted_at IS NULL
               AND r.status = 'active' AND r.deleted_at IS NULL
               AND (ur.expires_at IS NULL OR ur.expires_at >= :now)
             LIMIT 1"
        );
        $stmt->execute(['u' => $userUuid, 'now' => gmdate('Y-m-d H:i:s')]);
        return $stmt->fetchColumn() !== false;
    }

    public function roleLevel(string $roleSlug): ?int
    {
        $row = db($this->context)->table('roles')->select(['level'])->where('slug', '=', $roleSlug)->first();
        return is_array($row) ? (int) $row['level'] : null;
    }

    /** @return list<string> */
    public function rolePermissionSlugs(string $roleSlug): array
    {
        $pdo = db($this->context)->getPDO();
        $stmt = $pdo->prepare(
            "SELECT p.slug FROM roles r
             JOIN role_permissions rp ON rp.role_uuid = r.uuid
             JOIN permissions p ON p.uuid = rp.permission_uuid
             WHERE r.slug = :s"
        );
        $stmt->execute(['s' => $roleSlug]);
        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function actorHoldsAllPermissionsOf(string $actorUuid, string $roleSlug): bool
    {
        $need = $this->rolePermissionSlugs($roleSlug);
        if ($need === []) {
            return true;
        }
        $have = $this->actorPermissionSlugs($actorUuid);
        foreach ($need as $slug) {
            if (!in_array($slug, $have, true)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> roles (by slug) that grant tenancy.access_any */
    public function crossWorkspaceRoleSlugs(): array
    {
        $pdo = db($this->context)->getPDO();
        $stmt = $pdo->prepare(
            "SELECT DISTINCT r.slug FROM roles r
             JOIN role_permissions rp ON rp.role_uuid = r.uuid
             JOIN permissions p ON p.uuid = rp.permission_uuid
             WHERE p.slug = :perm AND r.status = 'active' AND r.deleted_at IS NULL"
        );
        $stmt->execute(['perm' => self::ACCESS_ANY]);
        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** @return list<string> the user's active roles (by slug) that grant tenancy.access_any */
    public function targetCrossWorkspaceRoleSlugs(string $userUuid): array
    {
        $pdo = db($this->context)->getPDO();
        $stmt = $pdo->prepare(
            "SELECT DISTINCT r.slug FROM user_roles ur
             JOIN users u ON u.uuid = ur.user_uuid
             JOIN roles r ON r.uuid = ur.role_uuid
             JOIN role_permissions rp ON rp.role_uuid = r.uuid
             JOIN permissions p ON p.uuid = rp.permission_uuid
             WHERE ur.user_uuid = :u AND p.slug = :perm
               AND u.status = 'active' AND u.deleted_at IS NULL
               AND r.status = 'active' AND r.deleted_at IS NULL
               AND (ur.expires_at IS NULL OR ur.expires_at >= :now)"
        );
        $stmt->execute(['u' => $userUuid, 'perm' => self::ACCESS_ANY, 'now' => gmdate('Y-m-d H:i:s')]);
        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function activeSuperuserCount(): int
    {
        return $this->countHolders(
            "JOIN roles r ON r.uuid = ur.role_uuid AND r.slug = 'superuser'",
            []
        );
    }

    public function activeCrossWorkspaceHolderCount(): int
    {
        return $this->countHolders(
            "JOIN roles r ON r.uuid = ur.role_uuid
             JOIN role_permissions rp ON rp.role_uuid = r.uuid
             JOIN permissions p ON p.uuid = rp.permission_uuid AND p.slug = :perm",
            ['perm' => self::ACCESS_ANY]
        );
    }

    /**
     * @param array<string,string> $params
     */
    private function countHolders(string $roleJoin, array $params): int
    {
        $pdo = db($this->context)->getPDO();
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT ur.user_uuid)
             FROM user_roles ur
             {$roleJoin}
             JOIN users u ON u.uuid = ur.user_uuid AND u.status = 'active' AND u.deleted_at IS NULL
             WHERE r.status = 'active' AND r.deleted_at IS NULL
               AND (ur.expires_at IS NULL OR ur.expires_at >= :now)"
        );
        $stmt->execute($params + ['now' => gmdate('Y-m-d H:i:s')]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function actorPermissionSlugs(string $actorUuid): array
    {
        $pdo = db($this->context)->getPDO();
        $stmt = $pdo->prepare(
            "SELECT DISTINCT p.slug FROM user_roles ur
             JOIN users u ON u.uuid = ur.user_uuid
             JOIN roles r ON r.uuid = ur.role_uuid AND r.status = 'active' AND r.deleted_at IS NULL
             JOIN role_permissions rp ON rp.role_uuid = r.uuid
             JOIN permissions p ON p.uuid = rp.permission_uuid
             WHERE ur.user_uuid = :u AND u.status = 'active' AND u.deleted_at IS NULL
               AND (ur.expires_at IS NULL OR ur.expires_at >= :now)"
        );
        $stmt->execute(['u' => $actorUuid, 'now' => gmdate('Y-m-d H:i:s')]);
        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }
}
```

- [ ] **Step 4: Create `app/Support/AuthorityAudit.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;

/**
 * Best-effort emitter for platform-authority audit events. Mirrors OperatorBypass::recordAudit —
 * never throws, never blocks the operation; the audit extension owns durable-persistence failure.
 */
final class AuthorityAudit
{
    public function __construct(private readonly ?AuditRecorderInterface $audit = null)
    {
    }

    /** @param array<string,mixed> $context */
    public function record(string $action, ?string $actorUuid, ?string $targetUuid, array $context): void
    {
        if ($this->audit === null) {
            return;
        }
        try {
            $this->audit->record(new AuditEntry(
                occurredAt: microtime(true),
                action: $action,
                category: 'security',
                actorUuid: $actorUuid,
                targetType: 'user',
                targetUuid: $targetUuid,
                context: $context,
            ));
        } catch (\Throwable) {
            // Best-effort by contract.
        }
    }
}
```

> Verify the `AuditRecorderInterface` / `AuditEntry` namespaces against `app/Content/Authorization/OperatorBypass.php`'s imports before running (they are the proven-correct references). Adjust the two `use` lines if they differ.

- [ ] **Step 5: Register both in `app/Providers/ThalloServiceProvider.php` services()**

Add near the `UserRoleAssignmentPolicy` binding (~:1237). `AuthorityAudit`'s `AuditRecorderInterface` is optional — resolve it softly via a factory so a missing audit binding is null, mirroring how other optional-audit services are built:

```php
            RoleAuthority::class => [
                'class' => RoleAuthority::class,
                'shared' => true,
                'autowire' => true,
            ],
            AuthorityAudit::class => [
                'class' => AuthorityAudit::class,
                'shared' => true,
                'factory' => [self::class, 'makeAuthorityAudit'],
            ],
```

Add the imports (`use App\Support\RoleAuthority;`, `use App\Support\AuthorityAudit;`) and the factory method (place it beside the other `make*` factories):

```php
    public static function makeAuthorityAudit(ContainerInterface $container): AuthorityAudit
    {
        $audit = $container->has(AuditRecorderInterface::class)
            ? $container->get(AuditRecorderInterface::class)
            : null;
        return new AuthorityAudit($audit);
    }
```

Add `use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;`. The provider's sibling
factory methods use `Psr\Container\ContainerInterface`; use that existing import.

- [ ] **Step 6: Run tests + phpcs**

Run: `vendor/bin/phpunit --filter=RoleAuthorityTest` → Expected: PASS (4 tests, including the full
active-user/role/assignment predicate matrix).
Run: `composer phpcs -- app/Support/RoleAuthority.php app/Support/AuthorityAudit.php`

- [ ] **Step 7: Stage (HELD)**

```bash
git add app/Support/RoleAuthority.php app/Support/AuthorityAudit.php app/Providers/ThalloServiceProvider.php tests/Integration/Authority/RoleAuthorityTest.php
```

---

## Task 3: Harden `UserRoleAssignmentPolicy`

**Files:**
- Modify: `app/Support/UserRoleAssignmentPolicy.php`
- Test: `tests/Integration/Authority/RoleAssignmentPolicyTest.php`

**Interfaces:**
- Consumes: `RoleAuthority` (Task 2), `AuthorityAudit` (Task 2), `Role` (`getSlug()/getLevel()`).
- Produces (public):
  - `assertCanSyncRoles(string $actorUuid, string $targetUuid, array $currentSlugs, array $desiredSlugs): void` (unchanged signature)
  - `mayAdd(string $actorUuid, Role $role): bool`
  - `mayRemove(string $actorUuid, Role $role): bool`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Support\RoleAssignmentException;
use App\Support\UserRoleAssignmentPolicy;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;

final class RoleAssignmentPolicyTest extends AppTestCase
{
    private function makeUser(): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid, 'username' => 'u_' . $uuid, 'email' => $uuid . '@x.test',
            'password' => 'x', 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    private function policy(): UserRoleAssignmentPolicy
    {
        return $this->container()->get(UserRoleAssignmentPolicy::class);
    }

    private function withRole(string $slug): string
    {
        $u = $this->makeUser();
        $this->container()->get(AegisPermissionProvider::class)->assignRole($u, $slug);
        // users.roles.manage lives on administrator + superuser; grant administrator too where needed.
        return $u;
    }

    public function testSuperuserRoleIsApiImmutable(): void
    {
        $super = $this->withRole('superuser');
        $this->expectException(RoleAssignmentException::class);
        // Even a superuser actor may not add `superuser` to anyone through the policy.
        $this->policy()->assertCanSyncRoles($super, $this->makeUser(), [], ['superuser']);
    }

    public function testSuperuserRoleCannotBeRemovedThroughApiPolicy(): void
    {
        $super = $this->withRole('superuser');
        $this->expectException(RoleAssignmentException::class);
        $this->policy()->assertCanSyncRoles($super, $this->makeUser(), ['superuser'], []);
    }

    public function testOnlySuperuserMayAssignWorkspaceManager(): void
    {
        $admin = $this->withRole('administrator');
        $this->expectException(RoleAssignmentException::class);
        $this->policy()->assertCanSyncRoles($admin, $this->makeUser(), [], ['workspace_manager']);
    }

    public function testSuperuserMayAssignWorkspaceManager(): void
    {
        $super = $this->withRole('superuser');
        $this->policy()->assertCanSyncRoles($super, $this->makeUser(), [], ['workspace_manager']);
        $this->addToAssertionCount(1); // no exception == allowed
    }

    public function testCustomLevel100RoleIsNotCanonicalSuperuser(): void
    {
        $roleUuid = Utils::generateNanoID(12);
        $this->connection()->table('roles')->insert([
            'uuid' => $roleUuid, 'name' => 'Custom Root', 'slug' => 'custom_root',
            'level' => 100, 'is_system' => false, 'status' => 'active',
        ]);
        $actor = $this->withRole('administrator'); // supplies users.roles.manage
        $this->container()->get(AegisPermissionProvider::class)->assignRole($actor, 'custom_root');

        $this->expectException(RoleAssignmentException::class);
        $this->policy()->assertCanSyncRoles($actor, $this->makeUser(), [], ['workspace_manager']);
    }

    public function testRemovingALowerRoleDoesNotRequireItsPermissions(): void
    {
        // administrator can remove `editor` from a user even without holding every editor permission,
        // as long as editor.level < administrator.level.
        $admin = $this->withRole('administrator');
        $target = $this->withRole('editor');
        $this->policy()->assertCanSyncRoles($admin, $target, ['editor'], []);
        $this->addToAssertionCount(1);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=RoleAssignmentPolicyTest`
Expected: FAIL — the current policy permits superuser→superuser and lacks the protected-role rule.

- [ ] **Step 3: Rewrite the policy**

Replace the body of `app/Support/UserRoleAssignmentPolicy.php` (keep the class/namespace) with:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Models\Role;
use Glueful\Extensions\Aegis\Repositories\RoleRepository;

/**
 * WHO may assign WHICH roles. Superuser identity is role-bound (the active `superuser` role), never
 * level-derived. `superuser` is API-immutable here; `workspace_manager` is superuser-only. Permission
 * possession is required only when ADDING a role. Denials are audited (best-effort).
 */
final class UserRoleAssignmentPolicy
{
    private const MANAGE_PERMISSION = 'users.roles.manage';

    private ?RoleRepository $roles = null;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AegisPermissionProvider $aegis,
        private readonly RoleAuthority $authority,
        private readonly AuthorityAudit $audit,
    ) {
    }

    /**
     * @param list<string> $currentSlugs
     * @param list<string> $desiredSlugs
     * @throws RoleAssignmentException 403 (permission/ceiling/protected/self) or 422 (unknown slug)
     */
    public function assertCanSyncRoles(
        string $actorUuid,
        string $targetUuid,
        array $currentSlugs,
        array $desiredSlugs,
    ): void {
        $added = array_values(array_diff($desiredSlugs, $currentSlugs));
        $removed = array_values(array_diff($currentSlugs, $desiredSlugs));
        if ($added === [] && $removed === []) {
            return;
        }

        if (!$this->canManageRoles($actorUuid)) {
            $this->deny($actorUuid, $targetUuid, 'no_manage_permission', 'You do not have permission to manage user roles.');
        }
        if (!$this->authority->isCanonicalSuperuser($actorUuid) && $actorUuid === $targetUuid) {
            $this->deny($actorUuid, $targetUuid, 'self_change', 'You cannot change your own roles.');
        }

        foreach ($added as $slug) {
            $role = $this->requireRole($slug, $actorUuid, $targetUuid);
            if (!$this->mayAdd($actorUuid, $role)) {
                $this->deny($actorUuid, $targetUuid, 'add_denied', "You cannot assign the role '{$slug}'.");
            }
        }
        foreach ($removed as $slug) {
            $role = $this->requireRole($slug, $actorUuid, $targetUuid);
            if (!$this->mayRemove($actorUuid, $role)) {
                $this->deny($actorUuid, $targetUuid, 'remove_denied', "You cannot revoke the role '{$slug}'.");
            }
        }
    }

    public function mayAdd(string $actorUuid, Role $role): bool
    {
        $slug = $role->getSlug();
        if ($slug === RoleAuthority::SUPERUSER) {
            return false; // API-immutable
        }
        if (!($this->maxLevel($actorUuid) > $role->getLevel())) {
            return false; // strict level ceiling
        }
        $isSuper = $this->authority->isCanonicalSuperuser($actorUuid);
        if ($slug === RoleAuthority::WORKSPACE_MANAGER && !$isSuper) {
            return false; // protected: superuser-only, independent of level ordering
        }
        return $isSuper || $this->authority->actorHoldsAllPermissionsOf($actorUuid, $slug);
    }

    public function mayRemove(string $actorUuid, Role $role): bool
    {
        $slug = $role->getSlug();
        if ($slug === RoleAuthority::SUPERUSER) {
            return false; // API-immutable
        }
        if (!($this->maxLevel($actorUuid) > $role->getLevel())) {
            return false;
        }
        if ($slug === RoleAuthority::WORKSPACE_MANAGER && !$this->authority->isCanonicalSuperuser($actorUuid)) {
            return false;
        }
        return true; // removal does not require possessing the role's permissions
    }

    private function requireRole(string $slug, string $actorUuid, string $targetUuid): Role
    {
        $role = $this->roles()->findRoleBySlug($slug);
        if (!$role instanceof Role) {
            $this->audit->record('security.role_assignment_denied', $actorUuid, $targetUuid, [
                'role' => $slug, 'outcome' => 'denied', 'reason' => 'unknown_role',
            ]);
            throw RoleAssignmentException::unprocessable("Unknown role '{$slug}'.");
        }
        return $role;
    }

    private function deny(string $actorUuid, string $targetUuid, string $reason, string $message): never
    {
        $this->audit->record('security.role_assignment_denied', $actorUuid, $targetUuid, [
            'outcome' => 'denied', 'reason' => $reason,
        ]);
        throw RoleAssignmentException::forbidden($message);
    }

    private function canManageRoles(string $actorUuid): bool
    {
        try {
            return $this->aegis->can($actorUuid, self::MANAGE_PERMISSION, 'thallo');
        } catch (\Throwable) {
            return false;
        }
    }

    private function maxLevel(string $actorUuid): int
    {
        $max = 0;
        foreach ($this->aegis->getUserRoles($actorUuid) as $role) {
            if ($role instanceof Role && $role->isActive()) {
                $max = max($max, $role->getLevel());
            }
        }
        return $max;
    }

    private function roles(): RoleRepository
    {
        return $this->roles ??= new RoleRepository(null, $this->context);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=RoleAssignmentPolicyTest` → Expected: PASS (6 tests).

Add denial-audit coverage in this class with a recording `AuditRecorderInterface` fake: a protected
role denial records `security.role_assignment_denied` with actor, target, and reason. Repeat with a
recorder that throws and assert the policy still returns the same denial rather than leaking the
audit failure.

- [ ] **Step 5: Run the existing user-admin regression + phpcs**

Run: `vendor/bin/phpunit --filter=UserAdmin` (existing controller/policy tests) → Expected: PASS.
Run: `composer phpcs -- app/Support/UserRoleAssignmentPolicy.php tests/Integration/Authority/RoleAssignmentPolicyTest.php`

- [ ] **Step 6: Stage (HELD)**

```bash
git add app/Support/UserRoleAssignmentPolicy.php tests/Integration/Authority/RoleAssignmentPolicyTest.php
```

---

## Task 4: `AuthorityContinuityGuard` (advisory lock + last-of-kind) + participation proof

**Files:**
- Create: `app/Support/AuthorityContinuityViolation.php`, `app/Support/AuthorityContinuityGuard.php`
- Test: `tests/Integration/Authority/AuthorityContinuityGuardTest.php`, `tests/Integration/Authority/ConnectionParticipationTest.php`

**Interfaces:**
- Consumes: `RoleAuthority` (Task 2), `AuthorityAudit` (Task 2).
- Produces:
  - `runExclusive(callable $fn): mixed` — opens a transaction, takes the authority advisory lock, runs `$fn`.
  - `assertPreservesAuthority(?string $actorUuid, string $targetUuid, array $rolesRemoved, bool $deactivatingOrDeleting, string $operation): void` — throws `RoleAssignmentException` (403). MUST be called inside `runExclusive`; actor/operation feed truthful denial audit.

- [ ] **Step 1: Write the connection-participation proof test (spec §5 requirement)**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Support\AuthorityContinuityGuard;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Helpers\Utils;

/**
 * Proves the spec §5 invariant: Aegis assign/revoke and Users update/soft-delete all participate in
 * the guard's db() transaction. If any assertion fails, route that mutation through a
 * transaction-participating repository seam — do not weaken the invariant.
 */
final class ConnectionParticipationTest extends AppTestCase
{
    private function makeUser(): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid, 'username' => 'u_' . $uuid, 'email' => $uuid . '@x.test',
            'password' => 'x', 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    public function testEveryAuthorityMutationParticipantRollsBackWithTheGuardTransaction(): void
    {
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $users = $this->container()->get(UserRepository::class);
        $guard = $this->container()->get(AuthorityContinuityGuard::class);
        $assigned = $this->makeUser();
        $revoked = $this->makeUser();
        $updated = $this->makeUser();
        $deleted = $this->makeUser();
        self::assertTrue($aegis->assignRole($revoked, 'editor'));

        try {
            $guard->runExclusive(function () use ($aegis, $users, $assigned, $revoked, $updated, $deleted): void {
                self::assertTrue($aegis->assignRole($assigned, 'editor'));
                self::assertTrue($aegis->revokeRole($revoked, 'editor'));
                self::assertTrue($users->update($updated, ['status' => 'inactive']));
                self::assertTrue($users->softDelete($deleted));
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $role = $this->connection()->table('roles')->select(['uuid'])->where('slug', '=', 'editor')->first();
        self::assertIsArray($role);
        $roleUuid = (string) $role['uuid'];
        self::assertSame(0, $this->connection()->table('user_roles')
            ->where('user_uuid', '=', $assigned)->where('role_uuid', '=', $roleUuid)->count());
        self::assertSame(1, $this->connection()->table('user_roles')
            ->where('user_uuid', '=', $revoked)->where('role_uuid', '=', $roleUuid)->count());
        $updatedRow = $this->connection()->table('users')->select(['status'])
            ->where('uuid', '=', $updated)->first();
        $deletedRow = $this->connection()->table('users')->select(['deleted_at'])
            ->where('uuid', '=', $deleted)->first();
        self::assertSame('active', $updatedRow['status'] ?? null);
        self::assertNull($deletedRow['deleted_at'] ?? null);
    }
}
```

- [ ] **Step 2: Run it — expect an error (guard does not exist)**

Run: `vendor/bin/phpunit --filter=ConnectionParticipationTest`
Expected: ERROR — `AuthorityContinuityGuard` not found.

- [ ] **Step 3: Write the guard behavior test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Support\AuthorityContinuityGuard;
use App\Support\RoleAssignmentException;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;

final class AuthorityContinuityGuardTest extends AppTestCase
{
    private function makeUser(): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid, 'username' => 'u_' . $uuid, 'email' => $uuid . '@x.test',
            'password' => 'x', 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    public function testBlocksDeactivatingTheSoleSuperuser(): void
    {
        // NOTE: the test DB is seeded fresh; if any superuser exists from setup, this test creates the
        // ONLY superuser it can reason about by asserting on a freshly-made lone holder. To keep the
        // count deterministic, this test asserts the guard blocks when it is the last active holder.
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $guard = $this->container()->get(AuthorityContinuityGuard::class);
        $sole = $this->makeUser();
        $aegis->assignRole($sole, 'superuser');

        // Simulate deactivation/deletion of the sole superuser.
        $this->expectException(RoleAssignmentException::class);
        $guard->runExclusive(function () use ($guard, $sole): void {
            $guard->assertPreservesAuthority('actor0000001', $sole, [], true, 'deactivate');
        });
    }

    public function testAllowsRemovingWorkspaceManagerWhenAnotherCrossWorkspaceHolderExists(): void
    {
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $guard = $this->container()->get(AuthorityContinuityGuard::class);
        $keep = $this->makeUser();
        $aegis->assignRole($keep, 'superuser'); // another cross-workspace holder
        $target = $this->makeUser();
        $aegis->assignRole($target, 'workspace_manager');

        $guard->runExclusive(function () use ($guard, $target): void {
            $guard->assertPreservesAuthority(
                'actor0000001',
                $target,
                ['workspace_manager'],
                false,
                'roles_sync',
            );
        });
        $this->addToAssertionCount(1); // no exception
    }

    public function testAdvisoryTransactionLockSerializesIndependentConnections(): void
    {
        // Established two-session pattern: MutationQuiescenceTest uses Connection::newPdo().
        $participant = $this->connection()->newPdo();
        $contender = $this->connection()->newPdo();
        $sql = "SELECT pg_try_advisory_xact_lock(hashtextextended('thallo:authority', 0))";

        $participant->beginTransaction();
        $contender->beginTransaction();
        try {
            self::assertTrue((bool) $participant->query($sql)->fetchColumn());
            self::assertFalse((bool) $contender->query($sql)->fetchColumn());
            $participant->commit();
            self::assertTrue((bool) $contender->query($sql)->fetchColumn());
        } finally {
            if ($participant->inTransaction()) {
                $participant->rollBack();
            }
            if ($contender->inTransaction()) {
                $contender->rollBack();
            }
        }
    }
}
```

- [ ] **Step 4: Create the internal violation and guard**

`app/Support/AuthorityContinuityViolation.php` carries audit context out of the rolled-back
transaction:

```php
<?php

declare(strict_types=1);

namespace App\Support;

final class AuthorityContinuityViolation extends \RuntimeException
{
    public function __construct(
        public readonly ?string $actorUuid,
        public readonly string $targetUuid,
        public readonly string $operation,
        public readonly string $reason,
    ) {
        parent::__construct('This change would remove the last holder of a required authority.');
    }
}
```

`app/Support/AuthorityContinuityGuard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Glueful\Bootstrap\ApplicationContext;

use function db;

/**
 * Serializes platform-authority changes on one PostgreSQL advisory transaction lock and blocks any
 * change that would leave zero active superusers or zero active cross-workspace holders. Callers run
 * their mutation inside {@see runExclusive()} and assert continuity BEFORE mutating, so the fresh
 * holder count and the mutation commit atomically.
 */
final class AuthorityContinuityGuard
{
    private const LOCK_KEY = 'thallo:authority';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly RoleAuthority $authority,
        private readonly AuthorityAudit $audit,
    ) {
    }

    public function runExclusive(callable $fn): mixed
    {
        try {
            return db($this->context)->transaction(function () use ($fn) {
                $stmt = db($this->context)->getPDO()->prepare(
                    'SELECT pg_advisory_xact_lock(hashtextextended(:k, 0))'
                );
                $stmt->execute(['k' => self::LOCK_KEY]);
                return $fn();
            });
        } catch (AuthorityContinuityViolation $e) {
            // transaction() has rolled back before control reaches this catch; the denial audit
            // therefore persists independently of the refused mutation.
            $this->audit->record('security.authority_change_denied', $e->actorUuid, $e->targetUuid, [
                'operation' => $e->operation, 'outcome' => 'denied', 'reason' => $e->reason,
            ]);
            throw RoleAssignmentException::forbidden($e->getMessage());
        }
    }

    /**
     * @param list<string> $rolesRemoved role slugs being revoked from the target in this operation
     * @throws RoleAssignmentException 403 when the change would remove the last superuser / last
     *         cross-workspace holder. MUST be called inside {@see runExclusive()}.
     */
    public function assertPreservesAuthority(
        ?string $actorUuid,
        string $targetUuid,
        array $rolesRemoved,
        bool $deactivatingOrDeleting,
        string $operation,
    ): void {
        $targetIsSuperuser = $this->authority->isCanonicalSuperuser($targetUuid);
        if ($targetIsSuperuser) {
            $losesSuperuser = $deactivatingOrDeleting
                || in_array(RoleAuthority::SUPERUSER, $rolesRemoved, true);
            if ($losesSuperuser && $this->authority->activeSuperuserCount() <= 1) {
                throw new AuthorityContinuityViolation(
                    $actorUuid,
                    $targetUuid,
                    $operation,
                    'last_superuser',
                );
            }
        }

        $targetAccessRoles = $this->authority->targetCrossWorkspaceRoleSlugs($targetUuid);
        if ($targetAccessRoles !== []) {
            $remaining = array_diff($targetAccessRoles, $rolesRemoved);
            $losesCrossWorkspace = $deactivatingOrDeleting || $remaining === [];
            if ($losesCrossWorkspace && $this->authority->activeCrossWorkspaceHolderCount() <= 1) {
                throw new AuthorityContinuityViolation(
                    $actorUuid,
                    $targetUuid,
                    $operation,
                    'last_cross_workspace_holder',
                );
            }
        }
    }
}
```

- [ ] **Step 5: Register in `ThalloServiceProvider` services()** (beside `RoleAuthority`)

```php
            AuthorityContinuityGuard::class => [
                'class' => AuthorityContinuityGuard::class,
                'shared' => true,
                'autowire' => true,
            ],
```

Add `use App\Support\AuthorityContinuityGuard;`.

- [ ] **Step 6: Run both tests + phpcs**

Run: `vendor/bin/phpunit --filter=ConnectionParticipationTest` → Expected: PASS (proves assign,
revoke, user update, and soft-delete participation).
Run: `vendor/bin/phpunit --filter=AuthorityContinuityGuardTest` → Expected: PASS (3 tests,
including two independent PostgreSQL sessions contending on the exact production lock key).

> If `ConnectionParticipationTest` FAILS: identify the failing participant. Do NOT weaken the
> invariant. For Aegis, add direct transaction-connection `user_roles` add/remove methods mirroring
> `UserRoleRepository` and invalidate its user cache after commit. For Users, add transaction-
> connection update/soft-delete methods. Re-run all four rollback assertions.

Add a test with the real audit store (or recording fake) proving a denied continuity mutation rolls
back the user change but retains `security.authority_change_denied` with actor/target/operation.

Run: `composer phpcs -- app/Support/AuthorityContinuityViolation.php app/Support/AuthorityContinuityGuard.php tests/Integration/Authority/AuthorityContinuityGuardTest.php tests/Integration/Authority/ConnectionParticipationTest.php`

- [ ] **Step 7: Stage (HELD)**

```bash
git add app/Support/AuthorityContinuityViolation.php app/Support/AuthorityContinuityGuard.php app/Providers/ThalloServiceProvider.php tests/Integration/Authority/AuthorityContinuityGuardTest.php tests/Integration/Authority/ConnectionParticipationTest.php
```

---

## Task 5: Wire the guard into `UserAdminController` (update + destroy)

**Files:**
- Modify: `app/Http/Controllers/UserAdminController.php`
- Test: `tests/Integration/Authority/UserAdminContinuityTest.php`

**Interfaces:**
- Consumes: `AuthorityContinuityGuard::runExclusive/assertPreservesAuthority` (Task 4), `AuthorityAudit` (Task 2), existing `UserRoleAssignmentPolicy::assertCanSyncRoles` (Task 3).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Http\Controllers\UserAdminController;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

final class UserAdminContinuityTest extends AppTestCase
{
    private function makeUser(): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid, 'username' => 'u_' . $uuid, 'email' => $uuid . '@x.test',
            'password' => 'x', 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    private function requestAs(string $actorUuid): Request
    {
        $r = Request::create('/v1/admin/users', 'DELETE');
        $r->attributes->set('user', ['uuid' => $actorUuid, 'roles' => ['superuser'], 'claims' => ['scopes' => []]]);
        return $r;
    }

    public function testCannotSoftDeleteTheSoleSuperuser(): void
    {
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $sole = $this->makeUser();
        $aegis->assignRole($sole, 'superuser');
        $actor = $this->makeUser(); // distinct actor so the "cannot delete self" guard is not the blocker
        $aegis->assignRole($actor, 'superuser');
        // Now demote the actor's peer so `sole` is the last — instead, delete `actor` while `sole` remains:
        $controller = $this->container()->get(UserAdminController::class);

        // Deleting one of two superusers is allowed:
        $ok = $controller->destroy($this->requestAs($sole), $actor);
        self::assertSame(200, $ok->getStatusCode());

        // Deleting the now-sole superuser is blocked with 403:
        $blocked = $controller->destroy($this->requestAs($this->makeUser()), $sole);
        self::assertSame(403, $blocked->getStatusCode());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=UserAdminContinuityTest`
Expected: FAIL — `destroy()` currently soft-deletes without a continuity check (returns 200).

- [ ] **Step 3: Add the constructor deps**

In `app/Http/Controllers/UserAdminController.php` add imports and constructor params:

```php
use App\Support\AuthorityContinuityGuard;
use App\Support\AuthorityAudit;
```

```php
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly UserRepository $users,
        private readonly AegisPermissionProvider $aegis,
        private readonly UserRoleAssignmentPolicy $rolePolicy,
        private readonly AuthorityContinuityGuard $continuity,
        private readonly AuthorityAudit $audit,
    ) {
    }
```

(The controller is `autowire => true`, so no factory change is needed. The continuity guard already holds `RoleAuthority`, so the controller needs no direct `RoleAuthority` dependency.)

- [ ] **Step 4: Guard `destroy()`**

Replace the body of `destroy()` (currently `app/Http/Controllers/UserAdminController.php:192-204`):

```php
    public function destroy(Request $request, string $uuid): Response
    {
        $actorUuid = ActorHelper::uuidFromRequest($request);
        if ($actorUuid !== null && $actorUuid === $uuid) {
            return Response::error('You cannot delete your own account.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($this->users->findByUuid($uuid) === null) {
            return Response::notFound('User not found.');
        }

        try {
            $this->continuity->runExclusive(function () use ($actorUuid, $uuid): void {
                $this->continuity->assertPreservesAuthority($actorUuid, $uuid, [], true, 'delete');
                if (!$this->users->softDelete($uuid)) {
                    throw new \RuntimeException('soft-delete failed');
                }
            });
        } catch (RoleAssignmentException $e) {
            return $this->roleAssignmentError($e);
        }

        return Response::success([], 'User deleted.');
    }
```

- [ ] **Step 5: Guard `update()` — authorize the whole transition before writing**

Replace `update()`'s mutation section (from the `$account = array_filter(...)` block through the `role_slugs` block, currently `:144-169`) with an authorize-then-mutate-under-lock structure:

```php
        // Determine the authority impact of this request BEFORE writing anything.
        $current = $this->currentRoleSlugs($uuid);
        $desired = $input->role_slugs; // null = leave roles unchanged
        $rolesRemoved = $desired === null ? [] : array_values(array_diff($current, $desired));
        $deactivating = $input->status !== null && $input->status !== '' && $input->status !== 'active';

        // Static role-assignment authorization (permission/ceiling/protected/self) first.
        if ($desired !== null) {
            try {
                $this->rolePolicy->assertCanSyncRoles(
                    ActorHelper::uuidFromRequest($request) ?? '',
                    $uuid,
                    $current,
                    $desired,
                );
            } catch (RoleAssignmentException $e) {
                return $this->roleAssignmentError($e);
            }
        }

        $account = array_filter(
            ['username' => $input->username, 'email' => $input->email, 'status' => $input->status],
            static fn ($v) => $v !== null && $v !== '',
        );

        $actorUuid = ActorHelper::uuidFromRequest($request);
        try {
            $this->continuity->runExclusive(function () use (
                $actorUuid,
                $uuid,
                $account,
                $desired,
                $rolesRemoved,
                $deactivating,
            ): void {
                if ($rolesRemoved !== [] || $deactivating) {
                    $this->continuity->assertPreservesAuthority(
                        $actorUuid,
                        $uuid,
                        $rolesRemoved,
                        $deactivating,
                        $deactivating ? 'deactivate' : 'roles_sync',
                    );
                }
                if ($account !== []) {
                    $this->users->update($uuid, $account);
                }
                if ($desired !== null) {
                    $this->syncRoles($uuid, $desired);
                }
            });
        } catch (RoleAssignmentException $e) {
            return $this->roleAssignmentError($e);
        }

        // Profile fields carry no authority; apply outside the lock (empty strings allowed to clear).
        $this->applyProfile($uuid, $input->first_name, $input->last_name, allowClear: true);

        if ($desired !== null) {
            foreach ($rolesRemoved as $slug) {
                $this->audit->record('security.role_revoked', $actorUuid, $uuid, ['role' => $slug]);
            }
            foreach (array_values(array_diff($desired, $current)) as $slug) {
                $this->audit->record('security.role_assigned', $actorUuid, $uuid, ['role' => $slug]);
            }
        }

        return Response::success([], 'User updated.');
```

> Keep the existing pre-checks above this block (the `findByUuid` 404 and the email/username uniqueness 422s) exactly as they are — they run before any mutation.

- [ ] **Step 6: Update the DI registration if the constructor param count is validated**

`UserAdminController` is `autowire => true` (`ThalloServiceProvider.php:~1233`), so autowire supplies the new deps. No change needed unless a compile-time check requires it — run the container compile in Step 7 to confirm.

- [ ] **Step 7: Run tests, container compile, phpcs**

Run: `vendor/bin/phpunit --filter=UserAdminContinuityTest` → Expected: PASS.
Run: `vendor/bin/phpunit --filter=UserAdmin` → Expected: PASS (existing user-admin tests unaffected).
Run: `php glueful di:container:compile` (or the project's container-compile command) → Expected: no unresolved-dependency error for `UserAdminController`.
Run: `composer phpcs -- app/Http/Controllers/UserAdminController.php tests/Integration/Authority/UserAdminContinuityTest.php`

Extend `UserAdminContinuityTest` with: status-deactivation of the sole superuser and sole
cross-workspace holder (403); allowed deactivation when another active holder exists; a denied
combined profile+role update leaves account/profile/roles unchanged; successful assignment and
revocation emit actor+target audit records; a throwing audit recorder does not roll back the already
authorized mutation. Together with Task 4's independent-session lock test, the existing
"first deletion succeeds, second deletion is blocked" case proves both serialized recount and the
last-holder outcome.

- [ ] **Step 8: Stage (HELD)**

```bash
git add app/Http/Controllers/UserAdminController.php tests/Integration/Authority/UserAdminContinuityTest.php
```

---

## Task 6: SetupService — install user gets `superuser` + `administrator`

**Files:**
- Modify: `app/Setup/SetupService.php:104-106`
- Modify test: `tests/Integration/Setup/SetupServiceTest.php`

**Interfaces:**
- Consumes: migration 013 (Task 1) roles; `RoleAuthority` for the assertion.

- [ ] **Step 1: Extend the existing fresh-install test**

`tests/Integration/Setup/SetupServiceTest.php` already truncates `users`, `user_roles`, and
`settings` in `setUp()` and drives a real fresh install. Extend
`testInstallCreatesAdminAndSetsInstalledMarker()` after it resolves `$userRow`:

```php
$uuid = (string) $userRow['uuid'];
$slugs = array_map(
    static fn ($role): string => $role->getSlug(),
    $this->container()->get(AegisPermissionProvider::class)->getUserRoles($uuid),
);
self::assertContains('superuser', $slugs);
self::assertContains('administrator', $slugs);
self::assertTrue($this->container()->get(RoleAuthority::class)->isCanonicalSuperuser($uuid));
```

Add the two imports. Do not create a test that calls `markTestSkipped()` when installed; that would
allow the setup invariant to go unproved depending on suite order.

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=testInstallCreatesAdminAndSetsInstalledMarker`
Expected: FAIL — the install user gets only `administrator`.

- [ ] **Step 3: Change the role assignment**

In `app/Setup/SetupService.php`, replace the single role assignment (currently `:104-106`):

```php
            // The install user is the ultimate authority: superuser (carries the tenancy operator
            // permissions via migration 013) + administrator (Thallo CMS content permissions that
            // superuser's enumerated grant list does not include).
            foreach (['superuser', 'administrator'] as $roleSlug) {
                $this->aegis->assignRole($userUuid, $roleSlug);
            }
```

> Remove the now-unused `$adminRoleSlug = (string) config(...)` line if it is no longer referenced. Grep the method for `roles.admin` first; leave the config key in `config/thallo.php` (harmless) unless it is dead everywhere.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter=testInstallCreatesAdminAndSetsInstalledMarker` → Expected: PASS.

- [ ] **Step 5: Regression + phpcs**

Run: `vendor/bin/phpunit --filter=Setup` (existing setup/provision tests) → Expected: PASS.
Run: `composer phpcs -- app/Setup/SetupService.php tests/Integration/Setup/SetupServiceTest.php`

- [ ] **Step 6: Stage (HELD)**

```bash
git add app/Setup/SetupService.php tests/Integration/Setup/SetupServiceTest.php
```

---

## Task 7: `thallo:superuser:grant` command

**Files:**
- Create: `app/Setup/Console/SuperuserGrantCommand.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (services() + commands())
- Test: `tests/Integration/Authority/SuperuserGrantCommandTest.php`

**Interfaces:**
- Consumes: `AegisPermissionProvider::assignRole`, `AuthorityContinuityGuard::runExclusive`, `AuthorityAudit`, `UserRepository::findByUuid`, `BaseCommand` helpers (`confirm`, `success`, `error`, `isInteractive`, `getService`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;
use Symfony\Component\Console\Tester\CommandTester;

final class SuperuserGrantCommandTest extends AppTestCase
{
    private function makeUser(): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid, 'username' => 'u_' . $uuid, 'email' => $uuid . '@x.test',
            'password' => 'x', 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    private function tester(): CommandTester
    {
        $command = $this->container()->get(\App\Setup\Console\SuperuserGrantCommand::class);
        return new CommandTester($command);
    }

    public function testGrantsSuperuserAndAdministratorWithForce(): void
    {
        $uuid = $this->makeUser();
        $exit = $this->tester()->execute(['user-uuid' => $uuid, '--force' => true]);
        self::assertSame(0, $exit);

        $slugs = array_map(
            static fn ($r) => $r->getSlug(),
            $this->container()->get(AegisPermissionProvider::class)->getUserRoles($uuid),
        );
        self::assertContains('superuser', $slugs);
        self::assertContains('administrator', $slugs);
    }

    public function testUnknownUserFails(): void
    {
        $exit = $this->tester()->execute(['user-uuid' => 'nope00000000', '--force' => true]);
        self::assertNotSame(0, $exit);
    }

    public function testIdempotentRerun(): void
    {
        $uuid = $this->makeUser();
        self::assertSame(0, $this->tester()->execute(['user-uuid' => $uuid, '--force' => true]));
        self::assertSame(0, $this->tester()->execute(['user-uuid' => $uuid, '--force' => true]));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=SuperuserGrantCommandTest`
Expected: FAIL — command class does not exist.

- [ ] **Step 3: Create the command**

```php
<?php

declare(strict_types=1);

namespace App\Setup\Console;

use App\Support\AuthorityAudit;
use App\Support\AuthorityContinuityGuard;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'thallo:superuser:grant',
    description: 'Break-glass: grant superuser + administrator to a user (local console only).',
)]
final class SuperuserGrantCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('user-uuid', InputArgument::REQUIRED, 'The user UUID to promote');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Proceed without interactive confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = (string) $input->getArgument('user-uuid');
        $users = $this->getService(UserRepository::class);
        $user = $users->findByUuid($uuid);
        if ($user === null || ($user['status'] ?? null) !== 'active' || ($user['deleted_at'] ?? null) !== null) {
            $this->error("No active user with UUID {$uuid}.");
            return self::FAILURE;
        }

        $force = (bool) $input->getOption('force');
        if (!$force && !$this->isInteractive()) {
            $this->error('Refusing to run non-interactively without --force.');
            return self::FAILURE;
        }
        $label = (string) ($user['email'] ?? $uuid);
        if (!$force && !$this->confirm("Grant superuser + administrator to {$label}?", false)) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $aegis = $this->getService(AegisPermissionProvider::class);
        $guard = $this->getService(AuthorityContinuityGuard::class);
        try {
            $guard->runExclusive(static function () use ($aegis, $uuid): void {
                foreach (['superuser', 'administrator'] as $slug) {
                    if (!$aegis->assignRole($uuid, $slug)) {
                        throw new \RuntimeException("Failed to assign required role '{$slug}'.");
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->error('Superuser grant failed; no roles were changed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->getService(AuthorityAudit::class)->record('security.superuser_granted', 'system:console', $uuid, [
            'roles' => ['superuser', 'administrator'], 'source' => 'cli',
        ]);

        $this->success("Granted superuser + administrator to {$label}.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register in `ThalloServiceProvider`** — services() (beside `CreateAdminCommand`, ~:1397) and the `commands()` list (~:1522):

```php
            SuperuserGrantCommand::class => [
                'class' => SuperuserGrantCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
```

```php
            CreateAdminCommand::class,
            SuperuserGrantCommand::class,
```

Add `use App\Setup\Console\SuperuserGrantCommand;`.

- [ ] **Step 5: Run tests + phpcs**

Extend `SuperuserGrantCommandTest` to cover all spec branches: inactive and soft-deleted users fail;
non-interactive execution without `--force` fails; interactive refusal changes nothing; a forced
second-assignment failure (temporarily rename the `administrator` role inside `try/finally`) returns
failure and rolls back the preceding `superuser` assignment; successful execution writes
`security.superuser_granted`; a throwing audit recorder does not change the successful exit/result.

Run: `vendor/bin/phpunit --filter=SuperuserGrantCommandTest` → Expected: PASS (all branches above,
not only the three happy/unknown/idempotent methods shown initially).
Run: `composer phpcs -- app/Setup/Console/SuperuserGrantCommand.php tests/Integration/Authority/SuperuserGrantCommandTest.php`

- [ ] **Step 6: Stage (HELD)**

```bash
git add app/Setup/Console/SuperuserGrantCommand.php app/Providers/ThalloServiceProvider.php tests/Integration/Authority/SuperuserGrantCommandTest.php
```

---

## Task 8: `thallo:superuser:transfer` command

**Files:**
- Create: `app/Setup/Console/SuperuserTransferCommand.php`
- Modify: `app/Providers/ThalloServiceProvider.php` (services() + commands())
- Test: `tests/Integration/Authority/SuperuserTransferCommandTest.php`

**Interfaces:**
- Consumes: `RoleAuthority::isCanonicalSuperuser`, `AegisPermissionProvider::assignRole/revokeRole`, `AuthorityContinuityGuard::runExclusive`, `UserRepository::findByUuid`, `AuthorityAudit`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Support\RoleAuthority;
use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;
use Symfony\Component\Console\Tester\CommandTester;

final class SuperuserTransferCommandTest extends AppTestCase
{
    private function makeUser(): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid, 'username' => 'u_' . $uuid, 'email' => $uuid . '@x.test',
            'password' => 'x', 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    private function authority(): RoleAuthority
    {
        return new RoleAuthority($this->container()->get(ApplicationContext::class));
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->container()->get(\App\Setup\Console\SuperuserTransferCommand::class));
    }

    public function testTransfersSuperuserFromSourceToTarget(): void
    {
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $from = $this->makeUser();
        $aegis->assignRole($from, 'superuser');
        $to = $this->makeUser();

        $exit = $this->tester()->execute(['from-user-uuid' => $from, 'to-user-uuid' => $to, '--force' => true]);
        self::assertSame(0, $exit);

        self::assertTrue($this->authority()->isCanonicalSuperuser($to));
        self::assertFalse($this->authority()->isCanonicalSuperuser($from));
    }

    public function testIdempotentCompletedRetry(): void
    {
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        $from = $this->makeUser();
        $aegis->assignRole($from, 'superuser');
        $to = $this->makeUser();

        self::assertSame(0, $this->tester()->execute(['from-user-uuid' => $from, 'to-user-uuid' => $to, '--force' => true]));
        // Re-running after completion (target is superuser, source is not) reports success without change.
        self::assertSame(0, $this->tester()->execute(['from-user-uuid' => $from, 'to-user-uuid' => $to, '--force' => true]));
        self::assertTrue($this->authority()->isCanonicalSuperuser($to));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=SuperuserTransferCommandTest`
Expected: FAIL — command does not exist.

- [ ] **Step 3: Create the command**

```php
<?php

declare(strict_types=1);

namespace App\Setup\Console;

use App\Support\AuthorityAudit;
use App\Support\AuthorityContinuityGuard;
use App\Support\RoleAuthority;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'thallo:superuser:transfer',
    description: 'Break-glass: move superuser from one user to another atomically (local console only).',
)]
final class SuperuserTransferCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('from-user-uuid', InputArgument::REQUIRED, 'Current superuser UUID');
        $this->addArgument('to-user-uuid', InputArgument::REQUIRED, 'Destination user UUID');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Proceed without interactive confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $from = (string) $input->getArgument('from-user-uuid');
        $to = (string) $input->getArgument('to-user-uuid');
        if ($from === $to) {
            $this->error('Source and destination must differ.');
            return self::FAILURE;
        }

        $users = $this->getService(UserRepository::class);
        $source = $users->findByUuid($from);
        $destination = $users->findByUuid($to);
        $active = static fn (?array $user): bool => $user !== null
            && ($user['status'] ?? null) === 'active'
            && ($user['deleted_at'] ?? null) === null;
        if (!$active($source)) {
            $this->error("Source user {$from} not found or inactive.");
            return self::FAILURE;
        }
        if (!$active($destination)) {
            $this->error("Destination user {$to} not found or inactive.");
            return self::FAILURE;
        }

        $authority = $this->getService(RoleAuthority::class);
        // Idempotent completed-state: target already superuser and source no longer is.
        if ($authority->isCanonicalSuperuser($to) && !$authority->isCanonicalSuperuser($from)) {
            $this->success('Transfer already complete; nothing to do.');
            return self::SUCCESS;
        }
        if (!$authority->isCanonicalSuperuser($from)) {
            $this->error("Source user {$from} is not an active superuser.");
            return self::FAILURE;
        }

        $force = (bool) $input->getOption('force');
        if (!$force && !$this->isInteractive()) {
            $this->error('Refusing to run non-interactively without --force.');
            return self::FAILURE;
        }
        if (!$force && !$this->confirm("Transfer superuser from {$from} to {$to}?", false)) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $aegis = $this->getService(AegisPermissionProvider::class);
        $guard = $this->getService(AuthorityContinuityGuard::class);
        // Grant the target FIRST (so a superuser always exists), then revoke the source — one txn.
        try {
            $guard->runExclusive(static function () use ($aegis, $from, $to): void {
                if (!$aegis->assignRole($to, 'superuser')) {
                    throw new \RuntimeException('Failed to assign superuser to destination.');
                }
                if (!$aegis->assignRole($to, 'administrator')) {
                    throw new \RuntimeException('Failed to assign administrator to destination.');
                }
                if (!$aegis->revokeRole($from, 'superuser')) {
                    throw new \RuntimeException('Failed to revoke superuser from source.');
                }
            });
        } catch (\Throwable $e) {
            $this->error('Superuser transfer failed; no roles were changed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->getService(AuthorityAudit::class)->record('security.superuser_transferred', 'system:console', $to, [
            'from_user_uuid' => $from, 'to_user_uuid' => $to, 'source' => 'cli',
        ]);

        $this->success("Transferred superuser from {$from} to {$to}.");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register in `ThalloServiceProvider`** (services() + commands()):

```php
            SuperuserTransferCommand::class => [
                'class' => SuperuserTransferCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
```

```php
            SuperuserGrantCommand::class,
            SuperuserTransferCommand::class,
```

Add `use App\Setup\Console\SuperuserTransferCommand;`.

- [ ] **Step 5: Run tests + phpcs**

Extend `SuperuserTransferCommandTest` with: unknown/inactive/soft-deleted source and target failures;
same-user failure; non-interactive refusal; interactive decline; completed-state idempotence only
after both UUIDs resolve; injected second-assignment and revoke failures each roll back every role
change; successful transfer writes `security.superuser_transferred` only after commit; a throwing
audit recorder does not change the committed transfer.

Run: `vendor/bin/phpunit --filter=SuperuserTransferCommandTest` → Expected: PASS (all branches above,
not only the two initial happy/idempotent methods).
Run: `composer phpcs -- app/Setup/Console/SuperuserTransferCommand.php tests/Integration/Authority/SuperuserTransferCommandTest.php`

- [ ] **Step 6: Stage (HELD)**

```bash
git add app/Setup/Console/SuperuserTransferCommand.php app/Providers/ThalloServiceProvider.php tests/Integration/Authority/SuperuserTransferCommandTest.php
```

---

## Task 9: `GET /assignable-roles` endpoint

**Files:**
- Create: `app/Http/Controllers/AssignableRolesController.php`
- Modify: `routes/admin.php` (near the users routes, ~:249), `app/Providers/ThalloServiceProvider.php` (services())
- Test: `tests/Integration/Authority/AssignableRolesEndpointTest.php`

**Interfaces:**
- Consumes: `UserRoleAssignmentPolicy::mayAdd/mayRemove` (Task 3), `RoleRepository` for the full role list, `AegisPermissionProvider::getUserRoles` for the target's current roles, `UserRepository::findByUuid` for edit-target validation, `ActorHelper::uuidFromRequest`.
- Produces: JSON `{ roles: [{ slug, name, assigned?, assignable, removable? }] }` under the standard `data` envelope.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Authority;

use App\Http\Controllers\AssignableRolesController;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

final class AssignableRolesEndpointTest extends AppTestCase
{
    private function makeUser(string ...$roles): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->connection()->table('users')->insert([
            'uuid' => $uuid, 'username' => 'u_' . $uuid, 'email' => $uuid . '@x.test',
            'password' => 'x', 'status' => 'active', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $aegis = $this->container()->get(AegisPermissionProvider::class);
        foreach ($roles as $r) {
            $aegis->assignRole($uuid, $r);
        }
        return $uuid;
    }

    private function requestAs(string $actorUuid, ?string $target = null): Request
    {
        $q = $target !== null ? ['target_uuid' => $target] : [];
        $r = Request::create('/v1/admin/users/assignable-roles', 'GET', $q);
        $r->attributes->set('user', ['uuid' => $actorUuid, 'roles' => [], 'claims' => ['scopes' => []]]);
        return $r;
    }

    private function slugs(\Symfony\Component\HttpFoundation\Response $resp): array
    {
        $data = json_decode((string) $resp->getContent(), true);
        return array_column($data['data']['roles'] ?? [], 'slug');
    }

    public function testSuperuserSeesWorkspaceManagerButAdministratorDoesNot(): void
    {
        $controller = $this->container()->get(AssignableRolesController::class);

        $super = $this->makeUser('superuser');
        self::assertContains('workspace_manager', $this->slugs($controller->index($this->requestAs($super))));
        self::assertNotContains('superuser', $this->slugs($controller->index($this->requestAs($super))));

        $admin = $this->makeUser('administrator');
        self::assertNotContains('workspace_manager', $this->slugs($controller->index($this->requestAs($admin))));
    }

    public function testEditModeIncludesTargetsUnassignableRoleAsLocked(): void
    {
        $controller = $this->container()->get(AssignableRolesController::class);
        $admin = $this->makeUser('administrator');
        $target = $this->makeUser('workspace_manager'); // admin cannot remove this

        $data = json_decode((string) $controller->index($this->requestAs($admin, $target))->getContent(), true);
        $byslug = [];
        foreach ($data['data']['roles'] as $r) {
            $byslug[$r['slug']] = $r;
        }
        self::assertArrayHasKey('workspace_manager', $byslug);
        self::assertTrue($byslug['workspace_manager']['assigned']);
        self::assertFalse($byslug['workspace_manager']['removable']);
    }

    public function testEditModeIncludesAssignedSuperuserAsLocked(): void
    {
        $controller = $this->container()->get(AssignableRolesController::class);
        $actor = $this->makeUser('administrator');
        $target = $this->makeUser('superuser');
        $data = json_decode((string) $controller->index($this->requestAs($actor, $target))->getContent(), true);
        $rows = array_column($data['data']['roles'], null, 'slug');

        self::assertTrue($rows['superuser']['assigned']);
        self::assertFalse($rows['superuser']['assignable']);
        self::assertFalse($rows['superuser']['removable']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit --filter=AssignableRolesEndpointTest`
Expected: FAIL — controller does not exist.

- [ ] **Step 3: Create the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ActorHelper;
use App\Support\UserRoleAssignmentPolicy;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Models\Role;
use Glueful\Extensions\Aegis\Repositories\RoleRepository;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Server-derived role picker. The frontend must not infer superuser status — this returns exactly the
 * roles the caller may assign (create mode) or, with a target, the assignable catalog plus the
 * target's currently-assigned roles as locked entries (edit mode). `superuser` is never offered,
 * but an already-assigned superuser is returned locked so a full-set form cannot erase it.
 */
final class AssignableRolesController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AegisPermissionProvider $aegis,
        private readonly UserRoleAssignmentPolicy $policy,
        private readonly UserRepository $users,
    ) {
    }

    #[ApiOperation(summary: 'List assignable roles', tags: ['Users'])]
    #[ApiResponse(200, description: 'Roles the caller may assign (+ target locked roles in edit mode).')]
    public function index(Request $request): Response
    {
        $actor = ActorHelper::uuidFromRequest($request) ?? '';
        $targetUuid = trim((string) $request->query->get('target_uuid', ''));
        if ($targetUuid !== '' && $this->users->findByUuid($targetUuid) === null) {
            return Response::notFound('User not found.');
        }

        $repo = new RoleRepository(null, $this->context);
        /** @var list<Role> $all */
        $all = array_values(array_filter($repo->findAllRoles(), static fn ($r) => $r instanceof Role));

        $assignedSlugs = [];
        if ($targetUuid !== '') {
            foreach ($this->aegis->getUserRoles($targetUuid) as $role) {
                if ($role instanceof Role) {
                    $assignedSlugs[$role->getSlug()] = $role;
                }
            }
        }

        $out = [];
        foreach ($all as $role) {
            $assigned = isset($assignedSlugs[$role->getSlug()]);
            if ($role->getSlug() === 'superuser') {
                if ($targetUuid === '' || !$assigned) {
                    continue; // never offered; included only when already assigned to this edit target
                }
                $out[] = [
                    'slug' => 'superuser', 'name' => $role->getName(), 'assigned' => true,
                    'assignable' => false, 'removable' => false,
                ];
                continue;
            }
            $canAdd = $this->policy->mayAdd($actor, $role);
            $canRemove = $this->policy->mayRemove($actor, $role);

            // Create mode: only addable roles. Edit mode: addable roles OR roles the target holds.
            if ($targetUuid === '') {
                if (!$canAdd) {
                    continue;
                }
                $out[] = ['slug' => $role->getSlug(), 'name' => $role->getName(), 'assignable' => true];
                continue;
            }
            if (!$canAdd && !$assigned) {
                continue;
            }
            $out[] = [
                'slug' => $role->getSlug(),
                'name' => $role->getName(),
                'assigned' => $assigned,
                'assignable' => $canAdd,
                'removable' => $assigned ? $canRemove : false,
            ];
        }

        return Response::success(['roles' => $out], 'Assignable roles retrieved.');
    }
}
```

> `RoleRepository::findAllRoles(array $filters = []): array` is the verified method (returns `Role[]`). `Role` exposes `getSlug()`, `getName()`, `getLevel()`.

- [ ] **Step 4: Register the route + service**

`routes/admin.php`, near the users routes (~:249), add:

```php
        $router->get('/users/assignable-roles', [AssignableRolesController::class, 'index'])
            ->middleware('content_permission:users.roles.manage');
```

Add `use App\Http\Controllers\AssignableRolesController;` at the top of `routes/admin.php`. Register the controller in `ThalloServiceProvider` services() (autowire), beside `UserAdminController`:

```php
            AssignableRolesController::class => [
                'class' => AssignableRolesController::class,
                'shared' => true,
                'autowire' => true,
            ],
```

Add `use App\Http\Controllers\AssignableRolesController;` to the provider imports.

> Route ordering: register `/users/assignable-roles` BEFORE any `/users/{uuid}` GET (there is none today, but keep the static path first to avoid a param capture). Verify with `php glueful route:list | grep assignable`.

- [ ] **Step 5: Run tests + phpcs**

Add real HTTP coverage (not only direct controller calls): unauthenticated → 401; caller lacking
`users.roles.manage` → 403; authorized caller → 200; unknown `target_uuid` → 404. Assert the route
inventory places this static path before `/users/{uuid}` and retains the enclosing auth/system
middleware.

Run: `vendor/bin/phpunit --filter=AssignableRolesEndpointTest` → Expected: PASS (controller shape +
HTTP authorization/404 coverage).
Run: `composer phpcs -- app/Http/Controllers/AssignableRolesController.php tests/Integration/Authority/AssignableRolesEndpointTest.php`

- [ ] **Step 6: Stage (HELD)**

```bash
git add app/Http/Controllers/AssignableRolesController.php routes/admin.php app/Providers/ThalloServiceProvider.php tests/Integration/Authority/AssignableRolesEndpointTest.php
```

---

## Task 10: SPA user-role picker consumes the endpoint

**Files:**
- Modify: `admin/src/queries/users.ts`
- Modify: `admin/src/pages/users/components/UserCreateModal.vue`
- Modify: `admin/src/pages/users/components/UserDetailsForm.vue`
- Create test: `admin/src/__tests__/userRolePicker.spec.ts`

**Interfaces:**
- Consumes: `GET /v1/admin/users/assignable-roles` (Task 9) with optional `?target_uuid=`.
- As-built facts: both forms currently call `useRoles()` from `queries/rbac.ts`; `UserDetailsForm`
  already tracks `originalRoles` and omits `role_slugs` when `rolesChanged()` is false. Preserve that
  behavior and replace only the picker data source/model hardening.

- [ ] **Step 1: Add the app-owned query to `admin/src/queries/users.ts`**

Use the module's existing `authFetch`, `runtimeConfig`, `toValue`, and Colada conventions:

```ts
export interface AssignableRole {
  slug: string
  name: string
  assigned?: boolean
  assignable: boolean
  removable?: boolean
}

export async function fetchAssignableRoles(targetUuid?: string): Promise<AssignableRole[]> {
  const query = targetUuid ? `?target_uuid=${encodeURIComponent(targetUuid)}` : ''
  const json = await authFetch(`${runtimeConfig.apiBase}/users/assignable-roles${query}`)
  const data = (json.data ?? json) as { roles?: AssignableRole[] }
  return data.roles ?? []
}

export function useAssignableRoles(targetUuid?: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['users', 'assignable-roles', toValue(targetUuid) ?? 'create'],
    query: () => fetchAssignableRoles(toValue(targetUuid)),
  })
}
```

Add a query test to `userRolePicker.spec.ts`: create mode calls
`/v1/admin/users/assignable-roles`; edit mode URL-encodes `target_uuid`; both unwrap `data.roles`.

- [ ] **Step 2: Write the failing component tests**

In `admin/src/__tests__/userRolePicker.spec.ts`, mock `@/queries/users` while preserving the real
types. Mount the two verified components with Nuxt UI form/select stubs. Cover:

```ts
it('preserves a locked role when editable roles change', async () => {
  assignable.value = [
    { slug: 'editor', name: 'Editor', assigned: true, assignable: true, removable: true },
    { slug: 'workspace_manager', name: 'Workspace Manager', assigned: true,
      assignable: false, removable: false },
  ]
  const wrapper = mountDetailsForm(userWithRoles('editor', 'workspace_manager'))
  expect(wrapper.find('[data-testid="role-locked-workspace_manager"]').exists()).toBe(true)
  await chooseRoles(wrapper, [])
  await submitDetails(wrapper)
  expect(update).toHaveBeenCalledWith(expect.objectContaining({
    input: expect.objectContaining({ role_slugs: ['workspace_manager'] }),
  }))
})

it('omits role_slugs for a profile-only edit', async () => {
  const wrapper = mountDetailsForm(userWithRoles('workspace_manager'))
  await editFirstNameAndSubmit(wrapper, 'Ada')
  expect(update.mock.calls[0][0].input).not.toHaveProperty('role_slugs')
})
```

Also assert create mode renders only server-returned addable roles and never the raw Aegis catalog;
an assigned locked `superuser` renders with `data-testid="role-locked-superuser"`; and a forged
selection update cannot remove either locked slug from the component model.

- [ ] **Step 3: Run to verify RED**

Run: `cd admin && pnpm vitest run src/__tests__/userRolePicker.spec.ts`
Expected: FAIL — both forms still consume `useRoles()` and have no locked-role model.

- [ ] **Step 4: Implement create mode**

In `UserCreateModal.vue`, replace `useRoles()` with `useAssignableRoles()` and map only the returned
roles into the existing `USelectMenu`. The create endpoint never returns locked roles. Keep
`role_slugs: roles.value` in the create payload.

- [ ] **Step 5: Implement edit mode with a guarded full-set model**

In `UserDetailsForm.vue`:

- call `useAssignableRoles(() => props.user.uuid)`;
- derive `lockedRoleSlugs` from `assigned && assignable === false && removable === false`;
- expose locked roles as read-only `UBadge`s with `data-testid="role-locked-{slug}"`;
- bind the editable `USelectMenu` to a computed setter that always unions locked slugs back into
  `roles.value`, so portal/UI behavior cannot remove them;
- keep `originalRoles` as the full original set. The existing `rolesChanged()` then omits
  `role_slugs` on profile-only edits and sends the complete editable+locked set after a genuine role
  change.

Do not touch `admin/src/components/tenancy/RolePicker.vue` or workspace membership forms; those remain
the `owner|admin|member|viewer` matrix surface.

- [ ] **Step 6: Run vitest + type-check + lint + build**

```bash
cd admin
pnpm vitest run src/__tests__/userRolePicker.spec.ts
pnpm type-check                        # Expected: 0 errors
pnpm lint                              # Expected: clean
pnpm build                             # Expected: production build succeeds
```

- [ ] **Step 7: Stage (HELD)**

```bash
git add admin/src
```

---

## Task 11: Full regression + local reset dry-run

**Files:** none (verification only).

- [ ] **Step 1: Backend suite**

Run: `composer test` (or `vendor/bin/phpunit`) → Expected: green, including all new `tests/Integration/Authority/*` and existing tenancy/authorization suites (SP3a truth tables, bypass matrices).

- [ ] **Step 2: phpcs (whole diff) + package boundaries**

Run: `composer phpcs` → Expected: clean.
Run: `composer boundaries` → Expected: no new violations.

- [ ] **Step 3: Admin SPA gates**

```bash
cd admin && pnpm type-check && pnpm vitest run && pnpm lint && pnpm build
```
Expected: all green.

- [ ] **Step 4: Complete the local transition begun in Task 0**

On the developer's local instance (NOT in CI):
```bash
php glueful migrate:status                      # old 013 must already be absent from Task 0
php glueful migrate:run                         # applies 013_CreateTenancyAuthorityRoles
php glueful thallo:superuser:grant <current-user-uuid>
php glueful migrate:status                      # 013 shows applied
```
Do **not** call rollback here: Task 1 deleted the old filename, so the migration manager could no
longer resolve its `down()` method. Task 0 is the only rollback point.
Then verify in the SPA: the current user can manage workspaces + see the Workspace Manager role in the global user role picker; a plain administrator cannot.

- [ ] **Step 5: Final staging summary (HELD)**

```bash
git status
# Confirm only Thallo app files are staged; CLAUDE.md is NOT staged.
# Do NOT commit — await explicit go-ahead, then commit in logical slices.
```

---

## Notes for the executor

- **Commit discipline:** every task says "stage (HELD)". Do not commit until the user says so. When they do, group into logical slices (migration+setup; support helpers+policy+guard; controller wiring; CLIs; endpoint+SPA), no AI attribution, exclude CLAUDE.md via explicit `git add`.
- **Contingency (Task 4):** if any assign/revoke/update/soft-delete participation assertion fails,
  implement the relevant transaction-connection seam described there rather than weakening the
  continuity invariant.
- **Harness accessors:** verified `AppTestCase` exposes `appContext()`, `connection()`, and
  `container()`; use those exact protected methods in integration tests.
