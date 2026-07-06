<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Capability\CapabilityRegistry;

final class CapabilityRegistrationTest extends AppTestCase
{
    public function testCollectionsCapabilityIsRegisteredAndEnabled(): void
    {
        $caps = $this->container()->get(CapabilityRegistry::class);
        self::assertTrue($caps->isEnabled('lemma.collections'));
    }
}
