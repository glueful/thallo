# Default Theme Runtime & Accessibility Refresh — Design

**Date:** 2026-07-26
**Status:** Draft for review (phases 1–2 of the theme modernization track)
**Packages:** `packages/thallo-render` (primary), Thallo app starter-schema validation,
`packages/thallo-commerce` (coexistence test only)

## §0 Context and problem statement

An external source review of the default theme's interaction layer reached a verdict this
spec adopts: the server-rendered foundation is right and must be preserved; the JavaScript
and accessibility layer on top of it has reached its structural limit. Every claim below was
re-verified against the source before this spec was written.

1. **Behavior forks with themes.** `ThemeLocator` falls back to the default theme for
   missing *templates*, but sets the asset directory wholesale to the custom theme's
   (`ThemeLocator.php` — `$assets = $appTheme . '/assets'`). A custom theme therefore forks
   carousel, navigation, color-mode, and form *behavior* the moment it is created, and stops
   receiving fixes. Behavior must be package-owned and versioned; themes own presentation.
2. **`blocks.js` is a mislabeled monolith.** Its header still reads "carousel controls
   only"; it actually contains four runtimes (carousel, navigation, color-mode, forms) in
   one 313-line file loaded globally from `layout.twig`.
3. **Navigation has no deliberate mobile mode** (the fallback header just wraps links; the
   megamenu panel has `min-width: 28rem`), and its keyboard comment promises
   Enter/Space/ArrowDown while the handler implements only ArrowDown and Escape. It also
   stamps `aria-haspopup="true"` on link toggles — menu-pattern ARIA on what is actually a
   disclosure-navigation pattern.
4. **Tabs declare `role="tablist"` with zero `role="tab"`/`tabpanel` inside it** — an ARIA
   anti-pattern worse than no ARIA (`tabs.twig`). The CSS caps at 8 tabs.
5. **Carousel autoplay has no visible pause control**, no offscreen/hidden-tab pause, and
   no status announcement (`blocks.js`, fixed 5s timer).
6. **The shell lacks accessibility fundamentals**: no skip link, no `<main>` target id, no
   labeled navigation, no `aria-current` on active links, no error-focus management on the
   form block (`layout.twig`, `navigation.twig`).

What the review got wrong (corrected here): storefront rendering has already shipped with a
deliberately dependency-free `shop.js`, so the runtime is a *retrofit consumer* for shop.js,
not a prerequisite — its migration is a named commerce follow-up (§8). And the missing SEO
head must be fixed through thallo-seo's own seam (its "no SEO storage duplication" rule),
which — together with selective asset loading and its page-cache interaction — is deferred
to the follow-up spec (§11).

## §1 Goals and non-goals

**Goals**

- One package-owned, versioned, fingerprinted behavior runtime under `thallo-render`,
  independent of the selected theme's asset directory.
- Accessibility corrections: shell fundamentals, a real mobile navigation mode, honest tab
  semantics, carousel autoplay controls, form error focus.
- Preserve every existing no-JS floor and public JS contract (§2.6).
- Prove the runtime and the existing `shop.js` coexist on one page.

**Non-goals (deferred, see §11)**

- Selective per-page asset loading, responsive images, `content-visibility`, cross-document
  View Transitions, the SEO head partial, archive/listing template improvements.
- Migrating `shop.js` onto the runtime (named commerce follow-up; §8 pins the seam).
- Any visual redesign. The default theme's neutral look does not change.

## §2 The package-owned runtime

### §2.1 Source of truth and layout

A new `packages/thallo-render/runtime/` directory owns all behavioral JavaScript:

```
packages/thallo-render/runtime/
  runtime.js          ← the single served file (no build step; see §2.3)
```

`runtime.js` is one dependency-free, unbundled modern-JavaScript file (the repo has no
bundler and this spec does not introduce one). Its language/platform boundary is named, not
vibes: **syntax and APIs must be Baseline Widely Available** (≥30 months of cross-browser
support at authoring time — covers everything this spec needs: IntersectionObserver,
`matchMedia` change events, CustomEvent, `Element.closest`), **and the file must parse and
run under the repo's Node test harness floor (Node ≥ 18)**, since the tests execute the
served bytes. Platform features newer than that baseline — concretely `<details name="…">`
exclusivity — may appear only in server MARKUP as progressive extras, with the runtime
guaranteeing the equivalent behavior itself (the one-open-sibling rule) so unsupporting
browsers lose nothing. It is internally organized as registered modules on a small core:

