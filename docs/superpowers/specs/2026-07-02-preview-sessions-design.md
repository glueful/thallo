# Preview Sessions (Preview v2) — Design

**Date:** 2026-07-02
**Status:** Approved design, pre-implementation
**Parent:** the remaining preview follow-ups from
`docs/superpowers/specs/2026-07-02-preview-through-theme-design.md` §8: full-site
preview navigation, preview of listing/archive pages, and per-preview themes — one
mechanism, not three.

"Being in preview" outlives the single token URL: `/_preview/{token}` starts a
short-lived **preview session**, navigation stays in preview chrome, the tokened draft
overlays its own canonical URL, and an optional theme override (signed into the token)
restyles the whole session including its assets. The render cache is structurally
bypassed for verified sessions only.

## 1. The session mechanism (the token IS the credential)

`GET /_preview/{token}` keeps rendering the draft as today and ALSO sets a cookie
**`lemma_preview={token}`** — `HttpOnly`, `SameSite=Lax`, `Path=/`, **`Secure` when
the request is HTTPS** (the cookie is a bearer credential; local HTTP dev stays
workable — `Secure` follows `$request->isSecure()`), `Max-Age` = the token's
REMAINING TTL. No new crypto, no server-side session state: the cookie value is
the already-signed token, re-verified on every request. **The session dies with the
token** (no sliding extension — the short-lived bearer model stays intact; operators
tune `lemma.preview.ttl_seconds`). `GET /_preview/exit` (registered BEFORE the
`{token}` route) clears the cookie and redirects to `/`; the preview banner gains an
"Exit preview" link pointing at it.

## 2. The verifier contract (pinned: NOT on PublicRouteResolver)

Signature+expiry verification is not route resolution. New contracts seam returning a
**typed VO, not a plain array** — the verified claims PLUS the original token (the
render layer still needs the raw token for `/_preview-assets/{token}/…` URLs):

```php
namespace Glueful\Lemma\Contracts\Delivery;

/** Verified preview-session claims + the original token. Immutable. */
final class PreviewSession
{
    public function __construct(
        public readonly string $token,
        public readonly string $entry,
        public readonly string $locale,
        public readonly ?string $version,
        public readonly ?string $theme,
        public readonly int $expiresAt,
    ) {
    }
}

interface PreviewSessionVerifier
{
    /**
     * Cheap signature + expiry check (no DB). Null on ANY failure — malformed, bad
     * signature, expired. The returned session is VERIFIED: consumers share it
     * without re-verifying and without pulling route semantics into the cache layer.
     */
    public function verify(string $token): ?PreviewSession;
}
```

Core implements it over `PreviewToken::verify` (the same key derivation the
minter/reader use). The draft-read path takes the VO too: core `PreviewReader` gains
**`readVerified(PreviewSession $session)`** — same result shape as `read()`, claims
trusted, NO re-verification (the token-based `read()` stays for the JSON door).
`PublicRouteResolver` gains NO verification method; `resolvePath` accepts the session:

```php
public function resolvePath(string $path, ?PreviewSession $previewSession = null): array;
```

When `$previewSession` is non-null and the path resolves to that `{entry, locale}`,
the resolver swaps in the **draft** (`kind: 'content'`, `preview: true` — the existing
preview shaping via `readVerified`: LIST shape, no seo, single-projection-safe). Every
other path resolves exactly as published. The resolver TRUSTS the VO; it never
re-verifies.

## 3. What a session request shows (single-draft overlay — pinned scope)

- The tokened `{entry, locale}` shows its **draft at its own canonical URL** (and at
  `/_preview/{token}` as today). Links to and from it just work.
