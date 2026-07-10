<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Enablement;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\Enablement\EnablementException;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\Enablement\TenancyEnablement;

final class TenancyEnablementRecoveryTest extends AppTestCase
{
    public function testRetryReturnsToRecordedStepAndClearsFailure(): void
    {
        $store = $this->container()->get(EnablementStore::class);
        $store->recordFailure(EnablementStep::MIGRATING_EXTENSION, 'failed');

        $status = $this->container()->get(TenancyEnablement::class)->retry();

        self::assertSame(EnablementStep::MIGRATING_EXTENSION, $status->step);
        self::assertNull($status->failure);
    }

    public function testCancelReturnsPreRetrofitStepToOff(): void
    {
        $store = $this->container()->get(EnablementStore::class);
        $store->setPendingTenant('tenant-one', 'Tenant One');
        $store->setStep(EnablementStep::AWAITING_CONFIRM);

        $status = $this->container()->get(TenancyEnablement::class)->cancel();

        self::assertSame(EnablementStep::OFF, $status->step);
        self::assertNull($status->pendingSlug);
    }

    public function testCancelRejectsPostRetrofitState(): void
    {
        $this->container()->get(EnablementStore::class)->setStep(EnablementStep::RELOADING);

        $this->expectException(EnablementException::class);
        $this->container()->get(TenancyEnablement::class)->cancel();
    }
}
