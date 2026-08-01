<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Executable coverage for block-gallery.js (modern-blocks spec §4): the native-<dialog>
 * lightbox loaded via block_script('gallery'). Mirrors AnimatedTextAssetTest's Node harness
 * skeleton (eval runtime.js THEN the asset bytes) and its own fresh-`vm`-context-per-scenario
 * discipline — the module name ('gallery') is a hardcoded literal here too. Unlike
 * animated-text, this asset builds real DOM (a <dialog> with nested controls) at runtime, so
 * the element stub grows: a generic node factory shared by static fixtures and
 * document.createElement()'d nodes, plus a tiny innerHTML parser for the fixed markup
 * block-gallery.js's build() emits.
 */
final class GalleryAssetTest extends AppTestCase
{
    private function runtimeJs(): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-render/runtime/runtime.js'
        );
    }

    private function assetJs(): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-render/runtime/block-gallery.js'
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

    public function testGalleryLightboxLifecycle(): void
    {
        $runtimeSrc = $this->runtimeJs();
        $assetSrc = $this->assetJs();

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate the gallery asset');
        }

        $file = sys_get_temp_dir() . '/thallo_gallery_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($runtimeSrc, $assetSrc));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            self::assertSame(0, $code, "gallery asset harness failed:\n" . implode("\n", $out));
            self::assertStringContainsString('ALL_PASS', implode("\n", $out));
        } finally {
            @unlink($file);
        }
    }

    private function harness(string $runtimeSrc, string $assetSrc): string
    {
        $runtimeJson = json_encode($runtimeSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $assetJson = json_encode($assetSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        'use strict';
        import { createContext, runInContext } from 'node:vm';

        var RUNTIME_SRC = {$runtimeJson};
        var ASSET_SRC = {$assetJson};

        function assert(c, m) { if (!c) { console.error('FAIL: ' + m); process.exit(1); } }
        function flush() { return new Promise(function (r) { setTimeout(r, 0); }); }

        // A generic element/node factory: covers BOTH the static gallery/anchor fixtures
        // built before eval AND the nodes block-gallery.js itself creates at runtime via
        // document.createElement('dialog') + .innerHTML (the dialog's prev/next/close/img/
        // status children). classList, matches (class OR tag selector — show() queries
        // 'img' by tag), closest (walks parentNode), querySelectorAll (class OR tag,
        // descendants only — matches real DOM: a node is never its own descendant),
        // addEventListener/removeEventListener/dispatchEvent (direct dispatch on the node
        // the listener was bound to — no bubbling simulation needed since the asset only
        // ever binds listeners on the exact node it later dispatches through).
        function makeNode(tag) {
          var attrs = {};
          var classes = [];
          var listeners = {};
          var kids = [];
          var text = '';
          var node = {
            tagName: (tag || 'div').toUpperCase(),
            parentNode: null,
            get children() { return kids; },
            get className() { return classes.join(' '); },
            set className(v) { classes = (v || '').split(' ').filter(Boolean); },
            classList: {
              add: function (c) { if (classes.indexOf(c) === -1) { classes.push(c); } },
              remove: function (c) { var i = classes.indexOf(c); if (i !== -1) { classes.splice(i, 1); } },
              contains: function (c) { return classes.indexOf(c) !== -1; }
            },
            appendChild: function (c) { c.parentNode = node; kids.push(c); return c; },
            removeChild: function (c) {
              var i = kids.indexOf(c);
              if (i !== -1) { kids.splice(i, 1); c.parentNode = null; }
              return c;
            },
            getAttribute: function (n) { return attrs[n] === undefined ? null : attrs[n]; },
            setAttribute: function (n, v) { attrs[n] = String(v); },
            removeAttribute: function (n) { delete attrs[n]; },
            hasAttribute: function (n) { return attrs[n] !== undefined; },
            matches: function (sel) {
              if (sel.charAt(0) === '.') { return node.classList.contains(sel.slice(1)); }
              return node.tagName === sel.toUpperCase();
            },
            closest: function (sel) {
              var n = node;
              while (n) { if (n.matches && n.matches(sel)) { return n; } n = n.parentNode; }
              return null;
            },
            querySelectorAll: function (sel) {
              var found = [];
              (function walk(n) {
                n.children.forEach(function (c) { if (c.matches(sel)) { found.push(c); } walk(c); });
              })(node);
              return found;
            },
            querySelector: function (sel) { return node.querySelectorAll(sel)[0] || null; },
            addEventListener: function (type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
            removeEventListener: function (type, fn) {
              var arr = listeners[type];
              if (!arr) { return; }
              var i = arr.indexOf(fn);
              if (i !== -1) { arr.splice(i, 1); }
            },
            dispatchEvent: function (evt) {
              var arr = (listeners[evt.type] || []).slice();
              arr.forEach(function (fn) { fn(evt); });
            },
            listenerCount: function (type) { return (listeners[type] || []).length; },
            get textContent() { return text; },
            set textContent(v) { text = v; },
            set innerHTML(html) { kids = []; parseInto(node, html); },
            get innerHTML() { return '[not serialized]'; }
          };
          return node;
        }

        // Tiny parser for the ONE fixed markup shape build() emits: sequential/nested
        // tags, double-quoted attributes, a void <img>, text-only leaves (the button
        // glyphs). Not a general HTML parser — scoped to exactly what block-gallery.js
        // is known to emit.
        function parseInto(parent, html) {
          var voidTags = { img: true, br: true, hr: true, input: true };
          var re = /<(\\/)?([a-zA-Z0-9]+)((?:\\s+[a-zA-Z0-9-]+(?:="[^"]*")?)*)\\s*>|([^<]+)/g;
          var stack = [parent];
          var m;
          while ((m = re.exec(html)) !== null) {
            if (m[4] !== undefined) {
              if (m[4].replace(/\\s+/g, '') !== '') { stack[stack.length - 1].textContent = m[4]; }
              continue;
            }
            var closing = !!m[1];
            var tag = m[2].toLowerCase();
            if (closing) { stack.pop(); continue; }
            var child = makeNode(tag);
            var attrRe = /([a-zA-Z0-9-]+)(?:="([^"]*)")?/g;
            var am;
            while ((am = attrRe.exec(m[3] || '')) !== null) {
              if (am[1] === 'class') { child.className = am[2] || ''; }
              else { child.setAttribute(am[1], am[2] === undefined ? '' : am[2]); }
            }
            stack[stack.length - 1].appendChild(child);
            if (!voidTags[tag]) { stack.push(child); }
          }
        }

        // The gallery markup contract (Task 4): root[data-lightbox] > N x
        // .thallo-block-gallery__item anchors (href = full-size URL, aria-label = the
        // fallback/authored label).
        function buildGallery(n, lightboxAttr) {
          var root = makeNode('div');
          root.className = 'thallo-block-gallery';
          root.setAttribute('data-lightbox', lightboxAttr === undefined ? '1' : lightboxAttr);
          var anchors = [];
          for (var i = 0; i < n; i++) {
            var a = makeNode('a');
            a.className = 'thallo-block-gallery__item';
            a.setAttribute('href', 'https://example.com/img' + (i + 1) + '.jpg');
            a.setAttribute('aria-label', 'Image ' + (i + 1) + ' of ' + n);
            a.focus = function () { this._focused = true; };
            root.appendChild(a);
            anchors.push(a);
          }
          return { root: root, anchors: anchors };
        }

        // Direct dispatch on the node the asset actually bound its listener to — no
        // bubbling simulation (see makeNode's doc comment).
        function click(target, eventTarget) {
          var evt = { type: 'click', target: eventTarget || target, defaultPrevented: false };
          evt.preventDefault = function () { evt.defaultPrevented = true; };
          target.dispatchEvent(evt);
          return evt;
        }

        // Wrap RT.register BEFORE running the asset bytes to capture the raw enhance()
        // function (same rationale as AnimatedTextAssetTest: the class-based path keeps
        // cleanup private, so direct capture is the only way to drive enhance()/cleanup
        // by hand for the failure-injection/cleanup cases).
        function captureEnhance(ctx, moduleName) {
          var captured = { fn: null, calls: 0 };
          var RT = ctx.window.ThalloRuntime;
          var orig = RT.register;
          RT.register = function (name, def) {
            if (name === moduleName) { captured.fn = def.enhance; captured.calls++; }
            return orig.call(RT, name, def);
          };
          return captured;
        }

        // A fresh realm: its own ThalloRuntime module registry, its own guard globals,
        // its own document/dialog stubs. dialogSupported controls whether
        // window.HTMLDialogElement exists at all (the asset's supported() feature test).
        function makeSandbox(dialogSupported) {
          var ctx = {};
          ctx.window = ctx;
          ctx.console = { error: function () {}, log: function () {} };
          ctx.__throwOnCreateDialog = false;
          ctx.__throwOnShowModal = false;
          ctx.__dialogCreateCount = 0;

          ctx.matchMedia = function (q) {
            return { matches: false, media: q, addEventListener: function () {}, removeEventListener: function () {} };
          };
          ctx.window.matchMedia = ctx.matchMedia;

          var body = makeNode('body');
          var docRoot = makeNode('html');
          docRoot.dataset = {}; // color-mode's hard gate reads documentElement.dataset
          docRoot.readyState = undefined;

          var doc = {
            readyState: 'complete',
            documentElement: docRoot,
            body: body,
            querySelector: function (sel) { return docRoot.querySelector(sel); },
            querySelectorAll: function (sel) { return docRoot.querySelectorAll(sel); },
            addEventListener: function () {},
            removeEventListener: function () {},
            createElement: function (tag) {
              if (tag === 'dialog' && ctx.__throwOnCreateDialog) {
                throw new Error('injected: createElement(dialog) boom');
              }
              var el = makeNode(tag);
              if (tag === 'dialog') {
                ctx.__dialogCreateCount++;
                el.open = false;
                el.showModal = function () {
                  if (ctx.__throwOnShowModal) { throw new Error('injected: showModal boom'); }
                  el.open = true;
                };
                el.close = function () {
                  if (!el.open) { return; }
                  el.open = false;
                  el.dispatchEvent({ type: 'close' });
                };
              }
              return el;
            }
          };
          ctx.document = doc;
          ctx.window.document = doc;

          if (dialogSupported) {
            function HTMLDialogElement() {}
            HTMLDialogElement.prototype.showModal = function () {};
            ctx.HTMLDialogElement = HTMLDialogElement;
            ctx.window.HTMLDialogElement = HTMLDialogElement;
          }

          createContext(ctx);
          return ctx;
        }

        (async function () {
          // 1. Double-eval of the asset registers once: no throw, guard set once.
          (function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            var spy = captureEnhance(ctx, 'gallery');
            runInContext(ASSET_SRC, ctx);
            assert(spy.calls === 1, 'first eval registered gallery exactly once');
            assert(ctx.window.__thalloBlockGallery === true, 'guard set after first eval');
            runInContext(ASSET_SRC, ctx); // second eval: guard must block before any register call
            assert(spy.calls === 1, 'second eval did not attempt to re-register (guard held)');
            assert(ctx.window.__thalloBlockGallery === true, 'guard remains true after second eval');
          })();

          // 1b. The RT.register duplicate-registration CATCH branch, exercised for real
          //     (mirrors AnimatedTextAssetTest scenario 1b / Task 5 scenario 1b): reset the
          //     guard while the registry still holds 'gallery', re-eval — RT.register
          //     throws for real, the catch must return WITHOUT setting the guard, exactly
          //     one registration must stand, and no additional self-enhance side effects.
          (function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);

            var registerAttempts = 0;
            var registerSuccesses = 0;
            var enhanceInvocations = 0;
            var RT = ctx.window.ThalloRuntime;
            var origRegister = RT.register;
            RT.register = function (name, def) {
              if (name !== 'gallery') { return origRegister.call(RT, name, def); }
              registerAttempts++;
              var wrapped = def.enhance;
              var countingDef = {
                selector: def.selector,
                enhance: function (root) { enhanceInvocations++; return wrapped(root); }
              };
              var result = origRegister.call(RT, name, countingDef); // throws on the 2nd attempt
              registerSuccesses++; // only reached when the call above did NOT throw
              return result;
            };

            var gallery = buildGallery(3);
            ctx.document.documentElement.appendChild(gallery.root);

            runInContext(ASSET_SRC, ctx); // first eval: registers + self-enhances the gallery
            assert(registerAttempts === 1 && registerSuccesses === 1, 'first eval registered once');
            assert(enhanceInvocations === 1, 'first eval self-enhanced the gallery once');
            assert(ctx.window.__thalloBlockGallery === true, 'guard set after first eval');
            var markerAfterFirst = gallery.root.getAttribute('data-thallo-enhanced');
            assert(markerAfterFirst && markerAfterFirst.indexOf('gallery') !== -1, 'gallery marked after first eval');

            runInContext('delete window.__thalloBlockGallery;', ctx); // guard cleared, registry untouched

            runInContext(ASSET_SRC, ctx); // second eval: RT.register now throws for real

            assert(registerAttempts === 2, 'second eval attempted to register again (guard was cleared)');
            assert(registerSuccesses === 1, 'RT.register threw on the 2nd attempt: exactly one registration stands');
            assert(ctx.window.__thalloBlockGallery !== true, 'the catch branch returns WITHOUT setting the guard');
            assert(enhanceInvocations === 1, 'the failed second eval produced no additional self-enhance invocation');
            assert(gallery.root.getAttribute('data-thallo-enhanced') === markerAfterFirst,
              'no additional marking/side effects from the failed second eval');
          })();

          // 2. Missing runtime: guard NOT set, static untouched; re-eval after restoring
          //    the runtime works (retry path) since the guard was never burned.
          (function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            var gallery = buildGallery(3);
            // Deleting a global from OUTSIDE a vm context does not reliably propagate for
            // SUBSEQUENT runInContext evaluations — must delete from INSIDE (same `vm`
            // quirk AnimatedTextAssetTest documents).
            runInContext('delete window.ThalloRuntime;', ctx);
            runInContext(ASSET_SRC, ctx);
            assert(ctx.window.__thalloBlockGallery !== true, 'guard NOT set when the runtime is missing');
            assert(gallery.root.getAttribute('data-thallo-enhanced') === null,
              'static untouched: no marker without a runtime');

            runInContext(RUNTIME_SRC, ctx); // restore: fresh module registry
            assert(typeof ctx.window.ThalloRuntime.register === 'function', 'runtime restored');

            runInContext(ASSET_SRC, ctx); // retry: guard was never burned, so this succeeds
            assert(ctx.window.__thalloBlockGallery === true, 'retry path: guard set after restore');
            ctx.window.ThalloRuntime.enhance(gallery.root);
            assert((gallery.root.getAttribute('data-thallo-enhanced') || '').indexOf('gallery') !== -1,
              'retry path: the module actually works after restore');
          })();

          // 3. Registration after a completed boot self-enhances an existing gallery.
          await (async function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            await flush(); // the runtime's own boot pass completes with no gallery module yet
            var gallery = buildGallery(3);
            ctx.document.documentElement.appendChild(gallery.root); // inserted AFTER boot already ran
            runInContext(ASSET_SRC, ctx); // registers, then self-enhances document.documentElement
            assert((gallery.root.getAttribute('data-thallo-enhanced') || '').indexOf('gallery') !== -1,
              'late registration self-enhance found the pre-existing gallery');
          })();

          // 4. data-lightbox="0": enhance() returns false (opt-out), never marked, a click
          //    is left untouched (no listener is ever attached).
          (function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            var gallery = buildGallery(3, '0');
            var spy = captureEnhance(ctx, 'gallery');
            runInContext(ASSET_SRC, ctx);
            var result = spy.fn(gallery.root);
            assert(result === false, 'data-lightbox=0: enhance() returns false');
            assert(gallery.root.listenerCount('click') === 0, 'data-lightbox=0: no click listener attached');
            ctx.window.ThalloRuntime.enhance(gallery.root);
            assert(gallery.root.getAttribute('data-thallo-enhanced') === null, 'data-lightbox=0: never marked');
            var evt = click(gallery.root, gallery.anchors[0]);
            assert(evt.defaultPrevented === false, 'data-lightbox=0: click left untouched');
            assert(ctx.__dialogCreateCount === 0, 'data-lightbox=0: no dialog ever built');
          })();

          // 5. Supported dialog: first click builds exactly ONE dialog, showModal is
          //    called (dialog.open becomes true), preventDefault is called, and the
          //    status region reads "1 of N". A second click on a DIFFERENT anchor in the
          //    same gallery reuses the same dialog (still exactly one created) and
          //    updates the status.
          (function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            var gallery = buildGallery(3);
            var spy = captureEnhance(ctx, 'gallery');
            runInContext(ASSET_SRC, ctx);
            spy.fn(gallery.root);

            var evt = click(gallery.root, gallery.anchors[0]);
            assert(ctx.__dialogCreateCount === 1, 'first click builds exactly one dialog');
            assert(evt.defaultPrevented === true, 'preventDefault called after a successful showModal');
            var dialog = ctx.document.body.children[ctx.document.body.children.length - 1];
            assert(dialog.open === true, 'showModal was called (dialog reports open)');
            assert(dialog.querySelector('.thallo-block-gallery__status').textContent === '1 of 3',
              'status region reads "1 of 3" for the first anchor');

            var evt2 = click(gallery.root, gallery.anchors[1]);
            assert(ctx.__dialogCreateCount === 1, 'second click on the same gallery reuses the dialog');
            assert(evt2.defaultPrevented === true, 'preventDefault called again on the second click');
            assert(dialog.querySelector('.thallo-block-gallery__status').textContent === '2 of 3',
              'status region updates to "2 of 3"');
          })();

          // 6. prev/next wrap in both directions.
          (function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            var gallery = buildGallery(3);
            var spy = captureEnhance(ctx, 'gallery');
            runInContext(ASSET_SRC, ctx);
            spy.fn(gallery.root);
            click(gallery.root, gallery.anchors[0]);
            var dialog = ctx.document.body.children[ctx.document.body.children.length - 1];
            var status = function () { return dialog.querySelector('.thallo-block-gallery__status').textContent; };
            var next = dialog.querySelector('.thallo-block-gallery__next');
            var prev = dialog.querySelector('.thallo-block-gallery__prev');

            assert(status() === '1 of 3', 'starts on 1 of 3');
            next.dispatchEvent({ type: 'click' });
            assert(status() === '2 of 3', 'next advances to 2 of 3');
            next.dispatchEvent({ type: 'click' });
            assert(status() === '3 of 3', 'next advances to 3 of 3');
            next.dispatchEvent({ type: 'click' });
            assert(status() === '1 of 3', 'next wraps forward from the last to the first');
            prev.dispatchEvent({ type: 'click' });
            assert(status() === '3 of 3', 'prev wraps backward from the first to the last');
          })();

          // 7. Close restores focus to the originating anchor.
          (function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            var gallery = buildGallery(3);
            var spy = captureEnhance(ctx, 'gallery');
            runInContext(ASSET_SRC, ctx);
            spy.fn(gallery.root);
            click(gallery.root, gallery.anchors[1]);
            var dialog = ctx.document.body.children[ctx.document.body.children.length - 1];
            assert(!gallery.anchors[1]._focused, 'not focused before close');
            dialog.querySelector('.thallo-block-gallery__close').dispatchEvent({ type: 'click' });
            assert(dialog.open === false, 'close button actually closed the dialog');
            assert(gallery.anchors[1]._focused === true, 'close restores focus to the originating anchor');
          })();

          // 8. Unsupported: no HTMLDialogElement.showModal at all — the anchor click is
          //    left completely untouched (no preventDefault, no dialog built).
          (function () {
            var ctx = makeSandbox(false);
            runInContext(RUNTIME_SRC, ctx);
            var gallery = buildGallery(3);
            var spy = captureEnhance(ctx, 'gallery');
            runInContext(ASSET_SRC, ctx);
            var result = spy.fn(gallery.root);
            assert(typeof result === 'function', 'unsupported dialogs still enhance (opt-in class + listener)');
            var evt = click(gallery.root, gallery.anchors[0]);
            assert(evt.defaultPrevented === false, 'unsupported: preventDefault never called');
            assert(ctx.__dialogCreateCount === 0, 'unsupported: no dialog ever built');
          })();

          // 9. Construction throw (document.createElement('dialog') throws): the click is
          //    left untouched, no dialog remains attached, and a LATER click retries
          //    successfully once the failure is gone.
          (function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            var gallery = buildGallery(3);
            var spy = captureEnhance(ctx, 'gallery');
            runInContext(ASSET_SRC, ctx);
            spy.fn(gallery.root);

            ctx.__throwOnCreateDialog = true;
            var evt = click(gallery.root, gallery.anchors[0]);
            assert(evt.defaultPrevented === false, 'construction throw: preventDefault never called');
            assert(ctx.document.body.children.length === 0, 'construction throw: no dialog remains attached');

            ctx.__throwOnCreateDialog = false;
            var evt2 = click(gallery.root, gallery.anchors[0]);
            assert(evt2.defaultPrevented === true, 'a later click retries successfully after the failure clears');
            assert(ctx.document.body.children.length === 1, 'the retry actually attached a dialog');
          })();

          // 10. showModal throw: the click is left untouched, the half-built dialog is
          //     discarded (removed from the DOM), and a LATER click retries successfully.
          (function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            var gallery = buildGallery(3);
            var spy = captureEnhance(ctx, 'gallery');
            runInContext(ASSET_SRC, ctx);
            spy.fn(gallery.root);

            ctx.__throwOnShowModal = true;
            var evt = click(gallery.root, gallery.anchors[0]);
            assert(evt.defaultPrevented === false, 'showModal throw: preventDefault never called');
            assert(ctx.document.body.children.length === 0, 'showModal throw: no dialog remains attached');

            ctx.__throwOnShowModal = false;
            var evt2 = click(gallery.root, gallery.anchors[0]);
            assert(evt2.defaultPrevented === true, 'a later click retries successfully after the failure clears');
            assert(ctx.document.body.children.length === 1, 'the retry actually attached a dialog');
          })();

          // 11. Two galleries on the same page: ONE registration total, but independent
          //     per-gallery state (dialogs, current index, focus target never cross over).
          await (async function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            await flush();
            var g1 = buildGallery(3);
            var g2 = buildGallery(2);
            ctx.document.documentElement.appendChild(g1.root);
            ctx.document.documentElement.appendChild(g2.root);
            var spy = captureEnhance(ctx, 'gallery');
            runInContext(ASSET_SRC, ctx); // registers once, self-enhances both galleries

            assert(spy.calls === 1, 'exactly one registration for two galleries on the page');
            assert((g1.root.getAttribute('data-thallo-enhanced') || '').indexOf('gallery') !== -1, 'gallery 1 enhanced');
            assert((g2.root.getAttribute('data-thallo-enhanced') || '').indexOf('gallery') !== -1, 'gallery 2 enhanced');

            var evt1 = click(g1.root, g1.anchors[0]);
            var dialog1 = ctx.document.body.children[ctx.document.body.children.length - 1];
            assert(evt1.defaultPrevented === true, 'gallery 1 click opened its own dialog');
            assert(dialog1.querySelector('.thallo-block-gallery__status').textContent === '1 of 3',
              'gallery 1 status reflects its own item count');

            var evt2 = click(g2.root, g2.anchors[1]);
            assert(evt2.defaultPrevented === true, 'gallery 2 click opened independently');
            assert(ctx.document.body.children.length === 2, 'both dialogs coexist independently');
            var dialog2 = ctx.document.body.children[ctx.document.body.children.length - 1];
            assert(dialog2 !== dialog1, 'gallery 2 built a DIFFERENT dialog node than gallery 1');
            assert(dialog2.querySelector('.thallo-block-gallery__status').textContent === '2 of 2',
              'gallery 2 status reflects ITS OWN item count, unaffected by gallery 1');
            assert(dialog1.querySelector('.thallo-block-gallery__status').textContent === '1 of 3',
              'gallery 1 status untouched by gallery 2 activity');
          })();

          // 12. Cleanup removes the click listener AND the dialog node, and remains
          //     resilient when one of the two cleanup actions throws: rigging the
          //     dialog-removal step (the LIFO-first action) to throw must not prevent the
          //     listener-removal step (the LIFO-second action) from still running, and
          //     cleanup() itself must not propagate the throw.
          (function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            var gallery = buildGallery(3);
            var spy = captureEnhance(ctx, 'gallery');
            runInContext(ASSET_SRC, ctx);
            var cleanup = spy.fn(gallery.root);
            assert(typeof cleanup === 'function', 'enhance returned a cleanup function');

            click(gallery.root, gallery.anchors[0]);
            assert(gallery.root.listenerCount('click') === 1, 'click listener attached after enhance');
            assert(ctx.document.body.children.length === 1, 'dialog attached after the first click');

            var originalRemoveChild = ctx.document.body.removeChild;
            ctx.document.body.removeChild = function () {
              throw new Error('injected: body.removeChild boom');
            };

            var threw = false;
            try { cleanup(); } catch (e) { threw = true; }
            assert(threw === false, 'cleanup itself must not throw even when one undo action throws');
            assert(gallery.root.listenerCount('click') === 0,
              'the OTHER cleanup action (listener removal) still ran despite the dialog-removal throw');

            ctx.document.body.removeChild = originalRemoveChild; // sanity: nothing else broke
          })();

          // 13. Cleanup also runs cleanly end-to-end (no dialog ever opened): both undo
          //     actions succeed, listener is gone, and discardDialog's no-dialog branch
          //     is a harmless no-op.
          (function () {
            var ctx = makeSandbox(true);
            runInContext(RUNTIME_SRC, ctx);
            var gallery = buildGallery(3);
            var spy = captureEnhance(ctx, 'gallery');
            runInContext(ASSET_SRC, ctx);
            var cleanup = spy.fn(gallery.root);
            assert(gallery.root.listenerCount('click') === 1, 'click listener attached after enhance');
            var threw = false;
            try { cleanup(); } catch (e) { threw = true; }
            assert(threw === false, 'cleanup with no dialog ever built does not throw');
            assert(gallery.root.listenerCount('click') === 0, 'cleanup removed the click listener');
          })();

          console.log('ALL_PASS');
        })().catch(function (e) { console.error('FAIL: ' + (e && e.stack || e)); process.exit(1); });
        JS;
    }
}
