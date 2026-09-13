<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;

/**
 * block-code.js: registers the `code` module with the runtime, adds a Copy button to an enhanced
 * block, writes the snippet to the clipboard on click, says "Copied" briefly, and cleans up.
 * Evaluated in Node against a minimal DOM and runtime stub (the served bytes, as shipped).
 */
final class CodeAssetTest extends AppTestCase
{
    private function findNode(): ?string
    {
        $env = getenv('THALLO_NODE_BIN');
        if (is_string($env) && $env !== '' && is_executable($env)) {
            return $env;
        }
        $which = trim((string) shell_exec('command -v node 2>/dev/null'));
        return $which !== '' ? $which : null;
    }

    public function testCopyButtonLifecycle(): void
    {
        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the code asset');
        }
        $asset = (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-render/runtime/block-code.js',
        );
        $file = sys_get_temp_dir() . '/thallo_code_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($asset));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "code asset harness failed:\n" . implode("\n", $out));
            self::assertStringContainsString('ALL_PASS', implode("\n", $out));
        } finally {
            @unlink($file);
        }
    }

    private function harness(string $assetSrc): string
    {
        $assetJson = json_encode($assetSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        'use strict';
        import { createContext, runInContext } from 'node:vm';
        var ASSET_SRC = {$assetJson};
        function assert(c, m) { if (!c) { console.error('FAIL: ' + m); process.exit(1); } }
        function flush() { return new Promise(function (r) { setTimeout(r, 0); }); }

        function makeNode(tag) {
          var attrs = {}, classes = [], listeners = {}, kids = [], text = '';
          var node = {
            tagName: tag.toUpperCase(), parentNode: null,
            get children() { return kids; },
            get className() { return classes.join(' '); },
            set className(v) { classes = (v || '').split(' ').filter(Boolean); },
            classList: { contains: function (c) { return classes.indexOf(c) !== -1; } },
            appendChild: function (c) { c.parentNode = node; kids.push(c); return c; },
            removeChild: function (c) {
              var i = kids.indexOf(c);
              if (i !== -1) { kids.splice(i, 1); c.parentNode = null; }
              return c;
            },
            getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
            setAttribute: function (n, v) { attrs[n] = String(v); },
            matches: function (sel) {
              if (sel.charAt(0) === '.') { return node.classList.contains(sel.slice(1)); }
              return node.tagName === sel.toUpperCase();
            },
            querySelectorAll: function (sel) {
              var found = [];
              (function walk(n) {
                n.children.forEach(function (c) { if (c.matches(sel)) { found.push(c); } walk(c); });
              })(node);
              return found;
            },
            querySelector: function (sel) { return node.querySelectorAll(sel)[0] || null; },
            addEventListener: function (t, fn) { (listeners[t] = listeners[t] || []).push(fn); },
            dispatchEvent: function (evt) { (listeners[evt.type] || []).slice().forEach(function (fn) { fn(evt); }); },
            get textContent() { return text; }, set textContent(v) { text = String(v); }
          };
          return node;
        }

        // The block's markup contract: figure.thallo-block-code[data-copy] > figcaption > span.__actions ; pre > code
        function buildBlock(copy) {
          var root = makeNode('figure'); root.className = 'thallo-block thallo-block-code';
          root.setAttribute('data-copy', copy);
          var cap = makeNode('figcaption');
          var actions = makeNode('span'); actions.className = 'thallo-block-code__actions';
          cap.appendChild(actions); root.appendChild(cap);
          var pre = makeNode('pre'); var code = makeNode('code');
          code.textContent = 'composer create-project glueful/thallo';
          pre.appendChild(code); root.appendChild(pre);
          return { root: root, actions: actions };
        }

        var written = [];
        var registered = {};
        var ctx = {};
        ctx.window = ctx;
        ctx.console = console;
        ctx.setTimeout = function (fn) { fn(); return 1; }; // "Copied" restores immediately in the harness
        ctx.clearTimeout = function () {};
        ctx.navigator = {
          clipboard: { writeText: function (t) { written.push(t); return Promise.resolve(); } }
        };
        ctx.document = { createElement: makeNode, querySelector: function () { return null; } };
        ctx.window.ThalloRuntime = {
          register: function (name, def) { registered[name] = def; },
          enhance: function () {}
        };
        createContext(ctx);

        runInContext(ASSET_SRC, ctx);
        assert(registered.code && typeof registered.code.enhance === 'function', 'registers the code module');
        assert(registered.code.selector.indexOf('.thallo-block-code') === 0, 'selector targets the block');
        runInContext(ASSET_SRC, ctx); // re-execution is harmless (fragment renders emit the tag twice)

        var b = buildBlock('1');
        var cleanup = registered.code.enhance(b.root);
        var button = b.actions.querySelector('button');
        assert(button !== null, 'enhance adds a button');
        assert(button.getAttribute('type') === 'button', 'button never submits');
        assert(button.textContent === 'Copy', 'initial label');

        button.dispatchEvent({ type: 'click', preventDefault: function () {} });
        await flush();
        assert(written.length === 1, 'writes to the clipboard once');
        assert(written[0] === 'composer create-project glueful/thallo', 'writes the snippet');
        assert(button.textContent === 'Copy', 'label restored after the timeout (immediate in the harness)');

        assert(typeof cleanup === 'function', 'enhance returns a cleanup');
        cleanup();
        assert(b.actions.querySelector('button') === null, 'cleanup removes the button');

        var off = buildBlock('0');
        var r = registered.code.enhance(off.root);
        assert(off.actions.querySelector('button') === null && r === false, 'data-copy=0 opts out');

        console.log('ALL_PASS');
        JS;
    }
}
