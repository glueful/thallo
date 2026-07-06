<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/**
 * A page of IndexableContent for backfill. Carries no total count: callers page until a
 * short page (count(items) < limit), so enumeration never pays COUNT queries per page.
 */
final class IndexablePage
{
    /** @param list<IndexableContent> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }
}
