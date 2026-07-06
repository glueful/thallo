<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Setup\Console\DoctorCommand;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives lemma:doctor through Symfony's CommandTester. The test repo's own runtime satisfies the
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
