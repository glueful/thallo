<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Resolution;

use App\Tests\Support\AppTestCase;
use RuntimeException;
use Thallo\Tenancy\Resolution\ResolutionActivationStep;
use Thallo\Tenancy\Resolution\ResolutionActivationStore;
use Thallo\Tenancy\System\SystemFlags;

final class ResolutionActivationStoreTest extends AppTestCase
{
    public function testCompleteFullCommitsFlagAndStepTogether(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.resolution_step', ResolutionActivationStep::REBUILDING_ROUTES->value);
        $firstBoot = new ResolutionActivationStore($flags, $this->connection(), null, 'boot-a');
        self::assertTrue($firstBoot->markAwaitingFreshBoot(ResolutionActivationStep::REBUILDING_ROUTES));
        self::assertFalse($firstBoot->completeFull(ResolutionActivationStep::AWAITING_FRESH_BOOT));
        $store = new ResolutionActivationStore($flags, $this->connection(), null, 'boot-b');

        self::assertTrue($store->completeFull(ResolutionActivationStep::AWAITING_FRESH_BOOT));
        self::assertSame('full', $flags->get('tenancy.resolution'));
        self::assertSame(ResolutionActivationStep::FULL, $store->step());
    }

    public function testCompleteFullRollsBackBothWritesOnInterruption(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.resolution_step', ResolutionActivationStep::REBUILDING_ROUTES->value);
        $firstBoot = new ResolutionActivationStore($flags, $this->connection(), null, 'boot-a');
        self::assertTrue($firstBoot->markAwaitingFreshBoot(ResolutionActivationStep::REBUILDING_ROUTES));
        $store = new ResolutionActivationStore(
            $flags,
            $this->connection(),
            static fn () => throw new RuntimeException('interrupted'),
            'boot-b'
        );

        try {
            $store->completeFull(ResolutionActivationStep::AWAITING_FRESH_BOOT);
            self::fail('The failpoint must interrupt the transaction.');
        } catch (RuntimeException) {
            self::assertNull($flags->get('tenancy.resolution'));
            self::assertSame(ResolutionActivationStep::AWAITING_FRESH_BOOT, $store->step());
        }
    }

    public function testDeactivateClearsFullResolutionAtomically(): void
    {
        $flags = $this->container()->get(SystemFlags::class);
        $flags->put('tenancy.resolution', 'full');
        $flags->put('tenancy.resolution_step', ResolutionActivationStep::FULL->value);
        $flags->put('tenancy.resolution_failure', 'old');
        $store = new ResolutionActivationStore($flags, $this->connection());

        self::assertTrue($store->deactivate(ResolutionActivationStep::FULL));
        self::assertSame(ResolutionActivationStep::INACTIVE, $store->step());
        self::assertNull($flags->get('tenancy.resolution'));
        self::assertNull($flags->get('tenancy.resolution_failure'));
    }
}
