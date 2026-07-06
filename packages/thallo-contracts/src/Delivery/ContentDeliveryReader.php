<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Read-only access to PUBLISHED content. Never exposes drafts. Consumed by render,
 * search, and reference-resolving packs.
 */
interface ContentDeliveryReader
{
    /** @return array<int,array<string,mixed>> Published rows for the type/locale. */
    public function listPublished(string $contentTypeUuid, string $locale, int $limit = 20): array;

    /** @return array<string,mixed>|null One published row (by slug, else uuid) or null. */
    public function findPublished(string $contentTypeUuid, string $locale, string $slugOrUuid): ?array;

    /**
     * One page of published URLs for sitemap generation. Rows carry READY ABSOLUTE URLs —
     * the App impl builds them via PathRenderer/public_url_base; the pack only serializes.
     *
     * @return array{
     *   items: list<array{
     *     href: string,
     *     lastmod: ?string,
     *     alternates: list<array{locale:string, href:string}>
     *   }>,
     *   total: int, limit: int, offset: int
     * }
     */
    public function enumeratePublishedForSitemap(int $limit, int $offset = 0): array;
}
