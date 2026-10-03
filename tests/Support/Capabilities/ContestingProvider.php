<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Capabilities;

use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\DeclaresCapabilities;
use Thallo\Contracts\Capability\ManagementMode;

/** Declares `test.contested` too, with a different engine: the two declarations conflict. */
final class ContestingProvider extends ServiceProvider implements DeclaresCapabilities
{
    public function capabilities(): array
    {
        return [new Capability(
            'test.contested',
            label: 'Contested',
            owningPackage: 'glueful/import-export',
            management: ManagementMode::Activation,
        )];
    }
}
