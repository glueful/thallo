# Runtime Web Components — Design

**Date:** 2026-08-01. **Status:** approved design, pre-implementation.
**Origin:** `docs/internal/RENDER_DIRECTION.md` §1a — promote ThalloRuntime modules to
custom elements.

## Context

The theme runtime (`packages/thallo-render/runtime/runtime.js`, theme-runtime spec §2)
is a single fingerprinted file: a registry core
(`ThalloRuntime.register(name, {selector, enhance, canvas})` +
`ThalloRuntime.enhance(root)`) and five modules — color-mode, forms, carousel,
navigation, tabs. Behaviors attach to class conventions emitted by the starter block
templates (`.thallo-block-carousel` …), idempotency rides a per-module
`data-thallo-enhanced` marker, DOM-injecting modules skip in canvas mode, and a
throwing module leaves its component unmarked without breaking the pass. Tests execute
the served bytes in Node (≥ 18) with a hand-stubbed DOM; `RuntimeSizeBudgetTest` pins
the gzip size (currently 9,798 bytes against a 12,288-byte ceiling).

Goal (v1): a **stable declarative authoring API for theme authors** —
`<thallo-carousel>`, `<thallo-tabs>`, `<thallo-navigation>`,
`<thallo-color-mode-toggle>` — layered over the existing enhancement functions, so
authors writing their own markup get behaviors without learning the discovery
conventions, and late-inserted markup upgrades itself without calling
`ThalloRuntime.enhance()`.

## Pinned rules

1. **Additive, not a migration.** Starter templates, class selectors, cached markup,
   and canvas contracts are untouched. Class-based discovery remains supported,
   tested, and non-deprecated in v1. No block-migration commitment lives in this
   spec; revisit only after real theme-author adoption.
2. **Elements delegate to the shared behavior modules** — light-DOM adapters, no
   Shadow DOM, no duplicated carousel/tabs/navigation logic, no parallel module
   functions exposed.
3. **One markup grammar.** The element's inner light DOM is the existing block
   skeleton; only the minimal structural classes are public API (carousel
   `__viewport`/`__track`; tabs radios/labels/panels; the navigation drawer
   structure). Styling classes beyond that set stay implementation details. Element
   attributes map to the EXISTING enhancer option vocabulary — no second option
   language.
4. **No-JS usability is real, not aspirational.** The light DOM *is* the fallback
   (scroll-snap carousel, radio tabs, details navigation) and must be styled without
   JS (see §5 CSS aliases). Elements degrade to usable HTML before registration and
   when JS fails to load.
5. **Idempotency across both paths.** A component enhanced by the scan loop and then
   encountered by element lifecycle (or vice versa) is enhanced exactly once — the
   shared marker is the single guard.
6. **Lifecycle ownership.** Enhancement may inject controls/listeners, but disconnect
   must remove them and restore the fallback DOM; reconnect re-enhances cleanly.
7. **Forms stays attribute-driven** (`data-thallo-form` on the native `<form>`); an
   autonomous wrapper element is a weak abstraction and ships nowhere in v1.
8. **Size budget unchanged**: the existing 12,288-byte gzip ceiling stands unless
   implementation actually exceeds it — no pre-emptive bump.

## Design

### 1. Core bridge: one pipeline, two discovery paths

All code stays in the single fingerprinted `runtime.js`. The core's per-component
enhancement step (marker check → canvas policy → try/catch → mark) is extracted into
a **private** pipeline function used by the scan loop, and exposed to elements only
through one new public method that closes over it (P2 pin — separate module IIFEs
cannot reach core privates):

```
ThalloRuntime.registerElement(tag, moduleName, {
  resolveTarget(el),   // optional: element → the component the module actually
                       // enhances (default: the element itself)
  projectOptions(el, target),  // optional: gated attribute/class projection;
                               // MUST return an undo function
})
```

`registerElement` guards on `typeof customElements === 'undefined'` — on old
browsers and in the Node harness the elements are simply absent and the class path
is untouched. Element names are fixed v1 API: `thallo-carousel`, `thallo-tabs`,
`thallo-navigation`, `thallo-color-mode-toggle`.

**connectedCallback** defers one microtask (P2 pin) so synchronously-constructed
children are complete, then: (1) canvas gate FIRST — if the module is `canvas:
'skip'` and the canvas stage is present, do nothing at all (P1 pin: no DOM mutation
of any kind, including class stamping or attribute projection, on canvas); (2)
`resolveTarget` — e.g. navigation resolves its inner drawer `<details>` (the
component the module's enhancer actually receives; marker and cleanup belong to the
TARGET, not the host); (3) `projectOptions` — attribute sugar written onto the
target in the existing vocabulary (e.g. `arrows` → `data-arrows="1"`) plus root-class
stamping, all captured in an undo function; (4) run the shared pipeline for that one
module on the target. Documented constraint: asynchronously-populated elements must
be fully built before insertion — the microtask deferral covers parser timing, not
arbitrary later population.

**disconnectedCallback** runs that (element, module)'s cleanup — module teardown,
then the projection undo — and removes ONLY that module's token from
`data-thallo-enhanced`, preserving other modules' markers (P1 pin).

**Cleanup storage** is keyed by (component, module): `WeakMap<Element,
Map<moduleName, cleanup>>` — a single per-element slot could overwrite another
module's cleanup (P1 pin). The scan path stores cleanups identically (it never calls
them in v1 — harmless, and block markup gains teardown capability for free).

### 2. Enhancer contract change: return values

`enhance(component)` return values become meaningful:

