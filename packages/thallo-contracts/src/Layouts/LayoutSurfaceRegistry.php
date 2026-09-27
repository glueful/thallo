<?php

declare(strict_types=1);

namespace Thallo\Contracts\Layouts;

/** The page kinds layouts can describe; core registers its own, packs add theirs. */
interface LayoutSurfaceRegistry
{
    public function get(string $key): ?LayoutSurface;

    /** Add a pack's surface; one registered under the same key is replaced. */
    public function register(LayoutSurface $surface): void;

    /** @return list<LayoutSurface> */
    public function all(): array;
}
