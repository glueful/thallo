<?php

declare(strict_types=1);

namespace Thallo\Contracts\Layouts;

/** The page kinds layouts can describe; core registers its own, packs add theirs. */
interface LayoutSurfaceRegistry
{
    public function get(string $key): ?LayoutSurface;

    /** @return list<LayoutSurface> */
    public function all(): array;
}
