<?php

declare(strict_types=1);

namespace App\Tests\Integration\Navigation;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Capability\CapabilityRegistry;

final class NavigationCapabilityTest extends AppTestCase
{
    public function testCapabilityRegisteredAndEnabledByDefault(): void
    {
        self::assertTrue(
            $this->container()->get(CapabilityRegistry::class)->isEnabled('lemma.navigation'),
            'lemma.navigation must be registered and enabled by default',
        );
    }
}
