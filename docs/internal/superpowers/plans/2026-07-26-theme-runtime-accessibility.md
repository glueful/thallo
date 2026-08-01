# Default Theme Runtime & Accessibility Refresh — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps
> use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the default theme's behavioral JavaScript into a package-owned,
fingerprinted runtime under `thallo-render` and fix the accessibility debts (mobile nav,
tabs ARIA, carousel autoplay, form focus, shell fundamentals) per
`docs/superpowers/specs/2026-07-26-theme-runtime-accessibility-design.md`.

**Architecture:** One unbundled `runtime.js` with a `window.ThalloRuntime`
register/enhance core (idempotent, canvas-aware), served content-fingerprinted at
`/_thallo/runtime/{file}` exactly like the shipped `ShopAssetMap`/`ShopAssetController`
pair (logical alias → 302 → exact immutable fingerprint; stale 404s). Themes keep CSS
only; `blocks.js` becomes a behavior-free compatibility loader. Templates gain the shell
a11y and the unified `<details>` navigation tree; the tabs floor drops its false ARIA.

**Tech Stack:** Plain JS (Baseline Widely Available + parses on Node ≥ 18), Twig, PHP 8.4
(PHPUnit-driven Node + hand-stubbed-DOM tests, the `ColorModeRuntimeTest` pattern), Vue 3
admin SPA (one editor gate), PostgreSQL-free (no migrations in this plan).

## Global Constraints

- Runtime JS: **Baseline Widely Available** syntax/APIs only; must parse and run under
  **Node ≥ 18** (tests execute the served bytes). `<details name>` appears only in server
  markup as a progressive extra; the runtime itself guarantees one-open-sibling.
- No bundler, no new npm dependencies, no build step. `runtime.js` is the served source.
- Canvas hard rule: modules with `canvas: 'skip'` (navigation, tabs, carousel, forms)
  no-op when `.thallo-preview-block` exists; `color-mode` is `canvas: 'allow'`.
- Preserved contracts (spec §2.6): `window.thalloColorMode` (`get/set/resolved/reflect`),
  `thallo:color-mode-change` event, `thallo.colorMode` storage key,
  `html[data-color-mode-enabled="true"]` gate, form PRG floor to `/_forms/submit`,
  carousel structural classes + `data-arrows/dots/autoplay`, navigation `--js` class
  handoff, `details name="nav-{block.id}"` exclusivity.
- Delivery: logical `runtime.js` → 302 (no Cache-Control) → `runtime-<12-hex>.js`;
  exact current fingerprint → `Cache-Control: public, max-age=31536000, immutable`;
  anything else 404. Fingerprint = first 12 hex of sha256 of file bytes (ShopAssetMap
  convention).
- `TemplatePolicy::CACHE_VERSION` bumps 11 → 12 in the same change that allowlists
  `runtime_script`.
- Navigation breakpoint: `max-width: 48rem`, named at every use.
- Tabs authoring maximum: **12**, enforced unconditionally (editor add-gate that still
  permits same-list reordering + plain save-time reject; pre-launch decision, zero tabs
  blocks exist in any install). No legacy-overflow CSS. Enhanced mode owns panel
  visibility via marker-scoped rules; the runtime module itself is unbounded for
  non-Thallo custom markup.
- Mobile nav pinned rules (spec §3.2): in-flow expansion, JS = animation/Escape/
  outside-click/state-sync/one-open-sibling only, close on navigation and on crossing to
  desktop width, ≥44px touch targets, reduced-motion honored.
- Tests: `phpunit` (never grep-filter output; use `set -o pipefail … | tail`), phpcs
  PSR12 on touched PHP, admin `pnpm run type-check` + `npx vitest run` when SPA touched.
- Commit per task on `dev` (never push, no AI attribution). Do NOT commit
  `docs/DISTRIBUTION.md`, `CLAUDE.md`, or the spec/plan files; do not touch
  `config/extensions.php`'s runtime state.

---

### Task 1: Runtime core (`ThalloRuntime`)

**Files:**
- Create: `packages/thallo-render/runtime/runtime.js`
- Test: `tests/Integration/Render/RuntimeCoreTest.php`

**Interfaces:**
- Produces: `window.ThalloRuntime.register(name, {enhance, canvas})` and
  `window.ThalloRuntime.enhance(root)`; per-component marker attribute
  `data-thallo-enhanced` (space-separated module names). Later tasks append module
  IIFEs to this same file below the `/* modules:start */` marker.

- [ ] **Step 1: Write the failing test**

`tests/Integration/Render/RuntimeCoreTest.php` — mirror `ColorModeRuntimeTest`'s
node-harness pattern exactly (same `findNode()`, same temp-file exec, skip-not-fail
without node):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Executable coverage for the ThalloRuntime core (theme-runtime spec §2.1): registration,
 * component-level idempotency (root itself + descendants), duplicate-name rejection, and
 * the canvas skip/allow policy. Mirrors ColorModeRuntimeTest's Node + hand-stubbed-DOM
 * pattern; skips (not fails) without node but always asserts structural markers.
 */
