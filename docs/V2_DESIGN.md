# Lemma V2 Technical Design — Rendered Delivery

This document makes the architecture decisions for the rendered half of the
hybrid CMS that [APPROACH.md](APPROACH.md) deliberately deferred: *"The
canonical source for your content... deliver it anywhere, rendered directly or
through headless APIs."* These are the decisions that are expensive to reverse
once themes exist in real installs: where HTML is produced, how public paths
reach the renderer, what a theme is, and how rendered pages are cached.
Everything else in the render phase can be refactored; these mostly cannot.

Status: **approved design — pre-implementation.** Sub-projects below each get
their own spec → plan → build cycle (`docs/superpowers/specs/`).

---

## 1. Rendering model: in-process SSR as capability packs

**Decision:** HTML is produced inside the same Glueful application, by a
removable capability pack — `glueful/lemma-render` — with a sibling
`glueful/lemma-navigation` pack for menus. Core stays render-agnostic; the
packs depend on `lemma-contracts` + framework only, like every other pack.

**Rejected alternatives:**

- *External renderer* (official Node/edge frontend consuming the delivery
  API): more moving parts, a second runtime to operate, and it contradicts the
  one-system hybrid story — the CMS should be able to serve the site itself.
- *Renderer abstraction for both*: no second renderer exists; a contract
  designed against one implementation is speculation (YAGNI).

**Rationale:** the classic CMS deployment (one app serves admin + API + site)
is the strongest form of "rendered directly", and the pack architecture keeps
it fully removable — with `lemma.render` disabled or the pack absent, the
install is exactly the headless product that exists today. The proven pack
invariants apply unchanged: capability-gated routes/listeners, no `App\*`
references (`composer boundaries`), permissions seeded by the pack and granted
by the host app.

## 2. Route→render path: lowest-priority catch-all into a resolver contract

**Decision:** the render pack registers **one lowest-priority GET/HEAD
catch-all** for public paths. All concrete routes win first — `/v1/*`,
`/admin/*`, `/sitemap.xml`, `/robots.txt`, extension static assets. The
catch-all hands the raw path to a **`PublicRouteResolver` contract** which
returns one of: *redirect* (honored as a real 30x), *published entry target*,
or *not found* (themed 404).

**The contract is a required seam, not an optimization.** `lemma-render`
never imports `App\*`, so it cannot consume the engine's `RouteResolver`
directly:

- `lemma-contracts` adds `Delivery\PublicRouteResolver` (+ a small resolution
  result shape: kind, entry identity, locale, redirect target/status,
  canonical path).
- Core implements it by wrapping the existing `RouteResolver` /
  `PathRenderer` / canonical logic (the `DraftSummaryReader` precedent).
- The render pack sees only the contract.

**Rejected alternatives:**

