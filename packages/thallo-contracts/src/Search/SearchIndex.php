<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/**
 * How a pack reports a change to the search index, after its own transaction commits (search block
 * spec §3.5.2). Bound to a no-op while search is off.
 */
interface SearchIndex
{
    public function changed(string $kind, string $sourceId): void;

    /** A change with no single item (a taxonomy edit): demand a rebuild of the kind. */
    public function kindChanged(string $kind, string $reason): void;
}
