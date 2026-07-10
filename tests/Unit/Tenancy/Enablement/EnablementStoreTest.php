<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Enablement;

use App\Tests\Support\AppTestCase;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;
use Thallo\Tenancy\System\SystemFlags;

final class EnablementStoreTest extends AppTestCase
{
    private function store(): EnablementStore
    {
        return new EnablementStore($this->container()->get(SystemFlags::class));
    }

    public function testDefaultsToOffWhenNothingPersisted(): void
    {
        self::assertSame(EnablementStep::OFF, $this->store()->step());
    }

    public function testSetStepPersists(): void
    {
        $this->store()->setStep(EnablementStep::AWAITING_CONFIRM);

        // Re-fetched instance (same underlying channel) must observe the persisted value.
        self::assertSame(EnablementStep::AWAITING_CONFIRM, $this->store()->step());
    }

    public function testCompareAndSetSucceedsOnMatch(): void
    {
        $s = $this->store();

        self::assertTrue($s->compareAndSet(EnablementStep::OFF, EnablementStep::INSTALLING));
        self::assertSame(EnablementStep::INSTALLING, $s->step());
    }

    public function testCompareAndSetFailsOnStaleExpectation(): void
    {
        $s = $this->store();
        $s->setStep(EnablementStep::INSTALLING);

        self::assertFalse($s->compareAndSet(EnablementStep::OFF, EnablementStep::AWAITING_INSTALL));
        self::assertSame(EnablementStep::INSTALLING, $s->step(), 'a lost CAS must not change the step');
    }

    public function testReloadingRoundTrips(): void
    {
        $s = $this->store();
        $s->setStep(EnablementStep::RELOADING);
        self::assertSame(EnablementStep::RELOADING, $s->step());
    }

    public function testFailureAndPendingTenantRoundTrip(): void
    {
        $s = $this->store();

        self::assertNull($s->failure());
        self::assertNull($s->failedFrom());
        self::assertNull($s->pendingSlug());
        self::assertNull($s->pendingName());

        $s->recordFailure(EnablementStep::RETROFITTING, 'retrofit exploded');
        self::assertSame(EnablementStep::FAILED, $s->step());
        self::assertSame('retrofit exploded', $s->failure());
        self::assertSame(EnablementStep::RETROFITTING, $s->failedFrom());

        $s->recordFailureCleared();
        self::assertNull($s->failure());
        self::assertNull($s->failedFrom());
        self::assertSame(EnablementStep::FAILED, $s->step(), 'clearing the failure must not touch the step');

        $s->setPendingTenant('acme', 'Acme Inc');
        self::assertSame('acme', $s->pendingSlug());
        self::assertSame('Acme Inc', $s->pendingName());

        $s->clearPending();
        self::assertNull($s->pendingSlug());
        self::assertNull($s->pendingName());
    }
}
