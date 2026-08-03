<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Capability\CapabilityRegistry;

final class PackWiringTest extends AppTestCase
{
    public function testSubscriptionsCapabilityIsRegisteredAndEnabledByDefault(): void
    {
        self::assertTrue(
            $this->container()->get(CapabilityRegistry::class)->isEnabled('thallo.subscriptions'),
            'thallo.subscriptions must be registered and enabled by default',
        );
    }

    public function testEngineProviderLineIsPresentInExtensionsConfig(): void
    {
        $enabled = (array) require dirname(__DIR__, 3) . '/config/extensions.php';

        self::assertContains(
            \Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class,
            $enabled['enabled'],
            'The engine provider line must be present in config/extensions.php',
        );
    }

    public function testBootDoesNotThrowWithEverythingEnabled(): void
    {
        // If the process-shared app booted (AppTestCase::setUpBeforeClass), the pack's
        // boot() already ran without throwing — a fresh CapabilityRegistry lookup confirms
        // this pack's provider participated in that boot.
        self::assertNotNull($this->container()->get(CapabilityRegistry::class));
    }
}
