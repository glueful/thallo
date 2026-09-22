<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/**
 * The words a `blocks` field shows a reader, for indexing. Implemented by the content engine over
 * block-type schemas; consumed soft-bound by the search pack.
 */
interface BlockTextExtractor
{
    /**
     * Each block's text in page order: string and text fields (rich text as its words), nested
     * blocks included, links, paths and non-words left out.
     *
     * @return list<string>
     */
    public function textOf(mixed $blocks): array;
}
