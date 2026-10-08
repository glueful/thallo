<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * What {@see StorefrontProductGrid::grid()} answers (product grid spec §3): the cards, the private
 * storage tag a page holding the grid is stored under, and the cache guard — the
 * catalog generation read before the products (null when it could not be read, which makes the
 * render uncacheable).
 */
final readonly class ProductGridView
{
    /** @param list<array<string,mixed>> $cards one array per card, in grid order */
    public function __construct(
        public array $cards,
        public string $storageTag,
        public string $guardKey,
        public ?string $guardValue,
    ) {
    }
}