```js
window.ThalloRuntime = {
  register(name, { enhance, canvas })  // canvas: 'skip' (default) | 'allow'
  enhance(root)                        // idempotent; runs every registered module
}
```

- **Modules registered:** `navigation`, `tabs`, `carousel`, `forms`, `color-mode` — the
  five concerns extracted from `blocks.js`, corrected per §3–§7.
- **Idempotency:** `root` is a **scan boundary**, never the element marked globally.
  Each module considers both `root` itself (when it matches) and matching descendants,
  marks every successfully enhanced component root with
  `data-thallo-enhanced~="<module>"`, and skips only that component on later scans.
  Therefore `enhance(document)` followed by `enhance(insertedSubtree)` enhances new
  components without re-enhancing old ones. Registration names are unique; registering
  a second implementation under an existing name throws rather than silently replacing
  behavior.
- **Discovery hooks:** modules select on the existing structural classes
  (`.thallo-block-carousel`, `.thallo-block-navigation`, `[data-thallo-form]`,
  `[data-color-mode-set]`) — no template churn for discovery. New behavior introduced by
  this spec (mobile nav, tabs) uses `data-thallo-enhance="<module>"` hooks in the markup,
  the stable naming future modules must follow.
- **Failure containment (pinned):** a module that throws while enhancing a component
  never breaks the pass — the core catches per component, logs via `console.error`,
  leaves that component UNMARKED, and continues with every other component and module.
  A module that stages partial mutations (attributes OR event listeners) must roll them
  back before rethrowing, so a failed enhancement leaves the server-rendered floor
  intact in both markup and behavior (tabs pins the concrete undo-log mechanics in §4).
- **Canvas policy, formalized:** the core checks for `.thallo-preview-block` once. Modules
  with `canvas: 'skip'` (navigation, tabs, carousel, forms) do not run in the canvas stage
  — the existing hard rule: injected DOM would diverge wrapper HTML from fetched HTML and
  break the canvas patch gate. `color-mode` declares `canvas: 'allow'` (it only stamps
  `data-theme` on `<html>`, exactly as today).

### §2.2 What themes keep

Theme asset directories keep **presentation only**: `site.css`, `blocks.css`,
`navigation.css`, `stepper.css` (and any custom-theme CSS). The default theme's existing
`blocks.js` becomes a tiny, behavior-free **compatibility loader** that loads the package
runtime's stable logical URL; the new layout no longer references it. It remains for one
compatibility release so already-cached default-theme HTML cannot request a deleted asset,
then is removed in the named cleanup follow-up. The CSS handoff contracts the runtime relies
on — e.g. `.thallo-block-navigation--js` switching submenu display to `.is-open` — are
kept byte-compatible so existing theme CSS (including copied custom-theme CSS) continues
to work.

### §2.3 Delivery: fingerprinted, cache-safe, theme-independent

Mirrors the shipped `ShopAssetMap`/`ShopAssetController` pattern exactly:

- A `RuntimeAssetMap` (thallo-render) computes a content fingerprint for `runtime.js` at
  construction and exposes `fingerprintedName('runtime.js')` → `runtime-<fp>.js`.
- A route `GET /_thallo/runtime/{file}` (registered with render's other underscore routes,
  ahead of the catch-all) has the same two exact modes as the shop controller:
  `runtime.js` is a non-immutable logical alias that 302s to the **current**
  `runtime-<fp>.js`; only the exact current fingerprint resolves to bytes and receives
  `Cache-Control: public, max-age=31536000, immutable`. Unknown or stale fingerprints
  404. One immutable URL therefore always identifies one byte sequence — the controller
  never serves new bytes under an old hash.
