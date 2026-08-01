# Runtime Web Components Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `<thallo-carousel>`, `<thallo-tabs>`, `<thallo-navigation>`, and `<thallo-color-mode-toggle>` as light-DOM custom-element adapters over the existing ThalloRuntime enhancers, with full lifecycle teardown.

**Architecture:** The runtime core's per-component step becomes a private pipeline returning an internal outcome; a new `ThalloRuntime.registerElement()` closes over it for the three module-backed elements (the color-mode toggle is a separate guarded adapter riding the page-level service). Enhancers gain a return contract (`false` = structural no-op; function = teardown) and complete cleanups. Boot scheduling moves to an end-of-file footer so element projection always precedes the legacy scan. CSS tag aliases give the no-JS floor real styling.

**Tech Stack:** Vanilla JS in ONE file (`packages/thallo-render/runtime/runtime.js`), language floor "Baseline Widely Available" AND must parse+execute under Node ≥ 18 (PHPUnit tests execute the served bytes in Node with a hand-stubbed DOM — no jsdom). PHPUnit 10 (`App\Tests\Integration\Render`, extends `AppTestCase`). Default-theme CSS.

**Spec:** `docs/internal/superpowers/specs/2026-08-01-runtime-web-components-design.md` — read it first; pinned rules govern.

## Global Constraints

- ONE file: all JS stays in `packages/thallo-render/runtime/runtime.js` (one fingerprint). No new JS assets.
- Class-based discovery stays supported and non-deprecated; every EXISTING runtime test keeps passing unmodified except where a task explicitly extends it.
- Light DOM only. No Shadow DOM. No `<thallo-form>`.
- Element names (fixed API): `thallo-carousel`, `thallo-tabs`, `thallo-navigation`, `thallo-color-mode-toggle`.
- `registerElement` defines EXACTLY the three module-backed names; the toggle is defined by its own guarded adapter and never enters the pipeline.
- Canvas gate FIRST in the element path: a `canvas: 'skip'` module on canvas mutates NOTHING — no class stamping, no attribute projection.
- Projection is transactional: canvas-skip / missing target / structural `false` / contained throw / disconnect-before-microtask all undo projection and store no lifecycle record.
- Cleanup keyed by (component, module); disconnect removes ONLY that module's marker token.
- Private pipeline order: canvas policy → marker check → try/catch → mark. Outcomes `enhanced | already-enhanced | canvas-skipped | structural-noop | failed` are internal, never public API.
- Size budget: existing 12,288-byte gzip ceiling in `RuntimeSizeBudgetTest` unchanged (current 9,798). If genuinely exceeded, STOP and surface — do not bump silently.
- The color-mode toggle block's root class is `thallo-block-color_mode` (underscore).
- Run PHP tests from repo root: `vendor/bin/phpunit --filter <TestClass>`. Node must be available (tests skip without it — ensure your runs actually execute, not skip).
- Commit per task: stage exact paths (`git add <paths>`) then `git commit --only <paths>`. NEVER `git add -A`. NO attribution trailers in commit messages.

---

### Task 1: Core pipeline + enhancer return contract + cleanup storage + boot footer

**Files:**
- Modify: `packages/thallo-render/runtime/runtime.js` (core IIFE lines 18-101 + new end-of-file footer)
- Test: `tests/Integration/Render/RuntimeCoreTest.php` (extend)

