<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Enablement;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\TenancyEnablement;

final class TenancyEnablementBeginTest extends AppTestCase
{
    public function testFirstBeginStopsAtExtensionEnableBoundary(): void
    {
        $status = $this->container()->get(TenancyEnablement::class)->begin();

        self::assertSame(EnablementStep::ENABLING_EXTENSION, $status->step);
        self::assertFalse($status->enabled);
    }
}
