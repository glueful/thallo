<?php

declare(strict_types=1);

namespace Thallo\Contracts\Capability;

/**
 * Answers whether a capability's owning engine backs it right now (spec B3). The host provides
 * the implementation (it knows about installed packages and schema readiness); packs only
 * consume the registry's effective state. Must never throw — an undeterminable answer is an
 * UNAVAILABLE verdict with the reason, so provider boot can never die on a capability question.
 */
interface CapabilityAvailabilityResolver
{
    public function resolve(Capability $capability): CapabilityAvailability;
}
