<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Composed SEO head data for one published entry variant (seo-head spec §2). The
 * engine implementation derives type/slug itself — callers supply only the identity
 * every render site holds (the same pair the page cache tags with).
 */
interface SeoHeadResolver
{
    /**
     * @return array{
     *   title: string,
     *   description: ?string,
     *   canonical: ?string,
     *   alternates: list<array{locale: string, href: string}>,
     *   x_default: ?string,
     *   og: array{title: string, description: ?string, image: ?string, url: ?string, type: string},
     *   twitter_card: ?string,
     *   robots: string,
     * }|null null when the entry is not published (or not routed) in this locale.
     */
    public function headFor(string $entryUuid, string $locale): ?array;
}
