<?php

declare(strict_types=1);

namespace App\Tests\Integration\Importers;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;

final class ImportersCapabilityTest extends AppTestCase
{
    public function testImportersCapabilityIsRegisteredAndEnabled(): void
    {
        $reg = $this->container()->get(CapabilityRegistry::class);
        $ids = array_map(fn (Capability $c) => $c->id, $reg->enabled());
        self::assertContains('thallo.importers', $ids, 'the thallo-importers pack must register its capability');
    }
}
