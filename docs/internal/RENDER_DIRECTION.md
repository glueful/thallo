# thallo-render Direction — Positioning & Future Packages

**Date:** 2026-08-01. This document captures where `thallo-render` stands against the
current MPA/CMS landscape and the three future directions discussed on top of it, each
with a recommendation and a rough priority. Like [POST_V1.md](POST_V1.md), this is
**not** an umbrella design doc — each item gets its own focused spec
(brainstorm → spec → plan) when it is actually scheduled. Nothing here is committed
work; it is the settled *direction* so the reasoning isn't lost.

---

## 0. Positioning: why the architecture is well-timed, not retro

The instinct to check is "is a Twig-rendering PHP MPA retro in 2026?" — and the answer
is no, because the JS ecosystem has spent the last few years converging back onto
exactly this model: Astro's zero-JS default, React Server Components, HTMX/Hotwire.
thallo-render's shape — full server render, one package-owned `runtime.js` of small
behavior modules, themes owning CSS only — is the islands/progressive-enhancement
position arrived at directly, without a bundler, hydration, or a client router.

Where it genuinely beats the mainstream stacks:

- **The cache model beats ISR at its own game.** The tagged full-page cache
  (`thallo:entry:{uuid}`, `thallo:type:{type}`) with event-driven purges is what
  Next.js on-demand revalidation and webhook-triggered SSG rebuilds approximate —
  deterministic, instant, no build pipeline. The tags are exactly the shape of CDN
  surrogate keys (Fastly/Cloudflare), so the edge story is a straight extension, not a
  rearchitecture.
- **The headless/rendered split is cleaner than either camp.** WordPress couples
  content to theme; most headless CMSes have no first-party rendered delivery at all.
  thallo-render is a removable capability pack over a delivery shape byte-identical to
  the headless API — one projection, two consumers.
- **DB-edited templates solve the WordPress theme-editor problem properly.** Static
  AST policy check, arrays-only context, append-only version history, ops
  kill-switch — "admins can edit templates safely" without the RCE history.
- **Correctness-by-construction details.** `path()` null unless published,
  escape-everything with `|raw` opt-in, reserved paths keeping API clients in JSON,
  real 30x / 410 redirect semantics, preview at real URLs through the real theme.

The real trade-offs (which the items below address):

1. **No component model shared with the client** — fine for content sites; the line is
   drawn at interactive-heavy pages. → §1.
2. **Ecosystem gravity** — theme authors and agencies live in React/Tailwind land. → §2.
3. **Boot-time theme resolution** — narrower than it sounds; see §3 for what the code
   already does.

The main risk is not technical; it is whether theme authors show up for a Twig
ecosystem in a React-dominated market. §2 is the mitigation.

---

## 1. Component package(s)

**Priority: highest leverage-to-effort of the three.** "Component package" is two
different products; build them separately rather than one thing that does both badly.

### 1a. In-theme interactivity: runtime → web components (near-term win)

The `ThalloRuntime` module registry (`packages/thallo-render/runtime/`) is already
~80% of a web-components library. Promote the behavior modules (carousel, tabs,
forms, …) to custom elements — `<thallo-carousel>`, `<thallo-tabs>` — giving theme
authors declarative, framework-free interactive components that work inside cached
Twig HTML with **zero build step**.

- Preserves everything that makes thallo-render good: full-page cache, no hydration,
  no bundler.
- Natural evolution of code the pack already ships and serves
  (`/_thallo/runtime/runtime.js`, fingerprinted + immutable).
- **Recommendation: do this first.** It is an evolution of the existing runtime spec,
  not a new package.

### 1b. Full app-building: a headless SDK, not a UI kit

For genuinely app-like frontends (configurators, dashboards), do **not** try to share
components with Twig — the dual-rendering path (twig.js and friends) has burned
everyone who's tried it. The real asset is that the delivery API is byte-identical to
the render context. The app-building package is therefore an **SDK**:

- Generated TypeScript types from content-type schemas.
- A delivery-API client (entries, listings, archives, menus).
- Preview-token support so external frontends get the same draft-preview story.

People then build Next/Astro/Vue apps against Thallo properly. Separate deliverable
from 1a, lower urgency.

