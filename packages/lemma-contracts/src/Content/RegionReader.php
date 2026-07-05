<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Content;

/**
 * Global chrome regions (global-regions spec). blocks() returns the ordered
 * {id,type,data} list — or null when the region is absent OR saved empty:
 * the pinned fallback rule means templates never distinguish "no region"
 * from "empty region"; both render the theme's hardcoded chrome. Hiding
 * chrome is a page _presentation decision, never an empty region.
 */
interface RegionReader
{
    /** @return list<array<string,mixed>>|null */
    public function blocks(string $slug): ?array;

    /** @return array<string,mixed> */
    public function settings(string $slug): array;
}
