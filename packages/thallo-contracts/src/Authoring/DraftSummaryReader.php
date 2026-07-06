<?php

declare(strict_types=1);

namespace Thallo\Contracts\Authoring;

/**
 * Read-only draft identity summary for authoring-adjacent packs (e.g. a review queue that
 * must show titles without coupling to the engine's storage model). Returns null for a
 * missing draft or a soft-deleted entry.
 */
interface DraftSummaryReader
{
    /**
     * @return array{entry_uuid:string,locale:string,title:?string,type_uuid:string,type_slug:string}|null
     */
    public function summary(string $entryUuid, string $locale): ?array;
}