**Interfaces:**
- Produces (private, used only by Task 2's closure): `runComponent(comp, name) -> 'enhanced'|'already-enhanced'|'canvas-skipped'|'structural-noop'|'failed'`; `storeCleanup(comp, name, fn)`, `takeCleanup(comp, name) -> fn|null`, `unmark(comp, name)`; boot footer markers `/* boot:footer */`. None of these helpers is exposed on `window.ThalloRuntime`.
- Produces (module contract, used by Tasks 3-5): `enhance()` may return `false` (structural no-op, not marked) or a cleanup function (stored per component+module).

- [ ] **Step 1: Write the failing tests**

Add to `RuntimeCoreTest::harness()` (before `console.log('ALL_PASS')`), and add the structural assertions in the PHP test method:

```php
// In testCoreRegistersEnhancesIdempotentlyAndHonorsCanvasPolicy(), after the
// existing modules:start assertion:
self::assertStringContainsString('/* boot:footer */', $src);
// Boot scheduling must live in the footer AFTER modules — not in the core IIFE:
$coreEnd = strpos($src, '/* modules:start */');
self::assertNotFalse($coreEnd);
self::assertStringNotContainsString('DOMContentLoaded', substr($src, 0, $coreEnd));
```

```js
// 5. Return contract: false = structural no-op — NOT marked, retried next pass.
var noopCalls = 0;
RT.register('nooper', { enhance: function () { noopCalls++; return false; }, selector: '.widget' });
RT.enhance(a);
assert(noopCalls === 1, 'noop enhancer ran');
assert((a.getAttribute('data-thallo-enhanced') || '').indexOf('nooper') === -1,
  'false return must not mark');
RT.enhance(a);
assert(noopCalls === 2, 'unmarked component is retried on the next pass');

// 6. The cleanup store remains private. Its same-component/multiple-module
//    behavior is exercised through registerElement adoption + disconnect in Task 2;
//    no destructive test hook is shipped on window.ThalloRuntime.
assert(RT.__takeCleanupForTest === undefined, 'private cleanup state is not exposed');
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter RuntimeCoreTest`
Expected: FAIL — `/* boot:footer */` absent, then (after adding markers only) harness FAILs on `false return must not mark`.

- [ ] **Step 3: Implement the core changes**

In the core IIFE, keep `modules`, `order`, `isCanvas`, `markerHas`, `mark`, `componentsIn` as-is and add:

```js
  function unmark(elm, name) {
    var v = elm.getAttribute('data-thallo-enhanced');
    if (v === null) { return; }
    var parts = v.split(' ').filter(function (t) { return t && t !== name; });
    if (parts.length) { elm.setAttribute('data-thallo-enhanced', parts.join(' ')); }
    else { elm.removeAttribute('data-thallo-enhanced'); }
  }

  // Cleanup storage keyed by (component, module) — a per-element single slot would
  // let one module's cleanup overwrite another's (spec §1).
  var cleanups = typeof WeakMap === 'function' ? new WeakMap() : null;
  function storeCleanup(comp, name, fn) {
    if (!cleanups || typeof fn !== 'function') { return; }
    var perModule = cleanups.get(comp);
    if (!perModule) { perModule = new Map(); cleanups.set(comp, perModule); }
    perModule.set(name, fn);
  }
  function takeCleanup(comp, name) {
    var perModule = cleanups ? cleanups.get(comp) : null;
    if (!perModule || !perModule.has(name)) { return null; }
    var fn = perModule.get(name);
    perModule.delete(name);
    if (perModule.size === 0) { cleanups.delete(comp); }
    return fn;
  }

  /* Private per-component pipeline (spec §1): canvas policy -> marker check ->
     try/catch -> mark. The outcome vocabulary is internal — consumed by
     registerElement (elements section), ignored by the scan loop. */
  function runComponent(comp, name) {
    var mod = modules[name];
    if (isCanvas() && mod.canvas === 'skip') { return 'canvas-skipped'; }
    if (markerHas(comp, name)) { return 'already-enhanced'; }
    try {
      var result = mod.enhance(comp);
      if (result === false) { return 'structural-noop'; } // never marked
      if (typeof result === 'function') { storeCleanup(comp, name, result); }
      mark(comp, name);
      return 'enhanced';
    } catch (err) {
      if (window.console && console.error) {
        console.error('ThalloRuntime: module "' + name + '" failed', err);
      }
      return 'failed';
    }
  }
```

Rewrite the scan loop body of `ThalloRuntime.enhance` to delegate (behavior identical — the outcome is ignored):

```js
    enhance: function (root) {
      var canvas = isCanvas();
      for (var i = 0; i < order.length; i++) {
        var name = order[i];
        if (canvas && modules[name].canvas === 'skip') { continue; }
        var comps = componentsIn(root, modules[name].selector);
        for (var j = 0; j < comps.length; j++) {
          runComponent(comps[j], name);
        }
      }
    }
```

DELETE the `boot()` function and its scheduling (lines 91-100) from the core IIFE. Append at the very END of the file (after `/* tabs:end */` — later tasks insert element sections before it; the footer must remain last):

```js
/* boot:footer — the ONE boot scheduler (spec §1 "Boot ordering is explicit").
   Runs after every module registration above AND after the element sections define
   their tags: customElements.define() upgrades already-parsed hosts synchronously,
   queuing their connection microtasks — so scheduling the whole-document scan on a
   LATER microtask (or on DOMContentLoaded, whose dispatch flushes those microtasks
   first) guarantees element projection wins before the legacy scan, which then
   no-ops on the shared marker. No public start() API. */
(function () {
  'use strict';
  function boot() { window.ThalloRuntime.enhance(document.documentElement); }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    Promise.resolve().then(boot);
  }
})();
```

- [ ] **Step 4: Run the full runtime suite**

Run: `vendor/bin/phpunit --filter "RuntimeCoreTest|CarouselRuntimeTest|TabsRuntimeTest|NavigationRuntimeTest|FormsRuntimeTest|ColorModeRuntimeTest|RuntimeDeliveryTest|RuntimeShopCoexistenceTest|RuntimeSizeBudgetTest"`
Expected: ALL PASS (confirm they RAN under Node, not skipped). The existing tests are the no-regression net for the refactor.

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/runtime/runtime.js tests/Integration/Render/RuntimeCoreTest.php
git commit --only packages/thallo-render/runtime/runtime.js tests/Integration/Render/RuntimeCoreTest.php -m "feat(render): runtime core — private pipeline, cleanup storage, boot footer"
```

---

### Task 2: `ThalloRuntime.registerElement()` bridge

**Files:**
- Modify: `packages/thallo-render/runtime/runtime.js` (core IIFE — the method closes over `runComponent`/`takeCleanup`/`unmark`/`isCanvas`/`modules`)
- Test: `tests/Integration/Render/RuntimeElementsBridgeTest.php` (create)

**Interfaces:**
- Consumes: Task 1's pipeline internals.
- Produces: `ThalloRuntime.registerElement(tag, moduleName, {resolveTarget?, projectOptions?})` — Task 6 calls it three times. `projectOptions(el, target)` MUST return an undo function (or null).

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Render/RuntimeElementsBridgeTest.php` mirroring `RuntimeCoreTest`'s shape (same `runtimeJs()`/`findNode()`/tmp-file/exec skeleton — copy those three private helpers verbatim). Its `harness()` extends the stub DOM and adds a `customElements` stub:

```js
'use strict';
function assert(c, m) { if (!c) { console.error('FAIL: ' + m); process.exit(1); } }

function el(cls) {
  var attrs = {};
  var classes = (cls || '').split(' ').filter(Boolean);
  var node = {
    children: [], parent: null, isConnected: true,
    get className() { return classes.join(' '); },
    classList: {
      add: function (c) { if (classes.indexOf(c) === -1) classes.push(c); },
      remove: function (c) { var i = classes.indexOf(c); if (i !== -1) classes.splice(i, 1); },
      contains: function (c) { return classes.indexOf(c) !== -1; }
    },
    appendChild: function (c) { c.parent = node; node.children.push(c); return c; },
    getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
    setAttribute: function (n, v) { attrs[n] = String(v); },
    removeAttribute: function (n) { delete attrs[n]; },
    hasAttribute: function (n) { return attrs[n] !== undefined; },
    matches: function (sel) { return sel.charAt(0) === '.' && node.classList.contains(sel.slice(1)); },
    closest: function (sel) {
      var cur = node;
      while (cur) { if (cur.matches && cur.matches(sel)) return cur; cur = cur.parent; }
      return null;
    },
    querySelectorAll: function (sel) {
      var found = [];
      (function walk(n) {
        n.children.forEach(function (c) { if (c.matches(sel)) found.push(c); walk(c); });
      })(node);
      return found;
    },
    querySelector: function (sel) { return node.querySelectorAll(sel)[0] || null; }
  };
  return node;
}

var docRoot = el('root');
global.document = {
  readyState: 'complete',
  addEventListener: function () {},
  querySelector: function (sel) { return docRoot.querySelector(sel); },
  querySelectorAll: function (sel) { return docRoot.querySelectorAll(sel); },
  documentElement: (function () { var h = el('html'); h.dataset = {}; return h; })()
};
global.window = global;
// customElements stub: capture classes; the harness drives lifecycle manually.
var defined = {};
global.HTMLElement = function () {};
global.customElements = { define: function (tag, cls) { defined[tag] = cls; } };

eval(RUNTIME_SRC_JSON);
var RT = window.ThalloRuntime;
var flush = function () { return new Promise(function (r) { setTimeout(r, 0); }); };

// Upgrade helper: graft the element class's lifecycle onto a stub node.
function upgrade(tag, node) {
  node.connectedCallback = defined[tag].prototype.connectedCallback;
  node.disconnectedCallback = defined[tag].prototype.disconnectedCallback;
  node.connectedCallback();
  return node;
}

(async function () {
  // Module under test: records enhancement, returns a cleanup.
  var enhanced = [], cleaned = 0;
  RT.register('probe', {
    selector: '.probe-root',
    enhance: function (c) { enhanced.push(c); return function () { cleaned++; }; }
  });
  var projected = 0, undone = 0;
  RT.registerElement('x-probe', 'probe', {
    projectOptions: function (elm) {
      elm.classList.add('probe-root'); projected++;
      return function () { elm.classList.remove('probe-root'); undone++; };
    }
  });
  assert(defined['x-probe'], 'registerElement defined the tag');

  // 1. Connect: microtask deferral, projection, pipeline, marker.
  var host = docRoot.appendChild(el(''));
  upgrade('x-probe', host);
  assert(enhanced.length === 0, 'connection work is deferred one microtask');
  await flush();
  assert(enhanced.length === 1 && enhanced[0] === host, 'connect enhanced the target');
  assert(host.classList.contains('probe-root'), 'projection applied');
  assert((host.getAttribute('data-thallo-enhanced') || '').indexOf('probe') !== -1, 'marked');

  // 2. Scan after connect: idempotent (shared marker).
  RT.enhance(docRoot);
  assert(enhanced.length === 1, 'scan after connect must not double-enhance');

  // 3. Disconnect: cleanup runs, projection undone, ONLY this module token removed.
  host.setAttribute('data-thallo-enhanced', host.getAttribute('data-thallo-enhanced') + ' other');
  host.disconnectedCallback();
  assert(cleaned === 1, 'module cleanup ran on disconnect');
  assert(undone === 1, 'projection undo ran on disconnect');
  assert(!host.classList.contains('probe-root'), 'projected class removed');
  assert(host.getAttribute('data-thallo-enhanced') === 'other',
    'only this module token removed: ' + host.getAttribute('data-thallo-enhanced'));

  // 4. Reconnect re-enhances cleanly.
  host.removeAttribute('data-thallo-enhanced');
  host.connectedCallback();
  await flush();
  assert(enhanced.length === 2, 'reconnect re-enhanced');
  host.disconnectedCallback();

  // 5. Transaction: structural false rolls projection back, nothing marked/stored.
  var noopUndone = 0;
  RT.register('noopmod', { selector: '.noop-root', enhance: function () { return false; } });
  RT.registerElement('x-noop', 'noopmod', {
    projectOptions: function (elm) {
      elm.classList.add('noop-root');
      return function () { elm.classList.remove('noop-root'); noopUndone++; };
    }
  });
  var h2 = docRoot.appendChild(el(''));
  upgrade('x-noop', h2);
  await flush();
  assert(noopUndone === 1 && !h2.classList.contains('noop-root'),
    'structural false rolled projection back');
  assert(h2.getAttribute('data-thallo-enhanced') === null, 'structural false never marks');
  h2.disconnectedCallback();
  assert(noopUndone === 1, 'failed connection retained no lifecycle rollback');
  h2.connectedCallback();
  await flush();
  assert(noopUndone === 2, 'fresh reconnect retried the structural no-op');
  h2.disconnectedCallback();
  assert(noopUndone === 2, 'second failed connection also retained no record');

  // 6. Transaction: contained throw rolls projection back.
  global.console = { error: function () {}, log: console.log };
  var throwUndone = 0;
  RT.register('throwmod', { selector: '.throw-root', enhance: function () { throw new Error('x'); } });
  RT.registerElement('x-throw', 'throwmod', {
    projectOptions: function (elm) {
      elm.classList.add('throw-root');
      return function () { elm.classList.remove('throw-root'); throwUndone++; };
    }
  });
  var h3 = docRoot.appendChild(el(''));
  upgrade('x-throw', h3);
  await flush();
  assert(throwUndone === 1, 'contained throw rolled projection back');

  // 7. Transaction: missing target — projection never applied, no record.
  var resolved = 0;
  RT.register('targmod', { selector: '.targ-root', enhance: function () {} });
  RT.registerElement('x-targ', 'targmod', {
    resolveTarget: function () { resolved++; return null; },
    projectOptions: function () { assert(false, 'projection must not run without a target'); }
  });
  var h4 = docRoot.appendChild(el(''));
  upgrade('x-targ', h4);
  await flush();
  assert(resolved === 1, 'resolveTarget consulted');

  // 8. Canvas gate FIRST: skip module on canvas mutates nothing at all.
  var stage = docRoot.appendChild(el('thallo-preview-block'));
  var h5 = docRoot.appendChild(el(''));
  upgrade('x-probe', h5);
  await flush();
  assert(!h5.classList.contains('probe-root'), 'canvas: no projection for skip module');
  assert(enhanced.length === 2, 'canvas: no enhancement for skip module');
  docRoot.children.splice(docRoot.children.indexOf(stage), 1);

  // 9. Disconnect before the deferred microtask cancels pending work.
  var h6 = docRoot.appendChild(el(''));
  upgrade('x-probe', h6);
  h6.disconnectedCallback(); // pending
  await flush();
  assert(enhanced.length === 2 && !h6.classList.contains('probe-root'),
    'disconnect-before-microtask cancels connection work');

  // 10. Already-enhanced target: projection kept, enhancer NOT re-invoked,
  //     element adopts the existing cleanup. A second module on the SAME target
  //     retains its own marker and cleanup — this is the public-lifecycle proof of
  //     WeakMap<Element, Map<moduleName, cleanup>>; no private test hook is shipped.
  var otherEnhanced = 0, otherCleaned = 0;
  RT.register('probe-other', {
    selector: '.probe-root',
    enhance: function () { otherEnhanced++; return function () { otherCleaned++; }; }
  });
  RT.registerElement('x-probe-other', 'probe-other', {});
  var h7 = docRoot.appendChild(el('probe-root'));
  var otherBefore = otherEnhanced;
  RT.enhance(h7); // scan path enhances both modules + stores both cleanups first
  assert(enhanced.length === 3, 'scan enhanced h7');
  assert(otherEnhanced === otherBefore + 1, 'second module enhanced the same target');
  upgrade('x-probe', h7);
  await flush();
  assert(enhanced.length === 3, 'already-enhanced: enhancer not re-invoked');
  assert(h7.classList.contains('probe-root'), 'already-enhanced: projection kept');
  var cleanedBefore = cleaned;
  h7.disconnectedCallback();
  assert(cleaned === cleanedBefore + 1, 'already-enhanced: adopted cleanup ran on disconnect');
  assert((h7.getAttribute('data-thallo-enhanced') || '').indexOf('probe-other') !== -1,
    'disconnect preserved the other module marker');
  upgrade('x-probe-other', h7);
  await flush();
  assert(otherEnhanced === otherBefore + 1,
    'other module cleanup remained independently adoptable');
  h7.disconnectedCallback();
  assert(otherCleaned === 1, 'other module cleanup ran through its own lifecycle');

  console.log('ALL_PASS');
})().catch(function (e) { console.error('FAIL: ' + (e && e.message)); process.exit(1); });
```

(In the PHP class, embed the harness with the runtime source substituted the same way `RuntimeCoreTest::harness()` does — `RUNTIME_SRC_JSON` above stands for the `$json`-interpolated source.)

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter RuntimeElementsBridgeTest`
Expected: FAIL — `registerElement` undefined.

- [ ] **Step 3: Implement `registerElement` inside the core IIFE**

```js
  /* Element bridge (spec §1): the ONE public path from custom elements into the
     private pipeline. Defines only what it is told; Task 6 registers the three
     module-backed v1 tags. Guarded: without customElements the elements are simply
     absent and the class-based path is untouched. */
  var elementRecords = typeof WeakMap === 'function' ? new WeakMap() : null;
  function abandonElementRecord(host, rec) {
    rec.pending = false;
    if (rec.undo) {
      try { rec.undo(); } catch (err) { /* rollback must not strand the record */ }
    }
    rec.undo = null;
    rec.target = null;
    if (elementRecords.get(host) === rec) { elementRecords.delete(host); }
  }
  function defineElement(tag, moduleName, opts) {
    var resolveTarget = (opts && opts.resolveTarget) || function (host) { return host; };
    var projectOptions = (opts && opts.projectOptions) || null;

    class ThalloElement extends HTMLElement {
      connectedCallback() {
        var host = this;
        var rec = { pending: true, undo: null, target: null };
        elementRecords.set(host, rec);
        // One-microtask deferral (spec §1): synchronously-constructed children are
        // complete; asynchronously-populated elements must be built before insertion.
        Promise.resolve().then(function () {
          if (!rec.pending || elementRecords.get(host) !== rec) { return; }
          rec.pending = false;
          if (host.isConnected === false) { abandonElementRecord(host, rec); return; }
          var mod = modules[moduleName];
          if (!mod) { abandonElementRecord(host, rec); return; }
          // Canvas gate FIRST — before ANY mutation, including projection.
          if (isCanvas() && mod.canvas === 'skip') {
            abandonElementRecord(host, rec); return;
          }
          var target = resolveTarget(host);
          if (!target) { abandonElementRecord(host, rec); return; }
          rec.target = target;
          if (projectOptions) { rec.undo = projectOptions(host, target) || null; }
          var outcome = runComponent(target, moduleName);
          if (outcome === 'enhanced' || outcome === 'already-enhanced') { return; }
          // Transactional rollback: structural-noop / failed / canvas-skipped
          // (including a canvas that appeared during projection) all leave NO record.
          abandonElementRecord(host, rec);
        });
      }
      disconnectedCallback() {
        var rec = elementRecords.get(this);
        if (!rec) { return; }
        elementRecords.delete(this);
        if (rec.pending) { // cancel pending connection work
          abandonElementRecord(this, rec);
          return;
        }
        if (rec.target) {
          var fn = takeCleanup(rec.target, moduleName);
          if (fn) { try { fn(); } catch (err) { /* teardown must not break disconnect */ } }
          unmark(rec.target, moduleName); // ONLY this module's token
        }
        if (rec.undo) { rec.undo(); }
      }
    }
    customElements.define(tag, ThalloElement);
  }

  // Attach conditionally so the whole feature is absent where custom elements are
  // (Node harness without a stub, legacy browsers) — existing tests keep passing.
  if (typeof customElements !== 'undefined' && customElements &&
      typeof customElements.define === 'function' && typeof HTMLElement === 'function' &&
      elementRecords) {
    window.ThalloRuntime.registerElement = defineElement;
  }
```

(`class … extends HTMLElement` parses under Node ≥ 18 and is Baseline — allowed.)

- [ ] **Step 4: Run to verify pass + no regression**

Run: `vendor/bin/phpunit --filter "RuntimeElementsBridgeTest|RuntimeCoreTest|RuntimeSizeBudgetTest"`
Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/runtime/runtime.js tests/Integration/Render/RuntimeElementsBridgeTest.php
git commit --only packages/thallo-render/runtime/runtime.js tests/Integration/Render/RuntimeElementsBridgeTest.php -m "feat(render): ThalloRuntime.registerElement — transactional element bridge"
```

---

### Task 3: Carousel teardown + structural `false`

**Files:**
- Modify: `packages/thallo-render/runtime/runtime.js` (`enhanceCarousel`, lines ~276-429)
- Test: `tests/Integration/Render/CarouselRuntimeTest.php` (extend, following its existing harness style)

**Interfaces:**
- Consumes: Task 1's return contract.
- Produces: `enhanceCarousel` returns `false` for missing viewport/track or <2 slides, else a cleanup removing every injected node/listener/observer/timer.

- [ ] **Step 1: Write the failing test**

Extend `CarouselRuntimeTest`'s harness (match its existing stub style, and add Task 2's minimal `HTMLElement`/`customElements`/`upgrade()`/microtask helpers). Register a harness-only `x-carousel-lifecycle` adapter for the existing `carousel` module; teardown is exercised only through `disconnectedCallback()`, never through a production test hook:

