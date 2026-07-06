<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Setup\Console\CreateAdminCommand;
use App\Setup\SetupService;
use App\Tests\Support\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateAdminCommandTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // uuid-keyed users table => TRUNCATE ... CASCADE is the reliable wipe.
        $this->connection()->getPDO()->exec('TRUNCATE TABLE users, user_roles, settings CASCADE');
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new CreateAdminCommand($this->container(), self::$app));
    }

    private function service(): SetupService
    {
        return $this->container()->get(SetupService::class);
    }

    public function testCreatesFirstAdmin(): void
    {
        $exit = $this->tester()->execute([
            '--admin-email' => 'admin@example.com',
            '--admin-password' => 'a-strong-password',
            '--site-name' => 'Demo',
        ], ['interactive' => false]);

        self::assertSame(0, $exit);
        self::assertTrue($this->service()->isInstalled());
    }

    public function testAlreadyInstalledExitsSuccessWithoutSecondAdmin(): void
    {
        $this->service()->install('Demo', 'first@example.com', 'a-strong-password', 'en');

        $tester = $this->tester();
        $exit = $tester->execute([
            '--admin-email' => 'second@example.com',
            '--admin-password' => 'a-strong-password',
        ], ['interactive' => false]);

        self::assertSame(0, $exit);
        self::assertStringContainsStringIgnoringCase('already installed', $tester->getDisplay());
        self::assertNull(
            $this->container()->get(\Glueful\Extensions\Users\Repositories\UserRepository::class)
                ->findByEmail('second@example.com'),
            'no second admin is created',
        );
    }

    public function testQuietMissingAdminEmailFailsFast(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['--admin-password' => 'a-strong-password'], ['interactive' => false]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('admin-email', $tester->getDisplay());
        self::assertFalse($this->service()->isInstalled());
    }
}
