<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/** One page of a contributor's enumeration, ordered by source id; `nextAfter` null at the end. */
final class SearchDocumentPage
{
    /** @param list<SearchDocument> $documents */
    public function __construct(
        public readonly array $documents,
        public readonly ?string $nextAfter,
    ) {
    }
}
