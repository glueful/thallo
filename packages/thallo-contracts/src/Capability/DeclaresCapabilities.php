<?php

declare(strict_types=1);

namespace Thallo\Contracts\Capability;

/**
 * An always-loaded pack's provider declares its capabilities here, instead of registering them in
 * boot(). Thallo collects every declaration before any provider boots, so a capability decision
 * made during boot already sees all of them. The method must be pure: no container, no database.
 */
interface DeclaresCapabilities
{
    /** @return list<Capability> */
    public function capabilities(): array;
}
