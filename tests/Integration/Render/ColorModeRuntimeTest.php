<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Executable coverage for the hard-gated color-mode runtime in runtime.js
 * (color-mode spec §3.2). We extract just the marked IIFE and evaluate it under
 * a hand-stubbed DOM in node — no jsdom/vitest harness exists in this package.
 * Skips (does not fail) when node is unavailable, but still asserts the markers
 * exist so the extraction contract is checked even then.
 */
final class ColorModeRuntimeTest extends AppTestCase
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

    public function testRuntimeIsHardGatedAndDrivesTheme(): void
    {
        if (!preg_match('#/\* color-mode:start \*/(.*)/\* color-mode:end \*/#s', $this->runtimeJs(), $m)) {
            self::fail('color-mode runtime markers not found in runtime.js');
        }
        $runtime = trim($m[1]);
        self::assertStringContainsString('thallo.colorMode', $runtime); // one real assertion even w/o node

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the color-mode runtime');
        }

        $file = sys_get_temp_dir() . '/thallo_color_mode_runtime_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($runtime));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            $output = implode("\n", $out);
            self::assertSame(0, $code, "runtime harness failed:\n" . $output);
            self::assertStringContainsString('ALL_PASS', $output);
        } finally {
            @unlink($file);
        }
    }

    /** Build a self-checking node harness around the extracted runtime source. */
    private function harness(string $runtimeSrc): string
    {
        $src = json_encode($runtimeSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        'use strict';

        function assert(cond, msg) { if (!cond) { console.error('FAIL: ' + msg); process.exit(1); } }

        // --- DOM/browser stubs -------------------------------------------------
        function host() {
          var L = {};
          return {
            _l: L,
            count: function (t) { return (L[t] || []).length; },
            addEventListener: function (t, fn) { (L[t] = L[t] || []).push(fn); },
            removeEventListener: function (t, fn) { L[t] = (L[t] || []).filter(function (f) { return f !== fn; }); },
            fire: function (t, ev) { (L[t] || []).forEach(function (fn) { fn(ev); }); }
          };
        }

        function CustomEvent(type, opts) { this.type = type; this.detail = opts && opts.detail; }

        function optionEl(value) {
          return {
            attrs: { 'data-color-mode-set': value },
            getAttribute: function (n) { return this.attrs[n] != null ? this.attrs[n] : null; },
            setAttribute: function (n, v) { this.attrs[n] = v; },
            closest: function (sel) { return sel === '[data-color-mode-set]' ? this : null; }
          };
        }

        function scenario(marker, storedValue, osDark, options) {
          var store = {};
          if (storedValue != null) store['thallo.colorMode'] = storedValue;
          var localStorage = {
            getItem: function (k) { return Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null; },
            setItem: function (k, v) { store[k] = String(v); },
            removeItem: function (k) { delete store[k]; }
          };
          var mql = host();
          mql.matches = !!osDark;
          var docHost = host();
          var events = [];
          var opts = options || [];
          var docEl = {
            dataset: {},
            dispatchEvent: function (ev) { events.push(ev); return true; }
          };
          if (marker != null) docEl.dataset.colorModeEnabled = marker;
          var document = {
            documentElement: docEl,
            addEventListener: docHost.addEventListener,
            querySelectorAll: function (sel) { return sel === '[data-color-mode-set]' ? opts : []; },
            _click: docHost
          };
          var window = { matchMedia: function () { return mql; } };

          var run = new Function('window', 'document', 'localStorage', 'CustomEvent', $src);
          run(window, document, localStorage, CustomEvent);

          return {
            store: store, mql: mql, docHost: docHost, docEl: docEl,
            events: events, window: window, opts: opts
          };
        }

        // Scenario 1: gate OFF (marker absent) → completely inert -------------
        var off = scenario(undefined, 'dark', true);
        assert(typeof off.window.thalloColorMode === 'undefined', 'gate off: no public API');
        assert(off.mql.count('change') === 0, 'gate off: no OS listener');
        assert(off.docHost.count('click') === 0, 'gate off: no click listener');
        assert(off.docEl.dataset.theme === undefined, 'gate off: data-theme untouched');
        assert(off.events.length === 0, 'gate off: no events');

        // Scenario 2: gate ON, default system, OS light ----------------------
        var s = scenario('true', null, false);
        var api = s.window.thalloColorMode;
        assert(api && typeof api.set === 'function', 'gate on: public API present');
        assert(api.get() === 'system', 'default mode is system');
        assert(api.resolved() === 'light', 'system + OS light resolves light');

        // explicit dark: persists, applies, dispatches -----------------------
        api.set('dark');
        assert(s.store['thallo.colorMode'] === 'dark', 'set(dark) persists');
        assert(s.docEl.dataset.theme === 'dark', 'set(dark) applies data-theme');
        var ev = s.events[s.events.length - 1];
        assert(ev && ev.type === 'thallo:color-mode-change', 'change event dispatched');
        assert(ev.detail.mode === 'dark' && ev.detail.resolved === 'dark', 'event carries mode+resolved');

        // system follows the OS ----------------------------------------------
        api.set('system');
        assert(s.docEl.dataset.theme === 'light', 'system + OS light → light');
        s.mql.matches = true;
        s.mql.fire('change', {});
        assert(s.docEl.dataset.theme === 'dark', 'system follows OS flip to dark');

        // explicit light PINS, ignoring OS -----------------------------------
        api.set('light');
        assert(s.docEl.dataset.theme === 'light', 'set(light) applies light');
        s.mql.matches = true;
        s.mql.fire('change', {});
        assert(s.docEl.dataset.theme === 'light', 'explicit light ignores OS flip');

        // junk mode is ignored (state unchanged) -----------------------------
        api.set('bogus');
        assert(s.store['thallo.colorMode'] === 'light', 'junk mode does not persist');
        assert(s.docEl.dataset.theme === 'light', 'junk mode does not change theme');

        // Scenario 3: click delegation on a toggle control -------------------
        var c = scenario('true', 'light', false);
        var el = {
          getAttribute: function (n) { return n === 'data-color-mode-set' ? 'dark' : null; },
          closest: function (sel) { return sel === '[data-color-mode-set]' ? el : null; }
        };
        var prevented = false;
        c.docHost.fire('click', { target: el, preventDefault: function () { prevented = true; } });
        assert(prevented === true, 'click delegation calls preventDefault');
        assert(c.store['thallo.colorMode'] === 'dark', 'click sets stored mode');
        assert(c.docEl.dataset.theme === 'dark', 'click applies data-theme');

        // Scenario 4: pressed-state reflection onto the toggle options --------
        var optLight = optionEl('light'), optSystem = optionEl('system'), optDark = optionEl('dark');
        var r = scenario('true', 'dark', false, [optLight, optSystem, optDark]);
        // reflect() runs on init from the stored 'dark' preference.
        assert(optDark.getAttribute('aria-checked') === 'true', 'init reflects stored dark');
        assert(optLight.getAttribute('aria-checked') === 'false', 'init unchecks light');
        assert(optSystem.getAttribute('aria-checked') === 'false', 'init unchecks system');
        // switching re-reflects onto the newly active option.
        r.window.thalloColorMode.set('system');
        assert(optSystem.getAttribute('aria-checked') === 'true', 'set(system) checks system');
        assert(optDark.getAttribute('aria-checked') === 'false', 'set(system) unchecks dark');

        console.log('ALL_PASS');
        JS;
    }
}
