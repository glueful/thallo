<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Navigation;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Contracts\Capability\CapabilityRegistry;

final class NavigationCapabilityTest extends AppTestCase
{
    public function testCapabilityRegisteredAndEnabledByDefault(): void
    {
        self::assertTrue(
            $this->container()->get(CapabilityRegistry::class)->isEnabled('thallo.navigation'),
            'thallo.navigation must be registered and enabled by default',
        );
    }
}
