<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * OPTIONAL responsive-variant companion at the generic render boundary
 * (storefront-performance spec §3). The Thallo app always binds its MIME-aware
 * implementation; other hosts may omit it. Batch by design: ONE blob/access/MIME lookup
 * produces the base src and every srcset candidate — per-width calls would be N+1 reads.
 *
 * Three pinned outcomes:
 *  - Valid image with surviving candidates: {src, srcset: string}.
 *  - Valid image with NO surviving candidates (the implementation is bound but resizing
 *    is unavailable/disabled, or every width fell to the server clamp):
 *    {src, srcset: null} — the base URL stands; null stays reserved for invalid media.
 *  - Missing, private, deleted, unservable, or non-image blob: null (the caller omits
 *    the image element — never an <img> pointing at a non-image).
 *
 * Implementations must never emit ?width= candidate URLs unless real resizing will serve
 * them (a candidate the server would ignore or clamp lies to the browser's selection
 * algorithm and multiplies cache keys for zero payload win).
 */
interface MediaVariantUrlResolver
{
    /**
     * @param list<int> $widths candidate widths in px, ascending
     * @return array{src: string, srcset: ?string}|null
     */
    public function variants(string $uuid, array $widths): ?array;
}
