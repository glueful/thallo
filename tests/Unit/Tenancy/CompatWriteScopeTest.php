<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Thallo\Tenancy\Compat\CompatWriteScope;

final class CompatWriteScopeTest extends TestCase
{
    public function testWidenedDisabledModeStampsOwnedTableOnly(): void
    {
        $scope = new CompatWriteScope(false, 'widened', 'tenant123456');

        self::assertSame('compat', $scope->mode());
        self::assertSame(
            ['slug' => 'post', 'tenant_uuid' => 'tenant123456'],
            $scope->stampIfMissing('content_types', ['slug' => 'post']),
        );
        self::assertSame(['key' => 'x'], $scope->stampIfMissing('thallo_system_flags', ['key' => 'x']));
    }

    public function testExistingTenantIsNeverOverwritten(): void
    {
        $scope = new CompatWriteScope(false, 'widened', 'tenant123456');
        self::assertSame(
            ['tenant_uuid' => 'other1234567'],
            $scope->stampIfMissing('content_types', ['tenant_uuid' => 'other1234567']),
        );
    }

    public function testMissingDefaultFailsClosedInCompatMode(): void
    {
        $this->expectException(\RuntimeException::class);
        (new CompatWriteScope(false, 'widened', null))->tenantUuidForWrite();
    }
}
