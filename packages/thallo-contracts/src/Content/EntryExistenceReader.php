<?php

declare(strict_types=1);

namespace Thallo\Contracts\Content;

/**
 * Read-only, tenant-scoped entry-existence check for cross-pack consumers (e.g. commerce
 * product-content linkage) that must validate an entry uuid without coupling to the engine's
 * storage model. Unlike {@see \Thallo\Contracts\Authoring\DraftSummaryReader} (locale-scoped,
 * draft-shaped), this is a bare identity check: does this uuid resolve to a live entry that
 * belongs to $tenant. Returns null for a missing entry, a soft-deleted entry, or an entry
 * belonging to a different tenant -- never throws, never reveals which case applied.
 */
interface EntryExistenceReader
{
    /** @return array{uuid:string,content_type_uuid:string}|null */
    public function exists(string $entryUuid, string $tenant): ?array;
}
