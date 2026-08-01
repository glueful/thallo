<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Executable coverage for the four v1 theme-runtime custom elements (web-components spec
 * §4): thallo-carousel / thallo-tabs / thallo-navigation ride Task 2's registerElement
 * transactional bridge into the existing carousel/tabs/navigation modules (attribute
 * sugar projected into the existing data-* option vocabulary, existing data-* always
 * wins); thallo-color-mode-toggle is the explicit pipeline exception — it never enters
 * registerElement, it only re-syncs window.thalloColorMode.reflect() on connect.
 * Mirrors RuntimeElementsBridgeTest's Node + hand-stubbed-DOM + customElements pattern,
 * with the stub node reflecting data-* setAttribute/removeAttribute calls into .dataset
 * (the carousel module reads root.dataset.arrows; production projects only the
 * attribute and relies on native dataset reflection, same as a real browser).
 */
final class RuntimeElementsTest extends AppTestCase
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

    private function runHarness(string $js, string $suffix): void
    {
        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the runtime elements');
        }

        $file = sys_get_temp_dir() . '/thallo_elements_' . $suffix . '_' . getmypid() . '.mjs';
        file_put_contents($file, $js);
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "elements harness ({$suffix}) failed:\n" . implode("\n", $out));
            self::assertStringContainsString('ALL_PASS', implode("\n", $out));
        } finally {
            @unlink($file);
        }
    }

    public function testAllFourTagsAreDefinedAndTheThreeModuleBackedElementsProjectAndTeardown(): void
    {
        $src = $this->runtimeJs();
        self::assertStringContainsString('/* elements:start', $src);
        self::assertStringContainsString('/* color-mode-toggle:start', $src);
        $this->runHarness($this->elementsHarness($src), 'main');
    }

    public function testBootOrderingWhileDocumentIsLoading(): void
    {
        $this->runHarness($this->bootLoadingHarness($this->runtimeJs()), 'boot-loading');
    }

    public function testBootOrderingWhenDocumentIsAlreadyComplete(): void
    {
        $this->runHarness($this->bootCompleteHarness($this->runtimeJs()), 'boot-complete');
    }

    /**
     * Regression for the scan-before-projection race (final review, navigation
     * finding 1): a manual same-task ThalloRuntime.enhance() can reach the drawer
     * (selector `[data-thallo-enhance="navigation"]`) BEFORE the element's own
     * connection microtask has projected `.thallo-block-navigation` /
     * `--reveal-hover` onto the host. Before the fix, `mobile.closest('.thallo-block-navigation')`
     * found nothing (the class isn't there yet) and fell back to the drawer
     * itself, so the module's root-scoped work (the `--js` class stamp, and the
     * reveal-hover hover-intent wiring) landed on the wrong element and stayed
     * wrong for the component's lifetime (enhance() runs once; the closed-over
     * `root` never gets corrected once projection eventually lands). The fix
     * makes closest() also match the bare `thallo-navigation` tag name (stable
     * from the start, unlike the projected class) and treats the `reveal-hover`
     * ATTRIBUTE (present in markup immediately) as equivalent to the projected
     * class.
     */
    public function testManualEnhanceBeforeConnectionMicrotaskFlushResolvesNavigationRootToHost(): void
    {
        $this->runHarness($this->manualNavEnhanceHarness($this->runtimeJs()), 'manual-nav-enhance');
    }

    /** Case: manual RT.enhance() reaching the drawer ahead of the connection microtask. */
    private function manualNavEnhanceHarness(string $src): string
    {
        $json = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $prelude = $this->stubPrelude();

        return <<<JS
        {$prelude}

        eval({$json});
        var RT = window.ThalloRuntime;

        (async function () {
          var n = makeNavHost(true); // host carries the reveal-hover ATTRIBUTE; drawer inside

          // A nested submenu <details>, wrapped the way real markup wraps it (a
          // parent the hover-intent listeners attach to), so reveal-hover behavior
          // is directly observable, not just the root-resolution side effect.
          var li = el('li');
          var sub = el('details');
          sub.classList.add('thallo-block-navigation__details');
          sub.open = false;
          var summary = el('summary');
          summary.setAttribute('data-nav-toggle', '');
          sub.appendChild(summary);
          var panel = el('div');
          panel.setAttribute('data-nav-panel', '');
          sub.appendChild(panel);
          li.appendChild(sub);
          n.drawer.appendChild(li);

          // Upgrade queues the connection microtask but does NOT flush it — this
          // models the parser-upgrade timing without letting projection run yet.
          upgrade('thallo-navigation', n.host);

          // Manual, same-task RT.enhance() reaches the drawer via the module
          // selector BEFORE the queued connection microtask (and therefore
          // projection) has run.
          RT.enhance(n.host);

          assert(n.host.classList.contains('thallo-block-navigation--js'),
            'root resolved to the HOST (tag-name closest match), not the drawer, ' +
            'even though the class had not been projected yet');
          assert(!sub.open, 'sanity: submenu starts closed');

          li.dispatchEvent({ type: 'mouseenter' });
          assert(sub.open === true,
            'reveal-hover behavior is active: honored via the reveal-hover ATTRIBUTE ' +
            'fallback even though the class was not projected yet at enhance() time');

          // Flushing the (still-pending) connection microtask afterwards must be a
          // harmless no-op for the module (already marked) and must finish
          // projecting the host classes the module bypassed at manual-enhance time.
          await flush();
          assert(n.host.classList.contains('thallo-block-navigation'), 'root class projected on flush');
          assert(n.host.classList.contains('thallo-block-navigation--reveal-hover'),
            'reveal-hover class projected on flush');
          assert((n.drawer.getAttribute('data-thallo-enhanced') || '').split(' ')
            .filter(function (t) { return t === 'navigation'; }).length === 1,
            'drawer marked exactly once: flush did not double-enhance');

          n.host.disconnectedCallback();
          assert(!n.host.classList.contains('thallo-block-navigation--js'), 'disconnect: --js class gone');
          assert(!n.host.classList.contains('thallo-block-navigation--reveal-hover'),
            'disconnect: reveal-hover class gone');
          assert(!n.host.classList.contains('thallo-block-navigation'), 'disconnect: root class gone');
          assert(n.drawer.getAttribute('data-thallo-enhanced') === null, 'disconnect: drawer marker gone');

          console.log('ALL_PASS');
        })().catch(function (e) { console.error('FAIL: ' + (e && e.stack)); process.exit(1); });
        JS;
    }

    /** Shared stub prelude: generic DOM node factory + customElements/document/window. */
    private function stubPrelude(): string
    {
        return <<<'JS'
        'use strict';
        function assert(c, m) { if (!c) { console.error('FAIL: ' + m); process.exit(1); } }

        // --- timer stubs: real setInterval could hang the process; stub it, keep
        //     the real setTimeout so the harness's own flush() still works. -------
        var intervalSeq = 0;
        var activeIntervals = {};
        global.setInterval = function (fn, ms) {
          intervalSeq++;
          activeIntervals[intervalSeq] = { fn: fn, ms: ms };
          return intervalSeq;
        };
        global.clearInterval = function (id) { delete activeIntervals[id]; };

        global.matchMedia = function () {
          return { matches: false, addEventListener: function () {}, removeEventListener: function () {} };
        };

        // --- generic element stub: classList, attrs, dataset (data-* setAttribute /
        //     removeAttribute reflect into .dataset — single-segment names only, mirroring
        //     what a real browser does natively), parent-aware appendChild,
        //     closest()/contains() walking the tree, and a minimal matches()/
        //     querySelector(All)() supporting '.class' and '[attr]'/'[attr="value"]'
        //     selectors (the only shapes the modules use). --
        // Comma-separated selector lists (e.g. '.thallo-block-navigation, thallo-navigation')
        // and bare tag-name selectors, in addition to the existing '.class' / '[attr]' forms —
        // needed to model closest() resolving a custom-element host by tag name.
        function matchesOne(node, sel) {
          if (sel.charAt(0) === '.') { return node.classList.contains(sel.slice(1)); }
          if (sel.charAt(0) === '[') {
            var m = /^\[([\w-]+)(="([^"]*)")?\]$/.exec(sel);
            if (!m) { return false; }
            var name = m[1];
            var val = m[3];
            if (val === undefined) { return node.hasAttribute(name); }
            return node.getAttribute(name) === val;
          }
          if (/^[\w-]+$/.test(sel)) { return node.tagName === sel.toUpperCase(); }
          return false;
        }
        function matchesSelector(node, sel) {
          return sel.split(',').some(function (part) { return matchesOne(node, part.trim()); });
        }
        function el(tag) {
          var attrs = {};
          var classes = [];
          var listeners = {};
          var node = {
            tagName: String(tag || 'div').toUpperCase(),
            nodeType: 1,
            children: [],
            parentNode: null,
            isConnected: true,
            dataset: {},
            classList: {
              add: function (c) { if (classes.indexOf(c) === -1) { classes.push(c); } },
              remove: function (c) { var i = classes.indexOf(c); if (i !== -1) { classes.splice(i, 1); } },
              contains: function (c) { return classes.indexOf(c) !== -1; }
            },
            appendChild: function (c) { c.parentNode = node; node.children.push(c); return c; },
            removeChild: function (c) {
              var i = node.children.indexOf(c);
              if (i !== -1) { node.children.splice(i, 1); c.parentNode = null; }
              return c;
            },
            getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
            // Real browsers reflect data-* attributes into .dataset automatically (the
            // production project() helper no longer dual-writes it); mirror ONLY the
            // single-segment sugar names the carousel module reads (data-arrows ->
            // dataset.arrows) — no camelCase translation for multi-segment names, since
            // nothing here needs it.
            setAttribute: function (n, v) {
              attrs[n] = String(v);
              if (n.indexOf('data-') === 0) { node.dataset[n.slice(5)] = String(v); }
            },
            removeAttribute: function (n) {
              delete attrs[n];
              if (n.indexOf('data-') === 0) { delete node.dataset[n.slice(5)]; }
            },
            hasAttribute: function (n) { return attrs[n] !== undefined; },
            addEventListener: function (t, fn) { (listeners[t] = listeners[t] || []).push(fn); },
            removeEventListener: function (t, fn) {
              var arr = listeners[t] || [];
              var i = arr.indexOf(fn);
              if (i !== -1) { arr.splice(i, 1); }
            },
            dispatchEvent: function (ev) {
              (listeners[ev.type] || []).slice().forEach(function (fn) { fn(ev); });
              return true;
            },
            matches: function (sel) { return matchesSelector(node, sel); },
            closest: function (sel) {
              var cur = node;
              while (cur) { if (cur.matches && cur.matches(sel)) { return cur; } cur = cur.parentNode; }
              return null;
            },
            contains: function (other) {
              var cur = other;
              while (cur) { if (cur === node) { return true; } cur = cur.parentNode; }
              return false;
            },
            querySelectorAll: function (sel) {
              var found = [];
              (function walk(n) {
                n.children.forEach(function (c) {
                  if (c.matches(sel)) { found.push(c); }
                  walk(c);
                });
              })(node);
              return found;
            },
            querySelector: function (sel) { return node.querySelectorAll(sel)[0] || null; },
            focus: function () {},
            animate: function () {}
          };
          return node;
        }

        var htmlEl = el('html');
        global.document = {
          readyState: 'complete',
          hidden: false,
          addEventListener: function () {},
          removeEventListener: function () {},
          querySelector: function () { return null; }, // no .thallo-preview-block: not canvas
          querySelectorAll: function () { return []; },
          createElement: el,
          documentElement: htmlEl
        };
        global.window = global;

        // customElements stub: capture classes; the harness drives lifecycle manually.
        var defined = {};
        global.HTMLElement = function () {};
        global.customElements = { define: function (tag, cls) { defined[tag] = cls; } };
        function upgrade(tag, node) {
          node.connectedCallback = defined[tag].prototype.connectedCallback;
          node.disconnectedCallback = defined[tag].prototype.disconnectedCallback;
          node.connectedCallback();
          return node;
        }
        function flush() { return new Promise(function (r) { setTimeout(r, 0); }); }

        // --- component-shaped light-DOM builders --------------------------------
        // Injected controls (carousel's button()/live region) set a plain
        // .className property directly, NOT via classList.add — mirror both shapes.
        function hasClass(node, cls) {
          return (node.classList && node.classList.contains(cls)) || node.className === cls;
        }
        function findByClass(root, cls) {
          for (var i = 0; i < root.children.length; i++) {
            if (hasClass(root.children[i], cls)) { return root.children[i]; }
          }
          return null;
        }
        function countByClass(root, cls) {
          var n = 0;
          for (var i = 0; i < root.children.length; i++) {
            if (hasClass(root.children[i], cls)) { n++; }
          }
          return n;
        }
        function makeCarouselHost() {
          var host = el('thallo-carousel');
          var viewport = el('div');
          viewport.classList.add('thallo-block-carousel__viewport');
          viewport.scrollLeft = 0;
          viewport.scrollTo = function (opts) { viewport.scrollLeft = opts.left; };
          var track = el('div');
          track.classList.add('thallo-block-carousel__track');
          track.offsetLeft = 0;
          [0, 400, 800].forEach(function (x) {
            var slide = el('div');
            slide.offsetLeft = x;
            track.children.push(slide); // slide membership only; no parent-walk needed
          });
          viewport.appendChild(track);
          host.appendChild(viewport);
          return { host: host, viewport: viewport, track: track };
        }
        function makeTabsHost(n) {
          var host = el('thallo-tabs');
          var radios = [];
          var labels = [];
          var panels = [];
          var list = el('div');
          list.classList.add('thallo-block-tabs__list');
          var panelsBox = el('div');
          panelsBox.classList.add('thallo-block-tabs__panels');
          for (var i = 0; i < n; i++) {
            var r = el('input');
            r.classList.add('thallo-block-tabs__radio');
            r.setAttribute('id', 'tabs-b1-' + (i + 1));
            r.checked = i === 0;
            host.appendChild(r);
            radios.push(r);
            var lab = el('label');
            lab.classList.add('thallo-block-tabs__label');
            list.appendChild(lab);
            labels.push(lab);
            var p = el('div');
            p.classList.add('thallo-block-tabs__panel');
            panelsBox.appendChild(p);
            panels.push(p);
          }
          host.appendChild(list);
          host.appendChild(panelsBox);
          return { host: host, radios: radios, labels: labels, panels: panels, list: list, panelsBox: panelsBox };
        }
        function makeNavHost(withDrawer) {
          var host = el('thallo-navigation');
          host.setAttribute('reveal-hover', '');
          var drawer = null;
          if (withDrawer) {
            drawer = el('details');
            drawer.setAttribute('data-thallo-enhance', 'navigation');
            drawer.open = false;
            host.appendChild(drawer);
          }
          return { host: host, drawer: drawer };
        }

        JS;
    }

    /** Cases 1-7: registration split, carousel sugar (+ existing data-* wins), tabs,
     *  navigation target resolution, the toggle exception, and double-path idempotency. */
    private function elementsHarness(string $src): string
    {
        $json = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $prelude = $this->stubPrelude();

        return <<<JS
        {$prelude}

        eval({$json});
        var RT = window.ThalloRuntime;

        (async function () {
          // 1. Registration split: exactly these four tags defined; the toggle's
          //    class is NOT produced by registerElement (no pipeline record for it).
          assert(defined['thallo-carousel'] && defined['thallo-tabs'] &&
                 defined['thallo-navigation'] && defined['thallo-color-mode-toggle'],
            'all four v1 tags defined');
          assert(Object.keys(defined).length === 4, 'exactly four tags defined');

          // 2. Carousel sugar: arrows + autoplay present, dots absent.
          var c1 = makeCarouselHost();
          c1.host.setAttribute('arrows', '');
          c1.host.setAttribute('autoplay', '');
          upgrade('thallo-carousel', c1.host);
          await flush();
          assert(c1.host.getAttribute('data-arrows') === '1', 'arrows sugar projected');
          assert(c1.host.getAttribute('data-autoplay') === '1', 'autoplay sugar projected');
          assert(c1.host.getAttribute('data-dots') === null, 'dots sugar NOT projected (absent attr)');
          assert(c1.host.classList.contains('thallo-block-carousel'), 'root class stamped');
          assert((c1.host.getAttribute('data-thallo-enhanced') || '').indexOf('carousel') !== -1,
            'carousel enhanced + marked');
          assert(findByClass(c1.host, 'thallo-block-carousel__prev') !== null, 'arrows: prev injected');
          assert(findByClass(c1.host, 'thallo-block-carousel__next') !== null, 'arrows: next injected');
          assert(findByClass(c1.host, 'thallo-block-carousel__pause') !== null, 'autoplay: pause injected');
          assert(findByClass(c1.host, 'thallo-block-carousel__status') !== null, 'autoplay: status injected');
          assert(findByClass(c1.host, 'thallo-block-carousel__dots') === null, 'no dots wrap injected');

          c1.host.disconnectedCallback();
          assert(findByClass(c1.host, 'thallo-block-carousel__prev') === null, 'disconnect: prev removed');
          assert(findByClass(c1.host, 'thallo-block-carousel__next') === null, 'disconnect: next removed');
          assert(findByClass(c1.host, 'thallo-block-carousel__pause') === null, 'disconnect: pause removed');
          assert(findByClass(c1.host, 'thallo-block-carousel__status') === null, 'disconnect: status removed');
          assert(!c1.host.classList.contains('thallo-block-carousel'), 'disconnect: root class removed');
          assert(c1.host.getAttribute('data-arrows') === null, 'disconnect: data-arrows removed');
          assert(c1.host.getAttribute('data-autoplay') === null, 'disconnect: data-autoplay removed');
          assert(c1.host.dataset.arrows === undefined, 'disconnect: dataset.arrows removed too');
          assert(c1.host.getAttribute('data-thallo-enhanced') === null, 'disconnect: marker gone');

          // 3. Existing data-* wins over sugar: explicit data-arrows="0" survives.
          var c2 = makeCarouselHost();
          c2.host.setAttribute('arrows', '');
          c2.host.setAttribute('data-arrows', '0'); // stub reflects this into dataset.arrows too
          upgrade('thallo-carousel', c2.host);
          await flush();
          assert(c2.host.getAttribute('data-arrows') === '0',
            'existing data-arrows NOT overwritten by sugar');
          assert(findByClass(c2.host, 'thallo-block-carousel__prev') === null,
            'arrows sugar suppressed: no prev/next injected');
          c2.host.disconnectedCallback();

          // 4. Tabs: stamps root class, enhances the radio floor, disconnect restores
          //    the served floor (Task 5's cleanup, reached through the element path).
          var t = makeTabsHost(3);
          upgrade('thallo-tabs', t.host);
          await flush();
          assert(t.host.classList.contains('thallo-block-tabs'), 'tabs root class stamped');
          assert(t.list.getAttribute('role') === 'tablist', 'tabs radio floor enhanced');
          assert((t.host.getAttribute('data-thallo-enhanced') || '').indexOf('tabs') !== -1, 'tabs marked');
          t.host.disconnectedCallback();
          assert(!t.host.classList.contains('thallo-block-tabs'), 'tabs disconnect: root class removed');
          assert(t.list.getAttribute('role') === null, 'tabs disconnect: served floor restored (no role)');
          assert(t.radios[0].checked === true, 'tabs disconnect: baseline checked restored');
          assert(t.host.getAttribute('data-thallo-enhanced') === null, 'tabs disconnect: marker gone');

          // 5. Navigation target resolution: marker lands on the DRAWER, not the host;
          //    --reveal-hover + root class stamped on the HOST.
          var n1 = makeNavHost(true);
          upgrade('thallo-navigation', n1.host);
          await flush();
          assert(n1.host.classList.contains('thallo-block-navigation'), 'nav root class on HOST');
          assert(n1.host.classList.contains('thallo-block-navigation--reveal-hover'),
            'reveal-hover class on HOST');
          assert((n1.drawer.getAttribute('data-thallo-enhanced') || '').indexOf('navigation') !== -1,
            'marker lands on the DRAWER');
          assert(n1.host.getAttribute('data-thallo-enhanced') === null, 'host itself carries no marker');
          n1.host.disconnectedCallback();
          assert(!n1.host.classList.contains('thallo-block-navigation'), 'nav disconnect: host class gone');
          assert(!n1.host.classList.contains('thallo-block-navigation--reveal-hover'),
            'nav disconnect: reveal-hover class gone');
          assert(n1.drawer.getAttribute('data-thallo-enhanced') === null, 'nav disconnect: drawer marker gone');

          // Missing drawer: nothing projected, nothing marked anywhere.
          var n2 = makeNavHost(false);
          upgrade('thallo-navigation', n2.host);
          await flush();
          assert(!n2.host.classList.contains('thallo-block-navigation'),
            'missing drawer: nothing projected on host');
          assert(n2.host.getAttribute('data-thallo-enhanced') === null, 'missing drawer: host not marked');

          // 6. Toggle: connectedCallback calls window.thalloColorMode.reflect() once
          //    after a flush when present; absent, it must not throw.
          var ToggleClass = defined['thallo-color-mode-toggle'];
          var reflectCalls = 0;
          window.thalloColorMode = { reflect: function () { reflectCalls++; } };
          var toggle1 = new ToggleClass();
          toggle1.connectedCallback();
          await flush();
          assert(reflectCalls === 1, 'toggle calls reflect() once with thalloColorMode present');

          delete window.thalloColorMode;
          var toggle2 = new ToggleClass();
          var threw = false;
          try { toggle2.connectedCallback(); await flush(); } catch (e) { threw = true; }
          assert(!threw, 'toggle connectedCallback does not throw without window.thalloColorMode');
          assert(reflectCalls === 1, 'reflect not called again when thalloColorMode is absent');

          // 7a. Double-path (scan-first): host already carries the markup class, scan
          //     enhances it directly; connect afterwards must NOT re-invoke the
          //     enhancer, and disconnect must adopt + run the scan-time cleanup.
          var c3 = makeCarouselHost();
          c3.host.classList.add('thallo-block-carousel');
          c3.host.setAttribute('arrows', '');
          c3.host.dataset.arrows = '1'; // markup already carries the resolved option
          RT.enhance(c3.host);
          assert((c3.host.getAttribute('data-thallo-enhanced') || '').indexOf('carousel') !== -1,
            'scan-first: enhanced + marked directly via RT.enhance');
          assert(countByClass(c3.host, 'thallo-block-carousel__prev') === 1, 'scan-first: injected once');
          upgrade('thallo-carousel', c3.host);
          await flush();
          assert(countByClass(c3.host, 'thallo-block-carousel__prev') === 1,
            'scan-first: connect after scan did not re-invoke the enhancer');
          c3.host.disconnectedCallback();
          assert(countByClass(c3.host, 'thallo-block-carousel__prev') === 0,
            'scan-first: disconnect adopted + ran the scan-time cleanup');
          assert(c3.host.getAttribute('data-thallo-enhanced') === null,
            'scan-first: marker removed via the adopted cleanup');

          // 7b. Double-path (connect-first): connect enhances once; a later whole-tree
          //     scan over the (now-classed) host must not double-enhance.
          var c4 = makeCarouselHost();
          c4.host.setAttribute('arrows', '');
          upgrade('thallo-carousel', c4.host);
          await flush();
          assert(countByClass(c4.host, 'thallo-block-carousel__prev') === 1, 'connect-first: injected once');
          RT.enhance(c4.host);
          assert(countByClass(c4.host, 'thallo-block-carousel__prev') === 1,
            'connect-first: later document scan did not double-enhance');
          c4.host.disconnectedCallback();

          console.log('ALL_PASS');
        })().catch(function (e) { console.error('FAIL: ' + (e && e.stack)); process.exit(1); });
        JS;
    }

    /**
     * Case 8 (loading): the customElements.define stub synchronously grafts the
     * lifecycle onto a pre-created host and invokes connectedCallback, modeling the
     * browser's parser upgrade of already-parsed elements. With readyState 'loading',
     * the footer only captures the DOMContentLoaded handler — it does not schedule a
     * scan yet. Flushing the connection microtask must project + enhance BEFORE the
     * captured handler (and therefore the document scan) ever runs.
     */
    private function bootLoadingHarness(string $src): string
    {
        $json = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $prelude = $this->stubPrelude();

        return <<<JS
        {$prelude}

        // Override the prelude's customElements.define: synchronously graft +
        // invoke connectedCallback for any host pre-registered under that tag —
        // models the parser upgrading an already-parsed custom element.
        var pendingHosts = {};
        function registerPendingHost(tag, host) {
          (pendingHosts[tag] = pendingHosts[tag] || []).push(host);
        }
        global.customElements = {
          define: function (tag, cls) {
            defined[tag] = cls;
            (pendingHosts[tag] || []).forEach(function (host) {
              host.connectedCallback = cls.prototype.connectedCallback;
              host.disconnectedCallback = cls.prototype.disconnectedCallback;
              host.connectedCallback();
            });
          }
        };

        // readyState 'loading': the footer must only capture the handler.
        var domContentLoadedHandler = null;
        global.document.readyState = 'loading';
        global.document.addEventListener = function (t, fn) {
          if (t === 'DOMContentLoaded') { domContentLoadedHandler = fn; }
        };

        var host = makeCarouselHost().host;
        host.setAttribute('arrows', '');
        htmlEl.appendChild(host);
        registerPendingHost('thallo-carousel', host);

        eval({$json});
        assert(domContentLoadedHandler !== null, 'footer captured the DOMContentLoaded handler');

        (async function () {
          // Flush the connection microtask queued by the synchronous upgrade above.
          await flush();
          assert(host.getAttribute('data-arrows') === '1',
            'projection ran via the connection microtask, before any scan');
          assert((host.getAttribute('data-thallo-enhanced') || '').indexOf('carousel') !== -1,
            'enhancement ran once, before any scan');
          assert(countByClass(host, 'thallo-block-carousel__prev') === 1, 'controls injected once');

          // Now invoke the captured handler: this is the whole-document scan.
          domContentLoadedHandler();
          assert(countByClass(host, 'thallo-block-carousel__prev') === 1,
            'document scan after DOMContentLoaded did not double-enhance');
          assert((host.getAttribute('data-thallo-enhanced') || '').split(' ')
            .filter(function (t) { return t === 'carousel'; }).length === 1,
            'marker carries exactly one carousel token');

          console.log('ALL_PASS');
        })().catch(function (e) { console.error('FAIL: ' + (e && e.stack)); process.exit(1); });
        JS;
    }

    /**
     * Case 8 (complete): readyState 'complete' means the footer schedules boot via a
     * SINGLE Promise.resolve().then(boot) at eval time. Because the pre-created
     * host's synchronous upgrade (during customElements.define, inside the
     * elements section) queues its own connection microtask BEFORE the footer runs
     * (the footer is the last thing in the file), FIFO microtask ordering guarantees
     * the connection work — and therefore projection — resolves before the footer's
     * scan, even though both were queued in the same synchronous pass.
     */
    private function bootCompleteHarness(string $src): string
    {
        $json = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $prelude = $this->stubPrelude();

        return <<<JS
        {$prelude}

        var pendingHosts = {};
        function registerPendingHost(tag, host) {
          (pendingHosts[tag] = pendingHosts[tag] || []).push(host);
        }
        global.customElements = {
          define: function (tag, cls) {
            defined[tag] = cls;
            (pendingHosts[tag] || []).forEach(function (host) {
              host.connectedCallback = cls.prototype.connectedCallback;
              host.disconnectedCallback = cls.prototype.disconnectedCallback;
              host.connectedCallback();
            });
          }
        };

        global.document.readyState = 'complete'; // footer path: Promise.resolve().then(boot)

        var host = makeCarouselHost().host;
        host.setAttribute('arrows', '');
        htmlEl.appendChild(host);
        registerPendingHost('thallo-carousel', host);

        eval({$json});

        // Wrap RT.enhance (the document-scan entry point) and host.setAttribute
        // (the projection write) to build an ordering trace — done AFTER eval, but
        // BEFORE any microtask has run (still the same synchronous turn), so both
        // wrappers are in place before either queued microtask fires.
        var trace = [];
        var realEnhance = window.ThalloRuntime.enhance;
        window.ThalloRuntime.enhance = function (root) {
          trace.push('document-scan');
          return realEnhance(root);
        };
        var realSetAttribute = host.setAttribute;
        host.setAttribute = function (n, v) {
          if (n === 'data-arrows') { trace.push('projection'); }
          return realSetAttribute(n, v);
        };

        (async function () {
          await flush(); // one turn: drains both queued microtasks in FIFO order

          assert(trace.indexOf('projection') !== -1, 'projection was traced');
          assert(trace.indexOf('document-scan') !== -1, 'document-scan was traced');
          assert(trace.indexOf('projection') < trace.indexOf('document-scan'),
            'projection precedes the document scan: ' + JSON.stringify(trace));
          assert(countByClass(host, 'thallo-block-carousel__prev') === 1,
            'controls were injected exactly once');
          var tokens = (host.getAttribute('data-thallo-enhanced') || '').split(' ')
            .filter(function (t) { return t === 'carousel'; });
          assert(tokens.length === 1, 'marker carries exactly one carousel token');

          console.log('ALL_PASS');
        })().catch(function (e) { console.error('FAIL: ' + (e && e.stack)); process.exit(1); });
        JS;
    }
}
