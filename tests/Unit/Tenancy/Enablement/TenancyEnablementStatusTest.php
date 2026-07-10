<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Enablement;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\Enablement\TenancyEnablement;

final class TenancyEnablementStatusTest extends AppTestCase
{
    public function testFreshInstallReportsOff(): void
    {
        $status = $this->container()->get(TenancyEnablement::class)->status()->toArray();

        self::assertSame('off', $status['step']);
        self::assertSame('none', $status['mode']);
        self::assertSame(0, $status['progress']);
        self::assertFalse($status['enabled']);
        self::assertFalse($status['reloading']);
    }
}
