<?php

declare(strict_types=1);

namespace Thallo\Contracts\Capability;

/**
 * Holds the declared capabilities and reports which are enabled. A package declares its
 * capabilities before boot — a provider implementing {@see DeclaresCapabilities}, or
 * `extra.thallo.capabilities` in its composer.json — and Thallo fills the registry from those
 * declarations, then seals it at its first decision. "Requested" = the host's switchboard asks for
 * it (an activation capability: only its stored switch). "Available" = the owning engine backs it
 * right now (installed + enabled + schema-ready). EFFECTIVE enabled — what every gate consumes —
 * is requested AND available, so a capability whose engine cannot back it fails closed everywhere.
 */
interface CapabilityRegistry
{
    /**
     * Thallo's own filling of the registry from the declarations. Calling it after the registry is
     * sealed (from a pack's boot, say) throws outside production and is ignored, with a log entry,
     * in production: declare capabilities instead.
     */
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
