<?php

declare(strict_types=1);

namespace App\Tests\Unit\Contracts;

use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityContractTest extends TestCase
{
    public function testCapabilityValueObjectExposesItsFields(): void
    {
        $cap = new Capability('thallo.forms', ['thallo.collections'], 'Forms', 'Public form submissions');
        self::assertSame('thallo.forms', $cap->id);
        self::assertSame(['thallo.collections'], $cap->requires);
        self::assertSame('Forms', $cap->label);
        self::assertSame('Public form submissions', $cap->description);
    }

    public function testCapabilityDefaults(): void
    {
        $cap = new Capability('thallo.render');
        self::assertSame([], $cap->requires);
        self::assertNull($cap->label);
        self::assertNull($cap->description);
    }

    public function testRegistryContractShape(): void
    {
        self::assertTrue(interface_exists(CapabilityRegistry::class));
        foreach (['register', 'all', 'enabled', 'isEnabled'] as $method) {
            self::assertTrue(
                method_exists(CapabilityRegistry::class, $method),
                "CapabilityRegistry must declare {$method}()"
            );
        }
    }
}
