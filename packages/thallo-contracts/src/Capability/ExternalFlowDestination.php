<?php

declare(strict_types=1);

namespace Thallo\Contracts\Capability;

/** Where an externally managed capability is switched: an admin path and its label. */
final class ExternalFlowDestination
{
    public function __construct(
        public readonly string $path,
        public readonly string $label,
    ) {
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException("An external flow's destination is an admin path, got \"{$path}\".");
        }
    }
}
