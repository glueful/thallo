<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Lists published entries of a type for templates (the blog_posts block, etc.).
 * Like FacetCountsReader, the result carries its OWN surrogate cache tags —
 * including the BROAD thallo:type:{slug} dependency so a newly published entry or
 * changed membership that alters the top-N still purges the page. Gate failures
 * (unknown/non-deliverable type, unresolved category) return {[], []} — never throw.
 */
interface EntryListReader
{
    /**
     * @param array{limit?: int, order?: string, category?: ?string} $opts
     * @return array{items: list<array<string,mixed>>, cache_tags: list<string>}
     */
    public function list(string $type, array $opts, string $locale): array;
}
