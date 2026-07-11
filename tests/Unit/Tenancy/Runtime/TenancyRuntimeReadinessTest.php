<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Runtime;

use App\Tests\Support\AppTestCase;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;

final class TenancyRuntimeReadinessTest extends AppTestCase
{
    public function testNotReadyWhenTenancyIsOff(): void
    {
        $readiness = $this->container()->get(TenantRuntimeReadiness::class);

        self::assertFalse($readiness->isReady($this->appContext()));
        self::assertSame(TenantRuntimeReadiness::MODE_NONE, $readiness->mode($this->appContext()));
    }
}
