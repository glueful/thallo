<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * A shop block's product, named for the stage (sections and templates design §6). Shop behaviour
 * never runs on the stage, so Featured product and Add to cart show a named placeholder there: the
 * product's name, or "choose a product". Soft-bound — the commerce pack implements it; without it
 * the stage says "choose a product".
 */
interface StorefrontBlockPreview
{
    /**
     * The name of the active product `$slug` names — or, with no slug, the product `$entryUuid` is
     * linked to — or null when there is none.
     */
    public function productLabel(?string $slug, ?string $entryUuid): ?string;
}
