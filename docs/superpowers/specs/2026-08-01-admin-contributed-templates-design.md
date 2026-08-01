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

- **`TemplatePolicy::FUNCTIONS`** += `shop_product_url`, `shop_category_url`,
  `shop_index_url` (already defined in `RenderContextExtension` via the soft-bound
  `StorefrontLinkResolver` seam — they parse in the linter's scratch env today) and
  the new `json_script`. `raw` and `constant` stay denied.
  **`CACHE_VERSION` 16 → 17.**
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
- `twigCompletions.ts` gains the four newly allowlisted functions.

## Testing

- **Catalog/runtime parity (new, P2-tightened):** for every **editable `.twig` row**
  in the catalog (excluding `custom.css` and read-only asset rows), the source the
  admin `GET` returns is byte-identical to what the render side resolves for that
  name — DB rows compared against the composite DB-first loader
  (`RenderTemplateLoader`), filesystem rows against the selected theme's filesystem
  chain. This is the invariant that the editor edits what actually renders.
- **Round-trip lint gate (new, release gate):** every shipped `.twig` under the
  render default theme, `packages/thallo-account/templates`, and
  `packages/thallo-commerce/templates` passes `TemplateLinter` — a template using
  denied vocabulary fails CI, not an admin's save.
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
