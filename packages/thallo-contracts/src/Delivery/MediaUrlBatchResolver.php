<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Batched companion to {@see MediaUrlResolver}: anonymous public URLs for up
 * to 100 blob uuids in ONE query, under the SAME fail-closed servability
 * predicate as MediaUrlResolver::url(). Unservable uuids — private, deleted,
 * missing, uploads disabled, or an auth-gated uploads access mode — are
 * OMITTED from the map, never null-filled. Input is deduped to first
 * occurrences, then capped at the FIRST 100 distinct uuids.
 */
interface MediaUrlBatchResolver
{
    /**
     * @param list<string> $uuids
     * @return array<string,string> uuid => url, request order preserved
     */
    public function urls(array $uuids): array;
}