---

## 2. Ecosystem gravity: meet authors at the CSS layer first

There is a cheap version and an expensive version; the cheap one buys most of the
value.

### 2a. Theme dev kit (recommended)

Most "I want to write React" energy from agency devs is really "I want Tailwind and
hot reload" — and hot reload is already free via Twig `auto_reload`. Twig itself is
not a hard sell (Craft and Drupal front-enders know it). Ship:

- **A Tailwind preset mapping onto the existing theme tokens** (colors, spacing,
  radius, fluid type) so token-driven CSS and Tailwind utilities coexist. Themes are
  just CSS; this changes nothing in the render model.
- **Theme scaffolding CLI** — `ThemeCloner` / `thallo:theme:clone` is already the
  seed; grow it into `theme:create` with the token map documented.

A React/JSX alternative theme engine was considered and rejected: it would require a
Node runtime alongside PHP and torch the simplicity story that is thallo-render's
whole differentiator. The headless SDK (§1b) is the sanctioned path for React-native
teams.

### 2b. The sleeper ecosystem play: a safe template marketplace

DB templates + the AST policy check are quietly the foundation for a **safe
theme/template marketplace** — the thing that actually built WordPress's ecosystem
(not its component model). Statically-checked templates that can be installed from a
catalog without arbitrary-code risk is a differentiator no competitor has. Long-term;
park it here so it isn't lost.

---

## 3. Boot-time theme resolution

**The v1 constraint is narrower than "requires restart."** What the code already does:

- `ActiveThemeSource` resolves **stored override → `RENDER_THEME` env → `default`**,
  revalidates the stored override on every resolution, and memoizes **per instance**
  — which under classic PHP-FPM means per request
  (`packages/thallo-render/src/ActiveThemeSource.php`). An admin switching the stored
  theme already takes effect on the next request under FPM, no restart.
- The restart requirement genuinely applies only to (a) `RENDER_THEME` env changes —
  acceptable, env changes imply a deploy anyway — and (b) long-running runtimes
  (FrankenPHP/Swoole/RoadRunner worker mode) where the container and its singletons
  survive across requests.

The actual blocker to fully dynamic resolution is **frozen strings at construction**:
the provider passes `activePaths()['name']` and the assets dir into services as plain
strings (`RenderServiceProvider.php` — cache-key name, `themeAssetsDir`), and
`ThemeLocator` takes the theme name in its constructor. In worker mode those
singletons pin the boot-time theme even though `ActiveThemeSource` could re-resolve.

### Improvement path (when scheduled)

1. **Indirection instead of frozen strings** — consumers take `ThemeLocator` (or a
   small `ThemePaths` provider) and read at call time.
2. **Per-theme Twig environments on demand** — the preview system already proves
   this works: themed preview sessions build an environment for a non-active theme
   per request, and compile caches are already segregated per theme
   (`storage/cache/twig/{theme}`). Live rendering reuses that machinery: resolve the
   theme per request, keep a small env map, warm on switch.
3. **Worker-mode memo invalidation** — reset `ActiveThemeSource`'s memo per request,
   or key it to a generation bumped by the existing
   `PurgeRenderCacheOnThemeChange` listener.

### Recommendation: defer until worker mode is a commitment

The page cache means most requests never touch Twig, and the per-request cost of
dynamic resolution is one settings lookup already paid under FPM. This work is worth
doing **when first-class worker-mode support (FrankenPHP/Swoole/RoadRunner) is
committed** — until then the boot freeze costs only a line in the README. The honest
framing today: *stored theme switches are already live-effective under FPM; env theme
changes are deploy-time by design.*

---

## Suggested sequencing

| Order | Item | Why |
|---|---|---|
| 1 | §1a runtime → web components | Highest leverage-to-effort; evolves shipped code; zero new infra. |
| 2 | §2a theme dev kit (Tailwind preset + scaffold CLI) | Cheap; directly attacks the ecosystem risk. |
| 3 | §1b headless TypeScript SDK | Unlocks app builders; independent of the render model. |
| 4 | §3 dynamic theme resolution | Gate on a worker-mode commitment. |
| — | §2b template marketplace | Long-term; revisit after 1a + 2a land. |
