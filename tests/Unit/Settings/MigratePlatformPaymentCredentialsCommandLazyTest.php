<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settings;

use App\Providers\ThalloServiceProvider;
use App\Settings\Console\MigratePlatformPaymentCredentialsCommand;
use Glueful\Bootstrap\ApplicationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The console registers every command class at startup. This one's collaborators need the
 * encryption service, whose constructor refuses to exist without APP_KEY — so before first
 * run the console logged "Failed to register deferred command … Encryption key not
 * configured" on every invocation. Construction must not touch those collaborators; they are
 * resolved when the command actually runs.
 */
final class MigratePlatformPaymentCredentialsCommandLazyTest extends TestCase
{
    public function testTheContainerFactoryConstructsTheCommandWithoutResolvingItsCollaborators(): void
    {
        $context = new ApplicationContext(dirname(__DIR__, 3), 'testing');
        $container = new class ($context) implements ContainerInterface {
            public function __construct(private readonly ApplicationContext $context)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === ApplicationContext::class) {
                    return $this->context;
                }
                throw new \LogicException("construction must not resolve {$id}");
            }

            public function has(string $id): bool
            {
                return $id === ApplicationContext::class;
            }
        };

        $definition = ThalloServiceProvider::services()[MigratePlatformPaymentCredentialsCommand::class];
        self::assertIsCallable(
            $definition['factory'] ?? null,
            'the command needs an explicit (lazy) factory, not autowiring',
        );

        $command = ($definition['factory'])($container);

        self::assertInstanceOf(MigratePlatformPaymentCredentialsCommand::class, $command);
    }
}
