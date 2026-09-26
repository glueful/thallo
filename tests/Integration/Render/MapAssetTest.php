<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Render;

use Thallo\Core\Tests\Support\AppTestCase;

/**
 * block-map.js (the map block's click to load): registers the `map` module, reveals the Show map
 * button on the placeholder, and on its click replaces the placeholder's contents with Google's
 * map — never anything but a Google Maps address. Evaluated in Node against a minimal DOM and
 * runtime stub (the served bytes, as shipped).
 */
final class MapAssetTest extends AppTestCase
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

    public function testClickToLoadLifecycle(): void
    {
        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the map asset');
        }
        $asset = (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-render/runtime/block-map.js',
        );
        $file = sys_get_temp_dir() . '/thallo_map_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($asset));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "map asset harness failed:\n" . implode("\n", $out));
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

        function makeNode(tag) {
          var attrs = {}, classes = [], listeners = {}, kids = [];
          var node = {
            tagName: tag.toUpperCase(), parentNode: null, hidden: false, src: '', title: '',
            get children() { return kids; },
            get firstChild() { return kids[0] || null; },
            get className() { return classes.join(' '); },
            set className(v) { classes = (v || '').split(' ').filter(Boolean); },
            classList: {
              contains: function (c) { return classes.indexOf(c) !== -1; },
              add: function (c) { if (classes.indexOf(c) === -1) { classes.push(c); } }
            },
            appendChild: function (c) { c.parentNode = node; kids.push(c); return c; },
            removeChild: function (c) {
              var i = kids.indexOf(c);
              if (i !== -1) { kids.splice(i, 1); c.parentNode = null; }
              return c;
            },
            getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
            setAttribute: function (n, v) { attrs[n] = String(v); },
            matches: function (sel) { return sel.charAt(0) === '.' && node.classList.contains(sel.slice(1)); },
            querySelector: function (sel) {
              var hit = null;
              (function walk(n) {
                n.children.forEach(function (c) { if (!hit && c.matches(sel)) { hit = c; } walk(c); });
              })(node);
              return hit;
            },
            addEventListener: function (t, fn) { (listeners[t] = listeners[t] || []).push(fn); },
            removeEventListener: function (t, fn) {
              listeners[t] = (listeners[t] || []).filter(function (f) { return f !== fn; });
            },
            dispatchEvent: function (evt) { (listeners[evt.type] || []).slice().forEach(function (fn) { fn(evt); }); }
          };
          return node;
        }

        // The placeholder's markup contract: div.__consent[data-map-src][data-map-title] > p, button.__load[hidden], a
        function buildPlaceholder(src) {
          var root = makeNode('div'); root.className = 'thallo-block-map__frame thallo-block-map__consent';
          root.setAttribute('data-map-src', src);
          root.setAttribute('data-map-title', 'Map of Accra Mall');
          root.appendChild(makeNode('p'));
          var button = makeNode('button'); button.className = 'thallo-block-map__load'; button.hidden = true;
          root.appendChild(button);
          root.appendChild(makeNode('a'));
          return { root: root, button: button };
        }

        var registered = {};
        var ctx = {};
        ctx.window = ctx;
        ctx.console = console;
        ctx.document = { createElement: makeNode };
        ctx.window.ThalloRuntime = {
          register: function (name, def) { registered[name] = def; },
          enhance: function () {}
        };
        createContext(ctx);

        runInContext(ASSET_SRC, ctx);
        assert(registered.map && typeof registered.map.enhance === 'function', 'registers the map module');
        assert(registered.map.selector === '.thallo-block-map__consent', 'selector targets the placeholder');
        assert(registered.map.canvas === undefined, 'skipped on the stage (the default)');
        runInContext(ASSET_SRC, ctx); // re-execution is harmless

        var src = 'https://www.google.com/maps?q=Accra+Mall&z=15&t=m&output=embed';
        var p = buildPlaceholder(src);
        var cleanup = registered.map.enhance(p.root);
        assert(p.button.hidden === false, 'the button appears once it can work');
        p.button.dispatchEvent({ type: 'click', preventDefault: function () {} });
        assert(p.root.children.length === 1, 'the placeholder gives way to the map');
        var frame = p.root.children[0];
        assert(frame.tagName === 'IFRAME' && frame.src === src, 'the map loads from the placeholder address');
        assert(frame.title === 'Map of Accra Mall', 'the map is named for assistive tech');
        assert(frame.getAttribute('referrerpolicy') === 'strict-origin-when-cross-origin', 'referrer policy');
        assert(p.root.classList.contains('thallo-block-map__consent--loaded'), 'marked loaded');

        var again = buildPlaceholder(src);
        var undo = registered.map.enhance(again.root);
        assert(typeof undo === 'function', 'enhance returns a cleanup');
        undo();
        assert(again.button.hidden === true, 'cleanup hides the button again');
        again.button.dispatchEvent({ type: 'click', preventDefault: function () {} });
        assert(again.root.children.length === 3, 'and a click after cleanup loads nothing');

        var foreign = buildPlaceholder('https://evil.example/maps');
        assert(registered.map.enhance(foreign.root) === false, 'a non-Google address is never loaded');
        assert(foreign.button.hidden === true, 'and its button stays hidden');

        console.log('ALL_PASS');
        JS;
    }
}