- Every other page — entries, listings, archives, term indexes, home — shows
  **published content in preview chrome**: banner (with Exit link), `Cache-Control:
  no-store`, `X-Robots-Tag: noindex`, `Cache-Tag` stripped. The draft is NOT injected
  into listings (decided; a leaked link exposes ONE draft plus the site in chrome —
  the bearer token's grant does not expand).
- In-session 404s/410s render **FRESH with the chrome** — `RenderErrorCache` is not
  consulted (the `/_preview` precedent generalizes: session surfaces never read or
  fill the SHARED fixed bodies, which are unbannered published-surface artifacts).

## 4. Session detection + cache safety (pinned: session state is NOT cache state)

Session detection is its own middleware, not a `RenderPageCache` concern —
`RenderPageCache` returns immediately when `cache_enabled=false`, so verification
living inside it would silently kill preview sessions on cache-disabled installs.

**`PreviewSessionMiddleware`** runs on the render routes BEFORE `RenderPageCache`:
it verifies the `lemma_preview` cookie via the `PreviewSessionVerifier` contract and,
on success, stores the `PreviewSession` VO as a request attribute
(`lemma_preview_session`); invalid/junk/expired cookies are IGNORED (attribute
absent). It works identically whatever the cache settings.

`RenderPageCache` then only checks the request attribute: present → full passthrough
(no cache read, no store). Junk cookies never reach it as sessions, so random cookies
cannot cache-bust; only holders of a genuinely signed, unexpired token bypass — which
they already could via `/_preview`. The verified-only rule is the security property;
a presence-only check is explicitly wrong. `RenderController` reads the SAME attribute
for chrome/overlay/theme — one verification per request, shared by all three consumers.

## 5. Per-preview theme (signed into the token) + preview assets

- `POST /v1/admin/entries/{uuid}/preview/{locale}` accepts optional **`theme`** and
  **signs it into the token payload**. Tamper-proof, expires with the token.
  **Old-format tokens (no theme field) keep verifying** — the payload change is
  additive; no-theme tokens use the boot theme everywhere.
- **Theme validation is a contracts seam (pinned — core must not import the render
  pack):** `PreviewThemeValidator` in `lemma-contracts`
  (`isValidTheme(string $name): bool`), implemented by `lemma-render` with the SAME
  ladder semantics `ThemeLocator` uses (app `themes/{name}` with valid `theme.json`,
  or `default`). The core mint endpoint consults it ONLY if bound; a supplied `theme`
  with NO validator bound (render pack absent) → 422 — exactly like an invalid theme.
  Render stays removable and validation matches the real theme ladder.
- Session requests carrying a theme: the render controller builds a per-request
  `ThemeLocator`/`TwigFactory` for that theme; the boot-frozen singletons are untouched
  for normal traffic. **PINNED: the per-preview Twig environment is REQUEST-LOCAL and
  is never assigned to the controller's memoized `$this->twig`** — the memo holds the
  boot theme only, and a themed preview must not poison the environment subsequent
  normal requests render through (tested: themed preview, then a plain request still
  renders the boot theme). A theme that has vanished since minting → the ordinary
  themed 404 posture is wrong here (the CONTENT exists) — fall back to the boot theme
  and log.
- **Preview assets (pinned — in scope because per-preview themes are):** a themed
  preview rendering theme B must not load theme A's assets. During a themed preview
  render, `asset('x.css')` emits **`/_preview-assets/{token}/x.css`** via a per-render
  asset-base override on the extension — **PINNED reset semantics, same discipline as
  the tag collector**: the extension gains `setAssetBase(?string)` and the controller
  resets the base to null BEFORE every render (the extension is process-shared mutable
  state; reset-before-render is what stops a Twig exception mid-preview leaking
  `/_preview-assets/{token}` URLs into the next normal render — tested exactly that
  way). The route:
  - verifies the token via the same verifier, reads its SIGNED theme;
  - validates the asset path with the same rules `asset()` enforces (no `..`, no
    absolute paths, no backslashes, no schemes);
  - serves only that theme's `assets/` directory;
  - headers `Cache-Control: no-store`; tagged `Default` for the OpenAPI deny-list.
  Un-themed sessions and normal traffic keep `/theme-assets/…` untouched.

## 6. Contract + surface summary

- New contracts: `PreviewSession` VO + `PreviewSessionVerifier` (core impl);
  `PreviewThemeValidator` (render-pack impl, consulted by mint only if bound).
- Changed: `resolvePath(string $path, ?PreviewSession $previewSession = null)`
  (breaking signature — monorepo precedent); `PreviewToken` payload gains optional
  `theme` (additive; old tokens verify); core `PreviewReader` gains
  `readVerified(PreviewSession)` (the token-based `read()` stays for the JSON door);
  mint accepts optional `theme` (422 on unknown OR when no validator is bound).
- New middleware: `PreviewSessionMiddleware` (render pack) — cookie → verified
  `lemma_preview_session` request attribute, BEFORE `RenderPageCache` on the render
  routes; independent of cache settings.
- New routes (render pack, capability-gated, none cached, none in OpenAPI):
  `GET /_preview/exit`, `GET /_preview-assets/{token}/{path}` (`path` spans slashes).
- `RenderPageCache`: passthrough on the request attribute (no verification of its own).
- `RenderController`: reads the attribute for chrome/overlay/theme; per-request theme
  machinery (request-local Twig environment — never assigned to the memoized boot-theme
  instance); asset-base override reset before every render alongside `resetTags()`;
  Set-Cookie (`Secure` on HTTPS) on `/_preview/{token}`.
- Default theme: banner gains the Exit link.

## 7. Testing

- Session flow: `/_preview/{token}` sets the cookie (HttpOnly/SameSite/Max-Age ≈
  remaining TTL); with the cookie, the tokened entry's canonical URL shows the DRAFT
  (banner + no-store + no Cache-Tag), `/post` shows PUBLISHED listings in chrome, and
  nothing enters the page cache.
- Cache guard both ways: with a VALID cookie a pre-seeded cache entry is neither
  served (sentinel body not returned) nor overwritten; with a JUNK cookie the cached
  sentinel IS served (normal behavior — the cache-bust guard).
- Expiry ends the session (expired cookie → normal published rendering, cached);
  `/_preview/exit` clears the cookie and redirects `/`.
- **Sessions survive `cache_enabled=false`** (the middleware-split point): with the
  page cache disabled, a session cookie still yields the draft overlay + chrome
  (config-override boot, controller/middleware-direct where the route latch bites).
- Mint with `theme` while the render pack's validator is UNBOUND → 422 (removability).
- Theme: a token minted with `theme=alt` renders the alt theme in-session (tmp theme
  dir) while a plain request still renders the boot theme — INCLUDING immediately
  after a themed preview (the memoized-environment poisoning guard); `asset()` emits
  `/_preview-assets/{token}/…` in themed sessions and `/theme-assets/…` otherwise; a
  Twig exception mid-themed-preview must not leak the preview asset base into the next
  normal render (the reset-before-render guard, tested with an intentionally failing
  template); the asset route serves the alt theme's file with no-store, rejects
  traversal (`..`), rejects junk tokens (404), and vanished-theme sessions fall back
  to the boot theme (logged).
- Mint: unknown `theme` → 422; token WITHOUT theme (old format) verifies and previews.
- OpenAPI: `_preview/exit` and `_preview-assets` absent from the regenerated spec.
- The JSON preview door and all non-session rendering are byte-unchanged.

## 8. Out of scope

All-drafts sessions; draft injection into listings/archives; sliding session TTL; SPA
theme-picker UI (the mint API accepts `theme` — UI later); cross-device session
hand-off; preview of scheduled-publish future states.
