<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Core answers "what does this public path serve?" for the render pack. `content` is the
 * PUBLIC DELIVERY SHAPE for one published entry — `seo` included — already
 * visibility-filtered and route-resolved by core; consumers treat it as READ-ONLY
 * template context (no mutation, no re-normalization). Normalization differences
 * (trailing slash, duplicate slashes) are returned as 301 redirects BEFORE any lookup,
 * so content resolution only ever sees canonical paths.
 */
interface PublicRouteResolver
{
    /**
     * @return array{kind: 'content'|'listing'|'archive'|'terms'|'redirect'|'gone'|'not_found',
     *   locale: ?string, type: ?string, content: ?array,
     *   redirect: ?array{location: string, status: int},
     *   listing: ?array{items: list<array<string,mixed>>, page: int, per_page: int,
     *     total: int, total_pages: int},
     *   term: ?array, term_type: ?string, field: ?string, preview: bool}
     *   `type` is the content-type slug (content/listing/archive/terms kinds) — template
     *   hierarchies select on it. `listing` (listing + archive kinds) carries LIST-shaped
     *   items each with a ready `href` (?string; null = routeless) and
     *   total_pages = max(1, ceil(total / per_page)) — never 0. `term` (archive kind) is
     *   the SHOW-shaped term entry (seo included); `term_type` its content-type slug
     *   (for surrogate cache tags); `field` the source reference field. `terms` is the
     *   THIN term-index kind: only `type`, `field`, `locale` are set (`preview: false`,
     *   every other payload key null) — the renderer fetches counts via
     *   FacetCountsReader and dispatches on its invariant. `preview` is true only on
     *   resolvePreview successes — preview is a content render, not a kind.
     *   `$previewSession` is an ALREADY-VERIFIED session (preview-sessions spec §2) —
     *   when the path resolves to its {entry, locale}, the draft is returned
     *   (`kind: content`, `preview: true`); the resolver never re-verifies.
     */
    public function resolvePath(string $path, ?PreviewSession $previewSession = null): array;

    /**
     * Same result shape, for a known entry (homepage). `$previewSession` is an
     * ALREADY-VERIFIED session (never re-verified here): when its {entry, locale}
     * matches the resolved entry, the draft — or the canvas's stashed working
     * copy — is returned instead of the published fields (single-draft scope;
     * additive param, working-copy-overlay spec §2).
     */
    public function resolveEntry(
        string $entryUuid,
        ?string $locale = null,
        ?PreviewSession $previewSession = null,
    ): array;

    /**
     * Resolve a signed preview token to its draft/pinned-version content, rendered-side.
     * Success is `kind: 'content'` with `preview: true` (same shape — preview is a
     * content render with different headers/context, never a separate kind). Content
     * carries NO `seo` key. EVERY failure (malformed, expired, missing draft/version)
     * is `not_found`. The token is the authorization (public_delivery does not apply).
     */
    public function resolvePreview(string $token): array;
}
