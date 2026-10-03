<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Capabilities;

use Glueful\Database\Connection;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Console\CapabilitiesStatusCommand;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\CapabilityBaseline;

/**
 * An activation capability is on only by its stored switch (spec §7.3a): `true` in
 * `thallo.capabilities` never turns it on. The status command and the workspace seed's view of
 * which capabilities are on read the stored switch alone, as the registry does.
 */
final class ActivationStoredStateTest extends AppTestCase
{
    private static ?ContainerInterface $configuredOn = null;

    protected function tearDown(): void
    {
        CapabilityBaseline::restore($this->connection()->getPDO());
        parent::tearDown();
    }

    /** An application whose configuration says Commerce is on, with no switch stored. */
    private function configuredOn(): ContainerInterface
    {
        self::$configuredOn ??= self::bootAppWithConfigOverride(
            'thallo',
            ['capabilities' => ['thallo.commerce' => true]],
        )->getContainer();
        $this->connection()->getPDO()->exec(
            "DELETE FROM thallo_system_flags WHERE key = 'capability.thallo.commerce.enabled'"
        );
        return self::$configuredOn;
    }

    public function testTheStatusCommandIgnoresAConfiguredOn(): void
    {
        $container = $this->configuredOn();
        $tester = new CommandTester($container->get(CapabilitiesStatusCommand::class));
        $tester->execute([]);
        self::assertMatchesRegularExpression('/thallo\.commerce\s*\|\s*off\b/', $tester->getDisplay());
    }

    public function testTheWorkspaceSeedSeesAConfiguredOnAsOff(): void
    {
        $container = $this->configuredOn();
        $db = $container->get(Connection::class);
        $states = $db->transaction(static fn (): array => $container->get(ActivationStore::class)->shareAll());
        self::assertFalse($states['thallo.commerce']['on']);
    }
}