- `layout.twig` replaces `<script defer src="{{ asset('blocks.js') }}">` with
  `<script defer src="{{ runtime_script() }}">` — a new Twig function on
  `RenderContextExtension` returning the stable logical alias. Cached rendered HTML stays
  valid across runtime releases; the uncached alias resolves it to the current immutable
  fingerprint. The preview pipeline needs no special-casing: the URL is
  theme-independent, so the preview's `setAssetBase` override does not apply to it.
- `TemplatePolicy`'s allowed-function list gains `runtime_script` and
  `TemplatePolicy::CACHE_VERSION` is bumped in the same change, as required for every
  allowlist mutation.

### §2.4 Custom-theme migration semantics

Existing custom themes copied the whole assets directory, including `blocks.js`, and may
have copied `layout.twig`. Nothing breaks: a copied layout keeps loading the copied
`blocks.js` from the theme's own assets (old behavior, frozen — exactly what happens
today). A custom theme that does **not** override `layout.twig` gets the new runtime
automatically via the default layout fallback. The theme docs gain one migration note:
delete the copied `blocks.js` and drop the script tag from a copied layout to adopt the
package runtime. The default-theme compatibility loader is not behavior ownership and is
not copied when `ThemeCloner` clones the pack default (cloning an existing custom theme
still copies that custom theme exactly). This is a deliberate, named exception to the
cloner's existing "never a partial copy" pin — the pin's own docblock is amended alongside,
and a clone test pins the exception. (Pre-launch, dev-only installs; no automated
migration.)

### §2.5 Commerce adoption seam

`ThalloRuntime.register()` / `ThalloRuntime.enhance(root)` and the
`data-thallo-enhance` hook naming ARE the adoption contract: when commerce (or any pack)
later contributes behavior, it registers modules on the same core instead of shipping
another disconnected global script. This spec documents the contract; it deliberately adds
no cross-pack registration mechanism yet (YAGNI until the shop.js follow-up needs it).

### §2.6 Preserved public contracts (pinned)

- `window.thalloColorMode` API (`get/set/resolved/reflect`), the
  `thallo:color-mode-change` event, the `thallo.colorMode` localStorage key, and the
  hard gate on `html[data-color-mode-enabled="true"]`. The inline no-flash resolver
  (`color_mode_script()`) is untouched.
- Form PRG floor: no-JS POST to `/_forms/submit` with identical server semantics.
- Carousel structural classes and the `data-arrows/dots/autoplay` opt-ins.
- Navigation's `--js` class handoff and `details`-based click mode with `name` exclusivity.
- The canvas no-op rule for DOM-injecting modules.

## §3 Navigation refresh

### §3.1 Semantics correction (desktop)

- Replace the menu-pattern ARIA with the WAI-ARIA **disclosure navigation** pattern:
  `aria-haspopup` is dropped from link/span toggles; toggles carry `aria-expanded` only
  (the `<summary>` variant already conveys state natively).
- Keyboard, matching the (corrected) documentation: **Enter/Space** activate the toggle —
  native on `<summary>`, which §3.2's unified details tree makes the ONLY toggle element
  (the hover-mode `<a>`/`<span>` toggle variants and their first-tap/first-Enter special
  case disappear with that unification; the parent URL is reachable as the first submenu
  link instead) — **ArrowDown** opens and focuses the first sublink, **Escape** closes and
  returns focus. The stale comment is corrected to match the implementation.
- The megamenu panel's `min-width: 28rem` gains a viewport clamp
  (`min-width: min(28rem, calc(100vw - 2 * var(--space-4)))`) so it can never force
  horizontal scroll on narrow desktop viewports.

### §3.2 Mobile mode: native disclosure stack (pinned decision)

The navigation block gains a mobile mode built on native `<details>`/`<summary>` — chosen
over a drawer/overlay because it fits the theme's progressive-enhancement contract and
works with zero JavaScript. Pinned requirements (user-ratified):

- `<details class="thallo-block-navigation__mobile" data-thallo-enhance="navigation">`
  wraps the one item list at every viewport; its `<summary>` is the hamburger control.
  Every parent item renders as nested `<details>` regardless of the configured desktop
  reveal mode. This unified details tree is what makes every submenu a zero-JS mobile
  accordion without duplicating navigation in the DOM. A parent URL is repeated as the
  first submenu link, preserving the current click-mode reachability rule.
