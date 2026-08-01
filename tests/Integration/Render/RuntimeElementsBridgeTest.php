<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Executable coverage for ThalloRuntime.registerElement() (theme-runtime spec §1): the
 * custom-element bridge into the private runComponent() pipeline landed in Task 1.
 * Mirrors RuntimeCoreTest's Node + hand-stubbed-DOM pattern, adding a customElements
 * stub and a manual-upgrade helper since the harness drives element lifecycle by hand
 * (no real customElements registry/parser in Node).
 */
final class RuntimeElementsBridgeTest extends AppTestCase
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

    public function testRegisterElementBridgesLifecycleIntoThePrivatePipeline(): void
    {
        $src = $this->runtimeJs();

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the runtime elements bridge');
        }

        $file = sys_get_temp_dir() . '/thallo_runtime_elements_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($src));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "elements bridge harness failed:\n" . implode("\n", $out));
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

        eval($json);
        var RT = window.ThalloRuntime;
        var flush = function () { return new Promise(function (r) { setTimeout(r, 0); }); };

        // Accounting for FINDING 1's fix: a throw from a caller-supplied adapter
        // (resolveTarget/projectOptions) must be caught internally, never surface as
        // an unhandled promise rejection.
        var unhandledRejections = 0;
        process.on('unhandledRejection', function () { unhandledRejections++; });

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

          // 11. Unknown module: registerElement names a module that was never
          //     registered — no module lookup match, so abandon before any mutation.
          var unknownProjected = 0;
          RT.registerElement('x-unknown', 'never-registered', {
            projectOptions: function (elm) { unknownProjected++; return function () {}; }
          });
          var h8 = docRoot.appendChild(el(''));
          upgrade('x-unknown', h8);
          await flush();
          assert(unknownProjected === 0, 'unknown module: projection never ran');
          assert(h8.getAttribute('data-thallo-enhanced') === null, 'unknown module: never marked');
          h8.disconnectedCallback(); // must be a harmless no-op — no record was ever kept
          assert(unknownProjected === 0, 'unknown module: disconnect stayed a no-op');

          // 12. Not connected: isConnected flips false before the deferred microtask
          //     runs (e.g. inserted then immediately removed within the same tick).
          var notConnectedProjected = 0;
          RT.registerElement('x-disconnected', 'probe', {
            projectOptions: function (elm) { notConnectedProjected++; return function () {}; }
          });
          var h9 = docRoot.appendChild(el(''));
          upgrade('x-disconnected', h9);
          h9.isConnected = false;
          await flush();
          assert(notConnectedProjected === 0, 'not connected: projection never ran');
          assert(h9.getAttribute('data-thallo-enhanced') === null, 'not connected: never marked');

          // 13. FINDING 1: a throwing resolveTarget is caught individually — logged,
          //     abandoned cleanly, and — the load-bearing assertion — never surfaces
          //     as an unhandled promise rejection (checked globally at the end).
          var throwResolveCalls = 0;
          RT.register('throwresolve', { selector: '.throwresolve-root', enhance: function () {} });
          RT.registerElement('x-throwresolve', 'throwresolve', {
            resolveTarget: function () { throwResolveCalls++; throw new Error('resolve boom'); },
            projectOptions: function () {
              assert(false, 'projection must not run when resolveTarget throws');
            }
          });
          var h10 = docRoot.appendChild(el(''));
          upgrade('x-throwresolve', h10);
          await flush();
          assert(throwResolveCalls === 1, 'resolveTarget was consulted');
          assert(h10.getAttribute('data-thallo-enhanced') === null, 'throwing resolveTarget: never marked');

          assert(unhandledRejections === 0,
            'no unhandled promise rejections occurred: ' + unhandledRejections);

          console.log('ALL_PASS');
        })().catch(function (e) { console.error('FAIL: ' + (e && e.message)); process.exit(1); });
        JS;
    }
}
