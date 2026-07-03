<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Facet counts for rendered templates (preview spec §5). The result CARRIES ITS OWN
 * surrogate cache tags: consumers (the render pack) cannot derive the term type's tag
 * from a bare counts list, and a VALID facet with zero counts must still tag the page
 * (it changes when the first matching entry publishes). Gate failures (unknown type,
 * non-filterable field, non-visible type on either side) return {[], []} — never throw.
 *
 * INVARIANT (consumers may dispatch on it): a VALID facet ALWAYS returns non-empty
 * cache_tags — even with zero items — and cache_tags is empty ONLY on gate failure.
 * The rendered term-index page's 404-vs-200 split relies on exactly this.
 */
interface FacetCountsReader
{
    /**
     * @return array{items: list<array{uuid: string, slug: ?string, count: int}>,
     *               cache_tags: list<string>}
     */
    public function counts(string $typeSlug, string $field, string $locale, int $limit = 100): array;
}
