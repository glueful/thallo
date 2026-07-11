<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy\Retrofit;

use App\Content\Retention\VersionPruner;
use App\Tests\Support\RetrofittedTenantTestCase;
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
