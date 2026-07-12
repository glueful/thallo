<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Content\Authorization\OperatorBypass;
use App\Content\Authorization\EffectiveRoleMatrix;
use App\Content\Authorization\TenantMembershipRoleReader;
use App\Content\Http\RequirePermission;
use App\Tests\Support\RetrofittedTenantTestCase;
use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;
use Glueful\Helpers\Utils;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TenantAuthorizationTruthTableTest extends RetrofittedTenantTestCase
{
    public function testMemberWithoutAegisRoleDraftsButCannotPublish(): void
    {
        $user = Utils::generateNanoID(12);
        $this->membership(self::$tenantAUuid, $user, 'member');
        $middleware = $this->middleware([]);

        $this->runAsTenant(self::$tenantAUuid, function () use ($middleware, $user): void {
            self::assertSame(200, $this->authorize($middleware, $user, 'content.create')->getStatusCode());
            self::assertSame(200, $this->authorize($middleware, $user, 'content.edit')->getStatusCode());
            self::assertSame(403, $this->authorize($middleware, $user, 'content.publish')->getStatusCode());
        });
    }

    public function testViewerIsMembershipScopedAndExplicitEscalationIsAudited(): void
    {
        $user = Utils::generateNanoID(12);
        $this->membership(self::$tenantAUuid, $user, 'viewer');
        $audit = $this->audit();
        $middleware = $this->middleware([
            'tenancy.access_any' => true,
            'content.delete' => true,
        ], $audit);

        $this->runAsTenant(self::$tenantAUuid, function () use ($middleware, $user, $audit): void {
            self::assertSame(200, $this->authorize($middleware, $user, 'content.view')->getStatusCode());
            self::assertSame(403, $this->authorize($middleware, $user, 'content.delete')->getStatusCode());
            self::assertSame(
                200,
                $this->authorize($middleware, $user, 'content.delete', operatorMode: true)->getStatusCode(),
            );
            self::assertCount(1, $audit->entries);
        });
    }

    public function testResolvedTenantWithIncompleteWiringFailsClosed(): void
    {
        $user = Utils::generateNanoID(12);
        $this->runAsTenant(self::$tenantAUuid, function () use ($user): void {
            $middleware = new RequirePermission($this->appContext());
            self::assertSame(403, $this->authorize($middleware, $user, 'content.view')->getStatusCode());
        });
    }

    public function testAdminHasPackageCapabilitiesAndViewerDoesNot(): void
    {
        $admin = Utils::generateNanoID(12);
        $viewer = Utils::generateNanoID(12);
        $this->membership(self::$tenantAUuid, $admin, 'admin');
        $this->membership(self::$tenantAUuid, $viewer, 'viewer');
        $middleware = $this->middleware([]);
        $capabilities = [
            'navigation.manage',
            'seo.manage',
            'templates.manage',
            'analytics.read',
            'workflow.review',
        ];

        $this->runAsTenant(self::$tenantAUuid, function () use (
            $middleware,
            $admin,
            $viewer,
            $capabilities,
        ): void {
            foreach ($capabilities as $capability) {
                self::assertSame(200, $this->authorize($middleware, $admin, $capability)->getStatusCode());
                self::assertSame(403, $this->authorize($middleware, $viewer, $capability)->getStatusCode());
            }
        });
    }

    public function testMembershipDoesNotLeakIntoAnotherTenant(): void
    {
        $user = Utils::generateNanoID(12);
        $this->membership(self::$tenantAUuid, $user, 'admin');
        $middleware = $this->middleware([]);

        $this->runAsTenant(self::$tenantBUuid, function () use ($middleware, $user): void {
            self::assertSame(403, $this->authorize($middleware, $user, 'content.view')->getStatusCode());
        });
    }

    public function testForeignOperatorNeedsAccessAnyAndMappedManagementPermission(): void
    {
        $user = Utils::generateNanoID(12);
        $audit = $this->audit();
        $middleware = $this->middleware([
            'tenancy.access_any' => true,
            'tenancy.manage' => true,
        ], $audit);

        $this->runAsTenant(self::$tenantBUuid, function () use ($middleware, $user, $audit): void {
            self::assertSame(
                200,
                $this->authorize($middleware, $user, 'tenant.members.manage')->getStatusCode(),
            );
            self::assertCount(1, $audit->entries);
        });
    }

    /** @param array<string,bool> $grants */
    private function middleware(array $grants, ?AuditRecorderInterface $audit = null): RequirePermission
    {
        $permissions = new class ($grants) extends PermissionManager {
            /** @param array<string,bool> $grants */
            public function __construct(private readonly array $grants)
            {
                parent::__construct();
            }

            public function can(string $userUuid, string $permission, string $resource, array $context = []): bool
            {
                return $this->grants[$permission] ?? false;
            }
        };
        return new RequirePermission(
            $this->appContext(),
            $this->container()->get(TenantMembershipRoleReader::class),
            $this->container()->get(EffectiveRoleMatrix::class),
            new OperatorBypass($this->appContext(), $permissions, $audit),
        );
    }

    private function authorize(
        RequirePermission $middleware,
        string $userUuid,
        string $capability,
        bool $operatorMode = false,
    ): Response {
        $request = Request::create('/v1/admin/test');
        $request->attributes->set('auth.user', new UserIdentity(uuid: $userUuid));
        if ($operatorMode) {
            $request->headers->set('X-Tenant-Operator-Mode', '1');
        }
        return $middleware->handle($request, static fn(): Response => new Response('ok', 200), $capability);
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

    private function audit(): object
    {
        return new class implements AuditRecorderInterface {
            /** @var list<AuditEntry> */
            public array $entries = [];

            public function record(AuditEntry $entry): void
            {
                $this->entries[] = $entry;
            }
        };
    }
}
