<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Soft-bound seam (Commerce-Slice-2 Fix A) that lets a block template rendered through the
 * CORE render pipeline (product-grid/featured-product/add-to-cart — {@see
 * \Thallo\Render\RenderContextExtension::blocks()}) emit a real, working no-JS fallback link
 * into the storefront catalog WITHOUT the render pack importing anything commerce-specific —
 * the exact soft-bound pattern this codebase already uses for `media()`/`form_render()`/
 * `site_logo()` (contract in thallo-contracts, nullable constructor param in
 * `RenderContextExtension`, `$container->has(...)` soft-bind in `RenderServiceProvider`).
 *
 * Every method here is PURE, deterministic string composition — no tenant resolution, no
 * database read, no business-rule evaluation — mirroring exactly what
 * `Thallo\Commerce\Shop\ShopUrlGenerator` already guarantees for its own methods. That purity
 * is what makes it safe to call from a block template that may render inside a page cached by
 * the GENERAL {@see \Thallo\Render\Http\Middleware\RenderPageCache} (which carries no
 * commerce-catalog invalidation wiring): a renamed product's cached noscript link goes stale
 * only in the sense that it lands on the OLD slug, which the shop's own slug-history ledger
 * already 301-redirects to the current URL — never a broken link, never stale business data.
 */
interface StorefrontLinkResolver
{
    /** The canonical `/{shop-prefix}/products/{slug}` URL for a product slug. */
    public function productUrl(string $slug): string;

    /** The canonical `/{shop-prefix}/categories/{slug}` URL for a category slug. */
    public function categoryUrl(string $slug): string;

    /** The canonical shop index ("browse all products") URL. */
    public function shopIndexUrl(): string;
}