- The expanded menu flows **in normal document flow** — it pushes content, never overlays.
- JS enhances only: smooth height animation, Escape-to-close, outside-click close, and
  state synchronization; it also enforces **only one open sibling submenu** after
  enhancement (the no-JS floor already gets this on browsers supporting `details name=`).
- The menu **closes after successful navigation** and **when the viewport crosses to
  desktop width** (a `matchMedia` listener).
- Visible focus states, ≥44px touch targets on summary/toggle rows, and reduced-motion
  (no height animation under `prefers-reduced-motion`) are required.
- The fallback header nav in `layout.twig` (regionless installs) gets the same
  `<details>` hamburger with plain links — one pattern everywhere.

CSS: the mobile block styles live in `navigation.css` behind a `max-width: 48rem` media
query (the navigation component's named v1 breakpoint; recorded beside every use because
plain CSS custom properties cannot parameterize media queries). Above it, CSS hides the
outer hamburger summary, exposes the list even when the outer details is closed, and
preserves the current desktop **visual behavior**: native summary activation for `click`,
and hover/focus disclosure styling plus runtime hover intent for `hover`. The server renders
one details-based nav tree; CSS decides which chrome (inline row vs. disclosure stack)
applies — no duplicate menus and no user-agent sniffing. Tests cover both sides of 48rem
and a representative long-label menu so the desktop side does not silently wrap.

## §4 Tabs: honest floor, real semantics on enhancement (pinned decision)

- **Server markup drops every ARIA tab role.** The floor is honest labeled radio controls:
  radios in a fieldset-like group (existing `name="tabs-{id}"` scoping), labels, CSS
  checked-sibling panels. Native radios already provide arrow-key switching — no
  duplicated keyboard handling before enhancement.
- **The `tabs` runtime module adds the real thing on enhancement:** `role="tablist"` on
  the list, `role="tab"` + `aria-selected` + `aria-controls` on labels, `role="tabpanel"`
  + `aria-labelledby` + `tabindex="-1"` on panels, roving tabindex, **Left/Right arrows,
  Home/End**, with the **automatic activation** model (selection follows focus — matches
  the radio floor's behavior so the two worlds feel identical).
- After successful enhancement, the underlying radios become `hidden` and leave both the
  accessibility tree and keyboard order; the tab labels are then the only exposed controls.
  The runtime prevents label default activation and updates the hidden radio's `checked`
  state programmatically, including dispatching/observing `change` so focus, selection, and
  the underlying state stay synchronized. A preselected server-side radio initializes the
  enhanced state correctly. The radios are hidden only after the complete ARIA tab
  structure is ready, so a failed partial enhancement leaves the honest radio floor.
- If enhancement never runs, radios + CSS remain fully usable — that IS the no-JS floor.
- **The 8-tab CSS cap becomes a real authoring maximum of 12.** Pure-CSS checked-sibling
  pairing requires enumerated selectors; the CSS enumeration extends to 12. Enforcement is
  NEW, deliberately narrow surface — the schema vocabulary has no item-count constraint
  for `blocks` container fields (`max_items` is reference/asset-only): the admin block
  editor refuses adding a 13th tab item (rearranging a full list stays allowed — only net
  additions are gated), and a tabs-specific save-time check rejects any tabs block
  exceeding 12 items, unconditionally. No grandfathering machinery exists BY DECISION:
  Thallo is pre-launch with no live content, and a scan of every dev draft found zero
  tabs blocks — content over the cap cannot exist before enforcement lands and cannot be
  created after it. (The runtime module itself is unbounded for non-Thallo custom
  markup.)
- **Enhanced mode owns panel visibility.** Once the tabs module marks the root
  (`data-thallo-enhanced~="tabs"`), the runtime drives labels and panels via the `hidden`
  attribute, and the floor's `display` rules (the base `display: none` and the enumerated
  checked-pairing) are marker-scoped to stand down — the two mechanisms never fight. No
  per-instance generated CSS is introduced.
