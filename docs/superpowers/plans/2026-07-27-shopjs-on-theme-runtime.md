# shop.js on the Theme Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps
> use checkbox (`- [ ]`) syntax for tracking.

**Goal:** shop.js registers its six concerns as ThalloRuntime modules and lets the core
drive enhancement on runtime pages, with byte-parity fallback when the runtime is absent,
per `docs/superpowers/specs/2026-07-27-shopjs-on-theme-runtime-design.md` (approved).

**Architecture:** Pure restructure of `packages/thallo-commerce/assets/shop.js` (no
template, delivery, or PHP-behavior changes): an exactly-once IIFE guard, a coalesced
cart fetch, six `ThalloRuntime.register()` calls whose enhance closures are the existing
per-component functions, and a runtime-aware `init()`/boot switch. Tests are the existing
PHPUnit-driven Node harnesses, extended to run the byte contract in BOTH configurations.

**Tech Stack:** Plain JS (Baseline Widely Available, parses on Node ≥ 18 — the
theme-runtime floor applies), PHPUnit + hand-stubbed-DOM Node harnesses. No new deps.

## Global Constraints

- shop.js stays commerce-owned, separately delivered (`/_shop/assets`, untouched), one
  file, no build step. NO Twig template changes, NO selector renames.
- PRG hard rule unchanged: never a second automatic POST, never native fallback after
  fetch() was called.
- Exactly-once execution: first line of the IIFE body (after 'use strict' + document
  guard) is `if (window.thalloShop) { return; }`.
- Six modules, exact names/selectors: `shop-form` (the FORM_SELECTOR list),
  `shop-gallery` (`[data-shop-gallery]`), `shop-mini-cart` (`[data-shop-mini-cart]`),
  `shop-product-grid` (`[data-shop-block="product-grid"]`), `shop-featured-product`
  (`[data-shop-block="featured-product"]`), `shop-add-to-cart`
  (`[data-shop-block="add-to-cart"]`). All canvas-skip (the default).
- Cart coalescing (spec §2.2): a module-scope in-flight promise; ONE
  `GET /_shop/cart` and ONE `updateCartRegions()` paint regardless of shell count; the
  slot clears on settle so a later enhance of an inserted shell fetches fresh.
  (Mechanism note, deviating from the spec's letter with the same pinned outcome: cart
  regions are DOCUMENT-WIDE by existing design — header count badges live outside the
  shells — so the shared promise performs the single document-wide paint; shells are
  triggers, not paint targets.)
- Runtime present ⇒ registration + core boot drives; shop.js attaches NO own
  DOMContentLoaded/immediate init. Runtime absent ⇒ today's self-driving `init()`
  exactly. `window.thalloShop = { init, bindForm }` survives; `init()` delegates to
  `ThalloRuntime.enhance(document.documentElement)` when the runtime exists.
- Inner idempotency markers (`data-shop-bound`, `data-shop-gallery-bound`) are RETAINED.
- Tests: `set -o pipefail && vendor/bin/phpunit <paths> 2>&1 | tail -5` (never grep);
  `node --check` before phpunit; phpcs PSR12 on touched PHP; commit per task on `dev`;
  never push; no AI attribution; nothing under docs/ or CLAUDE.md staged.

---

### Task 1: Per-component restructure + cart coalescer + exactly-once guard

**Files:**
- Modify: `packages/thallo-commerce/assets/shop.js` (IIFE head ~line 20; the mini-cart
  section ~line 373; NOTHING else moves)
- Test: `tests/Integration/Commerce/ShopJsRuntimeTest.php` (extend)

**Interfaces:**
- Produces: `hydrateMiniCart(el)` (per-shell trigger over the coalesced fetch) and the
  module-scope `cartFetchInFlight` promise slot — Task 2's `shop-mini-cart` module
  consumes `hydrateMiniCart`; `hydrateMiniCarts()` (the sweep) now loops shells over it.

- [ ] **Step 1: Write the failing coalescing test.** In `ShopJsRuntimeTest` (read its
  `harness()` builder first — reuse its fetch-recorder and element stubs):

