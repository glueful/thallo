<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Authorization;

use App\Content\Authorization\RoleMatrix;
use App\Tests\Support\AppTestCase;

final class RoleMatrixTest extends AppTestCase
{
    /** @dataProvider decisions */
    public function testMatrixDecisions(string $role, string $capability, bool $expected): void
    {
        $matrix = new RoleMatrix($this->appContext());
        self::assertSame($expected, $matrix->allows($role, $capability));
    }

    /** @return iterable<string,array{string,string,bool}> */
    public static function decisions(): iterable
    {
        yield 'owner manages members' => ['owner', 'tenant.members.manage', true];
        yield 'admin publishes' => ['admin', 'content.publish', true];
        yield 'admin cannot manage members' => ['admin', 'tenant.members.manage', false];
        yield 'member edits' => ['member', 'content.edit', true];
        yield 'member cannot publish' => ['member', 'content.publish', false];
        yield 'viewer views' => ['viewer', 'content.view', true];
        yield 'viewer cannot create' => ['viewer', 'content.create', false];
        yield 'unknown role denied' => ['ghost', 'content.view', false];
        yield 'unknown capability denied' => ['owner', 'tenant.billing.manage', false];
    }

    public function testCapabilitiesRoundTripAndFormAClosedUnion(): void
    {
        $matrix = new RoleMatrix($this->appContext());
        self::assertSame(config($this->appContext(), 'tenancy.role_matrix'), $matrix->capabilities());
        self::assertTrue($matrix->isTenantCapability('workflow.review'));
        self::assertFalse($matrix->isTenantCapability('system.access'));
    }
}
