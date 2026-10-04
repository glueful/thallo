<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/** The contributors of search result kinds, one per kind; the search pack implements it. */
interface SearchSourceRegistry
{
    /** @throws \LogicException on a duplicate or invalid kind */
    public function register(SearchSourceContributor $contributor): void;

    /** @return array<string, SearchSourceContributor> kind => contributor, in registration order */
    public function all(): array;
}
