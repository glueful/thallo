<?php

declare(strict_types=1);

namespace Thallo\Contracts\Capability;

/**
 * A capability a pack provides — an id (e.g. "lemma.forms"), the capability ids it
 * requires, and human-readable metadata. Pure value object; carries no behavior.
 */
final class Capability
{
    /** @param list<string> $requires Capability ids this one depends on. */
    public function __construct(
        public readonly string $id,
        public readonly array $requires = [],
        public readonly ?string $label = null,
        public readonly ?string $description = null,
    ) {
    }
}
