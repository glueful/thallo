<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The site's style classes for the current request (visual builder spec §4.3). `snapshot()` is
 * loaded once and memoised until `refresh()`, which every request that renders or validates
 * calls first, so one request works from exactly one generation-named snapshot. Core binds it;
 * the render pack resolves the class layer and keys the page cache through it.
 */
interface StyleClassProvider
{
    public function snapshot(): StyleClassSnapshot;

    public function refresh(): void;
}
