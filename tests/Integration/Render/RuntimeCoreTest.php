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
