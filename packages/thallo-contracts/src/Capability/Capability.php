<?php

declare(strict_types=1);

namespace Thallo\Contracts\Capability;

/**
 * A capability a pack provides — an id (e.g. "thallo.forms"), the capability ids it
 * requires, and human-readable metadata. Pure value object; carries no behavior.
 *
 * `owningPackage` names the Composer package whose ACTIVATION defines the capability
 * (spec B3): the engine that must be installed, enabled, and schema-ready before the
 * capability can be effectively on. Null = Thallo app/library-owned (no external engine).
 * The owner is always declared explicitly at the registration site — never inferred
 * from the capability id.
 *
 * `management` says how it is switched (ManagementMode): an `Activation` capability's owning
 * package is its engine, prepared by the activation flow, with optional `copy` for the admin; an
 * `ExternalFlow` capability links to its `destination`. The default, `Simple`, is a plain switch.
 */
final class Capability
{
    /** @param list<string> $requires Capability ids this one depends on. */
    public function __construct(
        public readonly string $id,
        public readonly array $requires = [],
        public readonly ?string $label = null,
        public readonly ?string $description = null,
        public readonly ?string $owningPackage = null,
        public readonly ManagementMode $management = ManagementMode::Simple,
        public readonly ?ExternalFlowDestination $destination = null,
        public readonly ?ActivationCopy $copy = null,
    ) {
        if ($management === ManagementMode::Activation && $owningPackage === null) {
            throw new \InvalidArgumentException(
                "Capability {$id} uses activation, so it needs an owning package (its engine)."
            );
        }
        if ($management === ManagementMode::ExternalFlow && $destination === null) {
            throw new \InvalidArgumentException(
                "Capability {$id} is managed by an external flow, so it needs a destination."
            );
        }
        if (
            $owningPackage !== null
            && preg_match('#^[a-z0-9]([a-z0-9_.-]*[a-z0-9])?/[a-z0-9]([a-z0-9_.-]*[a-z0-9])?$#', $owningPackage) !== 1
        ) {
            throw new \InvalidArgumentException(
                "owningPackage must be a composer vendor/name package string, got \"{$owningPackage}\"."
            );
        }
    }
}
