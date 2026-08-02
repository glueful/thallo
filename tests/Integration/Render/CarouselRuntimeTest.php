<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Executable coverage for the carousel runtime module (theme-runtime spec §5): pause/play
 * control with aria-pressed + switching action labels, sticky user pause vs automatic
 * pause (IntersectionObserver offscreen, hidden tab), the visually-hidden 'Slide N of M'
 * status region (aria-live=off during automatic rotation, polite after user action), and
 * reduced-motion disabling autoplay entirely. Mirrors RuntimeCoreTest's Node +
 * hand-stubbed-DOM pattern; skips (not fails) without node but always asserts structural
 * markers.
 */
final class CarouselRuntimeTest extends AppTestCase
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

    public function testCarouselPauseStateMachineStatusRegionAndReducedMotion(): void
    {
        $src = $this->runtimeJs();
        self::assertStringContainsString('/* carousel:start', $src);
        self::assertStringContainsString("register('carousel'", $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the carousel runtime module');
        }

        $file = sys_get_temp_dir() . '/thallo_carousel_runtime_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($src));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "carousel harness failed:\n" . implode("\n", $out));
            self::assertStringContainsString('ALL_PASS', implode("\n", $out));
        } finally {
            @unlink($file);
        }
    }

    /** Build a self-checking node harness around the full runtime source. */
    private function harness(string $src): string
    {
        $json = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        'use strict';
        function assert(c, m) { if (!c) { console.error('FAIL: ' + m); process.exit(1); } }

        // --- timer recorders ---------------------------------------------------
        var intervalSeq = 0;
        var activeIntervals = {};
        global.setInterval = function (fn, ms) {
          intervalSeq++;
          activeIntervals[intervalSeq] = { fn: fn, ms: ms };
          return intervalSeq;
        };
        global.clearInterval = function (id) { delete activeIntervals[id]; };
        function activeCount() { return Object.keys(activeIntervals).length; }
        function onlyInterval() {
          var keys = Object.keys(activeIntervals);
          return keys.length === 1 ? activeIntervals[keys[0]] : null;
        }

        // --- matchMedia keyed by query string; flipped for the reduced-motion variant --
        var reducedMotion = false;
        global.matchMedia = function (q) {
          return {
            matches: q === '(prefers-reduced-motion: reduce)' ? reducedMotion : false,
            addEventListener: function () {}
          };
        };

        // --- IntersectionObserver stub: constructor captures its callback -----
        var ioInstances = [];
        global.IntersectionObserver = function (cb) {
          var inst = { cb: cb, observed: [] };
          inst.observe = function (t) { inst.observed.push(t); };
          ioInstances.push(inst);
          return inst;
        };

        // --- element + document stubs ------------------------------------------
        function makeEl(tag) {
          var attrs = {};
          var node = {
            tagName: String(tag).toUpperCase(),
            nodeType: 1,
            className: '',
            textContent: '',
            dataset: {},
            children: [],
            listeners: {},
            appendChild: function (c) { node.children.push(c); return c; },
            getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
            setAttribute: function (n, v) { attrs[n] = String(v); },
            addEventListener: function (t, fn) {
              (node.listeners[t] = node.listeners[t] || []).push(fn);
            },
            matches: function () { return false; },
            querySelectorAll: function () { return []; },
            querySelector: function () { return null; }
          };
          return node;
        }

        // Inert <html> stub: keeps the color-mode module's hard gate closed and gives
        // the deferred boot pass a harmless scan boundary.
        var inert = {
          dataset: {},
          matches: function () { return false; },
          querySelectorAll: function () { return []; },
          querySelector: function () { return null; },
          getAttribute: function () { return null; },
          setAttribute: function () {},
          addEventListener: function () {},
          dispatchEvent: function () { return true; }
        };
        var docListeners = {};
        global.document = {
          readyState: 'complete',
          hidden: false,
          addEventListener: function (t, fn) {
            (docListeners[t] = docListeners[t] || []).push(fn);
          },
          querySelector: function () { return null; }, // no .thallo-preview-block: not canvas
          querySelectorAll: function () { return []; },
          createElement: makeEl,
          documentElement: inert
        };
        global.window = global;

        function dispatchVisibility(hidden) {
          document.hidden = hidden;
          (docListeners.visibilitychange || []).forEach(function (fn) { fn(); });
        }

        // --- carousel stub: viewport with scroll numbers + listener recorders --
        function makeCarousel() {
          var slides = [0, 400, 800].map(function (x) {
            return { nodeType: 1, offsetLeft: x };
          });
          var track = { offsetLeft: 0, children: slides };
          var scrollToCalls = [];
          var viewport = {
            scrollLeft: 0,
            listeners: {},
            scrollTo: function (opts) {
              scrollToCalls.push(opts.left);
              viewport.scrollLeft = opts.left; // instant settle
            },
            addEventListener: function (t, fn) {
              (viewport.listeners[t] = viewport.listeners[t] || []).push(fn);
            },
            removeEventListener: function (t, fn) {
              var list = viewport.listeners[t] || [];
              var idx = list.indexOf(fn);
              if (idx !== -1) { list.splice(idx, 1); }
            }
          };
          var root = makeEl('div');
          root.className = 'thallo-block-carousel';
          root.dataset = { arrows: '1', dots: '1', autoplay: '1' };
          root.matches = function (sel) { return sel === '.thallo-block-carousel'; };
          root.querySelector = function (sel) {
            if (sel === '.thallo-block-carousel__viewport') { return viewport; }
            if (sel === '.thallo-block-carousel__track') { return track; }
            return null;
          };
          return { root: root, viewport: viewport, scrollToCalls: scrollToCalls };
        }
        function findChild(root, cls) {
          for (var i = 0; i < root.children.length; i++) {
            if (root.children[i].className === cls) { return root.children[i]; }
          }
          return null;
        }
        function click(btn) { btn.listeners.click[0](); }

        eval($json);
        var car = makeCarousel();
        window.ThalloRuntime.enhance(car.root);

        // 1. Autoplay on: pause button + status region injected; rotating with live off.
        var pauseBtn = findChild(car.root, 'thallo-block-carousel__pause');
        assert(pauseBtn !== null, 'pause button injected when data-autoplay=1');
        assert(pauseBtn.getAttribute('aria-pressed') === 'false', 'initial aria-pressed=false');
        assert(pauseBtn.getAttribute('aria-label') === 'Pause slides', 'initial label Pause slides');
        var live = findChild(car.root, 'thallo-block-carousel__status');
        assert(live !== null, 'status region injected');
        assert(live.getAttribute('aria-live') === 'off', 'status aria-live=off while rotating');
        assert(activeCount() === 1, 'autoplay interval running');
        assert(onlyInterval().ms === 5000, 'rotation every 5000ms');
        assert(!!(car.viewport.listeners.pointerdown && car.viewport.listeners.keydown
          && car.viewport.listeners.wheel && !car.viewport.listeners.touchstart),
          'interaction listeners on viewport (pointer events cover touch: no touchstart)');
        onlyInterval().fn(); // one automatic tick: announces silently
        assert(live.textContent === 'Slide 2 of 3', 'tick updates status text');
        assert(live.getAttribute('aria-live') === 'off', 'automatic rotation stays aria-live=off');
        car.viewport.scrollLeft = 0; // rewind position for the navigation assertions below

        // 2. Offscreen/hidden auto-pause + all-clear resume (no user pause involved).
        assert(ioInstances.length === 1 && ioInstances[0].observed[0] === car.root,
          'IntersectionObserver observes the carousel root');
        var io = ioInstances[0];
        io.cb([{ isIntersecting: false }]);
        assert(activeCount() === 0, 'offscreen clears the interval');
        io.cb([{ isIntersecting: true }]);
        assert(activeCount() === 1, 'reintersecting restarts rotation');
        io.cb([{ isIntersecting: true }]);
        assert(activeCount() === 1, 'no duplicate intervals on repeat intersect');
        dispatchVisibility(true);
        assert(activeCount() === 0, 'hidden tab clears the interval');
        dispatchVisibility(false);
        assert(activeCount() === 1, 'visible tab restarts rotation');
        assert(pauseBtn.getAttribute('aria-pressed') === 'false',
          'automatic pause is not user pause');
        assert(live.getAttribute('aria-live') === 'off', 'automatic pauses keep the region off');

        // 3. User pause is sticky: automatic recovery never restarts.
        click(pauseBtn);
        assert(pauseBtn.getAttribute('aria-pressed') === 'true',
          'pause click sets aria-pressed=true');
        assert(pauseBtn.getAttribute('aria-label') === 'Play slides', 'label flips with state');
        assert(activeCount() === 0, 'pause click clears the interval');
        io.cb([{ isIntersecting: false }]);
        io.cb([{ isIntersecting: true }]);
        assert(activeCount() === 0, 'reintersect must NOT restart while userPaused');
        dispatchVisibility(true);
        dispatchVisibility(false);
        assert(activeCount() === 0, 'visibility recovery must NOT restart while userPaused');

        // 4. Explicit Play restarts; region turned polite by the user action, stays polite.
        assert(live.getAttribute('aria-live') === 'polite', 'polite after user pause');
        click(pauseBtn);
        assert(activeCount() === 1, 'Play restarts rotation');
        assert(pauseBtn.getAttribute('aria-pressed') === 'false', 'Play resets aria-pressed');
        assert(pauseBtn.getAttribute('aria-label') === 'Pause slides',
          'label back to Pause slides');
        assert(live.getAttribute('aria-live') === 'polite', 'region stays polite after Play');
        // Explicit Play re-checks every automatic gate before starting:
        click(pauseBtn); // user pause
        dispatchVisibility(true); // and tab hidden
        click(pauseBtn); // Play while hidden
        assert(activeCount() === 0, 'Play never overrides a hidden tab');
        assert(pauseBtn.getAttribute('aria-pressed') === 'false', 'Play still clears userPaused');
        dispatchVisibility(false);
        assert(activeCount() === 1, 'all-clear resumes because user chose Play');

        // 5. Interaction gating: the slider owns the x axis, the page owns y.
        //    Vertical wheel/touch (page scrolling that happens over the slider)
        //    must never sticky-pause; horizontal intent and deliberate presses do.
        car.viewport.listeners.wheel[0]({ deltaX: 0, deltaY: 120 });
        assert(activeCount() === 1, 'vertical wheel (page scroll over the slider) never pauses');
        assert(pauseBtn.getAttribute('aria-pressed') === 'false',
          'vertical wheel is not user pause');
        car.viewport.listeners.pointerdown[0]({ pointerType: 'touch', clientX: 200, clientY: 100 });
        car.viewport.listeners.pointermove[0]({ clientX: 203, clientY: 101 });
        assert(activeCount() === 1, 'sub-threshold jitter does not read as intent');
        car.viewport.listeners.pointermove[0]({ clientX: 204, clientY: 180 });
        assert(activeCount() === 1, 'vertical touch drag (page scroll) never pauses');
        assert((car.viewport.listeners.pointermove || []).length === 0,
          'gesture listeners cleaned up once intent resolved');
        car.viewport.listeners.wheel[0]({ deltaX: 90, deltaY: 4 });
        assert(activeCount() === 0, 'horizontal wheel is slide interaction: sticky pause');
        assert(pauseBtn.getAttribute('aria-pressed') === 'true',
          'horizontal wheel counts as user pause');
        click(pauseBtn); // Play again for the swipe case
        assert(activeCount() === 1, 'Play restarts before the swipe case');
        car.viewport.listeners.pointerdown[0]({ pointerType: 'touch', clientX: 200, clientY: 100 });
        car.viewport.listeners.pointermove[0]({ clientX: 280, clientY: 108 });
        assert(activeCount() === 0, 'horizontal touch swipe stops autoplay');
        assert(pauseBtn.getAttribute('aria-pressed') === 'true',
          'horizontal swipe counts as user pause');
        io.cb([{ isIntersecting: true }]);
        dispatchVisibility(false);
        assert(activeCount() === 0, 'no auto-resume after slide interaction');
        click(pauseBtn); // Play again for the mouse case
        assert(activeCount() === 1, 'Play restarts before the mouse case');
        car.viewport.listeners.pointerdown[0]({ pointerType: 'mouse', clientX: 10, clientY: 10 });
        assert(activeCount() === 0, 'mouse pointerdown on the slides is deliberate: sticky pause');
        assert(pauseBtn.getAttribute('aria-pressed') === 'true',
          'mouse press counts as user pause');
        var next = findChild(car.root, 'thallo-block-carousel__next');
        var prev = findChild(car.root, 'thallo-block-carousel__prev');
        assert(next !== null && prev !== null, 'arrows injected');
        click(next);
        assert(car.scrollToCalls[car.scrollToCalls.length - 1] === 400,
          'next scrolls to slide 2');
        assert(live.textContent === 'Slide 2 of 3', 'arrow updates status text');
        assert(live.getAttribute('aria-live') === 'polite', 'arrow announcement is polite');
        click(prev);
        assert(live.textContent === 'Slide 1 of 3', 'prev announces slide 1');
        assert(car.scrollToCalls[car.scrollToCalls.length - 1] === 0,
          'prev scrolls back to slide 1');

        // 6. Reduced motion: no interval ever, no pause button, no status region.
        reducedMotion = true;
        var seqBefore = intervalSeq;
        var car2 = makeCarousel();
        window.ThalloRuntime.enhance(car2.root);
        assert(intervalSeq === seqBefore, 'reduced motion: no interval ever created');
        assert(findChild(car2.root, 'thallo-block-carousel__pause') === null,
          'reduced motion: pause button not injected');
        assert(findChild(car2.root, 'thallo-block-carousel__status') === null,
          'reduced motion: status region not injected');
        assert(ioInstances.length === 1, 'reduced motion: no IntersectionObserver wired');
        assert(findChild(car2.root, 'thallo-block-carousel__next') !== null,
          'reduced motion: arrows still enhance');

        console.log('ALL_PASS');
        JS;
    }

    /** Shared exec-and-assert plumbing for the harnesses below. */
    private function runHarness(string $js, string $suffix): void
    {
        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the carousel runtime module');
        }

        $file = sys_get_temp_dir() . '/thallo_carousel_runtime_' . $suffix . '_' . getmypid() . '.mjs';
        file_put_contents($file, $js);
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "carousel harness failed:\n" . implode("\n", $out));
            self::assertStringContainsString('ALL_PASS', implode("\n", $out));
        } finally {
            @unlink($file);
        }
    }

    public function testCarouselStructuralNoOpReturnsFalseAndRetriesOnNextPass(): void
    {
        $this->runHarness($this->structuralFalseHarness($this->runtimeJs()), 'structural');
    }

    /**
     * Structural no-op returns false (never marks). A carousel root MISSING the
     * viewport must leave the shared marker untouched — and, because unmarked
     * components are retried, a second enhance() pass re-runs the module rather
     * than treating it as already handled.
     */
    private function structuralFalseHarness(string $src): string
    {
        $json = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        'use strict';
        function assert(c, m) { if (!c) { console.error('FAIL: ' + m); process.exit(1); } }

        global.matchMedia = function () {
          return { matches: false, addEventListener: function () {} };
        };

        function makeEl(tag) {
          var attrs = {};
          var node = {
            tagName: String(tag).toUpperCase(),
            nodeType: 1,
            className: '',
            dataset: {},
            children: [],
            appendChild: function (c) { node.children.push(c); return c; },
            getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
            setAttribute: function (n, v) { attrs[n] = String(v); },
            removeAttribute: function (n) { delete attrs[n]; },
            addEventListener: function () {},
            matches: function () { return false; },
            querySelectorAll: function () { return []; },
            querySelector: function () { return null; }
          };
          return node;
        }

        var inert = {
          dataset: {},
          matches: function () { return false; },
          querySelectorAll: function () { return []; },
          querySelector: function () { return null; },
          getAttribute: function () { return null; },
          setAttribute: function () {},
          addEventListener: function () {},
          dispatchEvent: function () { return true; }
        };
        global.document = {
          readyState: 'complete',
          hidden: false,
          addEventListener: function () {},
          querySelector: function () { return null; },
          querySelectorAll: function () { return []; },
          createElement: makeEl,
          documentElement: inert
        };
        global.window = global;

        eval($json);

        var viewportQueries = 0;
        var root = makeEl('div');
        root.className = 'thallo-block-carousel';
        root.matches = function (sel) { return sel === '.thallo-block-carousel'; };
        root.querySelector = function (sel) {
          if (sel === '.thallo-block-carousel__viewport') { viewportQueries++; }
          return null; // viewport (and track) genuinely absent
        };

        window.ThalloRuntime.enhance(root);
        assert(viewportQueries === 1, 'first pass queried for the viewport');
        assert((root.getAttribute('data-thallo-enhanced') || '').indexOf('carousel') === -1,
          'structural false never marks the carousel token');

        window.ThalloRuntime.enhance(root);
        assert(viewportQueries === 2, 'unmarked component retried the module on a second pass');
        assert((root.getAttribute('data-thallo-enhanced') || '').indexOf('carousel') === -1,
          'still unmarked after the second pass');

        console.log('ALL_PASS');
        JS;
    }

    public function testCarouselTeardownRemovesEveryInjectedNodeListenerObserverAndTimer(): void
    {
        $this->runHarness($this->teardownHarness($this->runtimeJs()), 'teardown');
    }

    /**
     * Teardown accounting, driven through the PUBLIC element lifecycle only (no
     * production test hook): register a harness-only x-carousel-lifecycle adapter
     * over the existing 'carousel' module (Task 2's registerElement bridge), connect
     * it on a valid 3-slide root with arrows/dots/autoplay all on, then disconnect
     * and assert every injected node, listener (same ref), observer, and timer was
     * torn down — followed by a clean reconnect.
     */
    private function teardownHarness(string $src): string
    {
        $json = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        'use strict';
        function assert(c, m) { if (!c) { console.error('FAIL: ' + m); process.exit(1); } }

        // --- timer recorders ---------------------------------------------------
        var intervalSeq = 0;
        var activeIntervals = {};
        var clearIntervalCalls = 0;
        global.setInterval = function (fn, ms) {
          intervalSeq++;
          activeIntervals[intervalSeq] = { fn: fn, ms: ms };
          return intervalSeq;
        };
        global.clearInterval = function (id) { clearIntervalCalls++; delete activeIntervals[id]; };
        function activeCount() { return Object.keys(activeIntervals).length; }

        // --- matchMedia: reduced motion off, so autoplay actually wires up -----
        global.matchMedia = function () {
          return { matches: false, addEventListener: function () {} };
        };

        // --- IntersectionObserver stub with disconnect tracking ----------------
        var ioInstances = [];
        global.IntersectionObserver = function (cb) {
          var inst = { cb: cb, observed: [], disconnectCalls: 0 };
          inst.observe = function (t) { inst.observed.push(t); };
          inst.disconnect = function () { inst.disconnectCalls++; };
          ioInstances.push(inst);
          return inst;
        };

        // --- element stub: parent-aware appendChild/removeChild + listener
        //     add/remove tracked with same-reference bookkeeping ----------------
        function makeEl(tag) {
          var attrs = {};
          var node = {
            tagName: String(tag || 'div').toUpperCase(),
            nodeType: 1,
            className: '',
            textContent: '',
            dataset: {},
            children: [],
            parentNode: null,
            isConnected: true,
            listeners: {},
            addedRefs: {},
            removedRefs: {},
            appendChild: function (c) { c.parentNode = node; node.children.push(c); return c; },
            removeChild: function (c) {
              var i = node.children.indexOf(c);
              if (i !== -1) { node.children.splice(i, 1); c.parentNode = null; }
              return c;
            },
            getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
            setAttribute: function (n, v) { attrs[n] = String(v); },
            removeAttribute: function (n) { delete attrs[n]; },
            addEventListener: function (t, fn) {
              (node.listeners[t] = node.listeners[t] || []).push(fn);
              (node.addedRefs[t] = node.addedRefs[t] || []).push(fn);
            },
            removeEventListener: function (t, fn) {
              (node.removedRefs[t] = node.removedRefs[t] || []).push(fn);
              var list = node.listeners[t] || [];
              var idx = list.indexOf(fn);
              if (idx !== -1) { list.splice(idx, 1); }
            },
            matches: function () { return false; },
            querySelectorAll: function () { return []; },
            querySelector: function () { return null; }
          };
          return node;
        }

        // Inert <html> stub: keeps the color-mode module's hard gate closed.
        var inert = {
          dataset: {},
          matches: function () { return false; },
          querySelectorAll: function () { return []; },
          querySelector: function () { return null; },
          getAttribute: function () { return null; },
          setAttribute: function () {},
          addEventListener: function () {},
          dispatchEvent: function () { return true; }
        };
        var doc = {
          readyState: 'complete',
          hidden: false,
          listeners: {},
          addedRefs: {},
          removedRefs: {},
          addEventListener: function (t, fn) {
            (doc.listeners[t] = doc.listeners[t] || []).push(fn);
            (doc.addedRefs[t] = doc.addedRefs[t] || []).push(fn);
          },
          removeEventListener: function (t, fn) {
            (doc.removedRefs[t] = doc.removedRefs[t] || []).push(fn);
            var list = doc.listeners[t] || [];
            var idx = list.indexOf(fn);
            if (idx !== -1) { list.splice(idx, 1); }
          },
          querySelector: function () { return null; },
          querySelectorAll: function () { return []; },
          createElement: makeEl,
          documentElement: inert
        };
        global.document = doc;
        global.window = global;

        // --- customElements bridge stub (Task 2 pattern) ------------------------
        global.HTMLElement = function () {};
        var defined = {};
        global.customElements = { define: function (tag, cls) { defined[tag] = cls; } };
        function upgrade(tag, node) {
          node.connectedCallback = defined[tag].prototype.connectedCallback;
          node.disconnectedCallback = defined[tag].prototype.disconnectedCallback;
          node.connectedCallback();
          return node;
        }
        function flush() { return new Promise(function (r) { setTimeout(r, 0); }); }

        eval($json);
        var RT = window.ThalloRuntime;
        RT.registerElement('x-carousel-lifecycle', 'carousel', {});

        (async function () {
          var slides = [0, 400, 800].map(function (x) { return { nodeType: 1, offsetLeft: x }; });
          var track = { offsetLeft: 0, children: slides };
          var viewport = makeEl('div');
          viewport.scrollLeft = 0;
          viewport.scrollTo = function (opts) { viewport.scrollLeft = opts.left; };

          var root = makeEl('div');
          root.className = 'thallo-block-carousel';
          root.dataset = { arrows: '1', dots: '1', autoplay: '1' };
          root.querySelector = function (sel) {
            if (sel === '.thallo-block-carousel__viewport') { return viewport; }
            if (sel === '.thallo-block-carousel__track') { return track; }
            return null;
          };

          upgrade('x-carousel-lifecycle', root);
          await flush();

          assert((root.getAttribute('data-thallo-enhanced') || '').indexOf('carousel') !== -1,
            'connect enhanced + marked the host');

          function findByClass(cls) {
            for (var i = 0; i < root.children.length; i++) {
              if (root.children[i].className === cls) { return root.children[i]; }
            }
            return null;
          }
          assert(findByClass('thallo-block-carousel__prev') !== null, 'prev arrow injected');
          assert(findByClass('thallo-block-carousel__next') !== null, 'next arrow injected');
          assert(findByClass('thallo-block-carousel__dots') !== null, 'dots wrap injected');
          assert(findByClass('thallo-block-carousel__status') !== null, 'status region injected');
          assert(findByClass('thallo-block-carousel__pause') !== null, 'pause button injected');
          assert(activeCount() === 1, 'autoplay timer running before teardown');
          assert(ioInstances.length === 1 && ioInstances[0].observed[0] === root,
            'IntersectionObserver observing the root');

          // Refs addEventListener received — teardown must removeEventListener the SAME ones.
          var scrollRef = viewport.addedRefs.scroll && viewport.addedRefs.scroll[0];
          var pointerdownRef = viewport.addedRefs.pointerdown && viewport.addedRefs.pointerdown[0];
          var keydownRef = viewport.addedRefs.keydown && viewport.addedRefs.keydown[0];
          var wheelRef = viewport.addedRefs.wheel && viewport.addedRefs.wheel[0];
          var visibilityRef = doc.addedRefs.visibilitychange && doc.addedRefs.visibilitychange[0];
          assert(scrollRef && pointerdownRef && keydownRef && wheelRef && visibilityRef,
            'every expected listener was registered before teardown');
          assert(!viewport.addedRefs.touchstart,
            'no legacy touchstart listener (pointer events cover touch)');

          root.disconnectedCallback();

          assert(findByClass('thallo-block-carousel__prev') === null, 'prev arrow removed');
          assert(findByClass('thallo-block-carousel__next') === null, 'next arrow removed');
          assert(findByClass('thallo-block-carousel__dots') === null, 'dots wrap removed');
          assert(findByClass('thallo-block-carousel__status') === null, 'status region removed');
          assert(findByClass('thallo-block-carousel__pause') === null, 'pause button removed');
          assert(root.children.length === 0, 'no injected node remains under root');

          assert(viewport.removedRefs.scroll && viewport.removedRefs.scroll[0] === scrollRef,
            'scroll listener removed with the SAME ref it was added with');
          assert(viewport.removedRefs.pointerdown && viewport.removedRefs.pointerdown[0] === pointerdownRef,
            'pointerdown listener removed with the SAME ref');
          assert(viewport.removedRefs.keydown && viewport.removedRefs.keydown[0] === keydownRef,
            'keydown listener removed with the SAME ref');
          assert(viewport.removedRefs.wheel && viewport.removedRefs.wheel[0] === wheelRef,
            'wheel listener removed with the SAME ref');
          assert(doc.removedRefs.visibilitychange && doc.removedRefs.visibilitychange[0] === visibilityRef,
            'document visibilitychange listener removed with the SAME ref');

          assert(ioInstances[0].disconnectCalls === 1, 'IntersectionObserver.disconnect called');
          assert(clearIntervalCalls >= 1, 'clearInterval called for the live timer');
          assert(activeCount() === 0, 'no interval remains running after teardown');

          assert((root.getAttribute('data-thallo-enhanced') || '').indexOf('carousel') === -1,
            'carousel marker removed on disconnect');

          // Reconnect + flush enhances cleanly again.
          root.connectedCallback();
          await flush();
          assert((root.getAttribute('data-thallo-enhanced') || '').indexOf('carousel') !== -1,
            'reconnect re-marked the host');
          assert(findByClass('thallo-block-carousel__prev') !== null,
            'reconnect re-injected the arrows');
          assert(activeCount() === 1, 'reconnect restarted autoplay');

          console.log('ALL_PASS');
        })().catch(function (e) { console.error('FAIL: ' + (e && e.stack)); process.exit(1); });
        JS;
    }
}
