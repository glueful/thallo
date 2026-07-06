<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/** A published entry+locale, normalized for indexing. App builds `href` (ready absolute/relative path). */
final class IndexableContent
{
    /** @param array<string,mixed> $fields decoded field values (all fields, per locale) */
    public function __construct(
        public readonly string $entryUuid,
        public readonly string $locale,
        public readonly string $contentTypeUuid,
        public readonly string $contentTypeSlug,
        public readonly bool $publicDelivery,
        public readonly string $href,
        public readonly ?string $entryLabel,
        public readonly array $fields,
        public readonly ?string $lastmod = null,
    ) {
    }
}
