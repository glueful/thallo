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
enhancement step (canvas policy → marker check → try/catch → mark) is extracted into
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

**`projectOptions` atomicity contract (fix-round amendment):** the bridge treats a
THROWN `projectOptions` as nothing-captured (`abandonElementRecord` has no undo to
run), so a projector that mutates and then throws would leak its partial mutations
permanently. Projectors must therefore be atomic: either mutate nothing before
returning the undo, or internally track each mutation and self-rollback-then-rethrow
on failure. The shipped `project()` helper (Task 6) implements the latter; custom
adapters must follow the same rule.

`registerElement` guards on `typeof customElements === 'undefined'` — on old
browsers and in the Node harness the elements are simply absent and the class path
is untouched. It defines exactly the three module-backed v1 elements:
`thallo-carousel`, `thallo-tabs`, and `thallo-navigation`. The fourth fixed name,
`thallo-color-mode-toggle`, is defined by the separate guarded page-service adapter
in §4; it never enters `registerElement` or pretends the registry's `<html>` no-op is
a component enhancer.

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

**Projection is transactional (P1 pin).** `registerElement` must distinguish a
successful/already-enhanced result from canvas-skip, missing target, structural
`false`, and contained throw. On every unsuccessful outcome it immediately runs the
projection undo (if projection ran), stores no lifecycle record, and leaves the
fallback DOM byte-equivalent to its pre-connection state. On success it composes the
module cleanup followed by projection undo and stores that composed cleanup. An
already-enhanced target keeps the projection and adopts the existing per-module
cleanup without invoking the enhancer again; projection undo still belongs to the
host lifecycle. A disconnect before the deferred microtask runs cancels the pending
work and performs any projection undo already created.

The private pipeline therefore returns an internal outcome rather than a bare value:
`enhanced | already-enhanced | canvas-skipped | structural-noop | failed`, plus the
stored cleanup when one exists. The scan loop ignores that outcome after containment;
`registerElement` consumes it to commit or roll back projection. This result is not a
public theme-author API.

**Boot ordering is explicit (P1 pin).** The current boot scheduling moves out of the
core IIFE into one end-of-file footer placed after every module registration and both
element-registration sections. The footer preserves today's behavior (`DOMContentLoaded`
when loading, one microtask otherwise) but queues it only after `customElements.define()`
has upgraded existing hosts and queued their connection microtasks. Therefore option
projection/element enhancement wins before the legacy whole-document scan in both
parser and late-loaded-runtime paths; the scan then observes the shared marker and is
a no-op. No public `start()` API is added.

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
  component is NOT marked and nothing is stored; the element adapter also rolls back
  any option/class projection, so the fallback DOM is untouched (P2 pin — today's
  silent-return paths in carousel/tabs are updated to return `false`).
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
pipeline or `registerElement`; a dedicated custom-elements-guarded adapter defines
it and its `connectedCallback` calls `window.thalloColorMode.reflect()` (when the
service exists) so late-inserted toggles show correct `aria-checked`. Clicks ride the
existing document-level delegation. Its light DOM is server-rendered fallback
controls (`[data-color-mode-set]` buttons) that the element only upgrades.

Root-class stamping (e.g. `.thallo-block-carousel` onto the element so starter CSS
styles injected controls) happens inside `projectOptions` — canvas-gated and undone
by cleanup (P1 pin).

### 5. CSS aliases — the no-JS floor must be styled without JS (P1 pin)

Autonomous custom elements default to `display: inline`, and JS-only class stamping
would leave the pre-JS floor unstyled. Aliases live with the stylesheet that already
owns each component: carousel/tabs/color-mode rules in `assets/blocks.css`, and
navigation rules in `assets/navigation.css`. Each gains tag aliases at zero extra
specificity for every rule that styles a block root the elements can adopt:

```css
:where(.thallo-block-carousel, thallo-carousel) { … }
```

plus explicit `display: block` for `thallo-carousel`, `thallo-tabs`, and
`thallo-navigation`. `thallo-color-mode-toggle` uses an inline-compatible display
(`inline-block`, with its existing inner segmented-control layout unchanged), not the
page-section block default. Note the toggle block's existing root class is
`thallo-block-color_mode` — underscore, from the block slug — so its alias is
`:where(.thallo-block-color_mode, thallo-color-mode-toggle)`, not a hyphenated guess. `blocks.css` also owns the feature-off rule:

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
- boot ordering in both `readyState === 'loading'` and late-load paths: element
  connection/projection precedes the one document scan; no option is locked in from
  pre-projection state.
- disconnect: injected DOM gone, listeners/timers/observers detached (stub-level
  accounting), projection undone, ONLY that module's marker token removed;
  reconnect re-enhances.
- canvas: `skip` modules through the lifecycle path mutate NOTHING on canvas — not
  even classes/attributes.
- projection transaction: canvas-skip, missing target, structural `false`, contained
  throw, and disconnect-before-microtask all run projection undo, leave no lifecycle
  record and preserve fallback bytes; successful/already-enhanced paths retain the
  projection until disconnect. Tabs interaction-then-disconnect restores the exact
  served radio floor (baseline snapshot test).
- CSS ownership: carousel/tabs/toggle aliases are asserted in `blocks.css`, navigation
  aliases in `navigation.css`; the three structural elements compute to block while
  the toggle remains inline-compatible, and the feature-off selector hides it.
- registration split: `registerElement` defines exactly the three module-backed
  names; the guarded color-mode adapter defines its fourth name once and calls only
  `thalloColorMode.reflect()`.
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
