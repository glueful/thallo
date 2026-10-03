<?php

declare(strict_types=1);

namespace Thallo\Contracts\Capability;

/**
 * How a capability is switched. `Simple`: a plain switch over its state. `Activation`: turning it
 * on prepares its engine (its owning package) through the activation flow. `ExternalFlow`: another
 * flow owns it, at a destination.
 */
enum ManagementMode: string
{
    case Simple = 'simple';
    case Activation = 'activation';
    case ExternalFlow = 'external_flow';
}