```php
public function testTwoMiniCartShellsCoalesceToOneCartFetchAndBothRegionsPaint(): void
{
    // Harness DOM: TWO [data-shop-mini-cart] shells, each containing a
    // [data-shop-cart-count] span, plus one header count OUTSIDE any shell.
    // Recorded fetch stub resolves GET /_shop/cart with {item_count: 3, items: []}.
    // After init() + microtask flush:
    //   - exactly ONE fetch to /_shop/cart was recorded,
    //   - all THREE count regions read '3'.
    // Then simulate a later inserted shell: call window.thalloShop.bindForm-free path —
    // in this standalone task, call the exposed hydrateMiniCart test seam via a second
    // init() AFTER clearing the recorder: the slot has settled, so a SECOND fetch is
    // recorded (fresh state for re-init, matching today's semantics).
}

public function testSecondEvaluationOfShopJsIsBehaviorallyInert(): void
{
    // BEHAVIORAL guard proof (standalone config), not a comment-marker check:
    //   1. eval shop.js; tag the export: window.thalloShop.__tag = 'first';
    //   2. eval shop.js AGAIN;
    //   3. window.thalloShop.__tag is STILL 'first' (same object — the second IIFE
    //      returned at the guard),
    //   4. the stub document's DOMContentLoaded listener count is exactly what it was
    //      after the first eval (readyState 'loading' variant: 1, not 2),
    //   5. with a mini-cart shell present and readyState 'complete' variant: init ran
    //      once → exactly ONE cart fetch recorded across both evals.
    // The /* shop-runtime:start */ structural marker is asserted too, but only as a
    // supplementary check — the red/green authority is 3–5.
}
```

(Concrete assertions, `FAIL:`-labeled in the Node harness like the file's existing
test; expose nothing new on `window` — the `__tag` property is written by the HARNESS,
not by shop.js.)

- [ ] **Step 2: Run to verify both fail for behavioral reasons.** Today the second eval
  re-runs the whole IIFE, so the guard test goes red on assertion 3 (new export object)
  and 4 (a second DOMContentLoaded listener). The coalescing test's red comes from its
  settled-slot re-init shape (today's `hydrateMiniCarts()` has no slot — the assertion
  that a second init BEFORE settle would NOT refetch has no mechanism to hold; write
  the in-flight portion of the test so it fails against today's always-fetch code).
  Confirm both are red before implementing:

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Commerce/ShopJsRuntimeTest.php 2>&1 | tail -5`

- [ ] **Step 3: Implement.** Three edits to shop.js:

(a) Exactly-once guard — directly after the existing `typeof document` guard:

```js
  if (window.thalloShop) {
    return; // every shop block template emits its own <script> tag; run once
  }
```

(b) Mini-cart: replace `hydrateMiniCarts()` (~line 373) with:

```js
  // ---- block hydration: mini-cart ------------------------------------------------
  // Cart regions are DOCUMENT-WIDE (header count badges live outside the shells), so
  // the fetch AND the paint are shared: the first shell to enhance starts them, every
  // concurrent shell awaits the same in-flight promise, and the slot clears on settle
  // so a later enhance of a freshly inserted shell fetches fresh state
  // (shopjs-on-runtime spec §2.2).
  var cartFetchInFlight = null;

  function hydrateMiniCart() {
    if (typeof window.fetch !== 'function') {
      return;
    }
    if (cartFetchInFlight) {
      return;
    }
    cartFetchInFlight = window
      .fetch('/_shop/cart', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        updateCartRegions(data);
      })
      .catch(function () {
        // Hydration is enhancement only — a failed cart read leaves the shell's
        // server-rendered content (same posture as every other hydrate).
      })
      .then(function () {
        cartFetchInFlight = null;
      });
  }

  function hydrateMiniCarts() {
    if (qsa(document, '[data-shop-mini-cart]').length === 0) {
      return;
    }
    hydrateMiniCart();
  }
