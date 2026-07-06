<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Setup\Console\ProvisionCommand;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the "fail loudly before any side effect" guarantee. An invalid DB field (here a
 * non-numeric --db-port override) must abort BEFORE the framework Installer runs — so the
 * command never writes .env or migrates against the real repo during the test.
 */
final class ProvisionCommandTest extends AppTestCase
{
    public function testInvalidDbPortFailsBeforeInstaller(): void
    {
        $command = new ProvisionCommand($this->container(), self::$app);
        $tester = new CommandTester($command);

        $exit = $tester->execute(['--db-port' => 'not-a-number'], ['interactive' => false]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('port', $tester->getDisplay());
    }
}
