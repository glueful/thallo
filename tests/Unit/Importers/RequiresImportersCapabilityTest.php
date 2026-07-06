<?php

declare(strict_types=1);

namespace App\Tests\Unit\Importers;

use App\Capabilities\DefaultCapabilityRegistry;
use Glueful\Http\Exceptions\Client\ForbiddenException;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Importers\Concerns\RequiresImportersCapability;
use PHPUnit\Framework\TestCase;

final class RequiresImportersCapabilityTest extends TestCase
{
    /** A minimal user of the trait that exposes the private guard for testing. */
    private function gate(): object
    {
        return new class {
            use RequiresImportersCapability;

            public function run(CapabilityRegistry $caps): void
            {
                $this->assertImportersEnabled($caps);
            }
        };
    }

    public function testThrowsWhenDisabled(): void
    {
        $reg = new DefaultCapabilityRegistry(['thallo.importers' => false]);
        $reg->register(new Capability('thallo.importers'));
        $this->expectException(ForbiddenException::class);
        $this->gate()->run($reg);
    }

    public function testPassesWhenEnabled(): void
    {
        $reg = new DefaultCapabilityRegistry();
        $reg->register(new Capability('thallo.importers'));
        $this->gate()->run($reg);
        self::assertTrue(true); // no exception
    }
}
