<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Executable coverage for block-animated-text.js (modern-blocks spec §3): lazy reveal +
 * finite one-cycle word rotation, loaded via block_script('animated-text'). Mirrors
 * RuntimeElementsBridgeTest's Node harness skeleton (eval runtime.js THEN the asset
 * bytes), but each scenario gets its OWN fresh `vm` context: the asset's module name
 * ('animated-text') is a hardcoded literal, not test-parameterizable like the probe
 * modules in the runtime-core/elements harnesses, so most scenarios need an isolated
 * module registry rather than sharing one across the whole script. The two scenarios
 * that are explicitly ABOUT shared/repeated state (double-eval, missing-runtime retry)
 * deliberately reuse one context on purpose.
 */
final class AnimatedTextAssetTest extends AppTestCase
{
    private function runtimeJs(): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-render/runtime/runtime.js'
        );
    }

    private function assetJs(): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-render/runtime/block-animated-text.js'
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

    public function testLazyRevealAndFiniteRotation(): void
    {
        $runtimeSrc = $this->runtimeJs();
        $assetSrc = $this->assetJs();

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the animated-text asset');
        }

        $file = sys_get_temp_dir() . '/thallo_animated_text_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($runtimeSrc, $assetSrc));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "animated-text asset harness failed:\n" . implode("\n", $out));
            self::assertStringContainsString('ALL_PASS', implode("\n", $out));
        } finally {
            @unlink($file);
        }
    }

    private function harness(string $runtimeSrc, string $assetSrc): string
    {
        $runtimeJson = json_encode($runtimeSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $assetJson = json_encode($assetSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        'use strict';
        import { createContext, runInContext } from 'node:vm';

        var RUNTIME_SRC = {$runtimeJson};
        var ASSET_SRC = {$assetJson};

        function assert(c, m) { if (!c) { console.error('FAIL: ' + m); process.exit(1); } }
        function flush() { return new Promise(function (r) { setTimeout(r, 0); }); }

        // Minimal element stub (mirrors RuntimeElementsBridgeTest's el()): classList,
        // matches/querySelectorAll by class token, get/setAttribute.
        function el(cls) {
          var attrs = {};
          var classes = (cls || '').split(' ').filter(Boolean);
          var node = {
            children: [], parent: null,
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

        // The animated_text markup contract (Task 4): root > __rotate > N x __word,
        // the first word already carrying --active (the static-floor server render).
        function buildBlock(n) {
          var root = el('thallo-block-animated_text');
          var stack = el('thallo-block-animated_text__rotate');
          root.appendChild(stack);
          var words = [];
          for (var i = 0; i < n; i++) {
            var cls = 'thallo-block-animated_text__word' + (i === 0 ? ' thallo-block-animated_text__word--active' : '');
            var w = el(cls);
            stack.appendChild(w);
            words.push(w);
          }
          return { root: root, words: words };
        }

        function activeIndexOf(block) {
          for (var i = 0; i < block.words.length; i++) {
            if (block.words[i].classList.contains('thallo-block-animated_text__word--active')) { return i; }
          }
          return -1;
        }

        function findListener(ctx, type) {
          for (var i = 0; i < ctx.__docListeners.length; i++) {
            if (ctx.__docListeners[i].type === type) { return ctx.__docListeners[i].fn; }
          }
          return null;
        }

        // A fresh realm: its own ThalloRuntime module registry, its own guard globals,
        // its own document/timers/IntersectionObserver stubs. reduced controls
        // matchMedia('(prefers-reduced-motion: reduce)').matches.
        function makeSandbox(reduced) {
          var ctx = {};
          ctx.window = ctx;
          ctx.console = { error: function () {}, log: function () {} };

          ctx.matchMedia = function (q) {
            return { matches: !!reduced, media: q, addEventListener: function () {}, removeEventListener: function () {} };
          };
          ctx.window.matchMedia = ctx.matchMedia;

          var intervals = {};
          var nextId = 1;
          ctx.setInterval = function (fn) { var id = nextId++; intervals[id] = fn; return id; };
          ctx.clearInterval = function (id) { delete intervals[id]; };
          ctx.window.setInterval = ctx.setInterval;
          ctx.window.clearInterval = ctx.clearInterval;
          ctx.__tick = function () {
            Object.keys(intervals).forEach(function (id) { if (intervals[id]) { intervals[id](); } });
          };
          ctx.__activeTimerCount = function () { return Object.keys(intervals).length; };

          var docListeners = [];
          var docRoot = el('html');
          docRoot.dataset = {}; // color-mode's hard gate reads documentElement.dataset
          var doc = {
            readyState: 'complete',
            hidden: false,
            addEventListener: function (type, fn) { docListeners.push({ type: type, fn: fn }); },
            removeEventListener: function (type, fn) {
              for (var i = docListeners.length - 1; i >= 0; i--) {
                if (docListeners[i].type === type && docListeners[i].fn === fn) { docListeners.splice(i, 1); }
              }
            },
            querySelector: function (sel) { return docRoot.querySelector(sel); },
            querySelectorAll: function (sel) { return docRoot.querySelectorAll(sel); },
            documentElement: docRoot
          };
          ctx.document = doc;
          ctx.window.document = doc;
          ctx.__docListeners = docListeners;

          var ioInstances = [];
          function FakeIO(cb) {
            this._cb = cb;
            this._observedTargets = [];
            this._disconnected = false;
            ioInstances.push(this);
          }
          FakeIO.prototype.observe = function (target) { this._observedTargets.push(target); };
          FakeIO.prototype.disconnect = function () { this._disconnected = true; };
          FakeIO.prototype.trigger = function (isIntersecting, target) {
            this._cb([{ isIntersecting: isIntersecting, target: target || this._observedTargets[0] }]);
          };
          ctx.IntersectionObserver = FakeIO;
          ctx.window.IntersectionObserver = FakeIO;
          ctx.__ioInstances = ioInstances;

          createContext(ctx);
          return ctx;
        }

        // Wrap RT.register BEFORE running the asset bytes to capture the raw enhance()
        // function: the class-based path keeps its cleanup private (RuntimeCoreTest pin
        // — "no destructive test hook is shipped"), so direct capture is the only way to
        // drive enhance()/cleanup() by hand for the cleanup + failure-injection cases.
        function captureEnhance(ctx, moduleName) {
          var captured = { fn: null, calls: 0 };
          var RT = ctx.window.ThalloRuntime;
          var orig = RT.register;
          RT.register = function (name, def) {
            if (name === moduleName) { captured.fn = def.enhance; captured.calls++; }
            return orig.call(RT, name, def);
          };
          return captured;
        }

        (async function () {
          // 1. Double-eval of the asset registers once: no throw, guard set once.
          (function () {
            var ctx = makeSandbox(false);
            runInContext(RUNTIME_SRC, ctx);
            var spy = captureEnhance(ctx, 'animated-text');
            runInContext(ASSET_SRC, ctx);
            assert(spy.calls === 1, 'first eval registered animated-text exactly once');
            assert(ctx.window.__thalloBlockAnimatedText === true, 'guard set after first eval');
            runInContext(ASSET_SRC, ctx); // second eval: guard must block before any register call
            assert(spy.calls === 1, 'second eval did not attempt to re-register (guard held)');
            assert(ctx.window.__thalloBlockAnimatedText === true, 'guard remains true after second eval');
          })();

          // 2. Missing runtime: guard NOT set, static untouched; re-eval after restoring
          //    the runtime works (retry path) since the guard was never burned.
          await (async function () {
            var ctx = makeSandbox(false);
            runInContext(RUNTIME_SRC, ctx);
            await flush();
            var block = buildBlock(3);
            // Deleting a global property from OUTSIDE a vm context does not reliably
            // propagate into the context's own global slot for SUBSEQUENT
            // runInContext evaluations (a documented `vm` quirk — writes sync both
            // ways, but deletes only sync reliably when performed from INSIDE).
            runInContext('delete window.ThalloRuntime;', ctx);
            runInContext(ASSET_SRC, ctx);
            assert(ctx.window.__thalloBlockAnimatedText !== true, 'guard NOT set when the runtime is missing');
            assert(!block.root.classList.contains('thallo-block-animated_text--prepared'),
              'static untouched: no prepared class without a runtime');
            assert(block.root.getAttribute('data-thallo-enhanced') === null,
              'static untouched: no marker without a runtime');

            runInContext(RUNTIME_SRC, ctx); // restore: fresh module registry
            await flush();
            assert(typeof ctx.window.ThalloRuntime.register === 'function', 'runtime restored');

            runInContext(ASSET_SRC, ctx); // retry: guard was never burned, so this succeeds
            assert(ctx.window.__thalloBlockAnimatedText === true, 'retry path: guard set after restore');
            ctx.window.ThalloRuntime.enhance(block.root);
            assert((block.root.getAttribute('data-thallo-enhanced') || '').indexOf('animated-text') !== -1,
              'retry path: the module actually works after restore');
          })();

          // 3. Registration after a completed boot self-enhances an existing block.
          await (async function () {
            var ctx = makeSandbox(false);
            runInContext(RUNTIME_SRC, ctx);
            await flush(); // the runtime's own boot pass completes with no animated-text module yet
            var block = buildBlock(3);
            ctx.document.documentElement.appendChild(block.root); // inserted AFTER boot already ran
            runInContext(ASSET_SRC, ctx); // registers, then self-enhances document.documentElement
            assert((block.root.getAttribute('data-thallo-enhanced') || '').indexOf('animated-text') !== -1,
              'late registration self-enhance found the pre-existing block');
            assert(block.root.classList.contains('thallo-block-animated_text--prepared'),
              'self-enhance prepared the block');
          })();

          // 4. Reveal-once: added on first intersection, never re-added, never removed
          //    again on a later non-intersecting entry (the reveal is one-way).
          (function () {
            var ctx = makeSandbox(false);
            runInContext(RUNTIME_SRC, ctx);
            var block = buildBlock(3);
            runInContext(ASSET_SRC, ctx);
            ctx.window.ThalloRuntime.enhance(block.root);

            var addCalls = 0;
            var originalAdd = block.root.classList.add;
            block.root.classList.add = function (c) {
              if (c === 'thallo-block-animated_text--in-view') { addCalls++; }
              return originalAdd.call(block.root.classList, c);
            };

            assert(!block.root.classList.contains('thallo-block-animated_text--in-view'), 'not in view before intersection');
            var io = ctx.__ioInstances[ctx.__ioInstances.length - 1];
            io.trigger(true);
            assert(block.root.classList.contains('thallo-block-animated_text--in-view'), 'in-view added on first intersection');
            assert(addCalls === 1, 'in-view class added exactly once');
            io.trigger(true);
            io.trigger(false); // leaving view afterward must not un-reveal
            io.trigger(true);
            assert(addCalls === 1, 'reveal-once: repeated/re-intersections never re-add the class');
            assert(block.root.classList.contains('thallo-block-animated_text--in-view'), 'still in-view after leaving and re-entering');
          })();

          // 5. Rotation completes exactly words.length - 1 steps then settles on the
          //    last word; the finite one-cycle contract survives further intersections.
          (function () {
            var ctx = makeSandbox(false);
            runInContext(RUNTIME_SRC, ctx);
            var block = buildBlock(4);
            runInContext(ASSET_SRC, ctx);
            ctx.window.ThalloRuntime.enhance(block.root);
            var io = ctx.__ioInstances[ctx.__ioInstances.length - 1];

            io.trigger(true);
            assert(ctx.__activeTimerCount() === 1, 'rotation timer started on intersection');
            assert(activeIndexOf(block) === 0, 'starts on word 0');
            ctx.__tick();
            assert(activeIndexOf(block) === 1, 'step 1 of 3');
            ctx.__tick();
            assert(activeIndexOf(block) === 2, 'step 2 of 3');
            ctx.__tick();
            assert(activeIndexOf(block) === 3, 'step 3 of 3: settles on the last word');
            assert(ctx.__activeTimerCount() === 0, 'timer cleared after exactly words.length-1 steps');
            ctx.__tick(); // no-op: nothing left registered
            assert(activeIndexOf(block) === 3, 'stays settled on the last word');

            io.trigger(false);
            io.trigger(true); // re-intersecting after completion must not restart rotation
            assert(ctx.__activeTimerCount() === 0, 'finite rotation: no restart after the one cycle completed');
            assert(activeIndexOf(block) === 3, 'still settled on the last word');
          })();

          // 6. Pause gates: offscreen pauses/resumes without resetting; the hidden-tab
          //    visibilitychange handler pauses/resumes the same way.
          (function () {
            var ctx = makeSandbox(false);
            runInContext(RUNTIME_SRC, ctx);
            var block = buildBlock(4);
            runInContext(ASSET_SRC, ctx);
            ctx.window.ThalloRuntime.enhance(block.root);
            var io = ctx.__ioInstances[ctx.__ioInstances.length - 1];

            io.trigger(true);
            assert(ctx.__activeTimerCount() === 1, 'running while in view');
            io.trigger(false);
            assert(ctx.__activeTimerCount() === 0, 'paused when scrolled offscreen');
            ctx.__tick(); // no-op
            var idxBeforeResume = activeIndexOf(block);
            io.trigger(true);
            assert(ctx.__activeTimerCount() === 1, 'resumed after re-entering view');
            ctx.__tick();
            assert(activeIndexOf(block) === idxBeforeResume + 1, 'resumed rotation continues from where it paused, not from 0');

            var vis = findListener(ctx, 'visibilitychange');
            assert(typeof vis === 'function', 'visibilitychange listener registered');
            ctx.document.hidden = true;
            vis();
            assert(ctx.__activeTimerCount() === 0, 'paused when the tab is hidden');
            ctx.document.hidden = false;
            vis();
            assert(ctx.__activeTimerCount() === 1, 'resumed when the tab is visible again (still in view)');
          })();

          // 7. Reduced motion: enhance() returns false, no classes, never marked.
          (function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            var block = buildBlock(3);
            var spy = captureEnhance(ctx, 'animated-text');
            runInContext(ASSET_SRC, ctx);
            assert(typeof spy.fn === 'function', 'enhance captured');
            var result = spy.fn(block.root);
            assert(result === false, 'reduced motion: enhance() returns false');
            assert(ctx.__ioInstances.length === 0, 'reduced motion: no IntersectionObserver constructed');
            assert(!block.root.classList.contains('thallo-block-animated_text--prepared'), 'reduced motion: no prepared class');
            assert(!block.root.classList.contains('thallo-block-animated_text--in-view'), 'reduced motion: no in-view class');
            ctx.window.ThalloRuntime.enhance(block.root); // through the real pipeline too
            assert(block.root.getAttribute('data-thallo-enhanced') === null, 'reduced motion: never marked by the pipeline');
          })();

          // 8. Cleanup restores the first word active and removes classes/IO/listener.
          (function () {
            var ctx = makeSandbox(false);
            runInContext(RUNTIME_SRC, ctx);
            var block = buildBlock(4);
            var spy = captureEnhance(ctx, 'animated-text');
            runInContext(ASSET_SRC, ctx);
            var cleanup = spy.fn(block.root);
            assert(typeof cleanup === 'function', 'enhance returned a cleanup function');
            var io = ctx.__ioInstances[ctx.__ioInstances.length - 1];
            io.trigger(true);
            ctx.__tick();
            ctx.__tick();
            assert(activeIndexOf(block) === 2, 'moved before cleanup runs');

            cleanup();

            assert(activeIndexOf(block) === 0, 'cleanup restores the first word active');
            assert(io._disconnected === true, 'cleanup disconnected the IntersectionObserver');
            assert(findListener(ctx, 'visibilitychange') === null, 'cleanup removed the visibilitychange listener');
            assert(!block.root.classList.contains('thallo-block-animated_text--prepared'), 'cleanup removed the prepared class');
            assert(!block.root.classList.contains('thallo-block-animated_text--in-view'), 'cleanup removed the in-view class');
            assert(ctx.__activeTimerCount() === 0, 'cleanup stopped the rotation timer');
          })();

          // 9. Failure injection: throw after PARTIALLY mutating observe() — the
          //    instance already recorded the observed target, then throws. The runtime
          //    core CONTAINS the throw (enhance() call site does not itself throw);
          //    rollback must still leave zero observer/listener/class/timer/marker.
          (function () {
            var ctx = makeSandbox(false);
            runInContext(RUNTIME_SRC, ctx);
            var block = buildBlock(3);
            runInContext(ASSET_SRC, ctx);

            var FakeIO = ctx.IntersectionObserver;
            var originalObserve = FakeIO.prototype.observe;
            FakeIO.prototype.observe = function (target) {
              originalObserve.call(this, target); // partial mutation
              throw new Error('injected: observe boom');
            };

            var threw = false;
            try { ctx.window.ThalloRuntime.enhance(block.root); } catch (e) { threw = true; }
            assert(threw === false, 'the runtime pipeline contains the throw internally');

            var io = ctx.__ioInstances[ctx.__ioInstances.length - 1];
            assert(io._disconnected === true, 'rollback disconnected the observer despite the partial observe');
            assert(findListener(ctx, 'visibilitychange') === null, 'no listener survives an observe failure');
            assert(!block.root.classList.contains('thallo-block-animated_text--prepared'), 'no prepared class after observe failure');
            assert(!block.root.classList.contains('thallo-block-animated_text--in-view'), 'no in-view class after observe failure');
            assert(ctx.__activeTimerCount() === 0, 'no timer after observe failure');
            assert(block.root.getAttribute('data-thallo-enhanced') === null, 'no runtime marker after observe failure');
          })();

          // 10. Failure injection: throw after PARTIALLY mutating addEventListener —
          //     the listener is really registered, then the call throws.
          (function () {
            var ctx = makeSandbox(false);
            runInContext(RUNTIME_SRC, ctx);
            var block = buildBlock(3);
            runInContext(ASSET_SRC, ctx);

            var originalAdd = ctx.document.addEventListener;
            ctx.document.addEventListener = function (type, fn) {
              if (type === 'visibilitychange') {
                originalAdd.call(ctx.document, type, fn); // partial mutation
                throw new Error('injected: addEventListener boom');
              }
              return originalAdd.call(ctx.document, type, fn);
            };

            ctx.window.ThalloRuntime.enhance(block.root);

            var io = ctx.__ioInstances[ctx.__ioInstances.length - 1];
            assert(io._disconnected === true, 'rollback disconnected the observer after a listener failure');
            assert(findListener(ctx, 'visibilitychange') === null, 'listener removed despite the partial add before the throw');
            assert(!block.root.classList.contains('thallo-block-animated_text--prepared'), 'no prepared class after listener failure');
            assert(!block.root.classList.contains('thallo-block-animated_text--in-view'), 'no in-view class after listener failure');
            assert(ctx.__activeTimerCount() === 0, 'no timer after listener failure');
            assert(block.root.getAttribute('data-thallo-enhanced') === null, 'no runtime marker after listener failure');
          })();

          // 11. Failure injection: throw after PARTIALLY mutating classList.add — the
          //     "prepared" class is really added, then the call throws.
          (function () {
            var ctx = makeSandbox(false);
            runInContext(RUNTIME_SRC, ctx);
            var block = buildBlock(3);
            runInContext(ASSET_SRC, ctx);

            var originalAdd = block.root.classList.add;
            block.root.classList.add = function (c) {
              if (c === 'thallo-block-animated_text--prepared') {
                originalAdd.call(block.root.classList, c); // partial mutation
                throw new Error('injected: classList.add boom');
              }
              return originalAdd.call(block.root.classList, c);
            };

            ctx.window.ThalloRuntime.enhance(block.root);

            var io = ctx.__ioInstances[ctx.__ioInstances.length - 1];
            assert(io._disconnected === true, 'rollback disconnected the observer after a class-add failure');
            assert(findListener(ctx, 'visibilitychange') === null, 'listener removed after a class-add failure');
            assert(!block.root.classList.contains('thallo-block-animated_text--prepared'), 'prepared class removed despite the partial add');
            assert(!block.root.classList.contains('thallo-block-animated_text--in-view'), 'no in-view class after a class-add failure');
            assert(ctx.__activeTimerCount() === 0, 'no timer after a class-add failure');
            assert(block.root.getAttribute('data-thallo-enhanced') === null, 'no runtime marker after a class-add failure');
          })();

          // 12. A throwing cleanup action must not stop the remaining cleanup actions.
          (function () {
            var ctx = makeSandbox(false);
            runInContext(RUNTIME_SRC, ctx);
            var block = buildBlock(3);
            var spy = captureEnhance(ctx, 'animated-text');
            runInContext(ASSET_SRC, ctx);
            var cleanup = spy.fn(block.root);
            assert(typeof cleanup === 'function', 'enhance returned a cleanup function');
            var io = ctx.__ioInstances[ctx.__ioInstances.length - 1];

            // The class-removal undo was pushed LAST (prepared last), so it runs FIRST
            // in the LIFO cleanup replay — rig exactly that one to throw.
            var originalRemove = block.root.classList.remove;
            block.root.classList.remove = function (c) {
              if (c === 'thallo-block-animated_text--prepared') {
                throw new Error('injected: cleanup classList.remove boom');
              }
              return originalRemove.call(block.root.classList, c);
            };

            var threw = false;
            try { cleanup(); } catch (e) { threw = true; }
            assert(threw === false, 'cleanup itself must not throw even when one undo action throws');
            assert(io._disconnected === true, 'remaining cleanup ran: observer still disconnected despite the earlier throw');
            assert(findListener(ctx, 'visibilitychange') === null, 'remaining cleanup ran: listener still removed despite the earlier throw');
          })();

          console.log('ALL_PASS');
        })().catch(function (e) { console.error('FAIL: ' + (e && e.stack || e)); process.exit(1); });
        JS;
    }
}
