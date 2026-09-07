<?php

declare(strict_types=1);

namespace App\Tests\Unit\Setup;

use App\Providers\ThalloServiceProvider;
use App\Setup\Console\CreateAdminCommand;
use App\Setup\Console\DoctorCommand;
use App\Setup\Console\ProvisionCommand;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The first-run commands must exist BEFORE anything can go wrong: boot() needs a reachable
 * database and, in production, a provider boot failure is logged and skipped — so commands
 * registered in boot() silently vanish exactly when the operator needs `thallo:doctor` and
 * `thallo:provision` to tell them what is wrong. They register in register(), which runs first
 * and touches nothing external.
 */
final class SetupCommandsRegisterBeforeBootTest extends TestCase
{
    public function testDoctorProvisionAndCreateAdminAreDeferredFromRegister(): void
    {
        ServiceProvider::flushDeferredCommands(); // clean slate

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException("register() must not resolve services (asked for {$id})");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $context = new ApplicationContext(dirname(__DIR__, 3), 'testing');

        (new ThalloServiceProvider($container))->register($context);

        $deferred = ServiceProvider::flushDeferredCommands();
        foreach ([DoctorCommand::class, ProvisionCommand::class, CreateAdminCommand::class] as $command) {
            self::assertContains($command, $deferred, "{$command} must be registered in register(), not boot()");
        }
    }
}
