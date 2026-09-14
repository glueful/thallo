<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Drops rendered pages so a content change is visible on the next request, whatever the cache
 * driver. Rendered pages are stored under surrogate tags; a driver without tag invalidation
 * (the default `file` driver) answers a tag purge by dropping every rendered page instead, so
 * freshness never degrades to the TTL window. Bound by the render pack; core and packs purge
 * through it when it is present.
 */
interface RenderedPageCachePurge
{
    /**
     * Drop every rendered page tagged with any of `$tags` — or every rendered page when the
     * driver cannot invalidate tags.
     *
     * @param list<string> $tags surrogate keys, e.g. `thallo:entry:{uuid}`, `thallo:render:page`
     */
    public function purge(array $tags): void;

    /** Drop every rendered page. */
    public function purgeAll(): bool;
}
