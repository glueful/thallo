<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Console;

use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Updates\Console\UpdateCheckCommand;

/** `thallo:update:check` is the operator's view of the notice: what is installed, what is newest. */
final class UpdateCheckCommandTest extends AppTestCase
{
    public function testItReportsTheStatusOfThisInstall(): void
    {
        $tester = new CommandTester(new UpdateCheckCommand($this->container(), self::$app));

        $exit = $tester->execute(['--force' => true]);
        $display = $tester->getDisplay();

        self::assertSame(0, $exit, $display);
        self::assertStringContainsString('development checkout', $display);
        self::assertStringContainsString('composer update && php glueful thallo:provision', $display);
    }
}
