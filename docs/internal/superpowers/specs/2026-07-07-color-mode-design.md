# Color Mode (light / dark / system) — Design Spec

> **Status:** Design, awaiting review. Feature **A** of the Thallo theming project
> (A = color mode, B = theme color config, C = style wrapper block). B and C are
> separate specs; template/theme *presets* are a separate future feature entirely.

**Goal:** Let a Thallo site render in light, dark, or system-following color mode,
with the visitor's choice applied flash-free and stored client-side — so the
rendered HTML stays mode-agnostic and the page cache never varies by preference.

**Product naming:** "Thallo" in all docs, copy, storage keys, and event names.
(Code identifiers elsewhere may remain `lemma`; these are new identifiers, so
they are `thallo.*`.)

---

## 1. Scope

**In scope**
- A three-state preference: `light | dark | system` (default `system`).
- A no-flash inline resolver that stamps `html[data-theme="light|dark"]` before paint.
- A complete **dark token set** (`html[data-theme="dark"]` re-mapping every theme
  token) and a visual pass across all seeded blocks in dark.
- A **color-mode toggle block** (3-way segmented control) authors can drop into a
  page or region.
- A **disable config** (`config/theme.php` → `color_mode.enabled`).
- **CSP guidance**: the inline script is a byte-stable literal with a published
  `sha256-…` hash; no nonce.

**Out of scope (other specs / future work)**
- Operator brand/accent color selection → **Spec B** (token *values*, same templates).
- Style wrapper block (token-overrides + class-hook) → **Spec C**.
- Template/theme *presets/variants* (`default`, `studio`, `editorial`, each with
  its own templates/assets) → separate future feature. Color mode must work
  unchanged inside any future preset because it operates purely on tokens + `data-theme`.

---

## 2. Pins (authoritative constraints)

Every task inherits these:

1. **Naming** — Thallo in docs/copy/storage/event names.
2. **Storage** — `localStorage['thallo.colorMode']` ∈ `{light, dark, system}`; default `system`.
3. **DOM state** — the inline resolver stamps `html[data-theme="light"]` or
   `html[data-theme="dark"]`; **never** `data-theme="system"`.
4. **Cache posture** — server HTML is mode-agnostic; `RenderPageCache` does **not**
   vary by color mode. No server-side branching on preference.
5. **CSP** — the inline script is a **byte-stable literal**; publish its
   `sha256-…`; **no nonce**.
6. **Disable config** — `config/theme.php` → `color_mode.enabled` (bool; env
   `THALLO_COLOR_MODE_ENABLED`). When `false`: no inline resolver, **no server
   enablement marker**, no toggle UI, and no dark CSS is *required* (dark rules stay
   inert because `data-theme` is never `dark`). Disabled must be inert **even if
   `localStorage['thallo.colorMode'] = dark`** already.
7. **Global runtime, hard-gated** — the color-mode runtime is a **global** module in
   external, deferred `blocks.js` that owns the `data-theme` writes and the
   `matchMedia` listener. It starts **only** when the server enablement marker
   (`data-color-mode-enabled="true"`, §3.4) is present — never on localStorage alone.
   Only the *resolver* is inline; toggle controls are **optional consumers** of the
   runtime, not its owners.
