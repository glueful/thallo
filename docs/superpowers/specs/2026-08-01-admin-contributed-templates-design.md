# Package-Contributed Templates in the Admin Theme Editor — Design

**Date:** 2026-08-01. **Status:** approved design, pre-implementation.

## Context

thallo-account and thallo-commerce contribute Twig templates (`account/*`, `shop/*`,
`blocks/*`) into the render chain via `RenderContributionRegistry` /
`TemplatePathContributor`. At render time these are first-class: the composite loader
is DB-first for **every** template name, so a DB-edited override of
`shop/checkout.twig` already shadows the package file. But the admin Theme editor
(`admin/src/pages/templates/index.vue`) cannot see them: `TemplateCatalog::list()`
walks only the pack default theme and the app theme
(`packages/thallo-render/src/Templates/TemplateCatalog.php:23-48`), and
`readFile()` has the same blindness — contributed templates are invisible in the
catalog, and `GET /render/templates/shop/checkout.twig` 404s, so the editor cannot
seed from the package source.

Goal: contributed package templates get the same admin UX as theme templates — listed
in the tree, source viewable, editable via DB override, history/restore/delete —
without weakening the template sandbox.

## Pinned rules (settled during brainstorming)

1. The catalog consumes the **same frozen `RenderContributionRegistry` snapshot** as
   runtime rendering. No separate package scan.
2. Precedence everywhere: **DB override → active theme → ordered package
   contribution → render default.**
3. Package files are **immutable baselines**. Save creates a DB override; no code
   path writes a package file. Deleting the override reveals the next source in the
   ladder automatically.
4. Only currently registered contributions appear in the catalog: disabling a
   capability removes its package baselines from the listing.
5. **Honest capability-off behavior (P1):** disabling a capability does NOT make
   existing overrides dormant. `TemplateCatalog` unions every active DB row
   (`TemplateCatalog.php:34`) and `DatabaseTemplateLoader` resolves overrides without
   requiring a filesystem baseline (`DatabaseTemplateLoader.php:43`). So with
   Commerce disabled, an active override of `shop/checkout.twig` remains listed as
   `origin: "db"` and remains resolvable by name; deleting it while the capability is
   off leaves no fallback. True dormancy would require stored contributor provenance
   on override rows — explicitly out of scope.
6. Contributor id is kept **internally** (deterministic resolution + diagnostics);
   no package badges, no per-package field in the public API. The only public
   contract change: origin vocabulary becomes `db | theme | package | default`.
7. `TemplatePolicy` remains the central security boundary — no contributed
   allowlists. Revisit only when a second independent package needs its own safe
   functions.

## Design

### 1. Registry accessor (P1)

`RenderContributionRegistry::frozenTemplatePaths()` currently returns directories
only (`RenderContributionRegistry.php:64`). Add a backward-compatible
**`frozenTemplateContributions(): list<array{contributor_id: string, dir: string}>`**
returning ordered rows, with `frozenTemplatePaths()` projected from the **same frozen
state**. Both consumers (ThemeLocator wiring, catalog wiring) may call either
accessor; the invariant is **one immutable snapshot**, not literally one method call.

### 2. Catalog

`RenderServiceProvider` passes the frozen contribution rows into `TemplateCatalog`'s
factory (`RenderServiceProvider.php:214-222`), alongside the existing theme dirs.

- **`list()`** builds the union in precedence order — pack default → contributions in
  *reverse* registry order → app theme — so later writes win, matching
  `ThemeLocator`'s chain (`ThemeLocator.php:58-66`) exactly. Rows whose winning file
  lives in a contributed dir get `origin: "package"`. Active DB rows flip the row to
  `origin: "db"` / `overridden: true`, unchanged from today. Contributor id may
  appear in log lines only.
- **`readFile()`** gains the same ladder: app theme → contributions in registry
  order → pack default. This is the editor-seeding fix.

`TemplatesAdminController` needs **no logic changes**: `show()` already falls through
to `readFile()`; `save()` already accepts these paths (syntactic validation only) and
creates DB overrides; `delete()` already deactivates the override. Update the
controller's documented origin vocabulary from `db|theme|default` to
`db|theme|package|default` (P2).

### 3. Policy & trusted-output contracts

