<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Executable coverage for the tabs runtime module (theme-runtime spec §4): real ARIA tabs
 * layered over the no-ARIA radio floor — tablist/tab/tabpanel roles with stable derived
 * ids, roving tabindex, automatic activation (ArrowLeft/ArrowRight with wrap, Home/End,
 * focus + select together), label clicks driving selection, radios hidden LAST, and the
 * critical radio sync: the floor's enumerated checked-pairing selector (0,6,0) outranks
 * the enhanced __panel[hidden] rule (0,4,0), so the old radio must be unchecked on every
 * selection change or its hidden panel would stay visible. Fail-safe: any throw during
 * enhancement replays a full undo log in reverse (attributes restored, listeners
 * detached) and rethrows, so the core leaves the component unmarked and the honest radio
 * floor stays intact in markup AND behavior. Mirrors RuntimeCoreTest's Node +
 * hand-stubbed-DOM pattern; skips (not fails) without node but always asserts structural
 * markers.
 */
final class TabsRuntimeTest extends AppTestCase
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

    public function testTabsAriaKeyboardRadioSyncAndFailSafeRollback(): void
    {
        $src = $this->runtimeJs();
        self::assertStringContainsString('/* tabs:start', $src);
        self::assertStringContainsString("register('tabs'", $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the tabs runtime module');
        }

        $file = sys_get_temp_dir() . '/thallo_tabs_runtime_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($src));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "tabs harness failed:\n" . implode("\n", $out));
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
        var realError = console.error.bind(console);
        function assert(c, m) { if (!c) { realError('FAIL: ' + m); process.exit(1); } }
        // The core's throw containment logs via console.error; swallow that noise but
        // keep console.log so ALL_PASS still reaches stdout.
        global.console = { error: function () {}, log: console.log };

        // --- document + inert <html> stubs (color-mode gate off; not canvas) ----
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
          addEventListener: function () {},
          querySelector: function () { return null; }, // no .thallo-preview-block: not canvas
          querySelectorAll: function () { return []; },
          documentElement: inert
        };
        global.window = global;

        // --- recording element stubs --------------------------------------------
        var mutationSeq = []; // every successful setAttribute, in order (phase ordering)
        function makeClassList(initial) {
          var set = {};
          initial.forEach(function (c) { set[c] = true; });
          return {
            add: function (c) { set[c] = true; },
            contains: function (c) { return !!set[c]; }
          };
        }
        function el(classes, opts) {
          opts = opts || {};
          var attrs = {};
          var node = {
            children: [],
            classList: makeClassList(classes),
            live: {},    // type -> handlers currently attached
            addCount: 0, // every addEventListener call ever made
            focusCount: 0,
            getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
            setAttribute: function (n, v) {
              if (opts.throwOnSet) { throw new Error('stub setAttribute refused'); }
              attrs[n] = String(v);
              mutationSeq.push({ el: node, name: n });
            },
            removeAttribute: function (n) { delete attrs[n]; },
            addEventListener: function (t, fn) {
              if (opts.throwOnListen) { throw new Error('stub addEventListener refused'); }
              node.addCount++;
              (node.live[t] = node.live[t] || []).push(fn);
            },
            removeEventListener: function (t, fn) {
              var arr = node.live[t] || [];
              var i = arr.indexOf(fn);
              if (i !== -1) { arr.splice(i, 1); }
            },
            focus: function () { node.focusCount++; }
          };
          if (opts.attrs) {
            Object.keys(opts.attrs).forEach(function (k) { attrs[k] = String(opts.attrs[k]); });
          }
          return node;
        }
        function fire(node, type, ev) {
          (node.live[type] || []).slice().forEach(function (fn) { fn(ev || {}); });
        }
        function liveCount(node) {
          return Object.keys(node.live).reduce(function (n, t) {
            return n + node.live[t].length;
          }, 0);
        }

        // --- tabs component stub (floor markup shape from tabs.twig) ------------
        function makeTabs(n, opts) {
          opts = opts || {};
          var checkedIdx = opts.checked === undefined ? 0 : opts.checked;
          var radios = [];
          var labels = [];
          var panels = [];
          for (var i = 0; i < n; i++) {
            var radio = el(['thallo-block-tabs__radio'], { attrs: { id: 'tabs-b1-' + (i + 1) } });
            radio.checked = i === checkedIdx;
            radio.dispatched = [];
            (function (r) {
              r.dispatchEvent = function (ev) { r.dispatched.push(ev); return true; };
            })(radio);
            radios.push(radio);
            labels.push(el(['thallo-block-tabs__label'],
              opts.labelListenThrow === i ? { throwOnListen: true } : {}));
            panels.push(el(['thallo-block-tabs__panel'],
              opts.panelThrow === i ? { throwOnSet: true } : {}));
          }
          var list = el(['thallo-block-tabs__list']);
          list.children = labels;
          var panelsBox = el(['thallo-block-tabs__panels']);
          panelsBox.children = panels;
          var root = el(['thallo-block', 'thallo-block-tabs']);
          root.children = radios.concat([list, panelsBox]);
          root.matches = function (sel) { return sel === '.thallo-block-tabs'; };
          root.querySelectorAll = function () { return []; };
          return {
            root: root, radios: radios, labels: labels, panels: panels,
            list: list, panelsBox: panelsBox
          };
        }
        function allNodes(t) {
          return [t.root, t.list, t.panelsBox].concat(t.radios, t.labels, t.panels);
        }
        // Zero-residue check: the honest radio floor must be byte- and
        // behavior-intact after a failed enhancement.
        function assertNoResidue(t, tag) {
          var residueAttrs = ['role', 'aria-selected', 'aria-controls', 'aria-labelledby',
            'aria-hidden', 'tabindex', 'hidden'];
          allNodes(t).forEach(function (n, ix) {
            residueAttrs.forEach(function (a) {
              assert(n.getAttribute(a) === null, tag + ': residue ' + a + ' on node ' + ix);
            });
            assert(liveCount(n) === 0, tag + ': live listener residue on node ' + ix);
          });
          t.labels.forEach(function (l, ix) {
            assert(l.getAttribute('id') === null, tag + ': label ' + ix + ' id residue');
          });
          t.panels.forEach(function (p, ix) {
            assert(p.getAttribute('id') === null, tag + ': panel ' + ix + ' id residue');
          });
          t.radios.forEach(function (r, ix) {
            assert(r.getAttribute('id') === 'tabs-b1-' + (ix + 1),
              tag + ': radio ' + ix + ' id changed');
          });
          assert(t.root.getAttribute('data-thallo-enhanced') === null,
            tag + ': component must stay unmarked');
        }

        eval($json);
        var RT = window.ThalloRuntime;

        // 1. Roles, stable derived ids, and the core marker landing after enhance.
        var t1 = makeTabs(3);
        RT.enhance(t1.root);
        assert(t1.list.getAttribute('role') === 'tablist', 'list role=tablist');
        for (var i = 0; i < 3; i++) {
          var lab = t1.labels[i];
          var pan = t1.panels[i];
          assert(lab.getAttribute('role') === 'tab', 'label ' + i + ' role=tab');
          assert(lab.getAttribute('aria-selected') !== null, 'label ' + i + ' aria-selected set');
          assert(pan.getAttribute('id') === 'tabs-b1-panel-' + (i + 1),
            'panel ' + i + ' stable id derived from the input id');
          assert(lab.getAttribute('aria-controls') === pan.getAttribute('id'),
            'label ' + i + ' aria-controls references its panel');
          assert(pan.getAttribute('role') === 'tabpanel', 'panel ' + i + ' role=tabpanel');
          assert(lab.getAttribute('id') !== null &&
            pan.getAttribute('aria-labelledby') === lab.getAttribute('id'),
            'panel ' + i + ' aria-labelledby references its tab');
          assert(pan.getAttribute('tabindex') === '-1', 'panel ' + i + ' tabindex=-1');
        }
        assert((t1.root.getAttribute('data-thallo-enhanced') || '').indexOf('tabs') !== -1,
          'core marks the component after enhance returns');

        // 2. Radios hidden + tabindex=-1 + aria-hidden=true — only AFTER all roles.
        t1.radios.forEach(function (r, ix) {
          assert(r.getAttribute('hidden') !== null, 'radio ' + ix + ' hidden');
          assert(r.getAttribute('tabindex') === '-1', 'radio ' + ix + ' tabindex=-1');
          assert(r.getAttribute('aria-hidden') === 'true', 'radio ' + ix + ' aria-hidden=true');
        });
        var lastRole = -1;
        var firstRadioHide = -1;
        mutationSeq.forEach(function (mu, ix) {
          if (mu.name === 'role') { lastRole = ix; }
          if (firstRadioHide === -1 && mu.name === 'hidden' && t1.radios.indexOf(mu.el) !== -1) {
            firstRadioHide = ix;
          }
        });
        assert(lastRole !== -1 && firstRadioHide > lastRole,
          'radios hidden only after every role is in place');

        // 3. Preselected radio 2 (checked server-side) initializes the enhanced state.
        var t2 = makeTabs(3, { checked: 1 });
        RT.enhance(t2.root);
        assert(t2.labels[1].getAttribute('aria-selected') === 'true', 'preselect: label 2 selected');
        assert(t2.labels[1].getAttribute('tabindex') === '0', 'preselect: label 2 in tab order');
        assert(t2.labels[0].getAttribute('aria-selected') === 'false' &&
          t2.labels[2].getAttribute('aria-selected') === 'false', 'preselect: others unselected');
        assert(t2.labels[0].getAttribute('tabindex') === '-1' &&
          t2.labels[2].getAttribute('tabindex') === '-1', 'preselect: others out of tab order');
        assert(t2.panels[1].getAttribute('hidden') === null, 'preselect: panel 2 visible');
        assert(t2.panels[0].getAttribute('hidden') !== null &&
          t2.panels[2].getAttribute('hidden') !== null, 'preselect: other panels hidden');

        // 4. ArrowRight: automatic activation (focus + select) with radio sync. The
        //    floor's checked-pairing CSS (0,6,0) outranks __panel[hidden] (0,4,0), so
        //    the OLD radio must be unchecked or its hidden panel would stay visible.
        var prevented = 0;
        fire(t1.list, 'keydown', { key: 'ArrowRight', preventDefault: function () { prevented++; } });
        assert(prevented === 1, 'ArrowRight is preventDefault-ed');
        assert(t1.labels[1].focusCount === 1, 'ArrowRight focuses tab 2');
        assert(t1.labels[1].getAttribute('aria-selected') === 'true', 'ArrowRight selects tab 2');
        assert(t1.labels[1].getAttribute('tabindex') === '0', 'tab 2 takes the roving tabindex');
        assert(t1.labels[0].getAttribute('aria-selected') === 'false' &&
          t1.labels[0].getAttribute('tabindex') === '-1', 'tab 1 deselected');
        assert(t1.radios[1].checked === true, 'NEW radio checked (sync)');
        assert(t1.radios[0].checked === false,
          'OLD radio unchecked (floor CSS must not re-show its hidden panel)');
        assert(t1.radios[1].dispatched.length === 1 && t1.radios[1].dispatched[0].type === 'change',
          'change dispatched on the newly-checked radio');
        assert(t1.panels[1].getAttribute('hidden') === null, 'panel 2 shown');
        assert(t1.panels[0].getAttribute('hidden') !== null, 'panel 1 hidden');

        // 5. Home/End jump; ArrowLeft wraps from first to last.
        fire(t1.list, 'keydown', { key: 'End', preventDefault: function () {} });
        assert(t1.labels[2].getAttribute('aria-selected') === 'true' && t1.labels[2].focusCount === 1,
          'End jumps to the last tab');
        assert(t1.radios[2].checked === true && t1.radios[1].checked === false, 'End syncs the radios');
        fire(t1.list, 'keydown', { key: 'Home', preventDefault: function () {} });
        assert(t1.labels[0].getAttribute('aria-selected') === 'true' && t1.labels[0].focusCount === 1,
          'Home jumps to the first tab');
        fire(t1.list, 'keydown', { key: 'ArrowLeft', preventDefault: function () {} });
        assert(t1.labels[2].getAttribute('aria-selected') === 'true' && t1.labels[2].focusCount === 2,
          'ArrowLeft wraps from first to last');
        assert(t1.radios[2].checked === true && t1.radios[0].checked === false, 'wrap syncs the radios');
        assert(t1.panels[2].getAttribute('hidden') === null &&
          t1.panels[0].getAttribute('hidden') !== null, 'wrap toggles the panels');

        // 6. Label click: default activation prevented; the module drives state.
        var clickPrevented = 0;
        fire(t1.labels[1], 'click', { preventDefault: function () { clickPrevented++; } });
        assert(clickPrevented === 1, 'label click default is prevented');
        assert(t1.labels[1].getAttribute('aria-selected') === 'true', 'click selects tab 2');
        assert(t1.radios[1].checked === true && t1.radios[2].checked === false,
          'click syncs the radios');
        assert(t1.radios[1].dispatched.length === 2, 'click dispatches change on the radio');
        assert(t1.panels[1].getAttribute('hidden') === null &&
          t1.panels[2].getAttribute('hidden') !== null, 'click toggles the panels');

        // 7. Unbounded enhanced path: a 13-label component fully works.
        var t3 = makeTabs(13);
        RT.enhance(t3.root);
        for (var j = 0; j < 13; j++) {
          assert(t3.labels[j].getAttribute('role') === 'tab', '13-up: label ' + j + ' is a tab');
        }
        assert(t3.panels[12].getAttribute('id') === 'tabs-b1-panel-13', '13-up: 13th panel id');
        fire(t3.list, 'keydown', { key: 'End', preventDefault: function () {} });
        assert(t3.labels[12].getAttribute('aria-selected') === 'true' && t3.labels[12].focusCount === 1,
          '13-up: End reaches the 13th tab');
        assert(t3.radios[12].checked === true && t3.radios[0].checked === false, '13-up: radio sync');
        assert(t3.panels[12].getAttribute('hidden') === null, '13-up: 13th panel shown');

        // Fail-safe (a): a panel whose setAttribute throws mid-phase-1 — full
        // rollback, zero residue, component unmarked, no listener was ever attached.
        var fa = makeTabs(3, { panelThrow: 2 });
        RT.enhance(fa.root);
        assertNoResidue(fa, 'phase-1 throw');
        var addsA = allNodes(fa).reduce(function (n, x) { return n + x.addCount; }, 0);
        assert(addsA === 0, 'phase-1 throw: no listeners were ever attached');

        // Fail-safe (b): a label whose addEventListener throws in phase 2 — earlier
        // attachments really happened, then every one was removed (net zero).
        var fb = makeTabs(3, { labelListenThrow: 1 });
        RT.enhance(fb.root);
        assertNoResidue(fb, 'phase-2 throw');
        assert(fb.list.addCount === 1 && fb.labels[0].addCount === 1,
          'phase-2 throw: earlier attachments happened before rollback');

        // Unpairable structure, directly: 3 radios but only 2 labels. enhance()
        // throws before ANY mutation (the pairing check runs before the undo log
        // even exists), so this is a stronger guarantee than the fail-safes above —
        // not just "rolled back", but never touched at all.
        var un = makeTabs(3);
        un.list.children = un.labels.slice(0, 2); // break the 1:1 radio/label pairing
        RT.enhance(un.root);
        assertNoResidue(un, 'unpairable (3 radios, 2 labels)');

        console.log('ALL_PASS');
        JS;
    }

    public function testTabsTeardownRestoresBaselineAfterInteractionAndEmptyBlockReturnsFalse(): void
    {
        $src = $this->runtimeJs();

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the tabs runtime module');
        }

        $file = sys_get_temp_dir() . '/thallo_tabs_teardown_runtime_' . getmypid() . '.mjs';
        file_put_contents($file, $this->teardownHarness($src));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "tabs teardown harness failed:\n" . implode("\n", $out));
            self::assertStringContainsString('ALL_PASS', implode("\n", $out));
        } finally {
            @unlink($file);
        }
    }

    /**
     * Two cases driven through the PUBLIC lifecycle only (no production test hook):
     *
     * (a) THE spec §3 case — interaction-then-disconnect restores the exact served
     *     floor. select() mutates radio.checked and panel[hidden] directly, bypassing
     *     the attribute/listener undo log, so the undo log alone can't restore the
     *     served floor after an interaction; the baseline snapshot taken before Phase 1
     *     is what closes that gap. Registers a harness-only x-tabs-lifecycle adapter
     *     (Task 2's registerElement bridge) over the existing 'tabs' module, connects
     *     it on a valid 3-tab root (radio 1 checked), simulates a label click selecting
     *     tab 3, then disconnects and asserts the served floor exactly — followed by a
     *     clean reconnect that enhances exactly once again.
     *
     * (b) Empty block: a root with no radios/labels/panels — enhance() returns false,
     *     so the root is never marked (previously it was silently marked on a bare
     *     `return;`), and a second scan pass retries the module rather than skipping it.
     */
    private function teardownHarness(string $src): string
    {
        $json = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        'use strict';
        function assert(c, m) { if (!c) { console.error('FAIL: ' + m); process.exit(1); } }
        global.console = { error: console.error, log: console.log };

        // --- inert <html> + document stubs (color-mode gate off; not canvas) ----
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
          addEventListener: function () {},
          querySelector: function () { return null; }, // no .thallo-preview-block: not canvas
          querySelectorAll: function () { return []; },
          documentElement: inert
        };
        global.window = global;

        // --- recording element stub (same idiom as the sibling harness) --------
        function makeClassList(initial) {
          var set = {};
          initial.forEach(function (c) { set[c] = true; });
          return {
            add: function (c) { set[c] = true; },
            contains: function (c) { return !!set[c]; }
          };
        }
        function el(classes, opts) {
          opts = opts || {};
          var attrs = {};
          var node = {
            children: [],
            classList: makeClassList(classes),
            live: {},
            getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
            setAttribute: function (n, v) { attrs[n] = String(v); },
            removeAttribute: function (n) { delete attrs[n]; },
            addEventListener: function (t, fn) { (node.live[t] = node.live[t] || []).push(fn); },
            removeEventListener: function (t, fn) {
              var arr = node.live[t] || [];
              var i = arr.indexOf(fn);
              if (i !== -1) { arr.splice(i, 1); }
            },
            focus: function () {}
          };
          if (opts.attrs) {
            Object.keys(opts.attrs).forEach(function (k) { attrs[k] = String(opts.attrs[k]); });
          }
          return node;
        }
        function fire(node, type, ev) {
          (node.live[type] || []).slice().forEach(function (fn) { fn(ev || {}); });
        }

        // --- tabs component stub (floor markup shape from tabs.twig) ------------
        function makeTabs(n, opts) {
          opts = opts || {};
          var checkedIdx = opts.checked === undefined ? 0 : opts.checked;
          var servedHidden = opts.servedHidden || []; // panel indices SERVED with [hidden]
          var radios = [];
          var labels = [];
          var panels = [];
          for (var i = 0; i < n; i++) {
            var radio = el(['thallo-block-tabs__radio'], { attrs: { id: 'tabs-b1-' + (i + 1) } });
            radio.checked = i === checkedIdx;
            radio.dispatchEvent = function () { return true; };
            radios.push(radio);
            labels.push(el(['thallo-block-tabs__label']));
            var panel = el(['thallo-block-tabs__panel']);
            if (servedHidden.indexOf(i) !== -1) { panel.setAttribute('hidden', ''); }
            panels.push(panel);
          }
          var list = el(['thallo-block-tabs__list']);
          list.children = labels;
          var panelsBox = el(['thallo-block-tabs__panels']);
          panelsBox.children = panels;
          var root = el(['thallo-block', 'thallo-block-tabs']);
          root.children = radios.concat([list, panelsBox]);
          root.isConnected = true;
          root.matches = function (sel) { return sel === '.thallo-block-tabs'; };
          root.querySelectorAll = function () { return []; };
          return {
            root: root, radios: radios, labels: labels, panels: panels,
            list: list, panelsBox: panelsBox
          };
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
        function flush() { return new Promise(function (r) { setTimeout(r, 0); }); }

        eval($json);
        var RT = window.ThalloRuntime;

        // --- (b) empty block: enhance() returns false, root never marked --------
        var empty = el(['thallo-block', 'thallo-block-tabs']);
        empty.matches = function (sel) { return sel === '.thallo-block-tabs'; };
        empty.querySelectorAll = function () { return []; };
        RT.enhance(empty);
        assert(empty.getAttribute('data-thallo-enhanced') === null,
          'empty block: structural false never marks the component');
        RT.enhance(empty);
        assert(empty.getAttribute('data-thallo-enhanced') === null,
          'empty block: still unmarked and retried on a second pass');

        // --- (a) interaction-then-disconnect restores the served floor ----------
        RT.registerElement('x-tabs-lifecycle', 'tabs', {});

        (async function () {
          // Panel 0 is SERVED with [hidden] already present (baselineHidden[0] = true) —
          // and it is also the initially-checked/current tab, so Phase 1 never touches
          // its [hidden] attribute at all (only non-current panels get it set): the
          // only thing that can ever restore it is the baseline-restore step in the
          // module's teardown closure. This exercises the `if (baselineHidden[k])`
          // true-branch, which no other test reaches (every other fixture's served
          // floor has zero hidden panels), and pins the exact restored value instead
          // of a looser "some state" assertion.
          var t = makeTabs(3, { servedHidden: [0] });

          upgrade('x-tabs-lifecycle', t.root);
          await flush();

          assert((t.root.getAttribute('data-thallo-enhanced') || '').indexOf('tabs') !== -1,
            'connect enhanced + marked the host');
          assert(t.list.getAttribute('role') === 'tablist', 'connect: list got tablist role');

          // Simulate the label-click select(2) path: default prevented, module drives
          // state — radio 3 checked, panel 1 (previously visible) now [hidden].
          fire(t.labels[2], 'click', { preventDefault: function () {} });
          assert(t.radios[2].checked === true && t.radios[0].checked === false,
            'select(2): radio sync happened before disconnect');
          assert(t.panels[0].getAttribute('hidden') !== null,
            'select(2): panel 1 hidden by the module (NOT via the undo log)');

          t.root.disconnectedCallback();

          // Served floor, exactly: baseline checked/hidden restored, undo log
          // reversed (no role/aria-*/tabindex/id remnants), radios un-hidden.
          assert(t.radios[0].checked === true, 'served floor: radio 1 checked (baseline)');
          assert(t.radios[2].checked === false, 'served floor: radio 3 unchecked (baseline)');
          assert(t.radios[1].checked === false, 'served floor: radio 2 unchecked (baseline)');
          t.panels.forEach(function (p, ix) {
            if (ix === 0) {
              assert(p.getAttribute('hidden') !== null,
                'served floor: panel 0 restored to its SERVED [hidden] baseline');
            } else {
              assert(p.getAttribute('hidden') === null, 'served floor: panel ' + ix + ' not [hidden]');
            }
            assert(p.getAttribute('role') === null, 'served floor: panel ' + ix + ' role removed');
            assert(p.getAttribute('aria-labelledby') === null,
              'served floor: panel ' + ix + ' aria-labelledby removed');
            assert(p.getAttribute('tabindex') === null, 'served floor: panel ' + ix + ' tabindex removed');
            assert(p.getAttribute('id') === null, 'served floor: panel ' + ix + ' id removed');
          });
          assert(t.list.getAttribute('role') === null, 'served floor: list role removed');
          t.labels.forEach(function (l, ix) {
            assert(l.getAttribute('role') === null, 'served floor: label ' + ix + ' role removed');
            assert(l.getAttribute('aria-selected') === null,
              'served floor: label ' + ix + ' aria-selected removed');
            assert(l.getAttribute('aria-controls') === null,
              'served floor: label ' + ix + ' aria-controls removed');
            assert(l.getAttribute('tabindex') === null, 'served floor: label ' + ix + ' tabindex removed');
            assert(l.getAttribute('id') === null, 'served floor: label ' + ix + ' id removed');
          });
          t.radios.forEach(function (r, ix) {
            assert(r.getAttribute('hidden') === null, 'served floor: radio ' + ix + ' un-hidden');
            assert(r.getAttribute('tabindex') === null, 'served floor: radio ' + ix + ' tabindex removed');
            assert(r.getAttribute('aria-hidden') === null, 'served floor: radio ' + ix + ' aria-hidden removed');
            assert(r.getAttribute('id') === 'tabs-b1-' + (ix + 1), 'served floor: radio ' + ix + ' id intact');
          });
          assert(t.root.getAttribute('data-thallo-enhanced') === null,
            'served floor: tabs marker gone');

          // Reconnect + flush enhances cleanly, exactly once again.
          t.root.connectedCallback();
          await flush();
          assert((t.root.getAttribute('data-thallo-enhanced') || '').indexOf('tabs') !== -1,
            'reconnect re-marked the host');
          assert(t.list.getAttribute('role') === 'tablist', 'reconnect: list re-enhanced');
          assert(t.radios[0].checked === true && t.radios[0].getAttribute('hidden') !== null,
            'reconnect: enhancement re-applied from the (restored) served floor');

          console.log('ALL_PASS');
        })().catch(function (e) { console.error('FAIL: ' + (e && e.stack)); process.exit(1); });
        JS;
    }
}