```js
// Structural no-op returns false (never marks).
// Build a carousel root MISSING the viewport; enhance via the registry; assert the
// root carries no 'carousel' marker token and enhance() reran the module on a second
// pass (unmarked components are retried).

// Teardown accounting: build a VALID, connected 3-slide x-carousel-lifecycle host
// carrying .thallo-block-carousel and data-arrows="1", data-dots="1",
// data-autoplay="1". Stub addEventListener/removeEventListener on
// viewport + document to COUNT registrations/removals by type; stub
// IntersectionObserver with observe/disconnect counters; stub
// setInterval/clearInterval. Connect + flush, assert the marker, then call
// disconnectedCallback(). Assert after disconnect:
//  - every injected node is gone: no children matching __prev/__next/__dots/
//    __status/__pause remain under root;
//  - removeEventListener was called for the viewport scroll handler AND each of
//    pointerdown/keydown/wheel/touchstart with the SAME function reference that
//    addEventListener received (track handler refs in the stub);
//  - document visibilitychange handler removed (same-ref check);
//  - IntersectionObserver.disconnect called;
//  - clearInterval called for any live timer;
//  - the carousel marker is removed; reconnect + flush enhances cleanly again.
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter CarouselRuntimeTest`
Expected: FAIL — cleanup is null / structural path marks.