- **`TemplatePolicy::FUNCTIONS`** gains twelve reviewed entries and drops the
  pre-existing `range` entry and `RangeBinary` node in the same `CACHE_VERSION`
  bump (16 → 17). `raw`, `constant`, `range(...)`, and `1..N` stay denied after
  this change. No shipped template uses either range form; keeping them would let a
  DB template allocate an arbitrarily large array before any called function can
  normalize its input.
  - `shop_product_url`, `shop_category_url`, `shop_index_url` — already defined in
    `RenderContextExtension` via the soft-bound `StorefrontLinkResolver` seam; they
    parse in the linter's scratch env today.
  - `json_script` — new, below.
  - **Amendment (gate audit):** eight further render-pack functions join after
    individual review, because seven shipped default-theme templates already use them
    (`layout.twig`, `blocks/blog_posts.twig`, `blocks/hero.twig`, `blocks/image.twig`,
    `blocks/color_mode.twig`, `blocks/style.twig`,
    `_listing_rows.twig`) and an exception list would permanently encode that Thallo's
    own default templates cannot round-trip through its editor. Review outcomes:
    `entries` (anonymous/public delivery only; server-clamped to 1..12 results —
    `app/Content/Delivery/EngineEntryListReader.php:42`), `is_preview` (reads one
    render flag), `claim_priority_image` (bounded one-bit render state, reset per
    render), `color_mode_enabled` / `color_mode_script` (boolean / static trusted
    output), `theme_colors_style` / `theme_style_scope` (closed-enum generated
    markup), and `media_image` (public-media authority) — the last allowlisted only
    together with **defensive width normalization**: positive ints only,
    deduplicated, capped at 8 candidates before the resolver runs. The normalizer
    bounds resolver work; denying the `range()` function and `RangeBinary` syntax
    separately prevents unbounded array allocation before the function is entered. Each function gets a focused
    safety/bounds test. The earlier "only three functions" pin was based on an incomplete
    inventory; the lint-all-shipped gate stays **exception-free**.
  - **Implementation amendment (Task 5, review-verified):** `TrueTest` joined
    `NODE_CLASSES` and `'true'` joined `TESTS` in the same v17 bump. Twig 3
    unconditionally wraps bare boolean conditions (`{% if fn() %}`, `x() ? a : b`,
    `{% if attr %}`) in a `TrueTest` node (`IfNode`/`ConditionalTernary` both call
    `TrueTest::wrap()`), so the shipped default theme (`layout.twig`,
    `blocks/color_mode.twig`, `blocks/blog_posts.twig`) cannot lint without it.
    Reviewed safe: pure boolean coercion, no execution/IO surface; the wrapped
    child expression is still independently vetted by the linter.
- **`json_script(value)`** — new function in `RenderContextExtension`: encodes with
  `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT |
  JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR` and returns `Twig\Markup`
  (`JSON_HEX_TAG` makes `</script>` breakout impossible). **Fail-closed (P2):** on
  unencodable input the `JsonException` propagates — the page renders through the
  standard render error ladder; the function never emits partial or unsafe output.
  `shop/product.twig:30` becomes
  `{{ json_script(structuredData) }}` inside the existing
  `<script type="application/ld+json">` tag, dropping `constant()` and `|raw`.
- **Enrichment boundary (P2):**
  `EntryBlocksRenderer::renderPublishedBlocks()` changes `?string → ?Twig\Markup`
  (`EntryBlocksRenderer.php:44`) — the one trusted wrapping point.
  `ShopCatalogController::resolveEnrichment()` updates its declared array shape
  accordingly (`ShopCatalogController.php:244`). `shop/product.twig:219` becomes
  `{{ enrichment_html }}` — no `|raw`.

### 4. Admin UI (minimal)

- Folders `account/` and `shop/` appear automatically — the tree already groups by
  first path segment (`index.vue:32-43`); merged `blocks/` (theme + account +
  commerce) needs nothing.
- The existing origin `UBadge` (`index.vue:298-304`) renders `package` with the
  neutral style already used for non-`db` origins.
- The filesystem note (`index.vue:375`) reads, for `origin === 'package'`:
  *"Package template — saving creates a database override; the package file is never
  modified."*
- `twigCompletions.ts` is synchronized to the complete policy-v17 function list,
  including the twelve additions and the removal of `range`.

