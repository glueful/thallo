<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * A Product grid's products (product grid spec §3.1): the block's data in, the cards and View-all
 * link out, plus what the render's cache needs (the workspace's catalog tag and the catalog
 * generation the products were read under). Soft-bound — the commerce pack implements it; without
 * it, or without the commerce engine behind it (null), `product_grid()` answers null and the block
 * renders nothing.
 */
interface StorefrontProductGrid
{
    /** @param array<string,mixed> $data the block's data; null = no commerce engine to ask */
    public function grid(array $data): ?ProductGridView;
}