- *Template-derived route list* (register `/{locale}/{type}/{slug}` etc.,
  mirroring `PathRenderer`'s route template): narrower and couples render to
  today's path shapes. If route templates change, or entries gain custom
  paths, nested paths, or landing pages, render would have to chase that
  logic. The resolver owns path semantics; render must not duplicate them.
- *Dedicated prefix or hostname* (`/site/*`): no shadowing risk, but ugly
  URLs or extra vhost configuration — weakens "the CMS serves your site".
- *Content negotiation* (same URL serves JSON or HTML by Accept header):
  cache-splitting, CDN and debugging pain in practice.

**Mechanism note:** the Glueful router has no fallback-route feature today.
The render-core sub-spec picks the closest equivalent — the stated preference
is adding a proper lowest-priority fallback to the framework router (the
dogfooding rule: improve the framework over reinventing beside it); a
404-interception layer is the fallback option. Whatever the mechanism, the
architecture is *"catch-all into resolver"* — not a route list.

## 3. Themes: filesystem, Twig, auto-escaped

**Decision:** Twig is the v2 theme engine. A theme is a directory —
`themes/{name}/` with `theme.json` (name, version, declared menu regions),
`templates/`, and `assets/` — git-versioned and composer/path-distributable.
The active theme is selected in settings (`lemma_settings`). No DB-edited
templates in v2; Twig's sandbox keeps that door open as a follow-up.

**Rejected alternatives:**

- *DB-stored templates*: arbitrary-code-in-the-database security surface,
  no git history, and versioning pain from day one — before a single page
  renders.
- *Plain PHP templates* (Plates-style): no sandboxing ever (permanently
  closes the DB-override door), manual escaping discipline, themes become
  arbitrary code.
- *Latte*: arguably the best escaping model, but a far smaller theme-developer
  ecosystem than Twig (Craft/Drupal/Symfony lineage).

**Template hierarchy (v2, deliberately minimal):**

```
layout.twig                 -- base layout
index.twig                  -- homepage (bound to a designated entry in
                            -- settings, or standalone)
entry/{type-slug}.twig      -- entry page for a content type...
entry.twig                  -- ...falling back to the generic entry template
404.twig, error.twig        -- themed error pages
```

**Render context:** `entry` (the delivery-shaped array, including its `seo`
object — the same shape the headless API serves), `site` (name, locale,
locales), and functions: `menu('main')` (**optional** — via `MenuReader` when
available, `[]` otherwise; see §5), `path(entry)` (via
`LemmaContext::renderPath()`), `asset('css/site.css')` (theme assets served
through the extension `serveFrontend()` static-serving mechanism). Twig
auto-escaping is on. V2 ships one minimal default theme as the reference
implementation.

## 4. Render caching: full-page, tag-invalidated (its own sub-project)

**Decision:** render core shipped **uncached SSR first**; caching followed as
sub-project 3 (**shipped 2026-07-02**). When it lands: full-page cache via the framework
`CacheStore`, keyed **`render:{theme}:{normalizedPath}`** — the theme is part
of the key so a theme switch can never serve stale markup; the `{locale}`
component originally sketched here proved redundant (locale is a pure
function of the path — amended with the sub-project 3 spec) — tagged with the
same entry/type tags the delivery cache uses. Invalidation rides the existing
`ContentLifecycleEvent` listener pattern (`InvalidateCacheTagsListener`):
entry/type events purge targeted pages; menu changes purge broadly; theme
file edits have an operator command (`render:cache:clear`). ETag on
responses; TTL as a safety net (config, default 1h). *User/preview cache
bypass is deferred to preview-through-theme* (amended: an anonymous page GET
carries nothing to detect, and event-driven purges make editor staleness
moot). CDN purge composes via the existing `PurgeCdnListener` seam. Full
detail: `docs/superpowers/specs/2026-07-02-lemma-render-caching-design.md`.

## 5. Navigation: `lemma-navigation` pack, soft-consumed by render

**Decision:** menus are data, owned by a small standalone pack built *before*
render: `navigation_menus` + tree items (linking to entries or raw URLs),
admin CRUD API + SPA editor, a public delivery endpoint (menus are valuable
headless), and a **`MenuReader` contract in `lemma-contracts`**.

**`lemma-render` does NOT hard-depend on `lemma-navigation`.** `menu('main')`
returns `[]` when no `MenuReader` implementation is available; the default
theme renders without a menu. If lemma-navigation is installed and enabled,
menus appear. This keeps each pack independently removable and composable.
Building navigation first is still correct: it proves the menu seam and gives
render a real integration on day one.

## 6. V2 render-core scope (pinned)

The first render milestone is sharp: **"Lemma can serve real HTML pages from
published content using a filesystem theme."**

**In scope for render core:**

- filesystem Twig themes
- clean public URL rendering through the lowest-priority catch-all
- homepage
- individual entry pages
- 404/error templates
- default reference theme
- optional menu rendering through `MenuReader`
- uncached SSR first; render cache as the next sub-project

**Follow-up tracks after render core (explicitly deferred, not in v2 core):**

- ✅ listing/archive pages — **shipped 2026-07-02** (incl. term-archive pages;
  `docs/superpowers/specs/2026-07-02-rendered-listing-archive-pages-design.md`)
- ✅ preview-through-theme — **shipped 2026-07-02**
  (`docs/superpowers/specs/2026-07-02-preview-through-theme-design.md`); preview
  SESSIONS (full-site nav, listing preview, per-preview themes) shipped 2026-07-02
  (`docs/superpowers/specs/2026-07-02-preview-sessions-design.md`)
- ✅ taxonomy term INDEX pages — **shipped 2026-07-02**
  (`docs/superpowers/specs/2026-07-02-term-index-pages-design.md`)
- ✅ DB-edited templates — **shipped 2026-07-03**
  (`docs/superpowers/specs/2026-07-03-db-edited-templates-design.md`)
- page/block builder

## 7. Sub-project sequence

Each is its own spec → plan → build cycle:

1. **`lemma-navigation`** — menus pack + SPA editor + `MenuReader` contract.
   Independently shippable; proves the seam render consumes.
2. **`lemma-render` core** — `PublicRouteResolver` contract + core wrapper,
   catch-all mechanism, Twig pipeline, themes, default theme, error pages.
3. **Render caching** — full-page cache + invalidation + CDN composition
   (§4).
4. **Follow-up tracks** (§6) as they're picked up, each against the then-
   existing contracts.