- [ ] **Step 3: Implement**

Rework `enhanceCarousel` (structure preserved; deltas only):

1. `if (!viewport || !track) { return false; }` and `if (slides.length < 2) { return false; }` (was bare `return`).
2. Accumulate teardown in a local `var undo = [];` with helpers used for EVERY mutation the module makes:

```js
    var undo = [];
    function addNode(parent, node) { parent.appendChild(node); undo.push(function () {
      if (node.parentNode) { node.parentNode.removeChild(node); } }); }
    function listen(targetEl, type, fn, opts) { targetEl.addEventListener(type, fn, opts);
      undo.push(function () { targetEl.removeEventListener(type, fn, opts); }); }
```

3. Replace every `root.appendChild(...)` with `addNode(root, ...)`; every `viewport.addEventListener`/`document.addEventListener('visibilitychange', ...)` with `listen(...)` (the visibilitychange handler becomes a named local, no longer anonymous). The IntersectionObserver instance is kept in a local `io` and `undo.push(function () { if (io) { io.disconnect(); } })`. The interval is already tracked in `timer`; push `function () { stopAuto(); }`.
4. End of the function: `return function () { for (var i = undo.length - 1; i >= 0; i--) { undo[i](); } };`

- [ ] **Step 4: Run**

Run: `vendor/bin/phpunit --filter "CarouselRuntimeTest|RuntimeCoreTest|RuntimeSizeBudgetTest"`
Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/runtime/runtime.js tests/Integration/Render/CarouselRuntimeTest.php
git commit --only packages/thallo-render/runtime/runtime.js tests/Integration/Render/CarouselRuntimeTest.php -m "feat(render): carousel enhancer — structural false + complete teardown"
```

---

### Task 4: Navigation teardown (+ open-state snapshot)

**Files:**
- Modify: `packages/thallo-render/runtime/runtime.js` (navigation module, lines ~463-574)
- Test: `tests/Integration/Render/NavigationRuntimeTest.php` (extend)

**Interfaces:**
- Consumes: Task 1's return contract.
- Produces: navigation `enhance` returns a cleanup that removes every listener, clears the pending hover timeout, removes the `--js` class, and restores each managed `<details>`'s initial `open` state.

- [ ] **Step 1: Write the failing test**

Extend `NavigationRuntimeTest`'s harness (match its existing stub style, add Task 2's minimal custom-element lifecycle helpers, and register a harness-only `x-navigation-lifecycle` adapter whose `resolveTarget(host)` returns the existing mobile `<details>` target):

```js
// Snapshot restore: build the drawer with parent details A open=false, B open=false;
// connect + flush; simulate opening A (set A.open = true as the toggle listener
// would); then call the host's disconnectedCallback().
// Assert: A.open restored to false (initial snapshot), root no longer has
// thallo-block-navigation--js, the document click listener and the mq change
// listener were removed (same-ref accounting in the stubs), and any pending
// hover-close timeout was cleared (stub setTimeout/clearTimeout counters).
// Reconnect + flush must re-enhance, restore the marker, and re-add the --js class.
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter NavigationRuntimeTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

