<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Thallo\Contracts\Extensions\ExtensionStateCoordinator;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Thallo's writers of the enabled-provider list hold the framework's extension-state lock
 * (ExtensionStateMutex: an flock on storage/framework/locks/extension-state.lock, on every driver)
 * for their whole sequence, and release it even when the sequence throws.
 */
final class ExtensionStateLockTest extends AppTestCase
{
    /** Whether another holder could take the lock right now (a second handle on the lock file). */
    private function anotherHolderCouldTakeIt(): bool
    {
        $dir = dirname(__DIR__, 3) . '/storage/framework/locks';
        @mkdir($dir, 0775, true);
        $handle = fopen($dir . '/extension-state.lock', 'c');
        self::assertIsResource($handle);
        try {
            $got = flock($handle, LOCK_EX | LOCK_NB);
            if ($got) {
                flock($handle, LOCK_UN);
            }
            return $got;
        } finally {
            fclose($handle);
        }
    }

    public function testTheSequenceRunsHoldingTheFrameworksLockAndReleasesItAfter(): void
    {
        $lock = $this->container()->get(ExtensionStateCoordinator::class);
        $heldInside = $lock->within(fn (): bool => !$this->anotherHolderCouldTakeIt());
        self::assertTrue($heldInside, 'another holder cannot take the lock inside');
        self::assertTrue($this->anotherHolderCouldTakeIt(), 'released after');
    }

    public function testTheLockIsReleasedWhenTheSequenceThrows(): void
    {
        try {
            $this->container()->get(ExtensionStateCoordinator::class)->within(static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        self::assertTrue($this->anotherHolderCouldTakeIt());
    }

    public function testASequenceThatReachesTheFrameworksLockDoesntWaitOnItself(): void
    {
        $lock = $this->container()->get(ExtensionStateCoordinator::class);
        $ran = $lock->within(fn (): string => \Glueful\Extensions\ExtensionStateMutex::within(
            $this->appContext(),
            static fn (): string => 'inner ran',
        ));
        self::assertSame('inner ran', $ran);
    }
}
