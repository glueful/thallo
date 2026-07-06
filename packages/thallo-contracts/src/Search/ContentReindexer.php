<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/**
 * Reindex a single published entry/locale into a pack-owned search index.
 * Implemented by a search pack when installed; unbound in core by default
 * (the reindex listener no-ops when nothing is bound).
 *
 * $locale === null means "whole entry" (all locales) — emitted by the whole-entry
 * delete path (EntryRepository::softDelete → EntryDeleted with locale null).
 */
interface ContentReindexer
{
    public function reindexEntry(string $entryUuid, ?string $locale): void;
}
