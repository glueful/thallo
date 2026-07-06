<?php

declare(strict_types=1);

namespace App\Capabilities;

use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;

/**
 * In-memory capability registry. Packs register their Capability during boot; the host's
 * switchboard ($overrides, the `lemma.capabilities` config map keyed by full capability id)
 * decides which installed capabilities are enabled. Absent id => enabled (default-on);
 * `false` => disabled.
 */
final class DefaultCapabilityRegistry implements CapabilityRegistry
{
    /** @var array<string,Capability> */
    private array $capabilities = [];

    /** @param array<string,bool> $overrides Full-capability-id => enabled flag. */
    public function __construct(private readonly array $overrides = [])
    {
    }

    public function register(Capability $capability): void
    {
        $this->capabilities[$capability->id] = $capability;
    }

    /** @return list<Capability> */
    public function all(): array
    {
        return array_values($this->capabilities);
    }

    /** @return list<Capability> */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->capabilities,
            fn (Capability $c): bool => $this->isEnabled($c->id),
        ));
    }

    public function isEnabled(string $id): bool
    {
        return isset($this->capabilities[$id]) && ($this->overrides[$id] ?? true) === true;
    }
}
