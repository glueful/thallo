<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Executable coverage for the forms runtime module (theme-runtime spec §6 + form-block
 * spec §6): submit interception, aria-busy + disabled submit during fetch, inline result
 * rendering with error focus + polite live announcement, and reset on success. Mirrors
 * RuntimeCoreTest's Node + hand-stubbed-DOM pattern; skips (not fails) without node but
 * always asserts structural markers.
 */
final class FormsRuntimeTest extends AppTestCase
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

    public function testFormsModuleInterceptsSubmitsAndAnnouncesResults(): void
    {
        $src = $this->runtimeJs();
        self::assertStringContainsString('/* form-block:start', $src);
        self::assertStringContainsString("register('forms'", $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the forms runtime module');
        }

        $file = sys_get_temp_dir() . '/thallo_forms_runtime_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($src));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "forms harness failed:\n" . implode("\n", $out));
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
        function tick() { return new Promise(function (r) { setTimeout(r, 0); }); }

        // --- DOM/browser stubs -------------------------------------------------
        function classListStub() {
          var set = {};
          return {
            add: function () {
              for (var i = 0; i < arguments.length; i++) { set[arguments[i]] = true; }
            },
            remove: function () {
              for (var i = 0; i < arguments.length; i++) { delete set[arguments[i]]; }
            },
            contains: function (c) { return !!set[c]; }
          };
        }

        var focusCalls = 0;
        var box = {
          attrs: {},
          textContent: '',
          classList: classListStub(),
          getAttribute: function (n) { return box.attrs[n] === undefined ? null : box.attrs[n]; },
          setAttribute: function (n, v) { box.attrs[n] = String(v); },
          focus: function () { focusCalls++; }
        };
        var btn = { disabled: false };

        var listeners = {};
        var resetCalls = 0;
        var form = {
          action: '/_forms/submit',
          attrs: {},
          addEventListener: function (t, fn) { (listeners[t] = listeners[t] || []).push(fn); },
          getAttribute: function (n) { return form.attrs[n] === undefined ? null : form.attrs[n]; },
          setAttribute: function (n, v) { form.attrs[n] = String(v); },
          removeAttribute: function (n) { delete form.attrs[n]; },
          matches: function (sel) { return sel === 'form[data-thallo-form]'; },
          querySelectorAll: function () { return []; },
          querySelector: function (sel) {
            if (sel === '.thallo-block-form__result') { return box; }
            if (sel === '.thallo-block-form__submit') { return btn; }
            return null;
          },
          reset: function () { resetCalls++; }
        };

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
        global.document = {
          readyState: 'complete',
          addEventListener: function () {},
          querySelector: function () { return null; }, // no .thallo-preview-block: not canvas
          querySelectorAll: function () { return []; },
          documentElement: inert
        };
        global.window = global;

        var resolveFetch = null;
        var fetchCalls = [];
        global.fetch = function (url, init) {
          fetchCalls.push({ url: url, init: init });
          return new Promise(function (res) { resolveFetch = res; });
        };
        global.FormData = function (f) { this.form = f; };

        eval($json);
        window.ThalloRuntime.enhance(form);

        // 1. Submit handler registered via the module; synthetic submit is intercepted.
        assert(listeners.submit && listeners.submit.length === 1,
          'submit handler registered by enhance');
        var prevented = false;
        listeners.submit[0]({ preventDefault: function () { prevented = true; } });
        assert(prevented === true, 'submit default prevented');
        assert(fetchCalls.length === 1 && fetchCalls[0].url === '/_forms/submit',
          'fetch POSTs to form.action');

        // 2. During fetch: busy state on the form, submit disabled.
        assert(form.getAttribute('aria-busy') === 'true', 'aria-busy=true during fetch');
        assert(btn.disabled === true, 'submit disabled during fetch');

        // 3. Failure JSON: message, error class, one focus, live-region wiring, busy cleared.
        resolveFetch({
          json: function () { return Promise.resolve({ ok: false, error: 'Bad email' }); }
        });
        await tick();
        assert(box.textContent === 'Bad email', 'result box shows server error text');
        assert(box.classList.contains('thallo-block-form__result--error'),
          'error class applied on failure');
        assert(!box.classList.contains('thallo-block-form__result--ok'),
          'ok class absent on failure');
        assert(focusCalls === 1, 'result box focused exactly once on failure');
        assert(box.getAttribute('role') === 'status', 'result box role=status');
        assert(box.getAttribute('aria-live') === 'polite', 'result box aria-live=polite');
        assert(box.getAttribute('tabindex') === '-1', 'result box tabindex=-1');
        assert(form.getAttribute('aria-busy') === null, 'aria-busy removed after settle');
        assert(btn.disabled === false, 'submit re-enabled after settle');

        // 4. Success JSON: reset + ok class; focus NOT called again.
        listeners.submit[0]({ preventDefault: function () {} });
        resolveFetch({
          json: function () { return Promise.resolve({ ok: true }); }
        });
        await tick();
        assert(resetCalls === 1, 'form.reset() called on success');
        assert(box.classList.contains('thallo-block-form__result--ok'),
          'ok class applied on success');
        assert(!box.classList.contains('thallo-block-form__result--error'),
          'error class removed on success');
        assert(focusCalls === 1, 'focus NOT called on success');

        console.log('ALL_PASS');
        JS;
    }
}
