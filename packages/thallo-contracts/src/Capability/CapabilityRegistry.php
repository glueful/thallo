<?php

declare(strict_types=1);

namespace Thallo\Contracts\Capability;

/**
 * Holds the capabilities registered by installed packs and reports which are enabled.
 * "Installed" = registered here (by a pack's service provider). "Enabled" = installed
 * AND not disabled by the host's capability switchboard. Core registers nothing.
 */
interface CapabilityRegistry
{
    public function register(Capability $capability): void;

    /** @return list<Capability> Every registered (installed) capability. */
    public function all(): array;

    /** @return list<Capability> Installed capabilities that are also enabled. */
    public function enabled(): array;

    public function isEnabled(string $id): bool;
}
