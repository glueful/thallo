# shop.js on the Theme Runtime — Design

**Date:** 2026-07-27
**Status:** Draft for review (theme-runtime spec §11.3 / §8 follow-up)
**Packages:** `packages/thallo-commerce` (shop.js + its tests), `packages/thallo-render`
(coexistence test update only — no render code changes)

## §0 Context

The theme-runtime spec (§2.5) pinned the adoption contract: when a pack contributes
behavior, it registers modules on `window.ThalloRuntime` instead of shipping another
disconnected global script. shop.js predates the runtime and still self-drives: one
724-line IIFE whose `init()` scans the document for six concerns (PRG form enhancement,
product gallery, mini-cart / product-grid / featured-product / add-to-cart hydration),
guarded by hand-rolled per-element bound-markers, loaded via `<script defer
src="/_shop/assets/shop.js">` emitted by EVERY shop block template and shop page (multiple
identical tags per page are normal — each executes; idempotency currently rests on the
bound-markers alone).

What adoption buys, concretely: the runtime's uniform component-level idempotency, its
per-component **failure containment** (a throwing hydration no longer kills the rest of
the pass — today one bad shell aborts every later concern in `init()`), the formalized
canvas policy, and the `ThalloRuntime.enhance(subtree)` seam — which shop.js already
wants (its own comment: "callers that need to re-run hydration after injecting new
blocks"). What must NOT change: commerce ownership and versioning of the file, its
delivery (`/_shop/assets`, fingerprint alias — untouched), the PRG hard rule, the
`window.thalloShop` public API, and behavior on pages WITHOUT the runtime.

## §1 Goals and non-goals

**Goals**

- shop.js registers its concerns as ThalloRuntime modules and lets the runtime core
  drive enhancement whenever the runtime is present.
- Byte-level behavior parity on both paths (runtime present / absent), proven by the
  existing Node-harness contract tests running against BOTH configurations.
- Exactly-once execution per page regardless of how many shop script tags render.

**Non-goals**

- Merging shop.js into `runtime.js` (commerce behavior stays commerce-owned and
  commerce-versioned), changing its delivery, touching any Twig template, or renaming
  its structural `[data-shop-*]` selectors (grandfathered, like the theme modules'
  structural classes — `data-thallo-enhance` naming binds NEW hooks only).
- Removing the no-runtime path entirely (see §2.3 — it survives as a deliberate
  fallback, not a parallel implementation).

## §2 Design

### §2.1 Execution-once guard

The IIFE gains a first-line re-entry guard: `if (window.thalloShop) { return; }`.
Multiple `<script>` tags for the same file currently re-execute the whole IIFE and
re-scan; after adoption a re-execution would also re-`register()` — which the runtime
core deliberately answers with a THROW. The guard makes execution exactly-once and is a
strict improvement on both paths.

### §2.2 Module registration (runtime present)

At the point where the IIFE currently wires `init()`, shop.js instead checks
`window.ThalloRuntime`. When present, it registers one module per concern — the enhance
closures are the existing functions, reshaped only where the table notes it (per-shell
signatures; the coalesced cart fetch):

| Module name | Selector | Enhance |
|---|---|---|
| `shop-form` | the existing `FORM_SELECTOR` list | `bindForm` |
| `shop-gallery` | `[data-shop-gallery]` | `bindGallery` |
| `shop-mini-cart` | the mini-cart shell selector | per-shell paint over a COALESCED cart fetch |
| `shop-product-grid` | the grid shell selector | per-shell hydrate (config is per-shell data-*) |
| `shop-featured-product` | the featured shell selector | idem |
| `shop-add-to-cart` | the add-to-cart shell selector | idem |

(The four hydrate concerns currently run as page-wide sweeps —
`hydrateMiniCarts()` etc.; each is refactored to a per-component
`hydrateX(el)` the sweep AND the module share, so both paths execute identical code.)

**Cart-fetch coalescing (pinned):** today `hydrateMiniCarts()` issues ONE
`GET /_shop/cart` and paints every shell; naive per-shell registration would issue one
request PER shell. The mini-cart concern therefore shares a module-scope in-flight
promise: the first shell's enhance starts the fetch, every concurrently-enhancing shell
awaits the SAME promise, and each shell paints itself from the shared result (the
promise slot clears on settle, so a LATER `enhance()` of a freshly inserted shell
fetches fresh cart state — matching today's re-`init()` semantics). The grid /
featured / add-to-cart concerns stay per-shell fetches on purpose: their queries derive
from each shell's own `data-*` config. §3 pins the two-shells-one-request test.

All six are `canvas: 'skip'` (the default) — today the canvas never executes fetched
scripts, so this is formalization, not behavior change. The runtime core's marker
(`data-thallo-enhanced~="shop-…"`) becomes the outer idempotency layer; the existing
internal bound-markers (`bindForm`'s guard, `data-shop-gallery-bound`) are RETAINED as
the shared inner layer — they are what keeps the two paths and
`window.thalloShop.bindForm` mutually idempotent.

When the runtime is present, shop.js does NOT attach its own `DOMContentLoaded`
listener or run a direct sweep. Script order guarantees `ThalloRuntime` exists at
shop.js eval time (the runtime tag is a head defer, shop tags are body defers, and
defers execute in document order) — but order does NOT mean the core's boot pass covers
the shop modules (amended 2026-07-27, review finding): defer scripts execute as
SEPARATE script tasks, a microtask checkpoint runs between tasks, and the core boots
via `Promise.resolve().then(boot)` when `readyState` is already past `'loading'` — so
on a real page the boot pass has ALREADY run before shop.js's task registers its
modules. shop.js therefore schedules ONE catch-up pass immediately after registering:
when `document.readyState === 'loading'` it schedules nothing (the core's boot is still
waiting on DOMContentLoaded and will cover the registrations); otherwise it queues
`Promise.resolve().then(init)` — `init()` delegates to
`ThalloRuntime.enhance(document.documentElement)`, and the core's
`data-thallo-enhanced` markers gate that pass per component, so it is a no-op wherever
the boot pass did already cover a component (e.g. same-task evaluation in a test
harness).

### §2.3 Fallback (runtime absent)

A page can legitimately lack the runtime: a custom theme with a copied pre-runtime
`layout.twig` still renders shop blocks. There, `window.ThalloRuntime` is undefined and
shop.js self-drives exactly as today (`init()` on DOMContentLoaded/immediately). This is
a FALLBACK, not a second implementation — both paths call the same per-component
functions; the only difference is who scans. Pinned: the fallback is retained until the
copied-layout compatibility posture itself is retired (a distribution-time decision, not
this spec's).

This deliberately REINTERPRETS the parent spec's §8 phrasing ("before the standalone
script is removed"): the *file* is never removed — it is commerce-owned and separately
delivered by design — what "standalone" meant and what this spec retires (on runtime
pages) is the self-driving scan. The parity gate the parent spec demanded applies to
that retirement and is §3's whole content.

### §2.4 Public API (pinned surface, runtime-aware semantics)

`window.thalloShop = { init, bindForm }` survives as the API surface, but `init()` is
runtime-aware: **when `ThalloRuntime` is present it delegates to
`ThalloRuntime.enhance(document.documentElement)`** — the direct sweep would bypass the
core's component markers, failure containment, and canvas policy, and would re-issue
hydration fetches for components the core already enhanced. The direct sweep survives
ONLY inside the §2.3 fallback path. Semantic consequence, accepted: on runtime pages
`init()` enhances NEW components only (exactly the builder-preview insertion use case)
and no longer re-fetches already-hydrated ones; callers wanting a fresh cart paint go
through the cart-mutation flows that already update regions. `bindForm` keeps its
direct, inner-marker-guarded behavior on both paths (the Node harness and hydration
completions rely on it).

## §3 Testing (the §8 parity gate)

- **ShopJsRuntimeTest** (the byte-contract harness) runs its FULL existing assertion set
  twice: (a) standalone — no ThalloRuntime stub, proving the fallback path is
  byte-parity with today; (b) runtime-present — the stub document pre-loads the real
  served `runtime.js`, then `shop.js`, and the SAME behavioral assertions pass with the
  core driving (plus: six `shop-*` modules registered, no duplicate-name throw on a
  simulated second script execution — the §2.1 guard returns early).
- **RuntimeShopCoexistenceTest** evolves: it now additionally asserts the shop modules
  appear in the shared registry, and keeps its no-cross-ownership assertions (theme
  modules never touch `[data-shop-*]` elements and vice versa).
- **Failure containment — same-module siblings (pinned):** TWO product-grid shells
  where the FIRST throws synchronously during enhance: the SECOND still enhances, later
  shop AND theme modules still run, and the failed shell stays unmarked
  (`data-thallo-enhanced` absent for `shop-product-grid`). This is the case that
  catches an accidentally retained internal sweep — a sweep disguised as a module would
  let shell 1's throw kill shell 2 under one catch. (The throw must be synchronous:
  async fetch failures are already swallowed by the hydrates' own `.catch` and never
  exercise the core's boundary.)
- **Mini-cart coalescing:** two mini-cart shells on one page → exactly ONE
  `GET /_shop/cart` (recorded fetch stub), BOTH shells painted; a later
  `ThalloRuntime.enhance(insertedShell)` after settle issues a fresh fetch.
- **Defer delivery as separate script tasks (pinned):** runtime.js and shop.js
  evaluated in SEPARATE node tasks (a real task boundary between the evals, matching
  browser defer semantics where the core's microtask boot fires before shop.js runs):
  the §2.2 catch-up pass must still bind the cart form and paint TWO mini-cart shells
  with exactly ONE `GET /_shop/cart` — proving both the late-registration recovery and
  the coalescing under core-driven per-shell invocation. The single-task harness alone
  is insufficient: it masks the pre-registration boot.
- Full suite + phpcs gates.

## §4 Out of scope → later

- Retiring the §2.3 fallback (distribution-time, with the copied-layout posture).
- Any storefront feature work; this is a pure structural adoption.
