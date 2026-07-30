<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Tests\Support\AppTestCase;

/**
 * Coexistence proof for the theme runtime and shop.js (theme-runtime spec §8 +
 * shopjs-on-runtime adoption): the two independently-delivered behavior layers share ONE
 * document and ONE core registry without duplicate ownership. Mirrors
 * {@see \App\Tests\Integration\Commerce\ShopJsRuntimeTest}'s Node + hand-stubbed-DOM
 * harness (its Element/Doc/selector stubs, extended with class-selector matching so the
 * runtime's own querySelector calls resolve) and loads BOTH served byte-sources —
 * `packages/thallo-render/runtime/runtime.js` and
 * `packages/thallo-commerce/assets/shop.js`; neither has a build step, so these files ARE
 * the served bytes — into ONE stub document containing a `form[data-thallo-form]` block
 * (result box + submit button) and shop.js's own cart/checkout form stubs, in the ADOPTED
 * load order: the theme-runtime core first, then shop.js, so shop.js REGISTERS its nine
 * `shop-*` modules and the core's single deferred boot pass binds both worlds. Proves:
 * neither eval throws; shop.js attaches nothing at eval time (no self-binding on runtime
 * pages); the shared registry holds the five theme modules AND the nine shop modules
 * (probed via the duplicate-registration throw); ownership stays disjoint in marker form
 * (the thallo form carries `data-thallo-enhanced~="forms"` only, the shop forms
 * `~="shop-form"` only, and `data-shop-bound` never lands on the thallo form); a second
 * core enhance() pass re-binds nothing; and each owner's submit fingerprint is intact —
 * the runtime intercepts the thallo form (aria-busy during the mocked fetch, no
 * credentials option) while shop.js intercepts its forms (same-origin credentials, the
 * checkout fetch carrying X-Idempotency-Key). Skips (does not fail) without node, but
 * still asserts structural source markers, including static selector disjointness
 * between the two files.
 */
