<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Executable coverage for the navigation runtime module (theme-runtime spec §3.2): the
 * --js root handoff, JS-enforced one-open-sibling on toggle (never details name=),
 * Escape close + summary refocus, ArrowDown into the panel, hover intent (reveal-hover
 * roots, desktop only, 180ms close delay), sublink clicks closing the mobile drawer on
 * mobile viewports only, the outer-details desktop/mobile state machine (OPEN on desktop
 * — the CSS re-exposure rides ::details-content, newer than the Baseline floor — closed
 * when crossing to mobile), and reduced motion skipping element.animate. Mirrors
 * RuntimeCoreTest's Node + hand-stubbed-DOM pattern; skips (not fails) without node but
 * always asserts structural markers.
 */
final class NavigationRuntimeTest extends AppTestCase
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

    public function testNavigationDisclosureStateMachineKeyboardAndReducedMotion(): void
    {
        $src = $this->runtimeJs();
        self::assertStringContainsString('/* navigation:start', $src);
        self::assertStringContainsString("register('navigation'", $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the navigation runtime module');
        }

        $file = sys_get_temp_dir() . '/thallo_navigation_runtime_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($src));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "navigation harness failed:\n" . implode("\n", $out));
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

        // --- timeout recorders (hover-intent close delay) ----------------------
        var timeoutSeq = 0;
        var activeTimeouts = {};
        global.setTimeout = function (fn, ms) {
          timeoutSeq++;
          activeTimeouts[timeoutSeq] = { fn: fn, ms: ms };
          return timeoutSeq;
        };
        global.clearTimeout = function (id) { delete activeTimeouts[id]; };
        function pendingTimeouts() { return Object.keys(activeTimeouts); }
        function runTimeout(id) {
          var t = activeTimeouts[id];
          delete activeTimeouts[id];
          t.fn();
        }

        // --- matchMedia keyed by query: controllable matches + change dispatch --
        var mqState = {};
        function mqEntry(q) {
          if (!mqState[q]) { mqState[q] = { matches: false, listeners: [] }; }
          return mqState[q];
        }
        global.matchMedia = function (q) {
          var st = mqEntry(q);
          return {
            get matches() { return st.matches; },
            addEventListener: function (t, fn) { if (t === 'change') { st.listeners.push(fn); } }
          };
        };
        function setMq(q, m) {
          var st = mqEntry(q);
          st.matches = m;
          st.listeners.forEach(function (fn) { fn({ matches: m }); });
        }
        var BP = '(max-width: 48rem)';
        var RM = '(prefers-reduced-motion: reduce)';

        // --- document + inert <html> stubs -------------------------------------
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
          addEventListener: function (t, fn) {
            (docListeners[t] = docListeners[t] || []).push(fn);
          },
          querySelector: function () { return null; }, // no .thallo-preview-block: not canvas
          querySelectorAll: function () { return []; },
          documentElement: inert
        };
        global.window = global;
        function docClick(target) {
          (docListeners.click || []).forEach(function (fn) { fn({ target: target }); });
        }

        // --- nav tree stubs -----------------------------------------------------
        function listenable(node) {
          node.listeners = {};
          node.addEventListener = function (t, fn) {
            (node.listeners[t] = node.listeners[t] || []).push(fn);
          };
          return node;
        }
        function fire(node, type, ev) {
          (node.listeners[type] || []).forEach(function (fn) { fn(ev || {}); });
        }
        // <details> stub: an open property whose setter dispatches 'toggle' to the
        // element's listeners. Like the real element it only fires on an actual
        // state change, so the module's close-others pass cannot recurse.
        function detailsOpenProp(node) {
          var isOpen = false;
          Object.defineProperty(node, 'open', {
            get: function () { return isOpen; },
            set: function (v) {
              v = !!v;
              if (v === isOpen) { return; }
              isOpen = v;
              (node.listeners.toggle || []).forEach(function (fn) { fn({ target: node }); });
            }
          });
        }
        function makeClassList(initial) {
          var set = {};
          initial.forEach(function (c) { set[c] = true; });
          return {
            add: function (c) { set[c] = true; },
            contains: function (c) { return !!set[c]; }
          };
        }
        function makeNavTree(revealHover) {
          function makeFocusable() {
            var n = listenable({ focusCount: 0 });
            n.focus = function () { n.focusCount++; };
            return n;
          }
          function makeParent() {
            var summary = makeFocusable();
            var panel = { animateCalls: [] };
            panel.animate = function (kf, opts) {
              panel.animateCalls.push({ keyframes: kf, options: opts });
            };
            var sublink = makeFocusable();
            sublink.closest = function (sel) { return sel === 'a[href]' ? sublink : null; };
            var d = listenable({});
            detailsOpenProp(d);
            d.querySelector = function (sel) {
              if (sel === '[data-nav-toggle]') { return summary; }
              if (sel === '[data-nav-panel]') { return panel; }
              if (sel === '.thallo-block-navigation__sublink, .thallo-block-navigation__col-title') {
                return sublink;
              }
              return null;
            };
            d.contains = function (n) { return n === summary || n === panel || n === sublink; };
            var li = listenable({});
            d.parentNode = li;
            return { d: d, summary: summary, panel: panel, sublink: sublink, li: li };
          }
          var a = makeParent();
          var b = makeParent();
          var rootClasses = ['thallo-block', 'thallo-block-navigation'];
          if (revealHover) { rootClasses.push('thallo-block-navigation--reveal-hover'); }
          var root = { classList: makeClassList(rootClasses) };
          var members = [
            a.d, a.summary, a.panel, a.sublink, a.li,
            b.d, b.summary, b.panel, b.sublink, b.li
          ];
          var attrs = {};
          var mobile = listenable({
            getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
            setAttribute: function (n, v) { attrs[n] = String(v); },
            matches: function (sel) { return sel === '[data-thallo-enhance="navigation"]'; },
            querySelectorAll: function (sel) {
              return sel === '.thallo-block-navigation__details' ? [a.d, b.d] : [];
            },
            querySelector: function () { return null; },
            // Real closest() supports comma-separated selector lists (the fix for
            // the scan-before-projection race adds a `thallo-navigation` tag-name
            // fallback alongside the class) — match on substring rather than exact
            // equality so this stub keeps working with either selector shape.
            closest: function (sel) { return sel.indexOf('.thallo-block-navigation') !== -1 ? root : null; },
            contains: function (n) { return members.indexOf(n) !== -1; }
          });
          detailsOpenProp(mobile);
          return { mobile: mobile, root: root, a: a, b: b };
        }

        eval($json);
        var RT = window.ThalloRuntime;

        // Enhance at DESKTOP width (BP matches=false is the stub default).
        var t1 = makeNavTree(true);
        RT.enhance(t1.mobile);

        // 1. Root gains the --js handoff class.
        assert(t1.root.classList.contains('thallo-block-navigation--js'), 'root gains --js class');

        // 7a. enhance() at desktop width opens the outer mobile details immediately
        //     (desktop CSS re-exposure rides ::details-content, newer than Baseline;
        //     the runtime guarantees visibility by keeping the details open).
        assert(t1.mobile.open === true, 'enhance() opens the outer details on desktop');

        // 2. One-open-sibling enforced by JS on toggle events, never details name=.
        t1.a.d.open = true;
        assert(t1.a.d.open === true && t1.b.d.open === false, 'A open alone after opening A');
        assert(t1.a.panel.animateCalls.length === 1, 'opening animates the panel via element.animate');
        t1.b.d.open = true;
        assert(t1.a.d.open === false, 'opening B closes A (JS-enforced exclusivity)');
        assert(t1.b.d.open === true, 'B stays open');

        // 3. Escape inside an open parent closes it and refocuses its summary.
        fire(t1.b.d, 'keydown', { key: 'Escape' });
        assert(t1.b.d.open === false, 'Escape closes the open parent');
        assert(t1.b.summary.focusCount === 1, 'Escape refocuses the summary');
        fire(t1.a.d, 'keydown', { key: 'Escape' });
        assert(t1.a.summary.focusCount === 0, 'Escape on a closed parent is a no-op');

        // 4. ArrowDown on a summary opens and focuses the first sublink.
        var prevented = 0;
        fire(t1.a.summary, 'keydown', {
          key: 'ArrowDown',
          preventDefault: function () { prevented++; }
        });
        assert(t1.a.d.open === true, 'ArrowDown opens the submenu');
        assert(t1.a.sublink.focusCount === 1, 'ArrowDown focuses the first sublink');
        assert(prevented === 1, 'ArrowDown is preventDefault-ed');
        t1.a.d.open = false;

        // 5. Hover intent on a reveal-hover root at desktop width: mouseenter opens
        //    immediately (cancelling any pending close), mouseleave closes after 180ms.
        fire(t1.a.li, 'mouseenter');
        assert(t1.a.d.open === true, 'mouseenter opens the submenu');
        fire(t1.a.li, 'mouseleave');
        assert(t1.a.d.open === true, 'mouseleave does not close synchronously');
        var pending = pendingTimeouts();
        assert(pending.length === 1, 'mouseleave schedules exactly one close');
        assert(activeTimeouts[pending[0]].ms === 180, 'close is delayed by 180ms');
        runTimeout(pending[0]);
        assert(t1.a.d.open === false, 'delayed close fires after 180ms');
        fire(t1.a.li, 'mouseenter');
        fire(t1.a.li, 'mouseleave');
        fire(t1.a.li, 'mouseenter'); // re-enter cancels the pending close
        assert(pendingTimeouts().length === 0, 're-enter cancels the pending close timer');
        assert(t1.a.d.open === true, 'submenu still open after re-enter');
        t1.a.d.open = false;

        // Outside-click closes open submenus.
        t1.b.d.open = true;
        docClick({ closest: function () { return null; } });
        assert(t1.b.d.open === false, 'outside click closes open submenus');

        // 7b. Media-query change to mobile CLOSES the outer details.
        setMq(BP, true);
        assert(t1.mobile.open === false, 'crossing to mobile closes the outer details');

        // 6. Sublink click closes the outer mobile details on MOBILE viewports only.
        t1.mobile.open = true; // hamburger tap
        docClick(t1.a.sublink);
        assert(t1.mobile.open === false, 'sublink click closes the drawer on mobile');

        // 7c. Media-query change to desktop OPENS the outer details.
        setMq(BP, false);
        assert(t1.mobile.open === true, 'crossing to desktop opens the outer details');

        // 6b. On desktop the outer details stays open across sublink clicks.
        docClick(t1.a.sublink);
        assert(t1.mobile.open === true, 'desktop sublink click leaves the outer details open');

        // Hover reveal is a desktop affordance: inert on mobile viewports.
        setMq(BP, true);
        fire(t1.a.li, 'mouseenter');
        assert(t1.a.d.open === false, 'hover does not open submenus on mobile');
        setMq(BP, false);

        // 8. Reduced motion: element.animate is never called; behavior is intact.
        mqEntry(RM).matches = true;
        var t2 = makeNavTree(true);
        RT.enhance(t2.mobile);
        t2.a.d.open = true;
        assert(t2.a.panel.animateCalls.length === 0, 'reduced motion skips element.animate');
        assert(t2.a.d.open === true && t2.b.d.open === false,
          'sibling exclusivity intact under reduced motion');

        console.log('ALL_PASS');
        JS;
    }

    public function testNavigationTeardownRestoresOpenStateAndRemovesEveryListenerAndTimer(): void
    {
        $src = $this->runtimeJs();
        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the navigation runtime module');
        }

        $file = sys_get_temp_dir() . '/thallo_navigation_teardown_' . getmypid() . '.mjs';
        file_put_contents($file, $this->teardownHarness($src));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "navigation teardown harness failed:\n" . implode("\n", $out));
            self::assertStringContainsString('ALL_PASS', implode("\n", $out));
        } finally {
            @unlink($file);
        }
    }

    /**
     * Teardown accounting, driven through the PUBLIC element lifecycle only (no
     * production test hook): register a harness-only x-navigation-lifecycle adapter
     * over the existing 'navigation' module (Task 2's registerElement bridge) whose
     * resolveTarget(host) returns the mobile drawer <details>, connect it on a host
     * wrapping the drawer, then disconnect and assert the open-state snapshot is
     * restored, the --js class is removed, every listener (document click + mq
     * change) is removed with the SAME ref, and any pending hover-close timeout is
     * cleared — followed by a clean reconnect.
     */
    private function teardownHarness(string $src): string
    {
        $json = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        'use strict';
        function assert(c, m) { if (!c) { console.error('FAIL: ' + m); process.exit(1); } }

        // --- timeout recorders (hover-intent close delay) + call counters ------
        // realSetTimeout is captured BEFORE the stub replaces global.setTimeout —
        // flush() needs a real timer to drain the connect microtask even while
        // the module's own hover-close timers are captured, not fired.
        var realSetTimeout = global.setTimeout;
        var timeoutSeq = 0;
        var activeTimeouts = {};
        var clearTimeoutCalls = 0;
        global.setTimeout = function (fn, ms) {
          timeoutSeq++;
          activeTimeouts[timeoutSeq] = { fn: fn, ms: ms };
          return timeoutSeq;
        };
        global.clearTimeout = function (id) { clearTimeoutCalls++; delete activeTimeouts[id]; };
        function pendingTimeouts() { return Object.keys(activeTimeouts); }

        // --- matchMedia keyed by query: controllable matches + same-ref add/remove --
        var mqState = {};
        function mqEntry(q) {
          if (!mqState[q]) { mqState[q] = { matches: false, addedRefs: [], removedRefs: [] }; }
          return mqState[q];
        }
        global.matchMedia = function (q) {
          var st = mqEntry(q);
          return {
            get matches() { return st.matches; },
            addEventListener: function (t, fn) { if (t === 'change') { st.addedRefs.push(fn); } },
            removeEventListener: function (t, fn) { if (t === 'change') { st.removedRefs.push(fn); } }
          };
        };
        var BP = '(max-width: 48rem)';

        // --- document + inert <html> stubs, with same-ref click add/remove -----
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
        var docAddedRefs = {};
        var docRemovedRefs = {};
        global.document = {
          readyState: 'complete',
          addEventListener: function (t, fn) {
            (docAddedRefs[t] = docAddedRefs[t] || []).push(fn);
          },
          removeEventListener: function (t, fn) {
            (docRemovedRefs[t] = docRemovedRefs[t] || []).push(fn);
          },
          querySelector: function () { return null; },
          querySelectorAll: function () { return []; },
          documentElement: inert
        };
        global.window = global;

        // --- nav tree stubs (mirrors the sibling test's makeNavTree) ------------
        function listenable(node) {
          node.listeners = {};
          node.addEventListener = function (t, fn) {
            (node.listeners[t] = node.listeners[t] || []).push(fn);
          };
          node.removeEventListener = function (t, fn) {
            var list = node.listeners[t] || [];
            var i = list.indexOf(fn);
            if (i !== -1) { list.splice(i, 1); }
          };
          return node;
        }
        function detailsOpenProp(node) {
          var isOpen = false;
          Object.defineProperty(node, 'open', {
            get: function () { return isOpen; },
            set: function (v) {
              v = !!v;
              if (v === isOpen) { return; }
              isOpen = v;
              (node.listeners.toggle || []).forEach(function (fn) { fn({ target: node }); });
            }
          });
        }
        function makeClassList(initial) {
          var set = {};
          initial.forEach(function (c) { set[c] = true; });
          return {
            add: function (c) { set[c] = true; },
            remove: function (c) { delete set[c]; },
            contains: function (c) { return !!set[c]; }
          };
        }
        function makeNavTree() {
          function makeParent() {
            var summary = listenable({ focus: function () {} });
            var panel = { animate: function () {} };
            var sublink = listenable({ focus: function () {} });
            var d = listenable({});
            detailsOpenProp(d);
            d.querySelector = function (sel) {
              if (sel === '[data-nav-toggle]') { return summary; }
              if (sel === '[data-nav-panel]') { return panel; }
              return null;
            };
            d.contains = function (n) { return n === summary || n === panel || n === sublink; };
            var li = listenable({});
            d.parentNode = li;
            return { d: d, summary: summary, panel: panel, sublink: sublink, li: li };
          }
          var a = makeParent();
          var b = makeParent();
          var root = { classList: makeClassList(['thallo-block', 'thallo-block-navigation',
            'thallo-block-navigation--reveal-hover']) };
          var members = [a.d, a.summary, a.panel, a.sublink, a.li, b.d, b.summary, b.panel, b.sublink, b.li];
          var attrs = {};
          var mobile = listenable({
            getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
            setAttribute: function (n, v) { attrs[n] = String(v); },
            removeAttribute: function (n) { delete attrs[n]; },
            matches: function (sel) { return sel === '[data-thallo-enhance="navigation"]'; },
            querySelectorAll: function (sel) {
              return sel === '.thallo-block-navigation__details' ? [a.d, b.d] : [];
            },
            querySelector: function () { return null; },
            // Real closest() supports comma-separated selector lists (the fix for
            // the scan-before-projection race adds a `thallo-navigation` tag-name
            // fallback alongside the class) — match on substring rather than exact
            // equality so this stub keeps working with either selector shape.
            closest: function (sel) { return sel.indexOf('.thallo-block-navigation') !== -1 ? root : null; },
            contains: function (n) { return members.indexOf(n) !== -1; }
          });
          detailsOpenProp(mobile);
          return { mobile: mobile, root: root, a: a, b: b };
        }

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
        function flush() { return new Promise(function (r) { realSetTimeout(r, 0); }); }

        eval($json);
        var RT = window.ThalloRuntime;

        (async function () {
          var tree = makeNavTree();
          var host = { isConnected: true };
          RT.registerElement('x-navigation-lifecycle', 'navigation', {
            resolveTarget: function () { return tree.mobile; }
          });

          upgrade('x-navigation-lifecycle', host);
          await flush();

          assert((tree.mobile.getAttribute('data-thallo-enhanced') || '').indexOf('navigation') !== -1,
            'connect enhanced + marked the mobile target');
          assert(tree.root.classList.contains('thallo-block-navigation--js'),
            'connect adds the --js handoff class');
          // Desktop width (BP matches=false, the stub default): enhance() itself
          // opens the outer drawer — a mutation AFTER the pre-enhance snapshot
          // (mobile.open was false before connect), so teardown must restore it
          // to false, not to whatever enhance() left it at.
          assert(tree.mobile.open === true, 'enhance() opens the outer drawer on desktop (precondition)');

          // Drive a hover close so a pending timeout exists before teardown.
          tree.a.li.listeners.mouseenter[0]();
          assert(tree.a.d.open === true, 'mouseenter opens the submenu (precondition)');
          tree.a.li.listeners.mouseleave[0]();
          assert(pendingTimeouts().length === 1, 'mouseleave scheduled a pending close timeout');

          // Open a parent details AFTER enhance (simulating the toggle listener) —
          // teardown must restore it to its pre-enhance snapshot (false), not to
          // whatever it happened to be at disconnect time.
          tree.b.d.open = true;
          assert(tree.b.d.open === true, 'B opened after enhance (precondition)');

          var docClickRef = docAddedRefs.click && docAddedRefs.click[0];
          var mqChangeRef = mqEntry(BP).addedRefs[0];
          assert(docClickRef && mqChangeRef, 'document click + mq change listeners were registered');

          var clearTimeoutBefore = clearTimeoutCalls;

          host.disconnectedCallback();

          // 1. Open-state snapshot restored — parents AND the outer mobile drawer
          //    (both parents were false at enhance(); the drawer was false too,
          //    even though enhance() itself had since flipped it true).
          assert(tree.a.d.open === false, 'A restored to its initial snapshot (false)');
          assert(tree.b.d.open === false, 'B restored to its initial snapshot (false), not left open');
          assert(tree.mobile.open === false,
            'outer mobile drawer restored to its pre-enhance snapshot (false), not left open');

          // 2. --js class removed.
          assert(!tree.root.classList.contains('thallo-block-navigation--js'),
            '--js class removed on teardown');

          // 3. document click + mq change listeners removed with the SAME ref.
          assert(docRemovedRefs.click && docRemovedRefs.click[0] === docClickRef,
            'document click listener removed with the SAME ref');
          assert(mqEntry(BP).removedRefs[0] === mqChangeRef,
            'mq change listener removed with the SAME ref');

          // 4. Pending hover-close timeout cleared.
          assert(clearTimeoutCalls > clearTimeoutBefore, 'clearTimeout called during teardown');
          assert(pendingTimeouts().length === 0, 'no pending hover-close timeout remains');

          // 5. Marker removed.
          assert((tree.mobile.getAttribute('data-thallo-enhanced') || '').indexOf('navigation') === -1,
            'navigation marker removed on disconnect');

          // 6. A second enhance after cleanup succeeds: re-adds the --js class.
          host.connectedCallback();
          await flush();
          assert((tree.mobile.getAttribute('data-thallo-enhanced') || '').indexOf('navigation') !== -1,
            'reconnect re-marked the mobile target');
          assert(tree.root.classList.contains('thallo-block-navigation--js'),
            'reconnect re-adds the --js class');

          console.log('ALL_PASS');
        })().catch(function (e) { console.error('FAIL: ' + (e && e.stack)); process.exit(1); });
        JS;
    }
}
