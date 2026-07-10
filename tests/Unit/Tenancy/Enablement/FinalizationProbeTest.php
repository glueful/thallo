<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Enablement;

use App\Tests\Support\AppTestCase;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Thallo\Tenancy\Cache\TenantCacheSegment;
use Thallo\Tenancy\Enablement\FinalizationProbe;
use Thallo\Tenancy\System\SystemFlags;

final class FinalizationProbeTest extends AppTestCase
{
    public function testFinalizeProbeFailsWhenTenancyIsOff(): void
    {
        $probe = $this->container()->get(FinalizationProbe::class);

        $report = $probe->report($this->appContext());

        self::assertFalse($report['enabled']);
        self::assertFalse($report['ok']);
    }

    public function testFinalizeProbeFailsWhenBlobSeamsAreMissing(): void
    {
        $probe = new FinalizationProbe(
            $this->container()->get(SystemFlags::class),
            $this->container()->get(Connection::class),
            $this->container()->get(TenantRuntimeReadiness::class),
            $this->container()->get(TenantCacheSegment::class),
        );

        $report = $probe->report($this->appContext());

        self::assertFalse($report['blobPolicy']);
        self::assertFalse($report['ok']);
    }
}
