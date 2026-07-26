<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Route-independent, tenant-scoped, published-only read of one entry's shaped fields.
 *
 * This is the read path {@see \Thallo\Render\EntryBlocksRenderer} composes to render a
 * linked entry's blocks region WITHOUT going through
 * {@see PublicRouteResolver::resolveEntry()} — that method requires a live `entry_routes`
 * row and therefore returns `not_found` for a route-less entry (the exact gap this contract
 * closes: a route-less starter "Product story" entry linked to a commerce product for
 * enrichment purposes, never independently routed).
 *
 * Fails closed (returns null), never throws, for:
 *   - the entry is missing, soft-deleted, or belongs to a different tenant;
 *   - the entry's content type is not publicly deliverable;
 *   - the entry has no PUBLISHED version in the requested locale (a draft-only entry is
 *     never returned — this is a published-only read path, like {@see PublicRouteResolver}).
 */
interface PublishedEntryBlocksReader
{
    /**
     * @return array{entry_uuid: string, type: string, fields: array<string,mixed>}|null
     */
    public function findPublishedBlocks(string $entryUuid, string $tenant, string $locale): ?array;
}
