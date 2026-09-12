<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Console;

use Thallo\Core\Setup\Console\DoctorCommand;
use Thallo\Core\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives thallo:doctor through Symfony's CommandTester. The test repo's own runtime satisfies the
 * pre-prompt checks (PHP 8.3, pdo_pgsql, writable .env/storage), so doctor reports success.
 */
final class DoctorCommandTest extends AppTestCase
{
    public function testDoctorReportsHealthyEnvironment(): void
    {
        $command = new DoctorCommand($this->container(), self::$app);
        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('PHP', $tester->getDisplay());
    }
}
