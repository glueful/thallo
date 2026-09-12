<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Tenancy\Retrofit;

use Thallo\Core\Content\Retention\VersionPruner;
use Thallo\Core\Tests\Support\RetrofittedTenantTestCase;
use Thallo\Tenancy\Retrofit\MutationBoundaryLock;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;

final class RawWriteBoundaryTest extends RetrofittedTenantTestCase
{
    public function testRawWriterIsRejectedWhileRetrofitHoldsExclusiveLock(): void
    {
        $lock = $this->container()->get(MutationBoundaryLock::class);
        $lock->acquireExclusive();
        $threw = false;
        try {
            $this->container()->get(VersionPruner::class)->deleteGuarded(['version00001']);
        } catch (RetrofitInProgressException) {
            $threw = true;
        } finally {
            $lock->releaseExclusive();
        }

        self::assertTrue($threw);
    }
}