Same `undo`/`listen` pattern as Task 3 inside the navigation `enhance`:

1. Snapshot first: `var initialOpen = []; for (i = 0; i < parents.length; i++) { initialOpen.push(!!parents[i].open); } var mobileInitialOpen = !!mobile.open;`
2. Every `addEventListener` (per-details `toggle`/`keydown`, summary `keydown`, parent `mouseenter`/`mouseleave`, document `click`, `mq` `change`) goes through `listen()`. The hover `closeTimer` moves to a shared array `var timers = [];` — push on set, and cleanup clears all pending.
3. `root.classList.add('thallo-block-navigation--js')` gets `undo.push(function () { root.classList.remove('thallo-block-navigation--js'); })`.
4. Cleanup tail restores state: after replaying `undo` in reverse, set every `parents[i].open = initialOpen[i]` and `mobile.open = mobileInitialOpen`.
5. Return the cleanup function.

- [ ] **Step 4: Run**

Run: `vendor/bin/phpunit --filter "NavigationRuntimeTest|RuntimeCoreTest|RuntimeSizeBudgetTest"`
Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/runtime/runtime.js tests/Integration/Render/NavigationRuntimeTest.php
git commit --only packages/thallo-render/runtime/runtime.js tests/Integration/Render/NavigationRuntimeTest.php -m "feat(render): navigation enhancer — complete teardown with open-state snapshot"
```

---

### Task 5: Tabs baseline snapshot + structural `false`

**Files:**
- Modify: `packages/thallo-render/runtime/runtime.js` (`enhanceTabs`, lines ~612-748)
- Test: `tests/Integration/Render/TabsRuntimeTest.php` (extend)

**Interfaces:**
- Consumes: Task 1's return contract.
- Produces: `enhanceTabs` returns `false` for the empty block, keeps throwing for unpairable structure, and returns a cleanup = replay undo log in reverse THEN restore the baseline snapshot (each radio's `checked` property, each panel's initial `hidden` attribute state).

- [ ] **Step 1: Write the failing test**

Extend `TabsRuntimeTest`'s harness with Task 2's minimal custom-element lifecycle helpers and register a harness-only `x-tabs-lifecycle` adapter for the existing `tabs` module:

```js
// THE spec §3 case: interaction-then-disconnect restores the exact served floor.
// Build a valid 3-tab block, radio[0] checked. Enhance. Simulate select(2) via the
// label click handler (radio[2].checked true, panel[0] hidden set by the module —
// NOT in the undo log). Then call disconnectedCallback() on the lifecycle host.
// Assert the SERVED floor exactly: radio[0].checked === true, radio[2].checked
// === false, NO panel carries [hidden], no role/aria-*/tabindex attributes remain
// on list/labels/panels, radios are un-hidden, and the tabs marker is gone. (The
// undo log restores attributes; the baseline snapshot restores checked + panel
// hidden that select() mutated.) Reconnect + flush must enhance exactly once again.