```

(Keep the original's `.catch` comment if it says something different — merge, don't
lose its wording.) Add the structural marker comment `/* shop-runtime:start */` above
the guard added in (a) — Task 2 closes with `/* shop-runtime:end */` around the
registration block; the tests assert the markers.

- [ ] **Step 4: Run + verify parity.** The file's EXISTING contract test must stay green
  untouched (it exercises forms/status/retry — none of this task's surface).

Run: `node --check packages/thallo-commerce/assets/shop.js && set -o pipefail && vendor/bin/phpunit tests/Integration/Commerce/ShopJsRuntimeTest.php tests/Integration/Commerce/ShopBlocksTest.php 2>&1 | tail -5`

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-commerce/assets/shop.js tests/Integration/Commerce/ShopJsRuntimeTest.php
git commit -m "refactor(commerce): shop.js exactly-once guard + coalesced cart fetch"
```

---

### Task 2: Runtime registration + runtime-aware init + fallback

**Files:**
- Modify: `packages/thallo-commerce/assets/shop.js` (the init/boot tail ~line 697 and
  the new registration block)
- Test: `tests/Integration/Commerce/ShopJsRuntimeTest.php` (dual-configuration harness +
  five new tests)

**Interfaces:**
- Consumes: Task 1's `hydrateMiniCart`, the existing `bindForm`/`bindGallery`/
  `hydrateProductGrid(el)`/`hydrateFeaturedProduct(el)`/`hydrateAddToCart(el)`
  per-component functions, and the runtime core contract
  (`window.ThalloRuntime.register(name, {enhance, selector})`, marker
  `data-thallo-enhanced`, per-component throw containment).
- Produces: the six registered `shop-*` modules; runtime-aware
  `window.thalloShop.init`.

- [ ] **Step 1: Extend the harness for dual configuration.** Refactor
  `ShopJsRuntimeTest::harness(string $shopJsSrc)` to
  `harness(string $shopJsSrc, ?string $runtimeSrc = null)`: when `$runtimeSrc` is
  given, eval it BEFORE shop.js and flush microtasks before assertions (mirror
  `FormsRuntimeTest`'s handling of the core's `Promise.resolve().then(boot)` deferred
  boot — read that file first). Add a `runtimeJs()` reader:
  `packages/thallo-render/runtime/runtime.js`.

  **The parity gate (spec §3) is the FULL existing assertion set in BOTH
  configurations**: extract the existing contract test's body into a private
  `runByteContract(?string $runtimeSrc): void` and give it two thin test methods —
  `testFormInterception…Standalone()` (null) and `…WithRuntimePresent()` (runtimeJs())
  — so every original assertion (interception, JSON POST, count/line-total/quote
  updates, focus + aria-live, double-submit suppression, ambiguous-rejection no-retry,
  explicit retry with the same idempotency key) runs against BOTH drivers verbatim.

- [ ] **Step 2: Write the five failing tests** (each a Node-harness method in the
  file's existing style; the DOM stubs follow the file's element builder):

