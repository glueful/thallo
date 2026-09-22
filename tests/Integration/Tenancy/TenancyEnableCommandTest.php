<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Tenancy;

use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Tenancy\Console\TenancyEnableCommand;
use Thallo\Tenancy\Enablement\EnablementStep;
use Thallo\Tenancy\Enablement\EnablementStore;

/**
 * Enabling workspaces from a terminal had no way out of `failed`: retry and cancel existed in the
 * service and the admin only, and a failed run printed the state and stopped. `--owner` took a
 * uuid no shipped command printed.
 */
final class TenancyEnableCommandTest extends AppTestCase
{
    private function tester(): CommandTester
    {
        return new CommandTester(new TenancyEnableCommand($this->container(), self::$app));
    }

    private function store(): EnablementStore
    {
        return $this->container()->get(EnablementStore::class);
    }

    public function testAFailedRunSaysWhyAndHowToGoOn(): void
    {
        $this->store()->recordFailure(EnablementStep::MIGRATING_EXTENSION, 'the migration timed out');

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('the migration timed out', $tester->getDisplay());
        self::assertStringContainsString('--retry', $tester->getDisplay());
        self::assertStringContainsString('--cancel', $tester->getDisplay());
        self::assertSame(EnablementStep::FAILED, $this->store()->step(), 'nothing was attempted');
    }

    public function testRetryResumesFromTheFailedStep(): void
    {
        $this->store()->recordFailure(EnablementStep::MIGRATING_EXTENSION, 'the migration timed out');

        $exit = $this->tester()->execute(['--retry' => true]);

        self::assertSame(0, $exit);
        self::assertNotSame(EnablementStep::FAILED, $this->store()->step());
    }

    public function testCancelLeavesAFailedPreRetrofitRunOff(): void
    {
        $this->store()->recordFailure(EnablementStep::MIGRATING_EXTENSION, 'the migration timed out');

        $exit = $this->tester()->execute(['--cancel' => true]);

        self::assertSame(0, $exit);
        self::assertSame(EnablementStep::OFF, $this->store()->step());
    }

    public function testAnOwnerGivenByAnUnknownEmailIsRefusedBeforeTheRetrofit(): void
    {
        $this->store()->setPendingTenant('first', 'First');
        $this->store()->setStep(EnablementStep::AWAITING_CONFIRM);

        $tester = $this->tester();
        $exit = $tester->execute(['--slug' => 'first', '--name' => 'First', '--owner' => 'nobody@example.com']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('nobody@example.com', $tester->getDisplay());
        self::assertSame(EnablementStep::AWAITING_CONFIRM, $this->store()->step());
    }
}
