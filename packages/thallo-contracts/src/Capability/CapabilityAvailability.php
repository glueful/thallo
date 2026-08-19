<?php

declare(strict_types=1);

namespace Thallo\Contracts\Capability;

/**
 * Whether a capability's owning engine can actually back it right now (spec B3). Closed value:
 * `available` is the verdict; `reason` says why not, `remedy` names the operator action that
 * fixes it (both null when available). No anonymous array shapes cross this contract.
 */
final class CapabilityAvailability
{
    private function __construct(
        public readonly bool $available,
        public readonly ?string $reason = null,
        public readonly ?string $remedy = null,
    ) {
    }

    public static function available(): self
    {
        return new self(true);
    }

    public static function unavailable(string $reason, ?string $remedy = null): self
    {
        return new self(false, $reason, $remedy);
    }
}