// Empty block: root with no radios/labels/panels — enhance() returns false; the
// root is NOT marked (was previously silently marked).
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter TabsRuntimeTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

1. Empty block: `return false;` (was bare `return`).
2. Before Phase 1, snapshot the baseline:

```js
    var baselineChecked = radios.map(function (r) { return !!r.checked; });
    var baselineHidden = panels.map(function (p) { return p.getAttribute('hidden') !== null; });
```

(If the file's ES floor avoids `.map` on the collected arrays — they are plain arrays from `childrenWithClass`, so `.map` is fine.)
3. On success, instead of returning nothing:

```js
    return function () {
      rollback(); // the existing undo log, reversed: attributes + listeners
      for (var k = 0; k < radios.length; k++) { radios[k].checked = baselineChecked[k]; }
      for (k = 0; k < panels.length; k++) {
        if (baselineHidden[k]) { panels[k].setAttribute('hidden', ''); }
        else { panels[k].removeAttribute('hidden'); }
      }
    };
```

(The throw path keeps calling `rollback()` + rethrow exactly as today.)

- [ ] **Step 4: Run**

Run: `vendor/bin/phpunit --filter "TabsRuntimeTest|RuntimeCoreTest|RuntimeSizeBudgetTest"`
Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/runtime/runtime.js tests/Integration/Render/TabsRuntimeTest.php
git commit --only packages/thallo-render/runtime/runtime.js tests/Integration/Render/TabsRuntimeTest.php -m "feat(render): tabs enhancer — structural false + baseline-snapshot teardown"
```

---

### Task 6: The four element definitions

**Files:**
- Modify: `packages/thallo-render/runtime/runtime.js` (new `/* elements:start */ … /* elements:end */` and `/* color-mode-toggle:start */ … end */` sections, inserted AFTER `/* tabs:end */` and BEFORE the boot footer)
- Test: `tests/Integration/Render/RuntimeElementsTest.php` (create; same skeleton as Task 2's test)

**Interfaces:**
- Consumes: Task 2's `registerElement`; Tasks 3-5's cleanups; `window.thalloColorMode` (existing).
- Produces: the four defined tags — the v1 public API.

- [ ] **Step 1: Write the failing test**

`RuntimeElementsTest` harness (Task 2's stub DOM + `customElements` stub + `upgrade()`/`flush()` helpers, PLUS `dataset` support on stub nodes — add `dataset: {}` and make `setAttribute('data-arrows', v)` also NOT required to sync dataset; the carousel module reads `root.dataset.arrows`, so the stub's `el()` gains: `node.dataset = {}` and a `setDataAttr` note — project sugar via `setAttribute` AND the module reads `dataset`, so the projection implementation below writes BOTH; assert on `getAttribute`):

```js
// 1. Registration split: exactly these four tags defined, and the toggle's class
//    is NOT produced by registerElement (no pipeline records for it).
assert(defined['thallo-carousel'] && defined['thallo-tabs'] &&
       defined['thallo-navigation'] && defined['thallo-color-mode-toggle'],
  'all four v1 tags defined');
assert(Object.keys(defined).length === 4, 'exactly four tags defined');

// 2. Carousel sugar: <thallo-carousel arrows autoplay> projects data-arrows="1",
//    data-autoplay="1" (NOT dots), stamps .thallo-block-carousel, enhances, marks.
//    (Give the host a valid __viewport/__track/3-slides light DOM first.)
//    Disconnect: injected controls gone, class + data-* projections removed,
//    marker token gone.

// 3. Existing data-* wins over sugar: host with data-arrows="0" AND arrows attr —
//    projection must NOT overwrite the explicit data-arrows.

// 4. Tabs: <thallo-tabs> stamps .thallo-block-tabs, enhances the radio floor,
//    disconnect restores the served floor (reuses Task 5's cleanup through the
//    element path).

// 5. Navigation target resolution: <thallo-navigation reveal-hover> containing the
//    drawer details[data-thallo-enhance="navigation"] — marker lands on the DRAWER,
//    not the host; --reveal-hover + .thallo-block-navigation stamped on the HOST;
//    missing drawer -> nothing projected, nothing marked anywhere.

// 6. Toggle: define present; connectedCallback with window.thalloColorMode stubbed
//    ({ reflect: counter }) calls reflect() once after a flush; with
//    window.thalloColorMode ABSENT it must not throw.

// 7. Double-path both orders for carousel: (a) scan first (host already carries
//    .thallo-block-carousel in markup) then connect — enhancer ran once, element
//    adopts cleanup; (b) connect first then RT.enhance(document) — still once.

// 8. Boot ordering in BOTH ready states (spec §6), using two fresh evals rather
//    than treating source shape as behavioral evidence. For each eval, create the
//    valid <thallo-carousel arrows> host BEFORE evaluating runtime.js. Its
//    customElements.define stub synchronously grafts the matching lifecycle and
//    invokes connectedCallback, modeling browser upgrade of already-parsed hosts.
//
//    loading: capture DOMContentLoaded, flush the connection microtask, then invoke
//    the captured handler. Assert data-arrows="1" was projected before the scan and
//    enhancement ran once.
//
//    complete: after eval but before microtasks flush, wrap RT.enhance to append
//    'document-scan' to a trace; the host's setAttribute appends 'projection' when
//    data-arrows is written. Await one turn and assert projection precedes
//    document-scan, controls were injected once, and the marker has one carousel
//    token. This executes the footer's SINGLE Promise.resolve().then(boot) path and
//    proves FIFO ordering after synchronous custom-element upgrade.
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter RuntimeElementsTest`
Expected: FAIL — tags undefined.

- [ ] **Step 3: Implement the sections**

```js
/* elements:start — the three module-backed v1 elements (web-components spec §4).
   Light-DOM adapters only: same inner skeleton as the blocks, attribute sugar
   projected into the EXISTING option vocabulary, all through registerElement's
   transactional pipeline. */
(function () {
  'use strict';
  var RT = window.ThalloRuntime;
  if (!RT.registerElement) { return; } // no customElements: elements absent by design

  // Shared projection helper: stamp a class (undo-aware) + map bare attributes to
  // existing data-* options WITHOUT overriding explicit data-* in the markup.
  function project(host, rootClass, attrMap) {
    var undos = [];
    if (rootClass && !host.classList.contains(rootClass)) {
      host.classList.add(rootClass);
      undos.push(function () { host.classList.remove(rootClass); });
    }
    if (attrMap) {
      Object.keys(attrMap).forEach(function (attr) {
        var dataAttr = attrMap[attr]; // e.g. 'data-arrows'
        if (host.hasAttribute(attr) && host.getAttribute(dataAttr) === null) {
          host.setAttribute(dataAttr, '1');
          if (host.dataset) { host.dataset[dataAttr.slice(5)] = '1'; }
          undos.push(function () {
            host.removeAttribute(dataAttr);
            if (host.dataset) { delete host.dataset[dataAttr.slice(5)]; }
          });
        }
      });
    }
    return function () { for (var i = undos.length - 1; i >= 0; i--) { undos[i](); } };
  }

  RT.registerElement('thallo-carousel', 'carousel', {
    projectOptions: function (host) {
      return project(host, 'thallo-block-carousel',
        { arrows: 'data-arrows', dots: 'data-dots', autoplay: 'data-autoplay' });
    }
  });

  RT.registerElement('thallo-tabs', 'tabs', {
    projectOptions: function (host) {
      return project(host, 'thallo-block-tabs', null);
    }
  });

  RT.registerElement('thallo-navigation', 'navigation', {
    // The module enhances the inner drawer details, not the block root — marker and
    // cleanup belong to the TARGET (spec §4).
    resolveTarget: function (host) {
      return host.querySelector('[data-thallo-enhance="navigation"]');
    },
    projectOptions: function (host) {
      var undoBase = project(host, 'thallo-block-navigation', null);
      var addedHover = false;
      if (host.hasAttribute('reveal-hover') &&
          !host.classList.contains('thallo-block-navigation--reveal-hover')) {
        host.classList.add('thallo-block-navigation--reveal-hover');
        addedHover = true;
      }
      return function () {
        if (addedHover) { host.classList.remove('thallo-block-navigation--reveal-hover'); }
        undoBase();
      };
    }
  });
})();
/* elements:end */

/* color-mode-toggle:start — the explicit pipeline EXCEPTION (spec §4): the
   color-mode registry entry is a no-op on <html>; the real behavior is the
   page-level delegated service. This adapter never enters registerElement — it
   only re-syncs late-inserted toggles' aria-checked via the service. Its light DOM
   is the server-rendered [data-color-mode-set] controls; clicks ride the existing
   document-level delegation. */
(function () {
  'use strict';
  if (typeof customElements === 'undefined' || !customElements ||
      typeof customElements.define !== 'function' || typeof HTMLElement !== 'function') {
    return;
  }
  class ThalloColorModeToggle extends HTMLElement {
    connectedCallback() {
      Promise.resolve().then(function () {
        if (window.thalloColorMode && typeof window.thalloColorMode.reflect === 'function') {
          window.thalloColorMode.reflect();
        }
      });
    }
  }
  customElements.define('thallo-color-mode-toggle', ThalloColorModeToggle);
})();
/* color-mode-toggle:end */
```

(Both sections go between `/* tabs:end */` and the `/* boot:footer */` block — the footer stays LAST.)

- [ ] **Step 4: Run the whole runtime suite**

Run: `vendor/bin/phpunit --filter "RuntimeElementsTest|RuntimeElementsBridgeTest|RuntimeCoreTest|CarouselRuntimeTest|TabsRuntimeTest|NavigationRuntimeTest|FormsRuntimeTest|ColorModeRuntimeTest|RuntimeDeliveryTest|RuntimeShopCoexistenceTest|RuntimeSizeBudgetTest"`
Expected: ALL PASS, including the size budget (STOP and surface if the ceiling is exceeded — do not bump).

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/runtime/runtime.js tests/Integration/Render/RuntimeElementsTest.php
git commit --only packages/thallo-render/runtime/runtime.js tests/Integration/Render/RuntimeElementsTest.php -m "feat(render): the four v1 elements — carousel, tabs, navigation, color-mode toggle"
```

---

### Task 7: CSS tag aliases + placement test

**Files:**
- Modify: `packages/thallo-render/themes/default/assets/blocks.css` (carousel, tabs, color_mode root rules + new element rules)
- Modify: `packages/thallo-render/themes/default/assets/navigation.css` (navigation root rules + element display)
- Test: `tests/Integration/Render/ElementCssAliasTest.php` (create — pure file-content assertions, no Node needed)

**Interfaces:**
- Consumes: nothing.
- Produces: styled no-JS floor for the four tags.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * CSS ownership + no-JS floor for the v1 elements (web-components spec §5):
 * aliases live with the stylesheet that owns each component; the three structural
 * elements compute to block; the toggle stays inline-compatible and hides when the
 * feature is off.
 */
final class ElementCssAliasTest extends AppTestCase
{
    private function css(string $file): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath()
            . '/packages/thallo-render/themes/default/assets/' . $file
        );
    }

    public function testBlocksCssOwnsCarouselTabsAndToggleAliases(): void
    {
        $css = $this->css('blocks.css');
        self::assertStringContainsString(':where(.thallo-block-carousel, thallo-carousel)', $css);
        self::assertStringContainsString(':where(.thallo-block-tabs, thallo-tabs)', $css);
        self::assertStringContainsString(
            '.layout--full :where(.thallo-block-tabs, thallo-tabs)',
            $css,
        );
        self::assertStringContainsString(
            '.layout--full :where(.thallo-block-carousel, thallo-carousel)',
            $css,
        );
        self::assertStringContainsString(
            ':where(.thallo-block-color_mode, thallo-color-mode-toggle)',
            $css,
        );
        self::assertStringContainsString('thallo-carousel, thallo-tabs { display: block; }', $css);
        self::assertStringContainsString('thallo-color-mode-toggle { display: inline-block; }', $css);
        self::assertStringContainsString(
            'html:not([data-color-mode-enabled="true"]) thallo-color-mode-toggle { display: none; }',
            $css,
        );
        self::assertStringNotContainsString('thallo-navigation', $css);
    }

    public function testNavigationCssOwnsNavigationAliases(): void
    {
        $css = $this->css('navigation.css');
        self::assertStringContainsString(':where(.thallo-block-navigation, thallo-navigation)', $css);
        self::assertStringContainsString(
            '.site-header__inner :where(.thallo-block-navigation, thallo-navigation)',
            $css,
        );
        self::assertStringContainsString(
            ':where(.thallo-block-navigation, thallo-navigation):has(',
            $css,
        );
        self::assertStringContainsString(
            '.thallo-region :where(.thallo-block-navigation, thallo-navigation)',
            $css,
        );
        self::assertStringContainsString('thallo-navigation { display: block; }', $css);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter ElementCssAliasTest`
Expected: FAIL.

- [ ] **Step 3: Implement the CSS**

In `blocks.css`:
1. Alias every selector whose SUBJECT is one of the three adoptable roots, whether bare or contextual. That includes the bare `.thallo-block-tabs`, `.thallo-block-carousel`, and `.thallo-block-color_mode` rules AND the `.layout--full .thallo-block-tabs` / `.layout--full .thallo-block-carousel` entries near the end of the file. Use `:where(.thallo-block-tabs, thallo-tabs)` etc. Child (`__`) and modifier (`--`) selectors remain class-based because their classes exist in the light-DOM skeleton or are projected behavior state. Finish with an `rg` inventory proving no contextual occurrence of a bare adoptable root was overlooked; the test above pins every currently shipped contextual case.
2. Add a new section at the end:

```css
/* Custom-element floor (web-components spec §5): autonomous elements default to
   display:inline; the structural elements are page sections. */
thallo-carousel, thallo-tabs { display: block; }
thallo-color-mode-toggle { display: inline-block; }
/* Feature off -> the toggle hides entirely (server stamps the flag on <html>). */
html:not([data-color-mode-enabled="true"]) thallo-color-mode-toggle { display: none; }
```

In `navigation.css`: alias every selector whose subject is the navigation root, not only selectors that START with the class. The required inventory is: the bare root rule; both `.site-header__inner .thallo-block-navigation` mobile-layout selectors (including the `:has(...)` form); and `.thallo-region .thallo-block-navigation`. Modifier and descendant selectors remain class-based and begin matching after the transactional class projection. Finish with the same `rg` inventory check, then add at the end:

```css
/* Custom-element floor (web-components spec §5). */
thallo-navigation { display: block; }
```

- [ ] **Step 4: Run + eyeball the rendered theme**

Run: `vendor/bin/phpunit --filter "ElementCssAliasTest|StarterTemplatesTest|BlocksRenderingTest"`
Expected: ALL PASS (the render suites prove the class-path cascade is unchanged).

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/themes/default/assets/blocks.css packages/thallo-render/themes/default/assets/navigation.css tests/Integration/Render/ElementCssAliasTest.php
git commit --only packages/thallo-render/themes/default/assets/blocks.css packages/thallo-render/themes/default/assets/navigation.css tests/Integration/Render/ElementCssAliasTest.php -m "feat(render): CSS tag aliases — styled no-JS floor for the v1 elements"
```

---

### Task 8: README copyable examples + full verification

**Files:**
- Modify: `packages/thallo-render/README.md` (new "Theme runtime elements" subsection under "Theme runtime")

- [ ] **Step 1: Write the docs**

Add a subsection with ONE complete copyable light-DOM example per element (spec pin: complete, copyable). Each example must be the real no-JS floor: carousel = viewport/track/slides; tabs = radios+labels+panels with the id pattern the enhancer derives (`tabs-{id}-N`); navigation = the drawer `<details data-thallo-enhance="navigation">` structure; toggle = the server-rendered `[data-color-mode-set]` buttons (copy the shape from `templates/blocks/color_mode.twig`). State the three contract notes verbatim: (1) elements are light-DOM adapters — the inner skeleton is the same one the starter blocks use and IS the no-JS fallback; (2) custom themes that copied `blocks.css`/`navigation.css` must re-copy (or port) the alias rules for element support; (3) asynchronously-populated elements must be fully built before insertion.

- [ ] **Step 2: Full verification**

```bash
vendor/bin/phpunit tests/Integration/Render
composer ci
composer boundaries
```

Expected: green. The targeted Render lane gives fast, local failure attribution; `composer ci` is the repository authority (PHPCS plus reset/migrate/full PHPUnit), and `composer boundaries` remains the package-layer gate. Confirm the Node-backed runtime tests RAN rather than skipped in both PHPUnit passes. Known local blocker: on this dev machine `composer ci`'s `test:reset-db` step fails with a pre-existing Postgres schema-ownership mismatch (`must be owner of schema public`) unrelated to any feature work — if hit, fall back to `vendor/bin/phpunit` (full run) + PHPCS on touched files, and report the substitution rather than treating it as a feature failure.

- [ ] **Step 3: Commit**

```bash
git add packages/thallo-render/README.md
git commit --only packages/thallo-render/README.md -m "docs(render): copyable light-DOM examples for the runtime elements"
```

---

## Verification (end-to-end)

1. `vendor/bin/phpunit tests/Integration/Render` — all green with Node available (watch for skips: the runtime tests skip silently without `node`; a skipped run proves nothing).
2. `composer ci` — PHPCS plus the reset/migrate/full PHPUnit authority, all green.
3. `composer boundaries` — package boundaries green.
4. `RuntimeSizeBudgetTest` green at the UNCHANGED 12,288-byte ceiling.
5. Manual smoke (optional): boot the app, add `<thallo-carousel arrows>` with the copyable example markup to a template, load the page — carousel arrows appear; disable JS — scroll-snap floor still styled; open the canvas editor — no element mutations in preview blocks.
