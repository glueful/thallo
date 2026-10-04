<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/**
 * Reads PUBLISHED content, normalized for a search index. Implemented App-side over the
 * leak-proof delivery spine, so drafts/unpublished/archived entries are never returned.
 */
interface IndexableContentReader
{
    /** The published record for one entry+locale, or null if not published/visible. */
    public function getIndexablePublished(string $entryUuid, string $locale): ?IndexableContent;

    /** One page of published records, optionally scoped by type slug / locale. */
    public function enumerateIndexablePublished(
        int $limit,
        int $offset = 0,
        ?string $typeSlug = null,
        ?string $locale = null,
    ): IndexablePage;

    /**
     * Distinct entries with at least one published locale, in uuid order, strictly after
     * `$afterUuid` — whole entries, so a page never splits one entry's translations.
     *
     * @return list<string>
     */
    public function publishedEntryUuidsAfter(?string $afterUuid, int $limit): array;

    /** @return list<string> the locales one entry is published in */
    public function publishedLocalesOf(string $entryUuid): array;
}