```php
public function testRuntimePresentRegistersSixModulesAndCoreDrivesEnhancement(): void
{
    // harness(shopJs, runtimeJs): after microtask flush —
    //   - window.ThalloRuntime lists modules shop-form, shop-gallery, shop-mini-cart,
    //     shop-product-grid, shop-featured-product, shop-add-to-cart (assert via a
    //     probe: registering each name again THROWS duplicate),
    //   - (the full byte contract itself is covered by Step 1's dual-run refactor —
    //     this test focuses on the REGISTRATION surface),
    //   - shop.js attached NO DOMContentLoaded listener of its own: snapshot the stub
    //     document's recorded DOMContentLoaded listener count AFTER the runtime eval,
    //     assert the shop.js eval leaves it UNCHANGED (the core may register its own
    //     when readyState is 'loading' — an absolute zero would be wrong).
}

public function testSecondScriptExecutionIsANoOp(): void
{
    // harness evals shop.js TWICE (runtime present): no duplicate-name throw escapes,
    //   window.thalloShop is the FIRST eval's object (reference equality via a tag
    //   property the harness sets after the first eval).
}

public function testInitDelegatesToRuntimeEnhanceOnRuntimePages(): void
{
    // Runtime present. After boot, insert a NEW [data-shop-mini-cart] shell into the
    // stub DOM, clear the fetch recorder, call window.thalloShop.init():
    //   - the new shell gets data-thallo-enhanced~="shop-mini-cart",
    //   - exactly ONE new cart fetch (fresh, slot settled),
    //   - the PREVIOUSLY enhanced form is NOT re-bound (its listener count unchanged —
    //     init() went through the core's markers, not a direct sweep).
}

public function testSameModuleSiblingContainment(): void
{
    // Runtime present. TWO [data-shop-block="product-grid"] shells; the FIRST's
    // getAttribute throws ONLY when asked for 'data-source' (the first attribute
    // hydrateProductGrid reads) and behaves normally for every other name — the
    // core's markerHas() reads 'data-thallo-enhanced' OUTSIDE its try, so a blanket
    // getAttribute override would abort the whole pass uncaught instead of exercising
    // the per-component catch. After boot:
    //   - the SECOND grid hydrates (its fetch fired / its data-thallo-enhanced set),
    //   - a module registered AFTER shop-product-grid still enhances — assert a
    //     [data-shop-block="featured-product"] shell hydrates (shop-gallery registers
    //     BEFORE the grid and proves nothing about later-module continuation),
    //   - the failed shell has NO data-thallo-enhanced for shop-product-grid,
    //   - console.error was called once (the core's containment log; stub console).
}

public function testCanvasStageRunsNoShopBehavior(): void
{
    // The runtime-aware init() is specifically what RESTORES the canvas guarantee —
    // prove it end-to-end. Runtime present; the stub DOM contains a
    // .thallo-preview-block element plus a shop cart form, a mini-cart shell, and a
    // grid shell. After boot AND after an explicit window.thalloShop.init():
    //   - ZERO shop fetches recorded (no cart read, no grid hydration),
    //   - the cart form has NO submit listener and no data-shop-bound marker,
    //   - no element carries any data-thallo-enhanced~="shop-*" marker.
}
```

- [ ] **Step 3: Run to verify all five fail**, then implement the shop.js tail. Replace
  the current boot block (`function init() { … }` through the `window.thalloShop`
  export, ~lines 697–724) with:

```js
  // ---- init -----------------------------------------------------------------------

  function directSweep() {
    var forms = qsa(document, FORM_SELECTOR);
    for (var i = 0; i < forms.length; i++) {
      bindForm(forms[i]);
    }
    var galleries = qsa(document, '[data-shop-gallery]');
    for (var g = 0; g < galleries.length; g++) {
      bindGallery(galleries[g]);
    }
    hydrateMiniCarts();
    hydrateProductGrids();
    hydrateFeaturedProducts();
    hydrateAddToCarts();
  }

  function init() {
    // Runtime pages: the core owns scanning, markers, canvas policy, and containment —
    // a direct sweep would bypass all four and re-fetch already-hydrated components
    // (shopjs-on-runtime spec §2.4). enhance() is component-idempotent, so init()
    // remains safe to call after inserting new blocks.
    if (window.ThalloRuntime) {
      window.ThalloRuntime.enhance(document.documentElement);
      return;
    }
    directSweep();
  }

  /* shop-runtime:end */
  if (window.ThalloRuntime) {
    // Adoption (theme-runtime spec §2.5 / shopjs-on-runtime spec §2.2): the core
    // drives; enhance closures ARE the per-component functions above. All six are
    // canvas-skip (the default) — formalizing that shop behavior never runs in the
    // canvas stage.
    window.ThalloRuntime.register('shop-form', { selector: FORM_SELECTOR, enhance: bindForm });
    window.ThalloRuntime.register('shop-gallery', { selector: '[data-shop-gallery]', enhance: bindGallery });
    window.ThalloRuntime.register('shop-mini-cart', { selector: '[data-shop-mini-cart]', enhance: hydrateMiniCart });
    window.ThalloRuntime.register('shop-product-grid', { selector: '[data-shop-block="product-grid"]', enhance: hydrateProductGrid });
    window.ThalloRuntime.register('shop-featured-product', { selector: '[data-shop-block="featured-product"]', enhance: hydrateFeaturedProduct });
    window.ThalloRuntime.register('shop-add-to-cart', { selector: '[data-shop-block="add-to-cart"]', enhance: hydrateAddToCart });
  } else if (document.readyState === 'loading') {
    // Fallback (spec §2.3): a copied pre-runtime layout has no ThalloRuntime — shop.js
    // self-drives exactly as before adoption.
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Exposed for the executable test harness (ShopJsRuntimeTest) and for callers that
  // need to re-run enhancement after injecting new blocks (e.g. a builder preview
  // inserting one). On runtime pages init() delegates to the core (see above).
  window.thalloShop = {
    init: init,
    bindForm: bindForm,
  };
})();
```

