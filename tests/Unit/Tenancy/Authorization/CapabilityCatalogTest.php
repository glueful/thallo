<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Authorization;

use App\Content\Authorization\CapabilityCatalog;
use App\Tests\Support\AppTestCase;

final class CapabilityCatalogTest extends AppTestCase
{
    public function testCatalogCoversMatrixAndExcludesPlatformCapabilities(): void
    {
        $catalog = new CapabilityCatalog();
        foreach ((array) config($this->appContext(), 'tenancy.role_matrix', []) as $capabilities) {
            foreach ((array) $capabilities as $capability) {
                self::assertTrue($catalog->has((string) $capability), (string) $capability);
            }
        }
        self::assertFalse($catalog->has('tenancy.manage'));
        self::assertFalse($catalog->has('tenancy.access_any'));
        self::assertSame(['tenant.roles.manage', 'tenant.members.manage'], $catalog->ownerFloor());
    }

    public function testPolicyHashIsDeterministic(): void
    {
        $catalog = new CapabilityCatalog();
        self::assertSame(
            $catalog->baselinePolicyHash($this->appContext()),
            $catalog->baselinePolicyHash($this->appContext()),
        );
    }
}