final class RuntimeShopCoexistenceTest extends AppTestCase
{
    private function runtimeJs(): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-render/runtime/runtime.js'
        );
    }

    private function shopJs(): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-commerce/assets/shop.js'
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

    public function testBothScriptsEnhanceOnlyTheirOwnFormsInOneDocument(): void
    {
        $runtime = $this->runtimeJs();
        $shop = $this->shopJs();

        // Structural markers — checked even without node. The selector universes are
        // provably disjoint at the source level: the runtime never references shop
        // endpoints/hooks, shop.js never references the runtime's form hook or endpoint.
        self::assertStringContainsString('window.ThalloRuntime', $runtime);
        self::assertStringContainsString("register('forms'", $runtime);
        self::assertStringContainsString('form[data-thallo-form]', $runtime);
        self::assertStringNotContainsString('/_shop/', $runtime);
        self::assertStringNotContainsString('data-shop-', $runtime);
        self::assertStringContainsString('window.thalloShop', $shop);
        self::assertStringContainsString('form[action="/_shop/cart/update"]', $shop);
        self::assertStringContainsString("register('shop-form'", $shop);
        self::assertStringContainsString("register('shop-add-to-cart'", $shop);
        self::assertStringContainsString("register('shop-wishlist'", $shop);
        self::assertStringContainsString("register('shop-wishlist-page'", $shop);
        self::assertStringNotContainsString('data-thallo-form', $shop);
        self::assertStringNotContainsString('/_forms/submit', $shop);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate runtime.js + shop.js together');
        }

        $file = sys_get_temp_dir() . '/thallo_runtime_shop_coexist_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($runtime, $shop));
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            $output = implode("\n", $out);
            self::assertSame(0, $code, "coexistence harness failed:\n" . $output);
            self::assertStringContainsString('ALL_PASS', $output);
        } finally {
            @unlink($file);
        }
    }

    /** Build a self-checking node harness loading BOTH real sources into one document. */
    private function harness(string $runtimeSrc, string $shopSrc): string
    {
        $runtime = json_encode($runtimeSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $shop = json_encode($shopSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        'use strict';

        function fail(msg) { console.error('FAIL: ' + msg); process.exit(1); }
        function assert(cond, msg) { if (!cond) { fail(msg); } }

        // ------------------------------------------------------------------
        // The ShopJsRuntimeTest DOM stub — elements with attributes, children,
        // per-element listener RECORDING (the coexistence evidence), and a small
        // selector matcher — extended with className/classList + class-selector
        // support so the runtime's own querySelector calls resolve too.
        // ------------------------------------------------------------------

        function Element(tagName) {
          this.tagName = tagName;
          this.attrs = {};
          this.children = [];
          this.parentNode = null;
          this._text = '';
          this._listeners = {};
          this.hidden = false;
          this.disabled = false;
          this.className = '';
          this.value = '';
          this.onclick = null;
          this._focusCount = 0;
        }
        Object.defineProperty(Element.prototype, 'textContent', {
          get: function () { return this._text; },
          set: function (v) { this._text = String(v); this.children = []; },
        });
        Object.defineProperty(Element.prototype, 'firstChild', {
          get: function () { return this.children.length ? this.children[0] : null; },
        });
        Object.defineProperty(Element.prototype, 'id', {
          get: function () { return this.attrs.id || ''; },
          set: function (v) { this.attrs.id = String(v); },
        });
        Object.defineProperty(Element.prototype, 'nextSibling', {
          get: function () {
            if (!this.parentNode) { return null; }
            var idx = this.parentNode.children.indexOf(this);
            return this.parentNode.children[idx + 1] || null;
          },
        });
        Object.defineProperty(Element.prototype, 'classList', {
          get: function () {
            var self = this;
            function names() {
              return self.className.split(/\\s+/).filter(function (c) { return c !== ''; });
            }
            return {
              add: function () {
                var cur = names();
                for (var i = 0; i < arguments.length; i++) {
                  if (cur.indexOf(arguments[i]) === -1) { cur.push(arguments[i]); }
                }
                self.className = cur.join(' ');
              },
              remove: function () {
                var cur = names();
                for (var i = 0; i < arguments.length; i++) {
                  var idx = cur.indexOf(arguments[i]);
                  if (idx !== -1) { cur.splice(idx, 1); }
                }
                self.className = cur.join(' ');
              },
              contains: function (c) { return names().indexOf(c) !== -1; },
            };
          },
        });
        Element.prototype.getAttribute = function (name) {
          return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null;
        };
        Element.prototype.setAttribute = function (name, value) { this.attrs[name] = String(value); };
        Element.prototype.removeAttribute = function (name) { delete this.attrs[name]; };
        Element.prototype.appendChild = function (child) {
          this.children.push(child);
          child.parentNode = this;
          return child;
        };
        Element.prototype.insertBefore = function (node, ref) {
          var idx = ref ? this.children.indexOf(ref) : -1;
          if (idx === -1) { this.children.push(node); } else { this.children.splice(idx, 0, node); }
          node.parentNode = this;
          return node;
        };
        Element.prototype.removeChild = function (child) {
          var idx = this.children.indexOf(child);
          if (idx !== -1) { this.children.splice(idx, 1); }
          child.parentNode = null;
          return child;
        };
        Element.prototype.addEventListener = function (type, fn) {
          (this._listeners[type] = this._listeners[type] || []).push(fn);
        };
        Element.prototype.focus = function () { this._focusCount++; };
        Element.prototype.querySelectorAll = function (sel) { return collect(this, sel, []); };
        Element.prototype.querySelector = function (sel) { return collect(this, sel, [])[0] || null; };

        function parseSelectorList(sel) {
          return sel.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        }

        function matchesCompound(el, compound) {
          var m = compound.match(
            /^([a-zA-Z0-9_-]*)((?:\\.[a-zA-Z0-9_-]+)*)((?:\\[[^\\]]+\\])*)\$/
          );
          if (!m) { return false; }
          var tag = m[1];
          var classPart = m[2];
          var attrsPart = m[3];
          if (tag && el.tagName !== tag) { return false; }
          if (classPart) {
            var classes = classPart.split('.');
            for (var c = 0; c < classes.length; c++) {
              if (classes[c] && !el.classList.contains(classes[c])) { return false; }
            }
          }
          var attrRe = /\\[([a-zA-Z0-9_-]+)(?:="([^"]*)")?\\]/g;
          var am;
          while ((am = attrRe.exec(attrsPart))) {
            var name = am[1];
            var value = am[2];
            if (!Object.prototype.hasOwnProperty.call(el.attrs, name)) { return false; }
            if (value !== undefined && el.attrs[name] !== value) { return false; }
          }
          return true;
        }

        function matchesSelector(el, selectorList) {
          var compounds = parseSelectorList(selectorList);
          for (var i = 0; i < compounds.length; i++) {
            if (matchesCompound(el, compounds[i])) { return true; }
          }
          return false;
        }

        function collect(root, selectorList, out) {
          for (var i = 0; i < root.children.length; i++) {
            var child = root.children[i];
            if (matchesSelector(child, selectorList)) { out.push(child); }
            collect(child, selectorList, out);
          }
          return out;
        }

        function findById(root, id) {
          for (var i = 0; i < root.children.length; i++) {
            var child = root.children[i];
            if (child.attrs.id === id) { return child; }
            var found = findById(child, id);
            if (found) { return found; }
          }
          return null;
        }

        function Doc() {
          // documentElement wraps body: the runtime's boot pass scans it, and the
          // color-mode module's hard gate reads its .dataset (absent flag -> inert).
          this.documentElement = new Element('html');
          this.documentElement.dataset = {};
          this.body = new Element('body');
          this.documentElement.appendChild(this.body);
          this.readyState = 'complete';
          this._listeners = {};
        }
        Doc.prototype.getElementById = function (id) { return findById(this.documentElement, id); };
        Doc.prototype.createElement = function (tag) { return new Element(tag); };
        Doc.prototype.addEventListener = function (type, fn) {
          (this._listeners[type] = this._listeners[type] || []).push(fn);
        };
        Doc.prototype.querySelectorAll = function (sel) {
          return collect(this.documentElement, sel, []);
        };
        Doc.prototype.querySelector = function (sel) {
          return collect(this.documentElement, sel, [])[0] || null;
        };

        function el(tag, attrs, children) {
          var e = new Element(tag);
          attrs = attrs || {};
          for (var k in attrs) {
            if (Object.prototype.hasOwnProperty.call(attrs, k)) {
              e.attrs[k] = String(attrs[k]);
              if (k === 'value') { e.value = String(attrs[k]); }
            }
          }
          (children || []).forEach(function (c) { e.appendChild(c); });
          return e;
        }

        function fireSubmit(form) {
          var evt = {
            type: 'submit',
            target: form,
            defaultPrevented: false,
            preventDefault: function () { this.defaultPrevented = true; },
          };
          var listeners = form._listeners['submit'] || [];
          for (var i = 0; i < listeners.length; i++) { listeners[i](evt); }
          return evt;
        }

        async function flush() {
          for (var i = 0; i < 15; i++) { await Promise.resolve(); }
        }

        function FakeFormData(form) {
          this._entries = [];
          var self = this;
          (function walk(node) {
            for (var i = 0; i < node.children.length; i++) {
              var child = node.children[i];
              if ((child.tagName === 'input' || child.tagName === 'select') && child.attrs.name) {
                self._entries.push([child.attrs.name, child.value]);
              }
              walk(child);
            }
          })(form);
        }
        FakeFormData.prototype.get = function (name) {
          for (var i = 0; i < this._entries.length; i++) {
            if (this._entries[i][0] === name) { return this._entries[i][1]; }
          }
          return null;
        };

        // ------------------------------------------------------------------
        // ONE document holding both worlds:
        //   (a) a thallo form block — result box + submit button (runtime hooks);
        //   (b) shop.js's own form stubs from ShopJsRuntimeTest — the cart-update
        //       form and the idempotency-key-carrying checkout/place form.
        // ------------------------------------------------------------------

        var doc = new Doc();

        var box = el('span', {});
        box.className = 'thallo-block-form__result';
        var thalloBtn = el('button', { type: 'submit' });
        thalloBtn.className = 'thallo-block-form__submit';
        var email = el('input', { type: 'email', name: 'email', value: 'a@example.test' });
        var thalloForm = el(
          'form',
          { 'data-thallo-form': '', action: '/_forms/submit' },
          [email, box, thalloBtn]
        );
        thalloForm.action = '/_forms/submit'; // property the runtime reads (fetch target)
        var resetCalls = 0;
        thalloForm.reset = function () { resetCalls++; };
        doc.body.appendChild(thalloForm);

        var variant = el('input', { type: 'hidden', name: 'variant_uuid', value: 'var-1' });
        var qty = el('input', { type: 'number', name: 'quantity', value: '2' });
        var cartBtn = el('button', { type: 'submit' });
        var shopCartForm = el('form', { action: '/_shop/cart/update' }, [variant, qty, cartBtn]);
        doc.body.appendChild(shopCartForm);

        var key = el('input', { type: 'hidden', name: 'idempotency_key', value: 'idem-key-coexist-1' });
        var placeBtn = el('button', { type: 'submit' });
        var shopPlaceForm = el('form', { action: '/_shop/checkout/place' }, [key, placeBtn]);
        doc.body.appendChild(shopPlaceForm);

        // Shared environment: both scripts see the SAME window/document/fetch/FormData
        // (the runtime references bare fetch/FormData, so they must live on the global).
        var calls = [];
        var behaviors = {
          '/_forms/submit': { ok: true, status: 200, data: { ok: true, message: 'Thanks!' } },
          '/_shop/cart/update': {
            ok: true,
            status: 200,
            data: {
              items: [{
                variant_uuid: 'var-1',
                product_name: 'Widget',
                quantity: 2,
                line_total_formatted: '20.00',
                currency: 'USD',
              }],
              item_count: 2,
              grand_total_formatted: '20.00',
              currency: 'USD',
              cart_url: '/cart',
              checkout_url: '/checkout',
            },
          },
          '/_shop/checkout/place': { ok: true, status: 200, data: { action: 'manual' } },
        };
        global.document = doc;
        global.window = global;
        global.fetch = function (url, opts) {
          calls.push({ url: url, opts: opts });
          var b = behaviors[url];
          if (!b) { return Promise.reject(new Error('unexpected fetch to ' + url)); }
          return Promise.resolve({
            ok: b.ok,
            status: b.status,
            json: function () { return Promise.resolve(b.data); },
          });
        };
        global.FormData = FakeFormData;

        // ------------------------------------------------------------------
        // Load the theme-runtime core FIRST — the adopted configuration: the core
        // is on the page before any shop block script tag, so shop.js registers
        // its nine shop-* modules instead of self-driving. This harness evals both
        // files in ONE task, so the core's deferred boot (a microtask — readyState
        // is already 'complete') runs after the registrations and covers both
        // worlds here, with shop.js's scheduled catch-up pass a marker-gated
        // no-op behind it. On REAL pages the two files are separate defer script
        // tasks and the boot fires BEFORE shop.js registers — there the catch-up
        // pass is what binds the shop modules (pinned by ShopJsRuntimeTest's
        // separate-tasks harness).
        // ------------------------------------------------------------------

        try {
          eval($runtime);
        } catch (e) {
          fail('runtime.js eval threw: ' + (e && e.stack ? e.stack : e));
        }
        assert(global.ThalloRuntime && typeof global.ThalloRuntime.enhance === 'function',
          'runtime.js exposed window.ThalloRuntime');

        try {
          eval($shop);
        } catch (e) {
          fail('shop.js eval threw: ' + (e && e.stack ? e.stack : e));
        }
        assert(global.thalloShop && typeof global.thalloShop.bindForm === 'function',
          'shop.js exposed window.thalloShop');

        // -- adoption checkpoint: shop.js no longer self-binds at eval time -----
        // Registration is its ONLY load-time effect on runtime pages; every
        // listener lands later, in the core's boot pass.
        assert(Object.keys(shopCartForm._listeners).length === 0
          && Object.keys(shopPlaceForm._listeners).length === 0,
          'shop.js attached no listeners at eval time (the core boot drives)');
        assert(shopCartForm.getAttribute('data-shop-bound') === null
          && shopPlaceForm.getAttribute('data-shop-bound') === null,
          'no shop form is bound before the core boot pass');
        assert(Object.keys(thalloForm._listeners).length === 0,
          'shop.js attached NOTHING to the thallo form');
        assert(calls.length === 0,
          'no hydration fetches for a document without shop block shells');

        // -- ONE shared registry: five theme modules + nine shop modules --------
        // Probed via the core's duplicate-name throw (silent replacement is the
        // failure mode the registry contract forbids).
        var registeredNames = ['color-mode', 'forms', 'carousel', 'navigation', 'tabs',
          'shop-form', 'shop-gallery', 'shop-buy', 'shop-mini-cart',
          'shop-product-grid', 'shop-featured-product', 'shop-add-to-cart',
          'shop-wishlist', 'shop-wishlist-page'];
        for (var rn = 0; rn < registeredNames.length; rn++) {
          var probeThrew = false;
          try {
            global.ThalloRuntime.register(registeredNames[rn], { selector: 'x', enhance: function () {} });
          } catch (e) {
            probeThrew = true;
            assert(String(e && e.message).indexOf('already registered') !== -1,
              'duplicate probe for ' + registeredNames[rn] + ' threw the duplicate-name error');
          }
          assert(probeThrew,
            'the shared registry contains ' + registeredNames[rn] + ' (duplicate probe throws)');
        }

        function markerList(elm) {
          var v = elm.getAttribute('data-thallo-enhanced');
          return v === null ? [] : v.split(/\\s+/).filter(Boolean);
        }

        (async function () {
          await flush();

          // -- listener-ownership checkpoint after the core's single boot pass ----
          assert(thalloForm._listeners.submit && thalloForm._listeners.submit.length === 1,
            'the boot pass bound the thallo form (forms module)');
          assert(Object.keys(thalloForm._listeners).join(',') === 'submit',
            'only a submit listener was added to the thallo form');
          assert(Object.keys(shopCartForm._listeners).join(',') === 'submit'
            && shopCartForm._listeners.submit.length === 1,
            'the boot pass bound the shop cart form (shop-form module) exactly once');
          assert(Object.keys(shopPlaceForm._listeners).join(',') === 'submit'
            && shopPlaceForm._listeners.submit.length === 1,
            'the boot pass bound the shop checkout form (shop-form module) exactly once');
          var cartHandler = shopCartForm._listeners.submit[0];
          var placeHandler = shopPlaceForm._listeners.submit[0];

          // -- no cross-ownership, in marker form ---------------------------------
          assert(markerList(thalloForm).indexOf('forms') !== -1,
            'the thallo form carries data-thallo-enhanced~="forms"');
          assert(markerList(thalloForm).join(',') === 'forms',
            'the thallo form carries ONLY the theme forms marker — no shop-* module touched it');
          assert(thalloForm.getAttribute('data-shop-bound') === null,
            'shop.js did not stamp its bound marker on the thallo form');
          assert(markerList(shopCartForm).indexOf('shop-form') !== -1,
            'the shop cart form carries data-thallo-enhanced~="shop-form"');
          assert(markerList(shopCartForm).join(',') === 'shop-form',
            'the shop cart form carries ONLY the shop-form marker — no theme module touched it');
          assert(markerList(shopPlaceForm).join(',') === 'shop-form',
            'the shop checkout form carries ONLY the shop-form marker — no theme module touched it');
          assert(shopCartForm.getAttribute('data-shop-bound') === '1'
            && shopPlaceForm.getAttribute('data-shop-bound') === '1',
            'shop.js stamped its inner idempotency marker on its own forms only');

          assert(box.getAttribute('role') === 'status'
            && box.getAttribute('aria-live') === 'polite',
            'the runtime wired the thallo result box as a live region (enhance ran)');
          assert(calls.length === 0, 'neither script fetched anything at load time');

          // -- a second core pass is inert: markers gate BOTH worlds --------------
          global.ThalloRuntime.enhance(doc.documentElement);
          assert(thalloForm._listeners.submit.length === 1
            && shopCartForm._listeners.submit.length === 1
            && shopCartForm._listeners.submit[0] === cartHandler
            && shopPlaceForm._listeners.submit.length === 1
            && shopPlaceForm._listeners.submit[0] === placeHandler,
            're-running enhance() re-bound nothing (markers gate both worlds)');
          assert(calls.length === 0, 'the second enhance() pass fetched nothing');

          // -- thallo form submit: intercepted by the RUNTIME only ----------------
          var evtA = fireSubmit(thalloForm);
          assert(evtA.defaultPrevented === true, 'runtime intercepted the thallo submit');
          assert(calls.length === 1 && calls[0].url === '/_forms/submit',
            'thallo submit produced exactly one fetch, to /_forms/submit');
          assert(calls[0].opts.method === 'POST', 'thallo fetch is a POST');
          assert(calls[0].opts.headers.Accept === 'application/json',
            'thallo fetch negotiates JSON');
          assert(calls[0].opts.headers['X-Idempotency-Key'] === undefined,
            'no shop idempotency header on the thallo fetch (shop.js did not make it)');
          assert(calls[0].opts.credentials === undefined,
            'no credentials option on the thallo fetch (shop.js always sets one)');
          assert(thalloForm.getAttribute('aria-busy') === 'true',
            'aria-busy=true on the thallo form during the mocked fetch');
          assert(thalloBtn.disabled === true, 'thallo submit button disabled during fetch');
          assert(cartBtn.disabled === false && placeBtn.disabled === false,
            'shop form controls untouched while the thallo fetch is pending');

          await flush();
          assert(thalloForm.getAttribute('aria-busy') === null,
            'aria-busy removed after the thallo fetch settles');
          assert(thalloBtn.disabled === false, 'thallo submit button re-enabled');
          assert(resetCalls === 1, 'thallo form reset on success');
          assert(box.textContent === 'Thanks!', 'thallo result box shows the server message');
          assert(box.classList.contains('thallo-block-form__result--ok'),
            'ok modifier applied to the thallo result box');
          assert(calls.length === 1, 'nothing issued further fetches for the thallo submit');

          // -- shop cart submit: intercepted by SHOP.JS's handler (core-bound) ----
          var evtB = fireSubmit(shopCartForm);
          assert(evtB.defaultPrevented === true, 'shop.js intercepted the cart submit');
          assert(calls.length === 2 && calls[1].url === '/_shop/cart/update',
            'cart submit produced exactly one fetch, to the cart endpoint');
          assert(calls[1].opts.method === 'POST' && calls[1].opts.credentials === 'same-origin',
            'cart fetch is a same-origin POST (shop.js made it, not the runtime)');
          assert(cartBtn.disabled === true, 'cart controls disabled while pending');
          assert(thalloForm.getAttribute('aria-busy') === null,
            'the shop submit never touches the thallo form busy state');

          await flush();
          assert(cartBtn.disabled === false, 'cart controls re-enabled after settle');
          var status = doc.getElementById('thallo-shop-status');
          assert(status !== null && status.textContent.indexOf('Cart updated') !== -1,
            'shop.js announced through its own status region');
          assert(status._focusCount >= 1, 'focus moved to the shop status region');
          assert(box.textContent === 'Thanks!',
            'the thallo result box is untouched by the shop submit');

          // -- shop checkout submit: shop.js carries the idempotency key ----------
          var evtC = fireSubmit(shopPlaceForm);
          assert(evtC.defaultPrevented === true, 'shop.js intercepted the checkout submit');
          assert(calls.length === 3 && calls[2].url === '/_shop/checkout/place',
            'checkout submit produced exactly one fetch, to the place endpoint');
          assert(calls[2].opts.headers['X-Idempotency-Key'] === 'idem-key-coexist-1',
            'the checkout fetch carries the idempotency key from its hidden field');

          await flush();
          assert(calls.length === 3, 'no further fetches after all three submits settled');

          console.log('ALL_PASS');
        })().catch(function (e) {
          console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e));
          process.exit(1);
        });
        JS;
    }
}
