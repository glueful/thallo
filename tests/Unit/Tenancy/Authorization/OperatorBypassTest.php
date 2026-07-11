<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Authorization;

use App\Content\Authorization\OperatorBypass;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\Request;

final class OperatorBypassTest extends AppTestCase
{
    public function testMembershipWinsWithoutExplicitHeader(): void
    {
        $bypass = new OperatorBypass($this->appContext(), $this->permissions(['*' => true]));
        $decision = $bypass->evaluate(
            Request::create('/entries'),
            'user00000001',
            'viewer',
            'content.delete',
            'tenant000001',
            [],
        );
        self::assertFalse($decision->granted);
        self::assertSame('membership_wins', $decision->reason);
    }

    public function testForeignAndEscalatedGrantsAreCapabilityCheckedAndAudited(): void
    {
        $audit = $this->audit();
        $permissions = $this->permissions([
            'tenancy.access_any' => true,
            'content.delete' => true,
            'tenancy.manage' => true,
        ]);
        $bypass = new OperatorBypass($this->appContext(), $permissions, $audit);

        $foreign = $bypass->evaluate(
            Request::create('/entries'),
            'user00000001',
            null,
            'content.delete',
            'tenant000001',
            [],
        );
        $request = Request::create('/members');
        $request->headers->set('X-Tenant-Operator-Mode', '1');
        $escalated = $bypass->evaluate(
            $request,
            'user00000001',
            'viewer',
            'tenant.members.manage',
            'tenant000001',
            [],
        );

        self::assertTrue($foreign->granted);
        self::assertSame('foreign', $foreign->mode);
        self::assertTrue($escalated->granted);
        self::assertSame('escalated', $escalated->mode);
        self::assertCount(2, $audit->entries);
    }

    public function testDecideDoesNotAuditButEvaluateStillDoes(): void
    {
        $audit = $this->audit();
        $bypass = new OperatorBypass(
            $this->appContext(),
            $this->permissions(['tenancy.access_any' => true, 'tenancy.manage' => true]),
            $audit,
        );
        $request = Request::create('/members');

        $decision = $bypass->decide(
            $request,
            'user00000001',
            null,
            'tenant.members.manage',
            'tenant000001',
            [],
        );

        self::assertTrue($decision->granted);
        self::assertCount(0, $audit->entries);

        $bypass->evaluate(
            $request,
            'user00000001',
            null,
            'tenant.members.manage',
            'tenant000001',
            [],
        );
        self::assertCount(1, $audit->entries);
    }

    public function testMissingGrantsAndUnknownTenantCapabilitiesDeny(): void
    {
        $withoutAccess = new OperatorBypass(
            $this->appContext(),
            $this->permissions(['content.delete' => true]),
        );
        self::assertFalse($withoutAccess->evaluate(
            Request::create('/'),
            'user00000001',
            null,
            'content.delete',
            'tenant000001',
            [],
        )->granted);

        $unknown = new OperatorBypass(
            $this->appContext(),
            $this->permissions(['tenancy.access_any' => true, 'tenancy.manage' => true]),
        );
        self::assertSame('unknown_tenant_capability', $unknown->evaluate(
            Request::create('/'),
            'user00000001',
            null,
            'tenant.billing.manage',
            'tenant000001',
            [],
        )->reason);
    }

    /** @param array<string,bool> $grants */
    private function permissions(array $grants): PermissionManager
    {
        return new class ($grants) extends PermissionManager {
            /** @param array<string,bool> $grants */
            public function __construct(private readonly array $grants)
            {
                parent::__construct();
            }

            public function can(string $userUuid, string $permission, string $resource, array $context = []): bool
            {
                return $this->grants[$permission] ?? $this->grants['*'] ?? false;
            }
        };
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
