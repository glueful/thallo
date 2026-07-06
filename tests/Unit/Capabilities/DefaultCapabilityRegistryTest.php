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

        $forms = new Capability('lemma.forms', label: 'Forms');
        $render = new Capability('lemma.render');
        $reg->register($forms);
        $reg->register($render);

        self::assertSame(['lemma.forms', 'lemma.render'], array_map(fn (Capability $c) => $c->id, $reg->all()));
    }

    public function testEnabledByDefaultWhenNoOverride(): void
    {
        $reg = new DefaultCapabilityRegistry(); // empty switchboard => default-on
        $reg->register(new Capability('lemma.forms'));
        self::assertTrue($reg->isEnabled('lemma.forms'));
        self::assertSame(['lemma.forms'], array_map(fn (Capability $c) => $c->id, $reg->enabled()));
    }

    public function testOverrideDisablesACapability(): void
    {
        $reg = new DefaultCapabilityRegistry(['lemma.forms' => false]);
        $reg->register(new Capability('lemma.forms'));
        $reg->register(new Capability('lemma.render'));

        self::assertFalse($reg->isEnabled('lemma.forms'));
        self::assertTrue($reg->isEnabled('lemma.render'));
        self::assertSame(['lemma.render'], array_map(fn (Capability $c) => $c->id, $reg->enabled()));
    }

    public function testUnregisteredIdIsNotEnabled(): void
    {
        $reg = new DefaultCapabilityRegistry();
        self::assertFalse($reg->isEnabled('lemma.nope')); // not installed => not enabled
    }

    public function testExplicitTrueOverrideIsEnabled(): void
    {
        $reg = new DefaultCapabilityRegistry(['lemma.forms' => true]);
        $reg->register(new Capability('lemma.forms'));
        self::assertTrue($reg->isEnabled('lemma.forms'));
    }
}
