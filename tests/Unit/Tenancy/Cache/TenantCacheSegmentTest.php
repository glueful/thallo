<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Cache;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\Cache\MissingTenantForCacheException;
use Thallo\Tenancy\Cache\TenantCacheSegment;
use Thallo\Tenancy\System\SystemFlags;

final class TenantCacheSegmentTest extends AppTestCase
{
    public function testNoSegmentWhenScopingIsOff(): void
    {
        self::assertSame(
            '',
            $this->container()->get(TenantCacheSegment::class)->segment($this->appContext()),
        );
    }

    public function testEnabledWithoutResolverFailsClosed(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.enabled', '1');

        $this->expectException(MissingTenantForCacheException::class);
        (new TenantCacheSegment($flags))->segment($this->appContext(), 'render');
    }
}
