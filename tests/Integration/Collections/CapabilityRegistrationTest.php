<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use Thallo\Contracts\Capability\CapabilityRegistry;

final class CapabilityRegistrationTest extends CollectionsTestCase
{
    public function testCollectionsCapabilityIsRegisteredAndEnabled(): void
    {
        $caps = $this->container()->get(CapabilityRegistry::class);
        self::assertTrue($caps->isEnabled('thallo.collections'));
    }
}
