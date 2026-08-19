<?php

declare(strict_types=1);

namespace Thallo\Contracts\Capability;

/**
 * Holds the capabilities registered by installed packs and reports which are enabled.
 * "Installed" = registered here (by a pack's service provider). "Requested" = not disabled
 * by the host's capability switchboard. "Available" = the owning engine backs it right now
 * (installed + enabled + schema-ready). EFFECTIVE enabled — what every gate consumes — is
 * requested AND available, so a capability whose engine cannot back it fails closed
 * everywhere automatically. Core registers nothing.
 */
interface CapabilityRegistry
{
    public function register(Capability $capability): void;

    /** @return list<Capability> Every registered (installed) capability, availability aside. */
    public function all(): array;

    /** @return list<Capability> Registered capabilities that are EFFECTIVELY enabled. */
    public function enabled(): array;

    /** Effective state: `isRequestedEnabled($id) && availability($id)->available`. */
    public function isEnabled(string $id): bool;

    /** The switchboard's answer alone — no availability consulted. */
    public function isRequestedEnabled(string $id): bool;

    /** The owning engine's answer alone — no switchboard consulted. */
    public function availability(string $id): CapabilityAvailability;
}
