<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/**
 * What a visitor is shown for one result, read from current records by its contributor (search
 * block spec §3.2). `text` is the contributor-approved plain text snippets are made from, at most
 * 20 KB; nothing shown ever comes from the index.
 */
final class ResultDisplay
{
    public function __construct(
        public readonly string $title,
        public readonly string $href,
        public readonly string $text,
        public readonly ?string $image = null,
        public readonly ?string $price = null,
    ) {
    }
}
