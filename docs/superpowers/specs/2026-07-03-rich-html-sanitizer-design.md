# Rich HTML Sanitization / Rendering — Design

**Date:** 2026-07-03
**Track:** the security follow-up pinned by the starter-library spec (its Out-of-scope section recorded this shape); **unblocks the `rich_text` starter block**. Platform-wide: affects every `text format:rich` field in Lemma, not just blocks.
**Depends on:** `symfony/html-sanitizer` (already in the dependency tree), `FieldValidator`'s cleaned-payload path (incl. the blocks recursion), the render extension + `TemplatePolicy`.

## 0. Decisions (from brainstorm)

| Decision | Choice |
|---|---|
| Engine | **`symfony/html-sanitizer`** — allowlist by construction, maintained, parser-level handling of event attrs/`javascript:`/malformed HTML. Never hand-rolled; HTMLPurifier adds nothing over the component we already have. |
| Enforcement | **Save-time + render-time**: `FieldValidator` sanitizes `format:rich` in the cleaned payload (stored data is clean), AND a `safe_html` Twig filter sanitizes at output (defense-in-depth for rows written around the API). The DB-template dual-enforcement philosophy. |
| Legacy | **None exists** (pre-release product) — no backfill; all stored rich content is clean by construction from day one. |
| Allowlist | **Fixed, code-level, TipTap-scoped** — not app-configurable in v1 (the `TemplatePolicy` stance). Stripped, never 422'd. |

## 1. Contract

`Glueful\Lemma\Contracts\Content\RichHtmlSanitizer` (new `Content` namespace in `lemma-contracts` — the render pack consumes it, so it lives behind the pack boundary):

```php
interface RichHtmlSanitizer
{
    /** Returns HTML safe to render raw: allowlisted TipTap vocabulary only. Idempotent. */
    public function sanitize(string $html): string;
}
```

## 2. Implementation — `App\Content\Sanitization\TipTapHtmlSanitizer`

Wraps `Symfony\Component\HtmlSanitizer\HtmlSanitizer` with an **explicitly built allowlist — pinned to the actual Symfony config API**: the config starts EMPTY and adds via `allowElement()` / `allowAttribute()` / `allowLinkSchemes(['http', 'https', 'mailto'])` / `allowRelativeLinks(true)`. **Never start from `allowSafeElements()` and subtract** — additive-only keeps the allowlist auditable and immune to upstream "safe" set changes.

Allowlisted vocabulary (what `RichText.vue`'s TipTap setup can emit):

- **Elements:** `p`, `h1`–`h6`, `ul`, `ol`, `li`, `blockquote`, `pre`, `code`, `strong`, `em`, `s`, `u`, `a`, `br`, `hr`.
- **Attributes:** `href` on `a` (scheme/relative rules above); the TipTap task-list shape — `data-type` on `ul`, `data-checked` on `li`. Checkbox `input`s are STRIPPED (CSS renders task state from `data-checked`).
- **Everything else stripped:** `img` (media flows through asset fields + `media()`), tables, `span`, `style`/`class`, all `on*` event attributes, SVG/MathML, `data:` URLs. Stripping is silent — authors never see 422s for paste artifacts.
- **Engine gotcha (pinned):** the Symfony sanitizer has a **default max input length that silently truncates** long documents — set it explicitly (`withMaxInputLength(1_000_000)`, 1MB) and test a long-document round-trip.

Registered in `LemmaServiceProvider` as the `RichHtmlSanitizer` binding (shared).

## 3. Save-time enforcement — `FieldValidator`

In the cleaned-payload path (`validateAt`'s per-field section), after the string type-check passes:

- `text format:rich` values are replaced by `sanitize($value)` in the CLEANED payload — so what persists is what was sanitized.
- **Rich fields inside blocks sanitize automatically** via the existing `validateAt` recursion — zero special-casing (block data flows through the same per-field path).
- Plain `text` fields are untouched (escaping remains the renderer's job — the `copy` block convention).
- Wiring: optional ctor param (`?RichHtmlSanitizer $sanitizer = null`) with the same lazy-fallback pattern as `BlockTypeRepository` (construct `TipTapHtmlSanitizer` when null) — direct-constructed validators in tests keep working.

## 4. Render surface — the `safe_html` Twig filter

On `RenderContextExtension`, soft-bound to the contract like `media()`:

- Sanitizes its input and returns it; idempotent (sanitizing already-clean content is a no-op), so double-application through save + render costs nothing and covers rows written around the API.
- **Fail-closed behavior (pinned, exact):** if no sanitizer is bound, **or the sanitizer throws**, return `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`. The filter is declared `is_safe => ['html']` **only because every path out of it is already safe** — sanitized markup or pre-escaped text. There is no code path returning unprocessed input.
- Non-string input → `''`.
- `'safe_html'` joins `TemplatePolicy::FILTERS`; **`CACHE_VERSION = 3`** (this project's bump).

## 5. Starter-library coordination (pinned now, not improvised later)

The sanitizer ships FIRST and takes `CACHE_VERSION = 3`. Executing the starter library afterwards amends its plan to:

1. bump `CACHE_VERSION` to **4** (its `media` + `safe_url` surfaces);
2. **swap `copy` back out for `rich_text`** (`body` — text, format rich) rendering `{{ data.body|safe_html }}` in `blocks/rich_text.twig` — the library stays at **10 small, opinionated starters** (rich text subsumes plain paragraphs; sites wanting an explicit plain-text authoring mode can add a `copy`-style type themselves in seconds);
3. update its tests accordingly (counts derive from `definitions()` already; the escaped-`copy` assertions become the `rich_text` sanitized-render assertions).

## 6. Security testing (the review emphasis)

- **Attack matrix (each must strip/neutralize):** `<script>`, `on*` event attributes (`onclick`, `onerror` on any element), `javascript:` links — plain, case-variant, entity-encoded (`jav&#x09;ascript:`), whitespace-obfuscated — unsafe `style` attributes, hostile SVG (`<svg onload>`), `data:` URLs in `href`, malformed/unclosed/mis-nested HTML, `<iframe>`/`<object>`/`<embed>`/`<form>`.
- **Fidelity:** the full TipTap vocabulary round-trips unmangled (headings, lists, task-list `data-*` attributes preserved with `input`s stripped, blockquote/code, links with allowed schemes + relative hrefs).
- **Properties:** idempotency (`sanitize(sanitize(x)) === sanitize(x)`); long-document round-trip (no silent truncation at the engine default).
- **Integration:** a rich field top-level AND inside a block arrives sanitized in the cleaned payload (`<script>` in a block's rich field is gone after draft save); plain text fields untouched; `safe_html` — sanitizes, fail-closed unbound (escaped output, exact `htmlspecialchars` flags), fail-closed on a throwing sanitizer stub, lints clean in a DB template; `CACHE_VERSION === 3`.

## 7. Error handling

| Case | Behavior |
|---|---|
| Disallowed markup in rich input | stripped silently at save (and again at render) — never a 422 |
| `safe_html` with no bound sanitizer | escaped output (exact pinned `htmlspecialchars` call) |
| Sanitizer throws at render | escaped output (same fallback); log left to the engine's normal channels |
| Non-string into `safe_html` | `''` |
| Over-length document | explicit 1MB limit; content beyond it truncates AT THE PIN, not at the engine's silent default |

## Out of scope

- Configurable allowlists; `img`/table/figure support in rich text (media is asset fields); per-site scheme policies.
- Backfill (no legacy content exists — pre-release).
- Sanitizing delivery-API reads (stored data is clean by construction; headless consumers should sanitize as standard practice regardless).