8. **Toggle UI** — a 3-way segmented control (light / system / dark) is v1.
9. **Sync** — a toggle asks the runtime to set the preference; the runtime writes
   localStorage, updates `data-theme`, and dispatches `thallo:color-mode-change` so
   every control (and the runtime's own OS listener) stays in sync.
10. **CSS work** — a complete dark token re-map plus a visual pass across every
    seeded block.
11. **Resolver source of truth** — the inline resolver is defined **once** as a PHP
    constant/value and rendered **verbatim** by Twig; its published `sha256-…` is
    asserted against *both* the constant *and* the rendered output (§6), so layout
    whitespace/escaping can't silently drift the CSP hash.

---

## 3. Architecture

Six units, each independently understandable and testable.

### 3.1 The no-flash resolver (inline `<head>` script)

A tiny synchronous IIFE emitted in `layout.twig`'s `<head>`, **before** the CSS
`<link>`s, so `data-theme` is set before the browser applies any styles.

Behavior (algorithm — the plan pins the exact bytes):
- Read `localStorage['thallo.colorMode']`; treat missing/invalid as `system`.
- Resolve: `system` → `matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'`;
  `light`/`dark` → themselves.
- Set `document.documentElement.dataset.theme = resolved`.
- Wrap in `try/catch` (localStorage can throw in private mode) → fall back to `light`.

Properties:
- **Single source of truth.** The resolver body is defined **once** as a PHP
  constant/value (e.g. `ColorMode::RESOLVER_JS`), and `layout.twig` renders that exact
  string **verbatim** (the one sanctioned raw emit — theme-owned static JS, not user
  content). Nothing is interpolated: the storage key `thallo.colorMode` is hardcoded,
  so there is exactly one possible script string ⇒ one hash. The published `sha256-…`
  is a constant derived from this value and asserted against both it and the rendered
  output (§6).
- **Conditional emit.** When `color_mode.enabled === false`, `layout.twig` emits the
  script *not at all* — the disable path never mutates the script text (which would
  drift the hash).
- **Enablement marker.** When enabled, the server also emits a static marker
  `data-color-mode-enabled="true"` on `<html>`. This is the authoritative gate the
  external runtime checks before it does anything (§3.6). It is config-driven, not
  preference-driven — identical for every visitor of a given site — so it does **not**
  vary the cache.
- **JS-off fallback:** if scripting is disabled, no `data-theme` is set and CSS
  falls back to `:root` (light). This is the accepted degraded state; we do **not**
  add a `@media (prefers-color-scheme)` fallback, to keep `data-theme` the single
  source of truth (a media query + `data-theme` could otherwise disagree).

### 3.2 The `data-theme` contract

`html[data-theme]` is the single switch. Only ever `light` or `dark` at runtime.
All visual difference is CSS keyed off this attribute — no server render depends on it.

### 3.3 The dark token set (the bulk of the CSS work)

A `html[data-theme="dark"] { … }` block (in `blocks.css`, or a dedicated
`tokens.css`/`theme.css` — decided in the plan) that **re-maps every token**:
`--bg, --ink, --muted, --surface, --surface-2, --line, --accent-ink, --shadow`
(and any others). Because every block is authored against tokens, the re-map
carries most of the theme automatically. The visual pass then audits the cases
tokens don't fully cover:
- `color-mix(... var(--bg))` tints (soft/subtle/ghost variants) — verify legibility.
- Inverted bands (`section--inverted`, `cta--solid`, `button --solid`) — ensure the
  invert still reads in dark.
- Shadows (`--shadow`) — usually softened/removed in dark.
- Images / logos — no token control; note any that need a dark asset (future).
- `logos --grayscale`, media posters, etc.

`system` needs no CSS branch — the resolver has already collapsed it to a concrete
`light`/`dark` value.

### 3.4 The disable config

`config/theme.php`:
```php
return [
    'color_mode' => [
        'enabled' => env('THALLO_COLOR_MODE_ENABLED', true),
    ],
];
```
Consumed in three places, all server-side, all gated by the same flag:
- `layout.twig` — emits the inline resolver **and** the `data-color-mode-enabled="true"`
  marker on `<html>` only when enabled.
- The toggle block template — renders **nothing** when disabled (no dead UI).
- (Implicitly) the external runtime — inert unless the marker is present (§3.6).

The render layer exposes the flag to Twig (e.g. a `color_mode_enabled()` function
or a render-context global — chosen in the plan). Dark CSS may remain present but is
inert when disabled (`data-theme` never becomes `dark`).

### 3.5 The toggle block (`color_mode`)

A new seeded block type (proposed slug **`color_mode`**), category *Content* (or
*Layout*). Renders a 3-way **segmented control** with light / system / dark options
(sun / monitor / moon icons via `icon()`), accessible as a radio group. The control
highlights the stored **preference** (`light|dark|system`) — not the resolved
`data-theme` — so "system" is a selectable, visible state.

- Server renders the static markup (all three options) with a stable hook class
  (`thallo-block-color_mode`) and `data-*` option markers; it does **not** know or
  render the current selection (that's client state).
- When `color_mode.enabled === false`, the template emits nothing.

### 3.6 The global color-mode runtime (`blocks.js`)

A **global** runtime module (external + deferred, sibling of the nav/carousel
enhancers) — **not** tied to the presence of a toggle block. It is the single owner
of runtime `data-theme` writes and the OS listener, so a page with **no** toggle
still tracks OS changes while the preference is `system`.

**Hard gate (disabled must be inert):** the runtime's first action is to check for
`document.documentElement` carrying `data-color-mode-enabled="true"` (the server
marker, §3.4). If it is absent, the runtime **returns immediately** — it never reads
`localStorage`, never touches `data-theme`, never binds a listener. This is what
makes `color_mode.enabled = false` inert **even when `localStorage['thallo.colorMode']
= dark`**: the stored value is simply never consulted.

When the marker is present:
- Maintain a `matchMedia('(prefers-color-scheme: dark)')` listener that is **active
  only while the stored preference is `system`**, re-stamping `data-theme` on OS change.
- Expose a `setPreference(pref)` operation: write `localStorage['thallo.colorMode']`,
  recompute + set `data-theme`, (de)activate the OS listener, and dispatch
  `thallo:color-mode-change` (detail = new preference).
- Listen for `thallo:color-mode-change` to reflect the current preference onto **every**
  `thallo-block-color_mode` control on the page.

**Toggle controls are optional consumers.** Each `thallo-block-color_mode` block, if
present, wires its option clicks to `setPreference(...)` and reflects the active
preference — but the runtime's correctness (first-paint agreement, OS-change tracking)
does not depend on any control existing.

---

## 4. Data flow

- **First paint:** resolver (3.1) runs in `<head>` → sets `data-theme` → CSS applies
  correct tokens → no flash.
- **Toggle:** click → `blocks.js` writes localStorage, sets `data-theme`, dispatches
  `thallo:color-mode-change` → sibling controls update; the matchMedia listener is
  (de)activated to match the new preference.
- **OS change while `system`:** matchMedia listener flips `data-theme` live.
- **Server:** never reads or emits a preference; every cached page is identical for
  all visitors.

---

## 5. CSP

- **Default (no `CSP_HEADER`):** no CSP header → the inline resolver runs freely.
- **Strict CSP operators:** we **publish the exact `sha256-…`** of the resolver and
  document adding `'sha256-…'` to `script-src`. The hash is constant (byte-stable
  literal) ⇒ the CSP header never varies per response ⇒ page cache is unaffected.
- **No nonce:** nonces must be per-response and would fight full-page HTML caching
  (a cached page bakes one nonce; reusing it defeats the nonce). Hash is the correct
  primitive for a cached, static inline script.
- Only **one** inline script exists (the resolver); all other JS is external
  `blocks.js`. One hash, minimal inline surface. The `data-color-mode-enabled` marker
  is an HTML **attribute**, not a script — no `script-src` impact.

---

## 6. Testing strategy

- **Hash-drift guard (two assertions, per the review):**
  1. `hash('sha256', ColorMode::RESOLVER_JS)` (base64) **=== the documented hash
     constant** — the source-of-truth guard.
  2. the script substring the rendered `layout.twig` emits **=== `ColorMode::RESOLVER_JS`**
     byte-for-byte — the render/escaping guard (catches Twig auto-escaping or stray
     whitespace changing the emitted bytes vs. the hashed constant).
  Together these mean the documented CSP hash matches what actually ships.
- **Config gating:** with `color_mode.enabled = true`, `layout.twig` emits **both**
  the resolver `<script>` and `data-color-mode-enabled="true"` on `<html>`; with
  `false`, it emits **neither**. Toggle block renders the 3-way control when enabled,
  nothing when disabled.
- **Disabled is inert:** with the feature disabled, the rendered `<html>` has no
  `data-color-mode-enabled` marker (and no resolver) — the documented gate the client
  runtime checks, so a preset `localStorage['thallo.colorMode'] = dark` can never
  re-stamp the page. (Also on the manual QA checklist as an end-to-end check.)
- **Mode-agnostic HTML:** a rendered public page carries **no** `data-theme` on
  `<html>` at the server (the resolver sets it client-side), and `RenderPageCache`'s
  key includes no mode signal. The `data-color-mode-enabled` marker is *allowed* —
  it is config-driven (identical for all visitors), not preference-driven, so it does
  not vary the cache.
- **Dark token presence:** assert a `html[data-theme="dark"]` block exists and
  re-maps the core tokens (a lightweight CSS presence check).
- **JS behavior** (resolver + global runtime): no theme-JS test harness exists today,
  so these are covered by the hash/render tests plus a documented manual QA checklist
  (first-paint no-flash; three states; multi-toggle sync; **OS-change-while-system on a
  page with no toggle**; disabled-stays-light even with `thallo.colorMode=dark`;
  private-mode/localStorage-throw fallback).

---

## 7. Rollout

- **Block seed:** add `color_mode` to `StarterBlockTypes` (block count 34 → 35) and
  reconcile migrated DBs via the block-types reseed mechanism (fold into the
  unreleased `021` reseed while it's still untracked, else a dedicated reseed
  migration — decided in the plan).
- **Region palettes:** add `color_mode` to the `header` (and optionally `footer`)
  palette in `RegionDefinitions` — a mode switch most naturally lives in site chrome.
- **Config:** add `config/theme.php` with the `color_mode.enabled` section and the
  `THALLO_COLOR_MODE_ENABLED` env; mirror into `.env.example`.
- **Docs:** THEMING.md gains a "Color mode" section (the `data-theme` contract, the
  dark token re-map, the published CSP hash, the disable config).

---

## 8. Decisions deferred to the implementation plan

- Exact resolver script bytes (and therefore the published hash).
- Where the dark token block lives (`blocks.css` vs a dedicated tokens file).
- How the config flag reaches Twig (`color_mode_enabled()` function vs context global).
- Whether the **preview chrome** (`region-preview.twig`) also runs the resolver in v1
  (nice-to-have; public `layout.twig` is the requirement).
- Final block slug (`color_mode` recommended) and its category.
