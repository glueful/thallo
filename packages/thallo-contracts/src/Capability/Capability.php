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
    ) {
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
