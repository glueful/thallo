<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * A Product grid's products (product grid spec §3.1): the block's data in, the cards and View-all
 * link out, plus what the render's cache needs (the workspace's catalog tag and the catalog
 * generation the products were read under). Soft-bound — the commerce pack implements it; without
 * it `product_grid()` answers null and the block renders nothing.
 */
interface StorefrontProductGrid
{
    /** @param array<string,mixed> $data the block's data */
    public function grid(array $data): ProductGridView;
}
