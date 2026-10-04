<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/**
 * One kind of search result a pack provides (search block spec §3.2). The search pack decides
 * whether the kind is available — every required capability on — and owns the index; the
 * contributor says what its items are, which of them an audience may see, and what to show.
 */
interface SearchSourceContributor
{
    /** Stable, matching {@see SearchIdentity::KIND}. */
    public function kind(): string;

    public function label(): string;

    /** @return list<string> capability ids beyond `thallo.search` */
    public function requiredCapabilities(): array;

    /** Bumping it creates rebuild demand for the kind. */
    public function schemaVersion(): int;

    /**
     * The item's current documents, one per locale; an empty list means remove it.
     *
     * @return list<SearchDocument>
     */
    public function documents(string $sourceId): array;

    /** Publicly listed items ordered by source id, strictly after `$after`. */
    public function enumerate(?string $after, int $size): SearchDocumentPage;

    public function visibilityFilter(SearchAudience $audience): KindFilter;

    /**
     * The authority on what is shown, read from current records; null drops the candidate.
     *
     * @param list<string> $sourceIds
     * @return array<string, ?ResultDisplay>
     */
    public function present(SearchAudience $audience, string $locale, array $sourceIds): array;
}