Notes the implementer must honor: `hydrateMiniCart` takes no per-shell work (Task 1's
shape — the shell is a trigger) so it slots directly as the module enhance;
`FORM_SELECTOR` is already a comma-joined string usable as one selector; the
`/* shop-runtime:start */` marker from Task 1 plus this task's `/* shop-runtime:end */`
bound the adoption region the tests assert. The file header comment (lines 1–18) gains
two sentences: runtime pages are core-driven via six registered `shop-*` modules;
runtime-absent pages self-drive as the fallback.

- [ ] **Step 4: Run everything**

Run: `node --check packages/thallo-commerce/assets/shop.js && set -o pipefail && vendor/bin/phpunit tests/Integration/Commerce/ShopJsRuntimeTest.php 2>&1 | tail -5`
Both configurations green, including the untouched original contract test (standalone).

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-commerce/assets/shop.js tests/Integration/Commerce/ShopJsRuntimeTest.php
git commit -m "feat(commerce): shop.js registers on ThalloRuntime with runtime-absent fallback"
```

---

### Task 3: Coexistence evolution + full gates + CHANGELOG

**Files:**
- Modify: `tests/Integration/Render/RuntimeShopCoexistenceTest.php`
- Modify: `CHANGELOG.md` (`[Unreleased]` → `### Added`)

- [ ] **Step 1: Evolve the coexistence test.** Read it first; keep every existing
  assertion that still holds (neither eval throws; theme forms module never touches
  shop forms and vice versa — still true: the modules' selectors are disjoint), and
  UPDATE what adoption changed: shop.js no longer self-binds — with both files loaded,
  the CORE binds the shop form, so the interception assertions now run after the boot
  microtask flush; ADD: the registry contains both the five theme modules and the six
  `shop-*` modules (probe by duplicate-registration throw), and the shop form carries
  `data-thallo-enhanced~="shop-form"` while the thallo form carries `~="forms"` — the
  no-cross-ownership proof in marker form.

- [ ] **Step 2: Full gates.**

Run: `set -o pipefail && vendor/bin/phpunit 2>&1 | tail -4` — full suite green.
Run: `set -o pipefail && composer phpcs 2>&1 | tail -3` — clean.

- [ ] **Step 3: CHANGELOG bullet** (top of `### Added`): shop.js now registers its six
  concerns on the theme runtime (core-driven scanning, component markers, per-component
  failure containment, formalized canvas skip) with an exactly-once execution guard and
  a coalesced cart fetch; pages without the runtime (copied pre-runtime layouts) keep
  the self-driving fallback unchanged; `window.thalloShop.init()` delegates to
  `ThalloRuntime.enhance()` on runtime pages.

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/Render/RuntimeShopCoexistenceTest.php CHANGELOG.md
git commit -m "test(render): coexistence proves shop modules join the shared runtime registry"
```

---

## Execution notes

- Strictly ordered 1 → 2 → 3.
- The spec's §2.2 "each shell paints itself" is implemented as the shared promise's
  single document-wide `updateCartRegions()` paint (Global Constraints records why —
  cart regions live outside shells by existing design); the pinned test outcome (two
  shells → one request → all regions update) is unchanged.
- No dev-DB steps, no OpenAPI, no SPA gates (nothing under admin/ is touched).
