<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Cache\Drivers\ArrayCacheDriver;

/**
 * A fully-functional in-memory CacheStore (it really tags and invalidates) that ALSO
 * records every `invalidateTags()` call so a test can assert the EXACT tag set the
 * cache-invalidation listener emitted.
 *
 * Used in CacheInvalidationTest to (a) prime a tagged value and prove it is gone after
 * the listener runs (driver-level effect) and (b) assert byte-for-byte that the tags the
 * listener invalidated match the surrogate keys the delivery layer emits.
 */
final class RecordingArrayCache extends ArrayCacheDriver
{
    /** @var list<list<string>> Every tag array passed to invalidateTags(), in order. */
    public array $invalidatedTagSets = [];

    /**
     * @param list<string> $tags
     */
    public function invalidateTags(array $tags): bool
    {
        $this->invalidatedTagSets[] = array_values($tags);
        return parent::invalidateTags($tags);
    }

    /** Flatten every recorded invalidation into a single de-duplicated tag list. */
    public function allInvalidatedTags(): array
    {
        $flat = [];
        foreach ($this->invalidatedTagSets as $set) {
            foreach ($set as $tag) {
                $flat[$tag] = true;
            }
        }
        return array_keys($flat);
    }
}
