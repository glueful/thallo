<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Enablement;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\RecordingExtensionActivation;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Thallo\Tenancy\Cache\CacheTransition;
use Thallo\Tenancy\Enablement\EnablementException;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\Enablement\TenancyEnablement;
use Thallo\Tenancy\Enablement\FinalizationProbe;
use Thallo\Tenancy\Retrofit\RetrofitMaintenanceGuard;
use Thallo\Tenancy\System\SystemFlags;

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

    public function testRetriedMigrationResumesToAwaitingConfirm(): void
    {
        $store = $this->container()->get(EnablementStore::class);
        $store->recordFailure(EnablementStep::MIGRATING_EXTENSION, 'failed');

        $enablement = $this->container()->get(TenancyEnablement::class);
        $enablement->retry();
        $status = $enablement->begin();

        self::assertSame(EnablementStep::AWAITING_CONFIRM, $status->step);
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

    public function testEnforcementActivationFailureResumesWithoutRetrofit(): void
    {
        $store = $this->container()->get(EnablementStore::class);
        $store->setStep(EnablementStep::ENABLING_ENFORCEMENT);
        $activation = new RecordingExtensionActivation(failNextActivation: true);
        $enablement = $this->service($activation);

        self::assertSame(EnablementStep::FAILED, $enablement->begin()->step);
        self::assertSame(EnablementStep::ENABLING_ENFORCEMENT, $store->failedFrom());
        self::assertFalse($this->container()->get(SystemFlags::class)->tenancyEnabled());

        self::assertSame(EnablementStep::ENABLING_ENFORCEMENT, $enablement->retry()->step);
        self::assertSame(EnablementStep::RELOADING, $enablement->begin()->step);
        self::assertSame(2, $activation->activateCalls);
    }

    public function testLegacyStepsAdvanceToMigrationWithoutActivatingEnforcement(): void
    {
        $activation = new RecordingExtensionActivation();
        $enablement = $this->service($activation);
        $store = $this->container()->get(EnablementStore::class);

        foreach (
            [
            EnablementStep::INSTALLING,
            EnablementStep::AWAITING_INSTALL,
            EnablementStep::ENABLING_EXTENSION,
            EnablementStep::AWAITING_PROVIDER_BOOT,
            ] as $legacy
        ) {
            $store->setStep($legacy);
            self::assertSame(EnablementStep::MIGRATING_EXTENSION, $enablement->begin()->step);
        }
        self::assertSame(0, $activation->activateCalls);
    }

    private function service(RecordingExtensionActivation $activation): TenancyEnablement
    {
        return new TenancyEnablement(
            $this->appContext(),
            $this->container()->get(EnablementStore::class),
            $this->container()->get(EnablementLock::class),
            $this->container()->get(SystemFlags::class),
            $activation,
            $this->container()->get(FinalizationProbe::class),
            $this->container()->get(TenantRuntimeReadiness::class),
            $this->container()->get(RetrofitMaintenanceGuard::class),
            $this->container()->get(CacheTransition::class),
            $this->container()->get(Connection::class),
        );
    }
}
