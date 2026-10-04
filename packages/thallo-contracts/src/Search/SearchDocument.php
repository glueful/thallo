<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/**
 * What a contributor puts in the index for one item in one locale (search block spec §3.2). The
 * index decides matching and ranking from it; what a visitor is shown comes from
 * {@see SearchSourceContributor::present()}, never from here. `meta` carries optional display
 * hints (`image`, `price`) whose meaning the contributor owns.
 */
final class SearchDocument
{
    /** @param array{image?: string, price?: string} $meta */
    public function __construct(
        public readonly string $kind,
        public readonly string $sourceId,
        public readonly string $locale,
        public readonly ?string $subtype,
        public readonly string $href,
        public readonly string $title,
        public readonly string $body,
        public readonly array $meta = [],
    ) {
        SearchIdentity::assertKind($kind);
        SearchIdentity::assertSourceId($sourceId);
        SearchIdentity::assertLocale($locale);
    }
}
