<?php

declare(strict_types=1);

namespace App\Tests\Integration\Capabilities;

use App\Capabilities\DefaultCapabilityRegistry;
use App\Providers\ThalloServiceProvider;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;

final class CapabilityRegistryWiringTest extends AppTestCase
{
    public function testContractResolvesToTheEngineRegistry(): void
    {
        $reg = $this->container()->get(CapabilityRegistry::class);
        self::assertInstanceOf(DefaultCapabilityRegistry::class, $reg);
    }

    public function testRegistryIsSharedSoRegistrationsPersist(): void
    {
        $reg = $this->container()->get(CapabilityRegistry::class);
        $reg->register(new Capability('test.fake', label: 'Fake'));

        // A second resolve must be the SAME instance (shared) and see the registration.
        $again = $this->container()->get(CapabilityRegistry::class);
        self::assertContains('test.fake', array_map(fn (Capability $c) => $c->id, $again->all()));
        // Default config has no override for test.fake => enabled.
        self::assertTrue($again->isEnabled('test.fake'));
    }

    public function testFactoryReadsTheWholeCapabilitiesMapNotDottedKeys(): void
    {
        // Seed a disabled override for a DOTTED id via the public config-defaults seam.
        // config/thallo.php's `capabilities` is empty, so this default surfaces (defaults
        // merge UNDER file config). A correct factory reads the whole `lemma.capabilities`
        // map and sees `test.fake => false`. A buggy dotted-access impl
        // (config('thallo.capabilities.test.fake')) would walk capabilities['test']['fake'],
        // never find the literal-key 'test.fake', fall back to the default, and wrongly
        // ENABLE it — failing this test.
        $this->appContext()->mergeConfigDefaults('thallo', ['capabilities' => ['test.fake' => false]]);

        // Call the factory directly to build a FRESH registry from the (now-seeded) config,
        // bypassing the shared singleton.
        $reg = ThalloServiceProvider::makeCapabilityRegistry($this->container());
        $reg->register(new Capability('test.fake'));

        self::assertFalse(
            $reg->isEnabled('test.fake'),
            'factory must read the whole lemma.capabilities map (full id key), not via dotted access',
        );
    }
}
