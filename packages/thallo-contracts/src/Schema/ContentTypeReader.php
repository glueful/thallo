<?php

declare(strict_types=1);

namespace Thallo\Contracts\Schema;

/**
 * Resolve content types and read their schemas — for packs (e.g. importers) that need to map
 * external data onto a content type's fields. Read-only; the engine owns schema storage.
 */
interface ContentTypeReader
{
    /** The content type's uuid for a slug, or null if no such (non-deleted) type. */
    public function findUuidBySlug(string $slug): ?string;

    /** The schema for a content type uuid, or null if the type does not exist. */
    public function schemaFor(string $uuid): ?ContentSchemaReader;

    /** True when the content type opts into anonymous public delivery. */
    public function isPublicDelivery(string $uuid): bool;

    /**
     * All non-deleted content types with the fields delivery-visibility decisions need,
     * keyed by type uuid. Lets consumers (e.g. search) resolve visibility from the live
     * schema store per request instead of denormalizing it.
     *
     * @return array<string, array{slug: string, public_delivery: bool}>
     */
    public function deliveryTypes(): array;
}