- Canvas: `canvas: 'skip'` — the canvas stage shows the radio floor, as today.

## §5 Carousel corrections

- **Visible pause/play control whenever autoplay is on** (WCAG 2.2.2): an injected
  `thallo-block-carousel__pause` button with an action label ("Pause slides" / "Play
  slides"). `aria-pressed="true"` means the user has paused rotation; the label and state
  change together so their meaning cannot invert.
- **Autoplay pauses when the carousel leaves the viewport** (IntersectionObserver) and
  **when the tab is hidden** (`visibilitychange`). Those are temporary automatic-pause
  reasons and resume only when all clear. Pointer/keyboard/wheel/touch interaction sets
  `userPaused`; automatic recovery never clears it, while an explicit Play action does.
- **Status announcement:** a visually-hidden "Slide N of M" region updates on change but
  uses `aria-live="off"` while automatic rotation is running. It switches to
  `aria-live="polite"` after user pause/interaction and for user-initiated navigation, so
  assistive technology is not interrupted every five seconds.
- Existing semantics kept: `prefers-reduced-motion` disables autoplay entirely; any
  pointer/keyboard/wheel/touch interaction stops automatic autoplay until the visitor
  explicitly presses Play; arrows/dots as opted in; all injection still
  `canvas: 'skip'`. Explicit Play may start rotation after an interaction when reduced
  motion is not requested, but it never overrides a currently hidden tab/offscreen
  carousel or the reduced-motion gate.

## §6 Forms corrections

- On a failed enhanced submit, **focus moves to the result box** (`tabindex="-1"` +
  `focus()`), which becomes `role="status"` with `aria-live="polite"` so both paths
  announce.
- The form gets `aria-busy="true"` for the duration of the fetch (submit already
  disables).
- PRG floor and server semantics unchanged (§2.6).

## §7 Shell accessibility (layout.twig + navigation.twig)

- **Skip link**: first element in `<body>` (`<a class="skip-link" href="#main">Skip to
  content</a>`), visually hidden until focused; `site.css` gains the two rules. It must
  render above the preview banner markup but the banner must not obscure it when focused.
- `<main>` gains `id="main"` (and `tabindex="-1"` so the skip target reliably takes
  focus).
- The fallback header `<nav class="site-nav">` and the navigation block's root `<nav>`
  gain `aria-label`. The navigation starter schema gains an optional `aria_label` string
  (default "Navigation") because the existing `menu()` contract returns items, not menu
  metadata; the fallback uses "Main navigation". The mobile summary renders an icon-only
  hamburger glyph (CSS bars, morphing to an X while open) placed at the far right of the
  header row, with a visually hidden "Menu" label preserving the accessible name — no
  `aria-label` needed. While open, the nav container claims the full header row so the
  list stacks beneath it in flow (`:has()`-scoped; browsers without `:has()` degrade to
  in-row expansion).
- **`aria-current="page"`** on the active link, server-rendered: `RenderContextExtension`
  already exposes `current_path` as a template variable, and the navigation block already
  consumes it for active classes. The navigation block + fallback nav reuse that variable
  and compare each item's resolved URL path against it; no redundant Twig function or
  `TemplatePolicy` surface is added. Path-only comparison (ignore query), exact match only
  — no prefix heuristics in v1.
- Page-cache interaction is nil by construction: `aria-current` is derived from the
  rendered page's own path, which is part of the cache key already.

## §8 shop.js coexistence (pinned decision: defer migration)

- `shop.js` remains independently loaded and dependency-free; this spec does not touch it.
- **No duplicate ownership:** the runtime's modules select only their own hooks (§2.1
  selectors); none of them match `[data-shop-*]` elements or `/_shop/*` forms. The forms
  module's selector `form[data-thallo-form]` specifically cannot capture shop forms.
- A **coexistence test** (mirroring `ShopJsRuntimeTest`'s PHPUnit-driven Node +
  hand-stubbed DOM harness) loads BOTH served files into one stub document containing a
  shop form and a thallo form block, and proves each script enhances only its own form and
  neither throws.
