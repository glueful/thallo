<?php

declare(strict_types=1);

namespace App\Tests\Unit\Capabilities;

use App\Capabilities\DefaultCapabilityRegistry;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class DefaultCapabilityRegistryTest extends TestCase
{
    public function testRegistersAndListsAll(): void
    {
        $reg = new DefaultCapabilityRegistry();
        self::assertInstanceOf(CapabilityRegistry::class, $reg);
        self::assertSame([], $reg->all());

        $forms = new Capability('thallo.forms', label: 'Forms');
        $render = new Capability('thallo.render');
        $reg->register($forms);
        $reg->register($render);

        self::assertSame(['thallo.forms', 'thallo.render'], array_map(fn (Capability $c) => $c->id, $reg->all()));
    }

    public function testEnabledByDefaultWhenNoOverride(): void
    {
        $reg = new DefaultCapabilityRegistry(); // empty switchboard => default-on
        $reg->register(new Capability('thallo.forms'));
        self::assertTrue($reg->isEnabled('thallo.forms'));
        self::assertSame(['thallo.forms'], array_map(fn (Capability $c) => $c->id, $reg->enabled()));
    }

    public function testOverrideDisablesACapability(): void
    {
        $reg = new DefaultCapabilityRegistry(['thallo.forms' => false]);
        $reg->register(new Capability('thallo.forms'));
        $reg->register(new Capability('thallo.render'));

        self::assertFalse($reg->isEnabled('thallo.forms'));
        self::assertTrue($reg->isEnabled('thallo.render'));
        self::assertSame(['thallo.render'], array_map(fn (Capability $c) => $c->id, $reg->enabled()));
    }

    public function testUnregisteredIdIsNotEnabled(): void
    {
        $reg = new DefaultCapabilityRegistry();
        self::assertFalse($reg->isEnabled('thallo.nope')); // not installed => not enabled
    }

    public function testExplicitTrueOverrideIsEnabled(): void
    {
        $reg = new DefaultCapabilityRegistry(['thallo.forms' => true]);
        $reg->register(new Capability('thallo.forms'));
        self::assertTrue($reg->isEnabled('thallo.forms'));
    }

    public function testAnUntouchedSwitchFollowsTheEngine(): void
    {
        $resolver = new class implements \Thallo\Contracts\Capability\CapabilityAvailabilityResolver {
            public function resolve(Capability $capability): \Thallo\Contracts\Capability\CapabilityAvailability
            {
                return $capability->owningPackage === null
                    ? \Thallo\Contracts\Capability\CapabilityAvailability::available()
                    : \Thallo\Contracts\Capability\CapabilityAvailability::unavailable('engine not enabled');
            }
        };
        $reg = new DefaultCapabilityRegistry([], $resolver); // nothing on the switchboard
        $reg->register(new Capability('thallo.render'));
        $reg->register(new Capability('thallo.commerce', owningPackage: 'acme/commerce'));

        self::assertTrue($reg->isRequestedEnabled('thallo.render'), 'engine available => untouched switch reads on');
        self::assertFalse(
            $reg->isRequestedEnabled('thallo.commerce'),
            'engine unavailable => an untouched switch reads OFF, not "requested but unavailable"'
        );
        self::assertFalse($reg->isEnabled('thallo.commerce'));
    }

    public function testAnExplicitRequestOutranksTheEngineDefault(): void
    {
        $resolver = new class implements \Thallo\Contracts\Capability\CapabilityAvailabilityResolver {
            public function resolve(Capability $capability): \Thallo\Contracts\Capability\CapabilityAvailability
            {
                return \Thallo\Contracts\Capability\CapabilityAvailability::unavailable('engine not enabled');
            }
        };
        $reg = new DefaultCapabilityRegistry(['thallo.commerce' => true], $resolver);
        $reg->register(new Capability('thallo.commerce', owningPackage: 'acme/commerce'));

        self::assertTrue($reg->isRequestedEnabled('thallo.commerce'), 'the operator asked for it');
        self::assertFalse($reg->isEnabled('thallo.commerce'), 'still ineffective while the engine is down');
    }

    public function testALiveSwitchboardAnsweringNullFallsBackToTheEngine(): void
    {
        $resolver = new class implements \Thallo\Contracts\Capability\CapabilityAvailabilityResolver {
            public function resolve(Capability $capability): \Thallo\Contracts\Capability\CapabilityAvailability
            {
                return $capability->id === 'thallo.commerce'
                    ? \Thallo\Contracts\Capability\CapabilityAvailability::unavailable('engine not enabled')
                    : \Thallo\Contracts\Capability\CapabilityAvailability::available();
            }
        };
        $seen = [];
        $reg = new DefaultCapabilityRegistry([], $resolver, static function (string $id) use (&$seen): ?bool {
            $seen[] = $id;
            return $id === 'thallo.forms' ? false : null; // forms explicitly off; the rest untouched
        });
        $reg->register(new Capability('thallo.forms'));
        $reg->register(new Capability('thallo.render'));
        $reg->register(new Capability('thallo.commerce', owningPackage: 'acme/commerce'));

        self::assertFalse($reg->isRequestedEnabled('thallo.forms'));
        self::assertTrue($reg->isRequestedEnabled('thallo.render'));
        self::assertFalse($reg->isRequestedEnabled('thallo.commerce'));
        self::assertSame(['thallo.forms', 'thallo.render', 'thallo.commerce'], $seen, 'one switchboard lookup each');
    }
}
