<?php

declare(strict_types=1);

namespace Thallo\Contracts\Capability;

/**
 * What the admin says about an activation capability: the turn-on and turn-off confirmations, and
 * where to go once it is on. Every part is optional; the admin has generic wording.
 */
final class ActivationCopy
{
    /** @param list<array{label: string, to: string}> $links */
    public function __construct(
        public readonly ?string $turnOn = null,
        public readonly ?string $turnOff = null,
        public readonly array $links = [],
    ) {
    }
}
