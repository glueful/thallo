<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\Controllers\CapabilityAdminController;

/** Purge-observing seam: counts compiled-route-state clears instead of touching real caches. */
final class TestableCapabilityAdminController extends CapabilityAdminController
{
    public int $routeStatePurges = 0;

    protected function clearCompiledRouteState(): void
    {
        $this->routeStatePurges++;
    }
}
