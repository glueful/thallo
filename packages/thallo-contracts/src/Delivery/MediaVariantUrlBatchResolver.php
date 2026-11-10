<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Batched companion to {@see MediaVariantUrlResolver}: the same three outcomes for up to 100
 * blob uuids in ONE query, for a list that shows many images — a shop's product grid, a
 * product's gallery. Input is deduped to first occurrences, then capped at the FIRST 100
 * distinct uuids; each kept uuid maps to its result, null for media the page may not show.
 */
interface MediaVariantUrlBatchResolver
{
    /**
     * @param list<string> $uuids
     * @param list<int> $widths candidate widths in px, ascending
     * @return array<string, array{src: string, srcset: ?string, webp: ?string}|null> request order
     */
    public function variantsMany(array $uuids, array $widths): array;
}