final class RuntimeCoreTest extends AppTestCase
{
    private function runtimeJs(): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-render/runtime/runtime.js'
        );
    }

    private function findNode(): ?string
    {
        $env = getenv('THALLO_NODE_BIN');
        if (is_string($env) && $env !== '' && is_executable($env)) {
            return $env;
        }
        $which = trim((string) shell_exec('command -v node 2>/dev/null'));
        return $which !== '' ? $which : null;
    }

    public function testCoreRegistersEnhancesIdempotentlyAndHonorsCanvasPolicy(): void
    {
        $src = $this->runtimeJs();
        self::assertStringContainsString('window.ThalloRuntime', $src);
        self::assertStringContainsString('/* modules:start */', $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the runtime core');
        }

        $file = sys_get_temp_dir() . '/thallo_runtime_core_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($src));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "core harness failed:\n" . implode("\n", $out));
            self::assertStringContainsString('ALL_PASS', implode("\n", $out));
        } finally {
            @unlink($file);
        }
    }

    private function harness(string $src): string
    {
        $json = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        'use strict';
        function assert(c, m) { if (!c) { console.error('FAIL: ' + m); process.exit(1); } }

        // Minimal element: classList, matches/querySelectorAll by class token,
        // get/setAttribute — enough for the core's scan + marker logic.
        function el(cls) {
          var attrs = {};
          var node = {
            className: cls || '', children: [],
            appendChild: function (c) { c.parent = node; node.children.push(c); return c; },
            getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
            setAttribute: function (n, v) { attrs[n] = String(v); },
            matches: function (sel) { return sel === '.' + node.className; },
            querySelectorAll: function (sel) {
              var found = [];
              (function walk(n) {
                n.children.forEach(function (c) {
                  if (c.matches(sel)) found.push(c);
                  walk(c);
                });
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
          documentElement: el('html')
        };
        global.window = global;

        eval($json);
        var RT = window.ThalloRuntime;
        assert(RT && typeof RT.register === 'function' && typeof RT.enhance === 'function',
          'core API surface');

        // 1. enhance() visits root itself AND descendants; marks per component.
        var hits = [];
        RT.register('probe', { enhance: function (c) { hits.push(c); }, selector: '.widget' });
        var a = docRoot.appendChild(el('widget'));
        var b = docRoot.appendChild(el('widget'));
        RT.enhance(docRoot);
        assert(hits.length === 2, 'descendant discovery: ' + hits.length);
        RT.enhance(b); // b matches root itself but is already marked
        assert(hits.length === 2, 'component-level idempotency');
        var c = b.appendChild(el('widget'));
        RT.enhance(b); // root-as-boundary: new descendant under an enhanced root
        assert(hits.length === 3 && hits[2] === c, 'inserted-subtree enhancement');
        assert((a.getAttribute('data-thallo-enhanced') || '').indexOf('probe') !== -1,
          'marker attribute set');

        // 2. Duplicate registration throws.
        var threw = false;
        try { RT.register('probe', { enhance: function () {}, selector: '.x' }); }
        catch (e) { threw = true; }
        assert(threw, 'duplicate module registration must throw');

        // 3. Throw containment: a throwing module leaves its component UNMARKED and
        //    does not break other components or modules.
        global.console = { error: function () {}, log: console.log };
        var after = [];
        RT.register('thrower', { enhance: function () { throw new Error('boom'); },
          selector: '.widget' });
        RT.register('after', { enhance: function (x) { after.push(x); }, selector: '.widget' });
        RT.enhance(docRoot);
        assert((a.getAttribute('data-thallo-enhanced') || '').indexOf('thrower') === -1,
          'throwing module must not mark its component');
        assert(after.length === 3, 'modules after a throwing one must still run: ' + after.length);

        // 4. Canvas policy: skip modules no-op when .thallo-preview-block exists.
        docRoot.appendChild(el('thallo-preview-block'));
        var skipHits = 0, allowHits = 0;
        RT.register('skipper', { enhance: function () { skipHits++; }, selector: '.widget' });
        RT.register('allower', { enhance: function () { allowHits++; }, selector: '.widget',
          canvas: 'allow' });
        RT.enhance(docRoot);
        assert(skipHits === 0, 'canvas skip module ran in canvas stage');
        assert(allowHits === 3, 'canvas allow module must still run: ' + allowHits);

        console.log('ALL_PASS');
        JS;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Render/RuntimeCoreTest.php 2>&1 | tail -5`
Expected: FAIL (file_get_contents on missing runtime.js).

- [ ] **Step 3: Implement the core**

Create `packages/thallo-render/runtime/runtime.js`:

```js
/* Thallo theme runtime (theme-runtime spec §2) — package-owned behavioral JS for the
   default theme. Served fingerprinted at /_thallo/runtime/ (RuntimeAssetController);
   themes own presentation (CSS) only. Language floor: Baseline Widely Available, and
   this file must parse under Node >= 18 (the tests execute the served bytes).

   Core contract:
     ThalloRuntime.register(name, { enhance(component), selector, canvas })
       - name: unique; re-registering an existing name THROWS (silent replacement
         would hide behavior forks).
       - selector: what the module enhances; enhance() receives each matching
         component root exactly once (data-thallo-enhanced marker, per module).
       - canvas: 'skip' (default) — no-op when the canvas stage is present
         (.thallo-preview-block; injected DOM would break the canvas patch gate);
         'allow' — runs everywhere (color-mode only: it touches <html>, no block DOM).
     ThalloRuntime.enhance(root)
       - root is a SCAN BOUNDARY: the root itself (when it matches) plus matching
         descendants. Safe to call repeatedly and on inserted subtrees. */
(function () {
  'use strict';

  var modules = Object.create(null);
  var order = [];

  function isCanvas() {
    return !!document.querySelector('.thallo-preview-block');
  }

  function markerHas(elm, name) {
    var v = elm.getAttribute('data-thallo-enhanced');
    return v !== null && (' ' + v + ' ').indexOf(' ' + name + ' ') !== -1;
  }

  function mark(elm, name) {
    var v = elm.getAttribute('data-thallo-enhanced');
    elm.setAttribute('data-thallo-enhanced', v ? v + ' ' + name : name);
  }

  function componentsIn(root, selector) {
    var found = [];
    if (root.matches && root.matches(selector)) {
      found.push(root);
    }
    var all = root.querySelectorAll ? root.querySelectorAll(selector) : [];
    for (var i = 0; i < all.length; i++) {
      found.push(all[i]);
    }
    return found;
  }

  window.ThalloRuntime = {
    register: function (name, def) {
      if (modules[name]) {
        throw new Error('ThalloRuntime: module "' + name + '" is already registered');
      }
      modules[name] = {
        enhance: def.enhance,
        selector: def.selector,
        canvas: def.canvas === 'allow' ? 'allow' : 'skip'
      };
      order.push(name);
    },
    enhance: function (root) {
      var canvas = isCanvas();
      for (var i = 0; i < order.length; i++) {
        var name = order[i];
        var mod = modules[name];
        if (canvas && mod.canvas === 'skip') {
          continue;
        }
        var comps = componentsIn(root, mod.selector);
        for (var j = 0; j < comps.length; j++) {
          if (markerHas(comps[j], name)) {
            continue;
          }
          // A throwing module must not break the rest of the pass: the component stays
          // UNMARKED (its module made no completed enhancement) and every other
          // component and module still runs.
          try {
            mod.enhance(comps[j]);
            mark(comps[j], name);
          } catch (err) {
            if (window.console && console.error) {
              console.error('ThalloRuntime: module "' + name + '" failed', err);
            }
          }
        }
      }
    }
  };

  function boot() {
    window.ThalloRuntime.enhance(document.documentElement);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
/* modules:start */
```

Note: the boot call runs at load; modules registered below `/* modules:start */` in this
same file register BEFORE `DOMContentLoaded` fires (script is `defer`), so a single boot
pass covers them. Later tasks append module IIFEs after the marker; each module ALSO calls
`window.ThalloRuntime.enhance(document.documentElement)` is NOT needed — the shared boot
handles it. (When `readyState` is already interactive/complete — the defer case — `boot()`
runs synchronously at the end of the file evaluation only if placed after modules; to keep
one boot regardless of position, the core defers the non-loading case with
`Promise.resolve().then(boot)`.) **Implement exactly that**: replace the `else { boot(); }`
branch with `else { Promise.resolve().then(boot); }` so modules registered later in the
file are always included in the boot pass.

- [ ] **Step 4: Run the test to verify it passes**

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Render/RuntimeCoreTest.php 2>&1 | tail -4`
Expected: OK (1 test).

- [ ] **Step 5: Commit**

```bash
git add packages/thallo-render/runtime/runtime.js tests/Integration/Render/RuntimeCoreTest.php
git commit -m "feat(render): ThalloRuntime core - registered modules, idempotent enhance, canvas policy"
```

---

### Task 2: Fingerprinted delivery + `runtime_script()` + compatibility loader

**Files:**
- Create: `packages/thallo-render/src/Templates/RuntimeAssetMap.php`
- Create: `packages/thallo-render/src/Http/Controllers/RuntimeAssetController.php`
- Modify: `packages/thallo-render/routes/public-routes.php` (before the `/theme-assets` route)
- Modify: `packages/thallo-render/src/RenderContextExtension.php` (functions list ~line 158; new method)
- Modify: `packages/thallo-render/src/Templates/TemplatePolicy.php` (FUNCTIONS + CACHE_VERSION 11→12)
- Modify: `packages/thallo-render/src/RenderServiceProvider.php` (bind RuntimeAssetMap + controller; mirror how RenderController is registered)
- Modify: `packages/thallo-render/themes/default/templates/layout.twig:28-30` (script swap)
- Rewrite: `packages/thallo-render/themes/default/assets/blocks.js` (compatibility loader)
- Modify: `packages/thallo-render/src/Templates/ThemeCloner.php` (skip `blocks.js` when source is the pack default; amend its "never a partial copy" docblock pin)
- Test: `tests/Integration/Render/RuntimeDeliveryTest.php`

**Interfaces:**
- Consumes: Task 1's `runtime/runtime.js`.
- Produces: `RuntimeAssetMap::__construct(string $runtimeDir)`,
  `resolve(string $filename): ?string`, `fingerprintedName(string $logicalName): ?string`
  (identical shape to `ShopAssetMap`); route `GET /_thallo/runtime/{file}`; Twig
  `runtime_script(): string` returning `'/_thallo/runtime/runtime.js'` (the stable logical
  alias — spec §2.3 pins alias-in-layout).

- [ ] **Step 1: Write the failing tests**

`tests/Integration/Render/RuntimeDeliveryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Render\Templates\RuntimeAssetMap;
use Thallo\Render\Templates\TemplatePolicy;

final class RuntimeDeliveryTest extends AppTestCase
{
    private function map(): RuntimeAssetMap
    {
        return new RuntimeAssetMap(
            $this->appContext()->getBasePath() . '/packages/thallo-render/runtime'
        );
    }

    private function hit(string $path): \Symfony\Component\HttpFoundation\Response
    {
        return (new Application($this->appContext()))->handle(Request::create($path, 'GET'));
    }

    public function testMapFingerprintsTheRuntime(): void
    {
        $name = $this->map()->fingerprintedName('runtime.js');
        self::assertMatchesRegularExpression('/^runtime-[0-9a-f]{12}\.js$/', (string) $name);
        self::assertNull($this->map()->resolve('runtime.js'), 'logical name is not a file key');
        self::assertFileExists((string) $this->map()->resolve((string) $name));
    }

    public function testLogicalAliasRedirectsUncachedToCurrentFingerprint(): void
    {
        $res = $this->hit('/_thallo/runtime/runtime.js');
        self::assertSame(302, $res->getStatusCode());
        self::assertSame(
            '/_thallo/runtime/' . rawurlencode((string) $this->map()->fingerprintedName('runtime.js')),
            $res->headers->get('Location'),
        );
        self::assertStringNotContainsString(
            'immutable',
            (string) $res->headers->get('Cache-Control'),
            'the alias must never be immutable-cached',
        );
    }

    public function testExactFingerprintServesImmutableBytesAndStaleFourOhFours(): void
    {
        $current = (string) $this->map()->fingerprintedName('runtime.js');
        $ok = $this->hit('/_thallo/runtime/' . $current);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('public, max-age=31536000, immutable', $ok->headers->get('Cache-Control'));
        self::assertStringContainsString('window.ThalloRuntime', (string) $ok->getContent());

        self::assertSame(404, $this->hit('/_thallo/runtime/runtime-deadbeefdead.js')->getStatusCode());
        self::assertSame(404, $this->hit('/_thallo/runtime/..%2F..%2Fetc%2Fpasswd')->getStatusCode());
    }

    public function testPolicyAllowsRuntimeScriptAndBumpedCacheVersion(): void
    {
        self::assertContains('runtime_script', TemplatePolicy::FUNCTIONS);
        self::assertGreaterThanOrEqual(12, TemplatePolicy::CACHE_VERSION,
            'CACHE_VERSION must bump with the runtime_script allowlist addition');
    }

    public function testCompatibilityLoaderIsBehaviorFreeAndRequestsOnlyTheAlias(): void
    {
        $loader = (string) file_get_contents($this->appContext()->getBasePath()
            . '/packages/thallo-render/themes/default/assets/blocks.js');
        self::assertStringContainsString('/_thallo/runtime/runtime.js', $loader);
        self::assertStringNotContainsString('querySelectorAll', $loader);
        self::assertStringNotContainsString('addEventListener(\'click\'', $loader);
        self::assertLessThan(1200, strlen($loader), 'loader must stay tiny and behavior-free');
    }

    public function testLayoutLoadsRuntimeScriptNotThemeBlocksJs(): void
    {
        $layout = (string) file_get_contents($this->appContext()->getBasePath()
            . '/packages/thallo-render/themes/default/templates/layout.twig');
        self::assertStringContainsString('runtime_script()', $layout);
        self::assertStringNotContainsString("asset('blocks.js')", $layout);
    }
}
```

(Delete the stray first `namespace` line when creating the file — single namespace
`App\Tests\Integration\Render`.)

- [ ] **Step 2: Run to verify failure**

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Render/RuntimeDeliveryTest.php 2>&1 | tail -5`
Expected: FAIL (class RuntimeAssetMap not found).

- [ ] **Step 3: Implement delivery**

`RuntimeAssetMap.php` — copy `ShopAssetMap`'s implementation verbatim with these deltas:
namespace `Thallo\Render\Templates`; class docblock references this spec (§2.3) and
`RuntimeAssetController`; constructor scans the pack's `runtime/` dir (still `.js`/`.css`
extensions, same sha256-12 fingerprint, same sorted deterministic scan, same
exact-lookup-only security posture).

`RuntimeAssetController.php` — copy `ShopAssetController` verbatim with deltas: namespace
`Thallo\Render\Http\Controllers`; depends on `RuntimeAssetMap`; redirect prefix
`'/_thallo/runtime/'`; docblock cites theme-runtime spec §2.3.

Route (public-routes.php, before `/theme-assets`; same middleware set as the other
static-first-segment routes):

```php
// Theme runtime (theme-runtime spec §2.3): the package-owned behavior runtime,
// content-fingerprinted. `runtime.js` is the stable alias templates emit (302 to the
// current fingerprint, never cached); only the exact current fingerprint serves bytes
// (immutable). Static first segment wins over the '*' catch-all.
$router->get('/_thallo/runtime/{file}', [RuntimeAssetController::class, 'serve'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap']);
```

`RenderContextExtension` — add to `getFunctions()`:
`new TwigFunction('runtime_script', $this->runtimeScript(...)),` and the method:

```php
/** Stable logical URL of the package theme runtime (theme-runtime spec §2.3). */
public function runtimeScript(): string
{
    return '/_thallo/runtime/runtime.js';
}
```

`TemplatePolicy` — add `'runtime_script'` to `FUNCTIONS`; change `CACHE_VERSION` to `12`
and extend the bump comment: `// bumped: runtime_script joined the function allowlist
(theme-runtime spec §2.3)`.

`RenderServiceProvider::services()` — register both (mirror the pack's existing
autowire/factory idiom; RuntimeAssetMap needs a factory closure passing
`dirname(__DIR__, 2) . '/runtime'`... follow how the provider builds other path-derived
services; `RuntimeAssetController` autowires against the map):

```php
RuntimeAssetMap::class => [
    'factory' => [self::class, 'makeRuntimeAssetMap'],
    'shared'  => true,
],
RuntimeAssetController::class => [
    'class' => RuntimeAssetController::class, 'shared' => true, 'autowire' => true,
],
```

with

```php
public static function makeRuntimeAssetMap(): RuntimeAssetMap
{
    return new RuntimeAssetMap(dirname(__DIR__) . '/runtime');
}
```

(`dirname(__DIR__)` from `src/` = the pack root; verify against how the provider computes
`themes/` paths and match that idiom.)

`layout.twig` — replace lines 28–30:

```twig
  {# Package theme runtime (theme-runtime spec §2): behavior is package-owned and
     fingerprinted — themes ship presentation (CSS) only. Loaded ONCE here; canvas-aware
     (modules no-op in the canvas stage). #}
  <script defer src="{{ runtime_script() }}"></script>
```

`blocks.js` — replace the ENTIRE file with the compatibility loader:

```js
/* COMPATIBILITY LOADER ONLY (theme-runtime spec §2.2) — the behavioral runtime moved to
   the package-owned /_thallo/runtime/runtime.js (see packages/thallo-render/runtime/).
   This file exists for ONE compatibility release so already-cached default-layout HTML
   that still references asset('blocks.js') keeps working; it must contain NO behavior.
   Removal is tracked in spec §11.4. */
(function () {
  'use strict';
  if (window.ThalloRuntime) { return; } // new layout already loaded the runtime
  var s = document.createElement('script');
  s.defer = true;
  s.src = '/_thallo/runtime/runtime.js';
  document.head.appendChild(s);
})();
```

`ThemeCloner` — where it copies the source theme's `assets/` (read the class first), skip
the `blocks.js` entry **only when the clone source is the pack default theme**; amend the
class docblock's "never a partial copy" pin: `Exception (theme-runtime spec §2.4): the
pack default's blocks.js is a temporary compatibility loader, not theme content — cloning
the pack default excludes it; cloning any custom theme still copies byte-exactly.` Add to
the EXISTING ThemeCloner test class a case: clone the pack default → `blocks.js` absent in
the clone; clone a custom theme containing `blocks.js` → still copied.

- [ ] **Step 4: Run tests**

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Render/RuntimeDeliveryTest.php tests/Integration/Render 2>&1 | tail -4`
Expected: new test OK; existing Render suite green EXCEPT tests that assert
`asset('blocks.js')` in layout or behavioral blocks.js content — update those in this task
(they are part of this task's deliverable; find them with
`grep -rn "blocks.js" tests/Integration/Render`).

- [ ] **Step 5: phpcs + commit**

```bash
vendor/bin/phpcs --standard=PSR12 packages/thallo-render/src tests/Integration/Render/RuntimeDeliveryTest.php
git add -A packages/thallo-render tests/Integration/Render
git commit -m "feat(render): fingerprinted package runtime delivery + compatibility loader"
```

---

### Task 3: color-mode + forms modules

**Files:**
- Modify: `packages/thallo-render/runtime/runtime.js` (append after `/* modules:start */`)
- Modify: `tests/Integration/Render/ColorModeRuntimeTest.php` (read runtime.js instead of blocks.js)
- Create: `tests/Integration/Render/FormsRuntimeTest.php`

**Interfaces:**
- Consumes: `ThalloRuntime.register` (Task 1).
- Produces: modules `color-mode` (canvas allow) and `forms` (canvas skip, selector
  `form[data-thallo-form]`).

- [ ] **Step 1: Move color-mode verbatim.** Cut the `/* color-mode:start */ …
  /* color-mode:end */` IIFE out of the OLD blocks.js git history (`git show
  HEAD~2:packages/thallo-render/themes/default/assets/blocks.js` — Task 2 rewrote the
  file; the exact source is in history) and append it to `runtime.js` INSIDE a module
  registration, preserving the markers (ColorModeRuntimeTest extracts by marker):

```js
/* color-mode:start */
/* [keep the original comment block verbatim] */
window.ThalloRuntime.register('color-mode', {
  selector: 'html',
  canvas: 'allow',
  enhance: function () { /* no-op: this module is event-delegation based */ }
});
(function () {
  /* [the ENTIRE original color-mode IIFE body, byte-identical] */
})();
/* color-mode:end */
```

The original IIFE self-executes (delegated document click listener + mql listener +
`window.thalloColorMode`); the registration entry exists so the module participates in
the registry/duplicate-name contract. Keep the original hard gate line
(`if (root.dataset.colorModeEnabled !== 'true') return;`) untouched.

- [ ] **Step 2: Repoint + run ColorModeRuntimeTest**

Change its `blocksJs()` to read `packages/thallo-render/runtime/runtime.js` (rename the
method `runtimeJs()`).
Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Render/ColorModeRuntimeTest.php 2>&1 | tail -3`
Expected: OK — the runtime body is byte-identical.

- [ ] **Step 3: Write FormsRuntimeTest (failing)**

Mirror the RuntimeCoreTest harness. Stub: a `form[data-thallo-form]` element with
`addEventListener('submit')` capture, a `.thallo-block-form__result` child (with
`setAttribute`, `focus()` recorder, `classList`), a `.thallo-block-form__submit` child, a
mocked `window.fetch` returning a controllable promise. Assertions:

```text
1. submit handler registered via the module (dispatch synthetic submit with
   preventDefault spy — default prevented).
2. During fetch: form.getAttribute('aria-busy') === 'true'; submit disabled.
3. Failure JSON {ok:false,error:'Bad email'} → result box textContent 'Bad email',
   class --error, box.focus() called once, role='status' + aria-live='polite' set,
   aria-busy removed.
4. Success JSON {ok:true} → form.reset() called, class --ok, focus NOT called.
```

Write the four as sequential asserts in one harness (the ShopJsRuntimeTest style), each
`FAIL:`-labeled; end `console.log('ALL_PASS')`.

- [ ] **Step 4: Implement the forms module (append to runtime.js)**

```js
/* form-block:start — progressive enhancement for [data-thallo-form] (form-block spec §6
   + theme-runtime spec §6). No-JS baseline is a normal POST to /_forms/submit (PRG); with
   JS we intercept, POST via fetch, and render the result inline with focus + live
   announcement. */
window.ThalloRuntime.register('forms', {
  selector: 'form[data-thallo-form]',
  enhance: function (form) {
    var box = form.querySelector('.thallo-block-form__result');
    var btn = form.querySelector('.thallo-block-form__submit');
    if (box) {
      box.setAttribute('role', 'status');
      box.setAttribute('aria-live', 'polite');
      box.setAttribute('tabindex', '-1');
    }
    function setResult(message, ok) {
      if (!box) { return; }
      box.textContent = message;
      box.classList.remove('thallo-block-form__result--error', 'thallo-block-form__result--ok');
      box.classList.add(ok ? 'thallo-block-form__result--ok' : 'thallo-block-form__result--error');
      if (!ok) { box.focus(); }
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (btn) { btn.disabled = true; }
      form.setAttribute('aria-busy', 'true');
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' }
      }).then(function (res) {
        return res.json().catch(function () { return {}; });
      }).then(function (json) {
        if (json && json.ok) {
          form.reset();
          setResult(json.message || 'Thanks — your message has been sent.', true);
        } else {
          setResult((json && json.error) || 'Please check your entries and try again.', false);
        }
      }).catch(function () {
        setResult('Something went wrong. Please try again.', false);
      }).then(function () {
        if (btn) { btn.disabled = false; }
        form.removeAttribute('aria-busy');
      });
    });
  }
});
/* form-block:end */
```

- [ ] **Step 5: Run both tests + core test, commit**

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Render/RuntimeCoreTest.php tests/Integration/Render/ColorModeRuntimeTest.php tests/Integration/Render/FormsRuntimeTest.php 2>&1 | tail -4`

```bash
git add packages/thallo-render/runtime/runtime.js tests/Integration/Render
git commit -m "feat(render): color-mode + forms runtime modules (error focus, aria-busy)"
```

---

### Task 4: carousel module

**Files:**
- Modify: `packages/thallo-render/runtime/runtime.js` (append)
- Create: `tests/Integration/Render/CarouselRuntimeTest.php`

- [ ] **Step 1: Failing test.** RuntimeCoreTest-style harness; stub a
  `.thallo-block-carousel` with viewport/track/3 slides (offsetLeft numbers, `scrollTo`
  recorder, scroll/pointerdown listeners), `IntersectionObserver` + `document.hidden` +
  `visibilitychange` stubs, `setInterval/clearInterval` recorders, matchMedia
  reduced-motion=false. Assert:

```text
1. data-autoplay=1 → pause button injected (class thallo-block-carousel__pause,
   aria-pressed=false, label 'Pause slides'); status region injected with
   aria-live='off' while rotating.
2. IntersectionObserver callback (not intersecting) → interval cleared (auto pause);
   intersecting again → interval restarted (no user pause yet).
3. Pause click → aria-pressed=true, label 'Play slides', interval cleared; a later
   intersecting callback does NOT restart (userPaused sticky).
4. Play click after user pause → rotation restarts; status region aria-live becomes
   'polite' after the user interaction and stays polite.
5. pointerdown on viewport → autoplay stops and does not auto-resume; next/prev arrow
   click updates the status text 'Slide 2 of 3' (polite).
6. matchMedia reduced-motion=true harness variant: no interval ever created; pause
   button not injected.
```

- [ ] **Step 2: Implement.** Append to runtime.js a `carousel` module: registration
  `{ selector: '.thallo-block-carousel', enhance: enhanceCarousel }`. Start from the
  original enhance() (git history `HEAD~3:…/blocks.js` lines 17–95: slideStart/
  currentIndex/goTo/arrows/dots/throttle — reuse byte-near) and add, per spec §5:

```js
    var userPaused = false;
    var autoOffscreen = false, autoHidden = false;
    var live = null; // status region
    function announce(i) {
      if (!live) { return; }
      live.textContent = 'Slide ' + (i + 1) + ' of ' + slides.length;
    }
    function politeAfterUserAction() {
      if (live) { live.setAttribute('aria-live', 'polite'); }
    }
    function rotating() { return timer !== null; }
    function startAuto() {
      if (userPaused || autoOffscreen || autoHidden || reducedMotion || timer) { return; }
      timer = setInterval(function () { var n = currentIndex() + 1; goTo(n); announce(((n % slides.length) + slides.length) % slides.length); }, 5000);
      syncPause();
    }
    function stopAuto() { if (timer) { clearInterval(timer); timer = null; } syncPause(); }
```

with `data-autoplay === '1' && !reducedMotion` gating: inject the pause button
(`aria-pressed`, labels 'Pause slides'/'Play slides' switching with state via
`syncPause()`), inject the visually-hidden status region
(`<span class="thallo-block-carousel__status" aria-live="off">` — `blocks.css` gains a
`.thallo-block-carousel__status` clip rule in Task 9's CSS batch, include it here if
preferred but ONE of the two tasks must add it — this task adds it), wire
IntersectionObserver (`autoOffscreen` flag) + `visibilitychange` (`autoHidden` flag) both
calling `stopAuto()/startAuto()`, and make every user interaction
(pointerdown/keydown/wheel/touchstart, arrow/dot clicks) set `userPaused = true`,
`stopAuto()`, `politeAfterUserAction()`. Pause button click toggles `userPaused` and
calls `politeAfterUserAction()`; Play only starts when the automatic gates allow
(`startAuto()` re-checks all flags per spec §5).

- [ ] **Step 3: Run + commit**

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Render/CarouselRuntimeTest.php tests/Integration/Render/RuntimeCoreTest.php 2>&1 | tail -4`

```bash
git add packages/thallo-render/runtime/runtime.js packages/thallo-render/themes/default/assets/blocks.css tests/Integration/Render/CarouselRuntimeTest.php
git commit -m "feat(render): carousel runtime module - pause control, visibility pause, status region"
```

---

### Task 5: navigation template unification + shell a11y markup

**Files:**
- Modify: `packages/thallo-render/themes/default/templates/blocks/navigation.twig`
- Modify: `packages/thallo-render/themes/default/templates/layout.twig`
- Modify: `packages/thallo-render/themes/default/assets/navigation.css`
- Modify: `packages/thallo-render/themes/default/assets/site.css` (skip link + visually-hidden)
- Modify: the navigation starter block definition (find it:
  `grep -rn "'navigation'" app/Content/Blocks/StarterBlockTypes.php`) — add optional
  `aria_label` string field (default label "Navigation")
- Test: extend the existing navigation render tests
  (`grep -rln "thallo-block-navigation" tests/Integration/Render` — likely
  `BlockLibraryRenderTest` / a navigation-specific test) + starter-sync fingerprint note

**Interfaces:**
- Produces: unified markup later consumed by Task 6's module — EVERY parent item is
  `<details class="thallo-block-navigation__details" name="nav-{{ block.id }}">` with
  `<summary class="thallo-block-navigation__link" data-nav-toggle>`; the whole list is
  wrapped in `<details class="thallo-block-navigation__mobile"
  data-thallo-enhance="navigation">` with `<summary
  class="thallo-block-navigation__hamburger">Menu</summary>`.

- [ ] **Step 1: Failing template tests.** In the existing navigation render test class
  add assertions (against rendered HTML of a menu with one hover-mode parent):

```text
1. No aria-haspopup anywhere; no <a … data-nav-toggle> and no <span … data-nav-toggle>
   — every data-nav-toggle is a <summary>.
2. Hover-mode parent renders <details … name="nav-{id}"> and its panel contains the
   parent's own URL as the first sublink (repeatParent now true for BOTH modes).
3. Root: <nav … aria-label="…"> present (block data aria_label, default 'Navigation').
4. Outer <details class="thallo-block-navigation__mobile"> wraps the list;
   its <summary> has visible text 'Menu'.
5. Active item: <a … aria-current="page"> when current_path matches (extend the
   existing --active class assertion).
6. layout.twig render: skip link '<a class="skip-link" href="#main">' is the FIRST
   body element, <main id="main" tabindex="-1">, fallback <nav … aria-label="Main
   navigation">, fallback nav wrapped in the same details hamburger pattern.
```

- [ ] **Step 2: Rewrite navigation.twig.** Concrete deltas to the current file
  (lines cited from the pre-change file):

  - `sublink` macro (line 9–11): add `{% if current_path is defined and p == current_path %} aria-current="page"{% endif %}` to the `<a>`.
  - `panel` macro (line 19): drop the `repeatParent` parameter special-casing — ALWAYS
    prepend the parent link when `item.url` is non-empty (line 41 condition loses
    `repeatParent and`); delete the parameter from both call sites and the macro
    signature (unified tree: the summary swallows the URL in every mode).
  - Root (line 67–69): `<nav class="thallo-block-navigation__nav"
    aria-label="{{ data.aria_label|default('Navigation') }}">`.
  - Wrap the `<ul class="thallo-block-navigation__list">` (line 70) in:

```twig
      <details class="thallo-block-navigation__mobile" data-thallo-enhance="navigation">
        <summary class="thallo-block-navigation__hamburger"><span class="thallo-block-navigation__hamburger-icon" aria-hidden="true"></span>Menu</summary>
        <ul class="thallo-block-navigation__list">
          …items…
        </ul>
      </details>
```

  - Parent items: DELETE the `reveal == 'click'`/hover branch split (lines 79–97).
    ONE branch for `hasKids` (keep the `--parent` and `--active` classes and the
    existing details/summary markup of the click branch, minus `aria-haspopup`):

```twig
          {% else %}
            <li class="thallo-block-navigation__item thallo-block-navigation__item--parent{% if active %} thallo-block-navigation__item--active{% endif %}">
              <details class="thallo-block-navigation__details" name="nav-{{ block.id }}">
                <summary class="thallo-block-navigation__link" data-nav-toggle>{% if item.icon|default('') %}<span class="thallo-block-navigation__icon">{{ icon(item.icon) }}</span>{% endif %}<span class="thallo-block-navigation__label">{{ item.label }}</span>{% if subicon != 'none' %}<span class="thallo-block-navigation__chevron">{{ icon(subicon) }}</span>{% endif %}</summary>
                {{ nav.panel(item, sublayout, current_path) }}
              </details>
            </li>
          {% endif %}
```

    The `--reveal-hover`/`--reveal-click` root modifier (line 65) STAYS — CSS keys hover
    styling and the runtime keys hover-intent off it.
  - Leaf items (line 76–78): add `{% if active %} aria-current="page"{% endif %}` to the `<a>`.

- [ ] **Step 3: layout.twig shell.** After `<body>` (line 32), FIRST element:
  `<a class="skip-link" href="#main">Skip to content</a>`. `<main class="{{ layoutClass }}">`
  → `<main id="main" tabindex="-1" class="{{ layoutClass }}">`. Fallback nav (lines
  114–118) becomes:

```twig
      <nav class="site-nav" aria-label="Main navigation">
        <details class="site-nav__mobile thallo-block-navigation__mobile" data-thallo-enhance="navigation">
          <summary class="thallo-block-navigation__hamburger"><span class="thallo-block-navigation__hamburger-icon" aria-hidden="true"></span>Menu</summary>
          <ul class="site-nav__list">
            {% for item in menu('main') %}
              {% set p = '/' ~ (item.url|default('')|split('://')|last|split('/', 2)|last) %}
              <li><a href="{{ item.url }}"{% if current_path is defined and p == current_path %} aria-current="page"{% endif %}>{{ item.label }}</a></li>
            {% endfor %}
          </ul>
        </details>
      </nav>
```

- [ ] **Step 4: CSS.** `site.css` gains:

```css
/* Skip link (theme-runtime spec §7): visually hidden until keyboard focus. */
.skip-link {
  position: absolute; left: var(--space-3); top: var(--space-3); z-index: 100;
  padding: var(--space-2) var(--space-3); background: var(--surface); color: var(--fg);
  border-radius: 0.375rem; transform: translateY(-200%);
}
.skip-link:focus { transform: none; }
```

and, in `navigation.css`, a visible focus state for the disclosure controls (spec §3.2
pins visible focus):

```css
.thallo-block-navigation__hamburger:focus-visible,
.thallo-block-navigation__details > summary:focus-visible {
  outline: 2px solid var(--accent); outline-offset: 2px;
}
```

`navigation.css` — three additions (each commented `/* 48rem: the navigation
component's named v1 breakpoint (theme-runtime spec §3.2) */`):

```css
/* Mobile disclosure stack — 48rem: the navigation component's named v1 breakpoint. */
.thallo-block-navigation__hamburger {
  display: none; cursor: pointer; list-style: none;
  min-height: 44px; align-items: center; gap: var(--space-2);
  padding: var(--space-2) var(--space-3);
}
.thallo-block-navigation__hamburger::-webkit-details-marker { display: none; }
@media (max-width: 48rem) {
  .thallo-block-navigation__hamburger { display: flex; }
  .thallo-block-navigation__mobile:not([open]) > .thallo-block-navigation__list,
  .site-nav__mobile:not([open]) > .site-nav__list { display: none; }
  .thallo-block-navigation__list { flex-direction: column; align-items: stretch; }
  .thallo-block-navigation__submenu,
  .thallo-block-navigation__submenu--columns {
    position: static; min-width: 0; box-shadow: none; border: 0;
  }
  .thallo-block-navigation__details > summary { min-height: 44px; }
}
@media (min-width: 48.01rem) {
  /* Desktop: the outer mobile details is chrome-less — list always visible. */
  .thallo-block-navigation__mobile > .thallo-block-navigation__list,
  .site-nav__mobile > .site-nav__list { display: flex; }
  /* Hover mode: reveal on hover/focus-within even while the details is closed. */
  .thallo-block-navigation--reveal-hover
    .thallo-block-navigation__item--parent:hover > .thallo-block-navigation__details > [data-nav-panel],
  .thallo-block-navigation--reveal-hover
    .thallo-block-navigation__item--parent:focus-within > .thallo-block-navigation__details > [data-nav-panel] {
    display: block;
  }
}
```

and the megamenu clamp (line ~160): `min-width: min(28rem, calc(100vw - 2 * var(--space-4)));`.

Read the existing open-state selectors first (`grep -n "is-open\|details\[open\]"
navigation.css`) and keep them working: submenu display selectors must key off
`details[open] > [data-nav-panel]` (native) plus the hover rules above. Reduced-motion:
no new animations in CSS (the runtime adds animation; CSS adds none).

- [ ] **Step 5: Starter schema.** In the navigation block definition add
  `['name' => 'aria_label', 'type' => 'string', 'label' => 'Navigation label (assistive)']`
  (follow the exact field-array shape of the neighboring fields in that definition; NOT
  required, NOT localized). Note in the commit body: the block-type fingerprint changes —
  provisioned installs pick it up via starter sync as `updated`.

- [ ] **Step 6: Run template tests, fix fallout, commit.** The render suite has many
  navigation assertions; run the whole Render + Content integration dirs, update tests
  that assert the OLD hover-mode `<a data-nav-toggle>` markup (they are asserting removed
  markup — rewrite to the details form).

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Render tests/Integration/Content 2>&1 | tail -4`

```bash
git add -A packages/thallo-render app/Content tests
git commit -m "feat(render): unified details navigation + mobile disclosure + shell a11y markup"
```

---

### Task 6: navigation runtime module

**Files:**
- Modify: `packages/thallo-render/runtime/runtime.js` (append)
- Create: `tests/Integration/Render/NavigationRuntimeTest.php`

**Interfaces:**
- Consumes: Task 5 markup (`.thallo-block-navigation` root modifiers,
  `__details`/`__mobile` details, `data-nav-toggle` summaries, `[data-nav-panel]`).

- [ ] **Step 1: Failing test.** Harness stubs `<details>` semantics (an `open` boolean +
  `toggle` listeners), matchMedia for `(max-width: 48rem)` with controllable `matches` +
  change dispatch, and a nav tree: root (`reveal-hover` variant), mobile outer details,
  two parent details + links. Assert:

```text
1. Root gains class thallo-block-navigation--js after enhance.
2. Opening parent A then parent B → A.open === false (one-open-sibling enforced by JS,
   not relying on details name=).
3. Escape inside an open parent closes it and focuses its summary.
4. ArrowDown on a summary opens and focuses the first sublink.
5. Hover mode (reveal-hover root, desktop matches=false→mobile? no: matches=false =
   desktop): mouseenter opens after hover-intent, mouseleave closes after 180ms delay
   (stub timers with recorded setTimeout).
6. Clicking a sublink (synthetic click on <a> inside the open mobile details) closes the
   outer mobile details (mobile viewport only — on desktop the outer details stays open).
7. matchMedia change to desktop (matches false) OPENS the outer mobile details; change
   to mobile (matches true) closes it; enhance() at desktop width opens it immediately.
   (Task-5 discovery: the desktop CSS re-exposure of the closed-details list rides
   ::details-content, which is newer than the Baseline floor — per spec §2.1 the runtime
   guarantees the equivalent behavior, and it does so by keeping the outer details OPEN
   on desktop, where its chrome is hidden anyway. The pinned "menu closes on crossing to
   desktop" keeps its visual intent: the drawer chrome only exists below 48rem.)
8. Reduced-motion matchMedia true → no animation calls (the animate helper is skipped;
   assert element.animate never called).
```

- [ ] **Step 2: Implement.** Append the `navigation` module: selector
  `.thallo-block-navigation__mobile, .thallo-block-navigation` — register with selector
  `'.thallo-block-navigation'` and treat the fallback (`.site-nav__mobile`) by ALSO
  registering selector match via a second registered name? No — ONE module, selector
  `'[data-thallo-enhance="navigation"]'` (both the block's mobile details and the
  fallback's carry it; the block ROOT enhancements key off `closest('.thallo-block-navigation')`
  when present). Behavior:

```js
window.ThalloRuntime.register('navigation', {
  selector: '[data-thallo-enhance="navigation"]',
  enhance: function (mobile) {
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var mq = window.matchMedia('(max-width: 48rem)');
    var root = (mobile.closest && mobile.closest('.thallo-block-navigation')) || mobile;
    if (root.classList) { root.classList.add('thallo-block-navigation--js'); }
    var revealHover = root.classList && root.classList.contains('thallo-block-navigation--reveal-hover');
    var parents = mobile.querySelectorAll('.thallo-block-navigation__details');

    function closeOthers(except) {
      for (var i = 0; i < parents.length; i++) {
        var d = parents[i];
        if (d !== except && !d.contains(except) && d.open) { d.open = false; }
      }
    }
    function animateOpen(panel) {
      if (reduced || !panel || !panel.animate) { return; }
      panel.animate([{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'none' }],
        { duration: 120, easing: 'ease-out' });
    }

    for (var i = 0; i < parents.length; i++) {
      (function (d) {
        var summary = d.querySelector('[data-nav-toggle]');
        var closeTimer = null;
        d.addEventListener('toggle', function () {
          if (d.open) { closeOthers(d); animateOpen(d.querySelector('[data-nav-panel]')); }
        });
        if (summary) {
          summary.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
              e.preventDefault();
              d.open = true;
              var f = d.querySelector('.thallo-block-navigation__sublink, .thallo-block-navigation__col-title');
              if (f) { f.focus(); }
            } else if (e.key === 'Escape' && d.open) {
              d.open = false; summary.focus();
            }
          });
        }
        d.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && d.open) {
            d.open = false;
            if (summary) { summary.focus(); }
          }
        });
        if (revealHover && !mq.matches) {
          d.parentNode.addEventListener('mouseenter', function () {
            if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
            d.open = true;
          });
          d.parentNode.addEventListener('mouseleave', function () {
            closeTimer = setTimeout(function () { d.open = false; }, 180);
          });
        }
      })(parents[i]);
    }

    // Outside-click closes any open submenu; a real navigation click closes the mobile menu.
    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!mobile.contains(t)) { closeOthers(null); return; }
      if (t.closest && t.closest('a[href]')) { mobile.open = false; }
    });
    // Crossing to desktop closes the mobile menu.
    mq.addEventListener('change', function (e) {
      if (!e.matches) { mobile.open = false; }
    });
  }
});
```

(Adjust to the stubs while implementing — e.g. `closest` guards — but keep every listed
behavior; keyboard Enter/Space need NO handler: `<summary>` is native.)

- [ ] **Step 3: Run + correct the stale comment.** The old blocks.js comment block about
  Enter/Space lived in the deleted code; ensure the module's comment documents the REAL
  keys (Enter/Space native on summary, ArrowDown, Escape).

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Render/NavigationRuntimeTest.php tests/Integration/Render/RuntimeCoreTest.php 2>&1 | tail -4`

```bash
git add packages/thallo-render/runtime/runtime.js tests/Integration/Render/NavigationRuntimeTest.php
git commit -m "feat(render): navigation runtime module - disclosure pattern, mobile close rules"
```

---

### Task 7: tabs floor — template, CSS overflow, save-time cap, editor cap

**Files:**
- Modify: `packages/thallo-render/themes/default/templates/blocks/tabs.twig`
- Modify: `packages/thallo-render/themes/default/assets/blocks.css` (tabs section, ~line 448)
- Modify: `app/Content/Validation/FieldValidator.php` (`validateBlocks`, per-block loop ~line 400)
- Modify: `admin/src/fields/components/BlocksField.vue` (`pickerTypesForList` + `insertAfter` + `moveBlockTo`/`duplicateBlock` guards)
- Test: `tests/Unit/Content/Validation/` (find the FieldValidator test class and extend);
  existing tabs render test (grep `thallo-block-tabs` in tests/); new SPA specs in the
  BlocksField spec file (grep `BlocksField` in `admin/src/__tests__/`)

- [ ] **Step 1: Failing tests.**
  - Template test: rendered tabs block contains NO `role=` attribute at all. Assert the
    enhanced-mode CSS scoping exists in `blocks.css` as strings (both
    `[data-thallo-enhanced~="tabs"]` panel rules) so the runtime's ownership of panel
    visibility can't be silently dropped.
  - Validator test (extend the FieldValidator test class):

```php
public function testTabsBlockRejectsAThirteenthItemOnSave(): void
{
    // Build a tabs block whose data.items holds 13 minimal child blocks; expect the
    // error path "<field>.0.items" => "tabs supports at most 12 items".
    // Mirror the class's existing block-fixture helpers for schema + payload shape.
}

public function testTabsBlockAcceptsTwelveItems(): void { /* 12 passes clean */ }
```

  - SPA spec: with a tabs block containing 12 items, `pickerTypesFor(<a child id>)`
    returns `[]`; with 11 it returns the normal allowlist; `insertAfter` into a full tabs
    list returns null and does not mutate the tree.

- [ ] **Step 2: Template.** `tabs.twig` line 11: delete ` role="tablist"` from the list
  div. Update the header comment: authoring cap 12 (server-enforced), no ARIA in the
  floor (the runtime adds real tab semantics), overflow floor documented (13+ legacy:
  labels/radios hidden, panels stacked visible).

- [ ] **Step 3: CSS.** In the tabs section of `blocks.css`: extend the enumerated
  checked-sibling rules from 8 to 12 (four more lines in each of the two enumerated
  chains — label-active and panel-visible), update the section comment to
  `(authoring cap: 12 — theme-runtime spec §4)`, and append:

```css
/* Enhanced mode (theme-runtime spec §4): once the tabs module marks the root, the
   runtime owns panel visibility via the hidden attribute; the floor's display:none base
   + enumerated checked-pairing stand down and must not fight it. */
.thallo-block-tabs[data-thallo-enhanced~="tabs"] .thallo-block-tabs__panel { display: block; }
.thallo-block-tabs[data-thallo-enhanced~="tabs"] .thallo-block-tabs__panel[hidden] { display: none; }
```

The EXISTING base rule `.thallo-block-tabs__panel { display: none; }` and the enumerated
checked-pairing chains stay un-scoped (they are the floor); the two enhanced-mode rules
above appear AFTER them so the marker-scoped selectors win by specificity + order. No
overflow (n+13) rules exist: content over the 12 cap cannot exist (Step 4's pre-launch
decision), and the runtime stays unbounded for non-Thallo custom markup regardless.

- [ ] **Step 4: Save-time cap — plain and unconditional.** Thallo is pre-launch with no
  live content, and a scan of every dev draft found ZERO tabs blocks (spec §4 records
  this decision) — no grandfathering machinery. In `FieldValidator::validateBlocks()`,
  inside the per-block loop directly after the unknown-type rejection (the point where
  `$type` is a known slug, ~line 405):

```php
            // Tabs authoring cap (theme-runtime spec §4): the no-JS floor's enumerated
            // CSS pairs at most 12 items, and content over the cap cannot exist —
            // pre-launch decision, zero tabs blocks in any install when this landed —
            // so the check is unconditional, no grandfather path.
            if ($type === 'tabs') {
                $items = $block['data']['items'] ?? null;
                if (is_array($items) && array_is_list($items) && count($items) > 12) {
                    $errors["{$path}.items"] = 'tabs supports at most 12 items';
                    continue;
                }
            }
```

  Validator tests: 12 items pass clean; 13 reject with error path `<field>.<i>.items`
  and message `tabs supports at most 12 items`; a nested tabs block (tabs inside a
  section's blocks field) rejects with the full dot path.

- [ ] **Step 5: Editor cap.** In `BlocksField.vue` add one helper below
  `pickerTypesForList` and thread it:

```ts
/** Tabs authoring cap (theme-runtime spec §4): a full tabs items list accepts nothing. */
function listIsFull(parentId: string | null, region: string | null): boolean {
  if (parentId === null || region !== 'items') return false
  const parent = ops.findById(model.value ?? [], parentId)
  if (!parent || parent.type !== 'tabs') return false
  return (parent.regions?.items?.length ?? regionItems(parent, 'items').length) >= 12
}
```

(Resolve the actual child-list accessor from `createBlockListOps` — read
`admin/src/fields/…blockListOps` for how a region's children are read; use that exact
accessor, not the guess above.) The guard gates ADDITIONS, never rearrangement:

- `pickerTypesForList` returns `[]` when `listIsFull(parentId, region)` — nothing new
  can be inserted into a full tabs list.
- `insertAfter` and `duplicateBlock` return `null`/no-op when their DESTINATION list is
  full (both add a net item).
- `moveBlockTo` and `onDragEnd` apply the guard ONLY when the move's source list differs
  from its destination list (a cross-list move adds an item to the destination).
  Reordering WITHIN a full tabs list — same parentId + same region for source and
  destination — is always allowed: the net count is unchanged, and blocking it would
  freeze a full list's order. The SPA spec asserts both: cross-list move into a full
  tabs list no-ops; same-list reorder of a full tabs list succeeds.

- [ ] **Step 6: Run everything touched, commit.**

Run: `set -o pipefail && vendor/bin/phpunit tests/Unit/Content tests/Integration/Render tests/Integration/Content 2>&1 | tail -4`
Run: `cd admin && pnpm run type-check && npx vitest run src/__tests__/<blocksfield spec> 2>&1 | tail -4`

```bash
git add -A packages/thallo-render/themes app/Content admin/src tests
git commit -m "feat(content): tabs honest floor - no false ARIA, 12-item cap, overflow floor"
```

---

### Task 8: tabs runtime module

**Files:**
- Modify: `packages/thallo-render/runtime/runtime.js` (append)
- Create: `tests/Integration/Render/TabsRuntimeTest.php`

- [ ] **Step 1: Failing test.** Harness stubs a tabs component (3 radios + list + 3
  labels + panels container + 3 panels; radios have `checked`, labels have
  `htmlFor`/click, elements support get/setAttribute + focus recorders + keydown
  listeners). Assert:

```text
1. After enhance: list role=tablist; each label role=tab + aria-selected +
   aria-controls=<panel id>; each panel role=tabpanel + aria-labelledby + tabindex=-1;
   panel ids generated stable (tabs-<blockid>-panel-N pattern from existing input ids).
2. Radios: hidden attribute set, tabindex=-1, aria-hidden=true — only AFTER all roles
   are in place. Fail-safe variant harness (a panel stub whose setAttribute throws
   mid-build, in the LISTENER phase variant a label whose addEventListener throws):
   after the failed enhance, the component carries ZERO enhancement residue — no
   role/aria-selected/aria-controls/aria-labelledby anywhere in it, no tabindex
   mutations on labels or panels, radios NOT hidden and still focusable, NET-ZERO
   event listeners (the stub elements record addEventListener/removeEventListener
   pairs; assert every attachment was removed), and the component is NOT marked
   data-thallo-enhanced~="tabs" (core throw containment) — i.e. the honest radio floor
   is fully intact in both markup AND behavior, not a half-wired tablist.
3. Preselected radio 2 (checked server-side) → label 2 aria-selected=true,
   tabindex=0; others tabindex=-1; panel 2 not hidden, others hidden.
4. ArrowRight from tab 1 → tab 2 focused AND selected (automatic activation), radio 2
   checked (sync), change event dispatched on radio.
5. Home/End jump to first/last. ArrowLeft wraps from first to last.
6. Clicking label 3 selects tab 3 (default label activation prevented; module drives
   radio.checked + panels).
7. A 13-label component: all 13 become tabs and all 13 reachable via End (unbounded
   enhanced path).
```

- [ ] **Step 2: Implement.** Append the `tabs` module (selector
  `'.thallo-block-tabs'`): collect radios/labels/panels by the structural classes;
  build ALL ARIA first into a staged array of mutations, apply, then hide radios
  (`hidden`, `tabindex="-1"`, `aria-hidden="true"`); wire `keydown` on the list
  (ArrowLeft/ArrowRight/Home/End with wrap → `select(i)` = focus label i, roving
  tabindex, set `aria-selected`, check radio i + dispatch `change`, toggle `hidden` on
  panels); label `click` → `preventDefault()` + `select(i)`. Wrap the whole enhance in
  try/catch with THREE ordered phases and a full undo log: (1) apply ARIA/tabindex/id
  attributes, recording each mutation (element + attribute + prior value); (2) attach
  event listeners (list keydown, label clicks), recording each (element + type +
  handler); (3) hide the radios — last. On ANY throw, replay the undo log in reverse:
  `removeEventListener` for every recorded attachment and restore every recorded
  attribute, so the component carries ZERO enhancement residue — no roles, no aria-*,
  no tabindex changes, no live listeners, radios un-hidden — then RETHROW so the core's
  per-component containment leaves the component unmarked (spec §4 fail-safe: a failed
  enhancement leaves the honest radio floor byte- and behavior-intact).

- [ ] **Step 3: Run + commit.**

Run: `set -o pipefail && vendor/bin/phpunit tests/Integration/Render/TabsRuntimeTest.php tests/Integration/Render/RuntimeCoreTest.php 2>&1 | tail -4`

```bash
git add packages/thallo-render/runtime/runtime.js tests/Integration/Render/TabsRuntimeTest.php
git commit -m "feat(render): tabs runtime module - real ARIA tabs over the radio floor"
```

---

### Task 9: coexistence proof + full-suite sweep + docs

**Files:**
- Create: `tests/Integration/Render/RuntimeShopCoexistenceTest.php`
- Modify: `CHANGELOG.md` ([Unreleased] Added)
- Modify: `packages/thallo-render/README.md` if present (runtime section + custom-theme
  migration note per spec §2.4); otherwise add the note to the theme docs location the
  pack uses (`grep -rn "custom theme" packages/thallo-render --include="*.md"`)

- [ ] **Step 1: Coexistence test.** Mirror `ShopJsRuntimeTest`'s harness. Load BOTH
  served byte-sources — `packages/thallo-render/runtime/runtime.js` and
  `packages/thallo-commerce/assets/shop.js` — into ONE stub document containing (a) a
  `form[data-thallo-form]` with a result box and (b) a shop cart form matching shop.js's
  own selectors (copy the minimal form stub from ShopJsRuntimeTest). Assert: neither
  eval throws; the thallo form's submit is intercepted by the runtime (aria-busy set);
  the shop form's submit is intercepted by shop.js (its idempotency-key fetch fires);
  NEITHER script attached a listener to the other's form (listener-count recorders per
  element).

- [ ] **Step 2: Full gates.**

Run: `set -o pipefail && vendor/bin/phpunit 2>&1 | tail -4` — full suite green.
Run: `vendor/bin/phpcs --standard=PSR12 <all touched PHP>` — clean.
Run: `cd admin && pnpm run type-check && pnpm run lint && npx vitest run 2>&1 | tail -4` — green.

- [ ] **Step 3: CHANGELOG.** `[Unreleased]` → `### Added` bullet: package-owned
  fingerprinted theme runtime (`/_thallo/runtime/`, ShopAsset-pattern delivery, themes
  keep CSS only, blocks.js now a temporary compatibility loader) + accessibility refresh
  (skip link/main target/labeled navs/aria-current, unified details navigation with a
  no-JS mobile disclosure stack at 48rem, honest-floor ARIA tabs with a 12-item
  authoring cap and legacy overflow floor, carousel pause/visibility/status controls,
  form error focus + aria-busy). Note the navigation starter block's new `aria_label`
  field and the block-type fingerprint update via starter sync.

- [ ] **Step 4: Commit.**

```bash
git add tests/Integration/Render/RuntimeShopCoexistenceTest.php CHANGELOG.md packages/thallo-render
git commit -m "test(render): runtime/shop.js coexistence proof + changelog"
```

---

## Execution notes

- Tasks 1→2→3/4 are strictly ordered; Task 5 must precede 6; Task 7 precedes 8; Task 9
  last. 3 and 4 are independent of each other and of 5–8.
- The old behavioral `blocks.js` source needed by Tasks 3/4 lives in git history after
  Task 2 rewrites the file: `git show <task-1-commit>:packages/thallo-render/themes/default/assets/blocks.js`.
- Every Node-harness test must skip-not-fail without node while still asserting a
  structural marker (the house pattern).
- Dev workstation after Task 5: run `php glueful thallo:tenant:sync --all --kind=block_type`
  once so the provisioned navigation block type picks up `aria_label` (reported
  `updated`); do NOT run any other sync kinds.
