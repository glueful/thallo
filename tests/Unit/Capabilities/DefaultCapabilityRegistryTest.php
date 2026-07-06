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
}
