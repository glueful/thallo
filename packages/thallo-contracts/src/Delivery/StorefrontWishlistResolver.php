<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Soft-bound seam (storefront-v1 spec §5) that lets templates rendered through the CORE render
 * pipeline emit the wishlist affordances — the opaque device-storage scope the client runtime
 * keys localStorage under, and the canonical wishlist page URL — WITHOUT the render pack
 * importing anything commerce-specific: the exact soft-bound pattern
 * {@see StorefrontLinkResolver} already established (contract in thallo-contracts, nullable
 * constructor param in `RenderContextExtension`, `$container->has(...)` soft-bind in
 * `RenderServiceProvider`).
 *
 * Unlike {@see StorefrontLinkResolver}'s pure string composition, both methods here are
 * capability-gated at CALL time (null while the shop is inactive — the Twig helpers pass that
 * through, so wishlist affordances simply disappear), and {@see self::storageScope()} resolves
 * the current tenant LIVE on every call — the implementation must never cache a resolved tenant,
 * because the bound service is shared across requests. The scope is deliberately OPAQUE (a
 * digest, never the raw tenant uuid): it reaches the browser as part of a localStorage key.
 */
interface StorefrontWishlistResolver
{
    /** Opaque device-storage scope for the current store, or null while the shop is inactive. */
    public function storageScope(): ?string;

    /** Canonical wishlist page URL, or null while the shop is inactive. */
    public function wishlistUrl(): ?string;
}
