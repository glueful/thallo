<?php

declare(strict_types=1);

namespace Thallo\Contracts\Starter;

/**
 * Holds the {@see StarterContentTypeContributor}s registered by installed packs. Interface only —
 * packs compile against this (never the concrete app-owned registry, keeping packs Thallo\Core\-free);
 * {@see \Thallo\Core\Content\Starter\DefaultStarterContributorRegistry} is the mutable implementation
 * `Thallo\Core\Providers\CoreServiceProvider` binds it to.
 */
interface StarterContributorRegistry
{
    public function register(StarterContentTypeContributor $contributor): void;

    /** @return list<StarterContentTypeContributor> */
    public function all(): array;
}