- Migration is tracked as a named commerce follow-up ("shop.js on the theme runtime"),
  gated on behavior-parity tests before the standalone script is removed.

## §9 Testing

- **Runtime modules:** extend the established Node + hand-stubbed-DOM pattern
  (`ColorModeRuntimeTest` / `ShopJsRuntimeTest`): one PHPUnit test class per module
  loading the SERVED bytes, dispatching synthetic events, asserting the contract —
  navigation (open/close/keyboard/one-open-sibling/mobile close-on-desktop),
  tabs (roles added, radios removed from the accessibility surface, arrows, radio sync),
  carousel (user-vs-automatic pause state, visibility pause, live-off during autoplay,
  polite after user action), forms (error focus, aria-busy), color-mode (existing test
  keeps passing against the new file). Core tests prove root-itself discovery,
  document-then-inserted-subtree enhancement, component-level idempotency, and
  duplicate-module rejection. Tests skip-not-fail without node, asserting structural
  markers.
- **Delivery:** `RuntimeAssetMap` unit tests (fingerprint stability, alias resolution) and
  route tests (logical alias → current fingerprint, exact fingerprint → immutable bytes,
  stale/unknown fingerprint → 404), plus a test that the default `blocks.js`
  compatibility loader requests only the logical runtime alias — mirroring the shop asset
  tests without weakening content-addressed identity.
- **Templates:** the existing render pipeline tests extend to assert the new markup —
  skip link + `id="main"`, labeled navs, `aria-current` on the active item, tabs markup
  free of ARIA roles, the unified details navigation tree with no duplicate menu, and both
  sides of the 48rem navigation breakpoint. Starter/editor validation tests prove 12 tabs
  accepts and 13 rejects (and that same-list reordering of a full tabs list still works);
  a CSS-contract test asserts the enhanced-mode scoping (floor `display` rules stand down
  under `data-thallo-enhanced~="tabs"`). A policy-cache test pins the
  `TemplatePolicy::CACHE_VERSION` bump alongside `runtime_script`.
- **Canvas:** a test proving the served runtime no-ops DOM injection when
  `.thallo-preview-block` is present (ports the existing hard-rule proof).
- **Coexistence:** §8's test.
- Full thallo suite + admin gates green; phpcs on touched PHP.

## §10 Migration and compatibility summary

| Surface | Before | After |
|---|---|---|
| Behavior JS | `themes/default/assets/blocks.js`, forked by custom themes | `packages/thallo-render/runtime/runtime.js`, package-owned, fingerprinted at `/_thallo/runtime/` |
| Default theme assets | 4 CSS + behavioral blocks.js | 4 CSS + temporary behavior-free compatibility loader (new layouts do not load it) |
| Custom themes (copied layout) | copied blocks.js, frozen at copy time | unchanged until they adopt (§2.4 note) |
| Custom themes (default layout fallback) | default layout's `asset('blocks.js')` resolved to the theme's copied file — still frozen | package runtime automatically (theme-independent URL) |
| Cached pages | reference theme asset URL | old default-layout HTML reaches the compatibility loader; new HTML stores the stable runtime alias, which redirects to exact immutable bytes |
| Canvas stage | blocks.js no-op rule | same rule, formalized per-module |
| shop.js | independent | unchanged; coexistence proven |

## §11 Out of scope → follow-up specs

1. **Selective asset loading + modern MPA polish** (per-block module manifests, page-cache
   key interaction, responsive images, hero LCP priority, `content-visibility`,
   cross-document View Transitions).
2. **SEO head partial** consuming thallo-seo's resolver seam (descriptions, canonicals,
   hreflang, Open Graph, Twitter) — must not duplicate SEO storage.
3. **shop.js on the theme runtime** (commerce follow-up; §8).
4. ~~Remove the default-theme `blocks.js` compatibility loader~~ — DONE 2026-07-27,
   pre-launch: no released version ever shipped the loader, so the "one compatibility
   release" drain window collapsed to a dev cache purge; the ThemeCloner exception and
   its docblock-pin amendment were reverted with it.
5. Archive/listing template improvements and theme presets with stronger character.
