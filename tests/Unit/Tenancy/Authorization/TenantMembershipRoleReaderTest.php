<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Authorization;

use App\Content\Authorization\TenantMembershipRoleReader;
use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

final class TenantMembershipRoleReaderTest extends AppTestCase
{
    public function testRoleLookupIsMemoizedOnlyWithinOneRequest(): void
    {
        $tenant = Utils::generateNanoID(12);
        $user = Utils::generateNanoID(12);
        $this->seedMembership($tenant, $user, 'member', 'active');
        $reader = $this->reader($tenant);
        $firstRequest = Request::create('/');

        self::assertSame('member', $reader->roleFor($firstRequest, $user));
        $this->connection()->table('tenant_memberships')
            ->where('tenant_uuid', '=', $tenant)
            ->where('user_uuid', '=', $user)
            ->update(['role' => 'viewer']);
        self::assertSame('member', $reader->roleFor($firstRequest, $user));
        self::assertSame('viewer', $reader->roleFor(Request::create('/'), $user));
    }

    public function testInactiveMissingAndUnresolvedMembershipsDeny(): void
    {
        $tenant = Utils::generateNanoID(12);
        $user = Utils::generateNanoID(12);
        $this->seedMembership($tenant, $user, 'admin', 'suspended');

        self::assertNull($this->reader($tenant)->roleFor(Request::create('/'), $user));
        self::assertNull($this->reader($tenant)->roleFor(Request::create('/'), Utils::generateNanoID(12)));
        self::assertNull($this->reader('')->roleFor(Request::create('/'), $user));
    }

    private function reader(string $tenantUuid): TenantMembershipRoleReader
    {
        $resolver = new class ($tenantUuid) implements CurrentTenantResolver {
            public function __construct(private readonly string $uuid)
            {
            }

            public function tenantUuid(ApplicationContext $context): string
            {
                return $this->uuid;
            }
        };
        return new TenantMembershipRoleReader($this->appContext(), $resolver);
    }

    private function seedMembership(string $tenantUuid, string $userUuid, string $role, string $status): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('tenants')->insert([
            'uuid' => $tenantUuid,
            'slug' => $tenantUuid,
            'name' => $tenantUuid,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->connection()->table('tenant_memberships')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'user_uuid' => $userUuid,
            'role' => $role,
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
