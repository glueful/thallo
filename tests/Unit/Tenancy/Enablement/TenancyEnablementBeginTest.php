<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Tenancy\Enablement;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\TenancyEnablement;

final class TenancyEnablementBeginTest extends AppTestCase
{
    public function testFirstBeginStopsAtMigrationBoundary(): void
    {
        $status = $this->container()->get(TenancyEnablement::class)->begin();

        self::assertSame(EnablementStep::MIGRATING_EXTENSION, $status->step);
        self::assertFalse($status->enabled);
    }
}