- **`false`** — structural no-op (missing/malformed required structure): the
  component is NOT marked and nothing is stored; the fallback DOM is untouched (P2
  pin — today's silent-return paths in carousel/tabs are updated to return `false`).
- **a function** — success with teardown; stored per (component, module).
- **anything else** — success without teardown (color-mode's registry no-op keeps
  working unchanged).
- **throw** — unchanged containment: rollback is the module's job (tabs), the
  component stays unmarked, the pass continues.

### 3. Complete per-module teardown (P1 pin — the hard part)

- **Carousel** returns a cleanup that removes ALL of: injected arrow/dot/status/pause
  nodes; the throttled scroll handler on the viewport; the four interaction handlers
  (`pointerdown`/`keydown`/`wheel`/`touchstart`); the IntersectionObserver
  (disconnect); the document `visibilitychange` handler (captured, no longer
  anonymous); any live interval timer.
- **Navigation** returns a cleanup that removes every per-details `toggle`/`keydown`
  listener, summary `keydown`, hover-intent `mouseenter`/`mouseleave` (parent nodes),
  the document click listener, the media-query `change` listener; clears any pending
  hover close timeout; removes the `--js` root class; and **restores the initial
  `open` state of every `<details>` it manages** (snapshotted at enhance time).
- **Tabs** — the existing undo log covers enhancement-time mutations but NOT
  interaction-time ones (`select()` mutates checked/hidden/ARIA without logging).
  Enhancement therefore also **snapshots the baseline**: each radio's `checked`,
  each panel's `hidden`, and every mutable ARIA attribute it manages
  (`aria-selected`, `tabindex`, `aria-checked` equivalents). Cleanup = replay the
  undo log in reverse, then restore the baseline snapshot — the radio floor comes
  back exactly as served.
- **Color-mode** — none needed: page-level delegated service, no injected DOM.

### 4. The four elements

| Element | Sugar → existing vocabulary | Target resolution | Malformed → |
|---|---|---|---|
| `<thallo-carousel>` | `arrows`, `dots`, `autoplay` → `data-*="1"` | self | enhancer returns `false`; fallback untouched |
| `<thallo-tabs>` | none (v1) | self | enhancer throws → rollback → unmarked |
| `<thallo-navigation>` | `reveal-hover` → the `--reveal-hover` root class | inner drawer `<details>` (`[data-thallo-enhance="navigation"]`); missing target → core-level structural no-op, nothing marked | no-op |
| `<thallo-color-mode-toggle>` | none | — (see below) | inert |

`<thallo-color-mode-toggle>` is an **explicit exception to the pipeline** (P2 pin):
the color-mode registry entry is a deliberate no-op on `<html>` and the real behavior
is the page-level delegated service. The element does NOT enter the component
pipeline; its `connectedCallback` calls `window.thalloColorMode.reflect()` (when the
service exists) so late-inserted toggles show correct `aria-checked`, and clicks ride
the existing document-level delegation. Its light DOM is server-rendered fallback
controls (`[data-color-mode-set]` buttons) that the element only upgrades.

Root-class stamping (e.g. `.thallo-block-carousel` onto the element so starter CSS
styles injected controls) happens inside `projectOptions` — canvas-gated and undone
by cleanup (P1 pin).

### 5. CSS aliases — the no-JS floor must be styled without JS (P1 pin)

Autonomous custom elements default to `display: inline`, and JS-only class stamping
would leave the pre-JS floor unstyled. The default theme's `assets/blocks.css` gains
tag aliases at zero extra specificity for every rule that styles a block root the
elements can adopt:

```css
:where(.thallo-block-carousel, thallo-carousel) { … }
```

plus explicit `display: block` for the four element tags, and a feature-off rule for
the toggle:

```css
html:not([data-color-mode-enabled="true"]) thallo-color-mode-toggle { display: none; }
```

Custom themes that copied `blocks.css` keep their frozen copy (existing
copied-assets posture); the copyable examples note that element support in a copied
theme means re-copying (or porting) the alias rules.

### 6. Testing & budget

Same Node + hand-stubbed-DOM pattern; the harness gains a minimal `customElements`
stub (capture `define`d classes; drive `connectedCallback`/`disconnectedCallback`
manually). Coverage per element and for the core bridge:

- upgrade enhances; attribute sugar writes the existing vocabulary; navigation
  resolves the inner drawer target (marker/cleanup on the target, not the host).
- double-path idempotency both orders (scan → connect, connect → scan).
- disconnect: injected DOM gone, listeners/timers/observers detached (stub-level
  accounting), projection undone, ONLY that module's marker token removed;
  reconnect re-enhances.
- canvas: `skip` modules through the lifecycle path mutate NOTHING on canvas — not
  even classes/attributes.
- structural no-op returns `false` → unmarked, fallback byte-identical; tabs
  interaction-then-disconnect restores the exact served radio floor (baseline
  snapshot test).
- `customElements` absent → zero throws (existing tests already execute the bytes
  bare and become the guard's regression net).
- `RuntimeSizeBudgetTest`: existing 12,288-byte gzip ceiling unchanged (current
  9,798); raise only if implementation genuinely exceeds it, as its own reviewed
  decision.

Docs: a complete copyable light-DOM example per element in the thallo-render README
(pin 3's "document complete copyable examples").

## Out of scope (deliberate)

- Starter-block template migration (revisit after real adoption).
- Shadow DOM; `<thallo-form>`; MutationObserver auto-scan; framework wrappers.
- Deprecating class-based discovery (stays supported and tested).
- Calling stored cleanups from the scan path (stored but unused in v1).
