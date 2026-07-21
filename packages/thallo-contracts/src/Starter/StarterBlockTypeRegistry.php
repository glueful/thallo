<?php

declare(strict_types=1);

namespace Thallo\Contracts\Starter;

/**
 * Holds the {@see StarterBlockTypeContributor}s registered by installed packs. Interface only —
 * packs compile against this (never the concrete app-owned registry, keeping packs App\-free);
 * {@see \App\Content\Starter\DefaultStarterBlockTypeRegistry} is the mutable implementation
 * `App\Providers\ThalloServiceProvider` binds it to.
 */
interface StarterBlockTypeRegistry
{
    public function register(StarterBlockTypeContributor $contributor): void;

    /** @return list<StarterBlockTypeContributor> */
    public function all(): array;
}
