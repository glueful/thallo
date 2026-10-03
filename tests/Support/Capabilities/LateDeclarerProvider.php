<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Capabilities;

use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\DeclaresCapabilities;

/** A test provider that only declares a capability. */
final class LateDeclarerProvider extends ServiceProvider implements DeclaresCapabilities
{
    public function capabilities(): array
    {
        return [new Capability('test.late', label: 'Late')];
    }
}