## Testing

- **Catalog/runtime parity (new, P2-tightened):** for every **editable `.twig` row**
  in the catalog (excluding `custom.css` and read-only asset rows), the source the
  admin `GET` returns is byte-identical to what the render side resolves for that
  name — DB rows compared against the composite DB-first loader
  (`RenderTemplateLoader`), filesystem rows against the selected theme's filesystem
  chain. The test fixture includes at least two package contributors with a
  colliding template name and feeds the same frozen registry snapshot to both
  `TemplateCatalog` and `ThemeLocator`; package precedence is therefore exercised,
  not inferred. A separate production-factory test proves
  `RenderServiceProvider::makeTemplateCatalog()` consumes that frozen snapshot.
  This is the invariant that the editor edits what actually renders.
- **Round-trip lint gate (new, release gate) — amended after the gate's first run
  (Task 7 audit, user-ruled):** the gate's dry run exposed vocabulary the
  function-only inventory missed. Resolution:
  - **Sanctioned vocabulary joins policy v17** (same unreleased bump): filters
    `editable_text` (escapes its value in both modes) and `style_hook`
    (sanitizer-backed); `ForElseNode` (`{% for %}…{% else %}`); macro machinery
    (`macro`/`import` tags + `ImportNode`/`MacroNode`/`MacroReferenceExpression`),
    with a linter rule constraining imports to the **self-import shape**
    (`{% import _self %}` only — all four shipped users conform).
  - **`matches` (`MatchesBinary`) stays denied** — deliberate regex-DoS posture.
    `blocks/style.twig`'s two regex trust-boundary checks are replaced by **bounded
    helper filters** whose patterns live in PHP: `hex_color` (value when it matches
    `/^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/`, else `''`) and
    `numeric_clamp(value, min, max)` (clamped float when `is_numeric`, else null).
    Both join the FILTERS allowlist.
  - **`shop/product.twig`'s `{% for i in 1..5 %}` → `[1, 2, 3, 4, 5]`** (range
    denial holds).
  - **Two named disk-only pins — a closed two-template policy, NOT a general
    exception mechanism:** `blocks/html.twig` (`|raw` by design — trusted-editor
    escape hatch) and `blocks/shortcode.twig` (guarded dynamic include by design).
    The SAVE POLICY is unchanged — `raw` and non-constant includes stay denied for
    every DB template. The gate becomes a **two-way ratchet**: every shipped
    template lints clean EXCEPT exactly these two, each failing ONLY for its named
    reason; the gate fails if either becomes clean, gains a different violation,
    changes path, or any third template fails.
  - **Admin UX for the two pins:** their catalog rows are marked read-only with an
    explanatory reason (advertising Save and returning 422 would be poor UX). The
    pin list lives in one place (`TemplatePolicy::DISK_ONLY_TEMPLATES`).
  - **Direct denial tests** prove arbitrary DB templates still cannot use `raw`,
    non-constant include targets, `matches`, or non-self imports.
- **Function safety/bounds tests (amendment):** `media_image` width normalization
  (dedupe, positive-int filter, cap 8); `entries` limit clamp 1..12 at
  `EngineEntryListReader`; both `range(...)` and `1..N` remain denied; the remaining six newly
  allowlisted functions each lint clean and execute through a DB-override template.
- **Admin API integration** (extend `tests/Integration/Render/TemplatesAdminApiTest.php`):
  contributed name listed with `origin: "package"`; `GET` seeds the package source;
  `PUT` creates an override that wins at render; `DELETE` reveals the package
  baseline; capability off → package row absent while an existing override remains
  listed as `origin: "db"` and resolvable (the honest P1 behavior, asserted).
- **Rendering:** product-page JSON-LD via `json_script` including a
  `</script>`-in-data escape case and a fail-closed unencodable-input case;
  enrichment renders unescaped without `|raw`.
- **Admin unit** (extend `admin/src/__tests__/templatesPage.spec.ts`): package rows
  grouped and badged; package note text.

## Out of scope (deliberate)

- Package badges / contributor ids in the public API.
- Contributed policy allowlists (revisit at a second independent package).
- Stored contributor provenance on override rows (true capability-off dormancy).
- Exposing package `assets/` as read-only rows.
- Any "create new template" UI affordance (unchanged from today).
