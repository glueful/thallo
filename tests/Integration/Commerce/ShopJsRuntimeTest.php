<?php

declare(strict_types=1);

namespace App\Tests\Integration\Commerce;

use App\Tests\Support\AppTestCase;

/**
 * Executable coverage for `shop.js` (storefront-rendering spec §10 / task-11 brief). Mirrors
 * {@see \App\Tests\Integration\Render\ColorModeRuntimeTest}'s Node + hand-stubbed-DOM pattern —
 * no jsdom/vitest harness exists in this repo — but the DOM stub here is a real (if minimal)
 * element/document tree with a tiny attribute-selector matcher, since shop.js discovers its
 * forms/regions via `querySelector(All)`, not via parameters passed in.
 *
 * Loads the file BYTE-IDENTICAL to what {@see \Thallo\Commerce\Http\Shop\ShopAssetController}
 * serves (there is no build step — the served file IS this source file) and PROVES, by actually
 * dispatching synthetic `submit` events and a mocked `window.fetch`, every behavioral guarantee
 * in the brief: one JSON POST per interception, live count/line-total/quote-region updates,
 * focus + aria-live moving to the shared status region, double-submit suppression, and — the
 * hard rule — an ambiguous (rejected) fetch never retries automatically and never falls back to
 * a native form submission; only an explicit retry (clicking the injected retry control) issues
 * a second POST, and it carries the SAME `X-Idempotency-Key` the first attempt did. Skips (does
 * not fail) when node is unavailable, but still asserts a structural marker so the contract is
 * checked even then.
 */
final class ShopJsRuntimeTest extends AppTestCase
{
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

    public function testFormInterceptionJsonUpdatesFocusAriaLiveDoubleSubmitAndAmbiguousRetry(): void
    {
        $src = $this->shopJs();
        // One real assertion even without node — the public surface the harness (and any future
        // consumer) hooks into must exist.
        self::assertStringContainsString('window.thalloShop', $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $file = sys_get_temp_dir() . '/thallo_shop_js_runtime_' . getmypid() . '.mjs';
        file_put_contents($file, $this->harness($src));
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

    /** Build a self-checking node harness around the real shop.js source. */
    private function harness(string $shopJsSrc): string
    {
        $src = json_encode($shopJsSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        'use strict';

        function fail(msg) { console.error('FAIL: ' + msg); process.exit(1); }
        function assert(cond, msg) { if (!cond) { fail(msg); } }

        // ------------------------------------------------------------------
        // A minimal but real DOM: elements support attributes, children,
        // event listeners, and a small attribute/tag selector matcher — enough
        // for shop.js's own querySelector(All) calls to actually find things.
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
          var m = compound.match(/^([a-zA-Z0-9_-]*)((?:\\[[^\\]]+\\])*)\$/);
          if (!m) { return false; }
          var tag = m[1];
          var attrsPart = m[2];
          if (tag && el.tagName !== tag) { return false; }
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
          this.body = new Element('body');
          this.readyState = 'complete';
          this._listeners = {};
        }
        Doc.prototype.getElementById = function (id) { return findById(this.body, id); };
        Doc.prototype.createElement = function (tag) { return new Element(tag); };
        Doc.prototype.addEventListener = function (type, fn) {
          (this._listeners[type] = this._listeners[type] || []).push(fn);
        };
        Doc.prototype.querySelectorAll = function (sel) { return collect(this.body, sel, []); };
        Doc.prototype.querySelector = function (sel) { return collect(this.body, sel, [])[0] || null; };

        function el(tag, attrs, children) {
          var e = new Element(tag);
          attrs = attrs || {};
          for (var k in attrs) {
            if (Object.prototype.hasOwnProperty.call(attrs, k)) {
              e.attrs[k] = String(attrs[k]);
              // `value` is a live IDL property distinct from the attribute in a real DOM
              // (which is exactly what shop.js reads/writes) — mirror it for a freshly built
              // stub element, same as the initial attribute->property reflection a browser
              // does before any user interaction.
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

        // ------------------------------------------------------------------
        // A controllable fetch mock: each call pops the next queued behavior.
        // ------------------------------------------------------------------

        function makeFetch(queue, calls) {
          return function (url, opts) {
            calls.push({ url: url, opts: opts });
            var behavior = queue.shift();
            if (!behavior) { return Promise.reject(new Error('no fetch behavior queued for ' + url)); }
            if (behavior.reject) { return Promise.reject(behavior.error || new Error('network error')); }
            return Promise.resolve({
              ok: behavior.ok,
              status: behavior.status,
              json: function () { return Promise.resolve(behavior.data); },
            });
          };
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

        function loadShopJs(win, doc) {
          var src = $src;
          var fn = new Function('window', 'document', src);
          fn(win, doc);
        }

        // ------------------------------------------------------------------
        // Scenario 1: a cart-mutation form — one JSON POST, live region updates,
        // focus + aria-live, double-submit suppression.
        // ------------------------------------------------------------------

        (async function scenario1() {
          var doc = new Doc();
          var qty = el('input', { type: 'number', name: 'quantity', value: '2' });
          var variant = el('input', { type: 'hidden', name: 'variant_uuid', value: 'var-1' });
          var button = el('button', { type: 'submit' });
          var form = el('form', { action: '/_shop/cart/update' }, [variant, qty, button]);
          doc.body.appendChild(form);

          var countEl = el('span', { 'data-shop-cart-count': '' });
          doc.body.appendChild(countEl);
          var emptyEl = el('p', { 'data-shop-cart-empty': '' });
          var linesEl = el('ul', { 'data-shop-cart-lines': '' });
          var totalEl = el('p', { 'data-shop-cart-total': '' });
          var linkEl = el('a', { 'data-shop-cart-link': '', href: '/cart' });
          var drawer = el('div', { 'data-shop-cart-drawer': '' }, [emptyEl, linesEl, totalEl, linkEl]);
          doc.body.appendChild(drawer);

          var calls = [];
          var cartLine = {
            variant_uuid: 'var-1',
            product_name: 'Widget',
            quantity: 2,
            line_total_formatted: '20.00',
            currency: 'USD',
          };
          var queue = [{
            ok: true, status: 200, data: {
              items: [cartLine],
              item_count: 2,
              grand_total_formatted: '20.00',
              currency: 'USD',
              cart_url: '/cart',
              checkout_url: '/checkout',
            },
          }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          loadShopJs(win, doc);

          var evt = fireSubmit(form);
          assert(evt.defaultPrevented === true, 'scenario1: interception must preventDefault');
          assert(calls.length === 1, 'scenario1: exactly one fetch call issued synchronously');
          assert(calls[0].url === '/_shop/cart/update', 'scenario1: posted to the form action');
          assert(calls[0].opts.method === 'POST', 'scenario1: POST method');
          assert(calls[0].opts.headers.Accept === 'application/json', 'scenario1: Accept: application/json header');

          // A second submit WHILE pending must not add a second fetch call.
          fireSubmit(form);
          assert(calls.length === 1, 'scenario1: a second submit while pending is suppressed, not queued');

          await flush();

          assert(calls.length === 1, 'scenario1: success settles without any further automatic fetch');
          assert(countEl.textContent === '2', 'scenario1: mini-cart count region updated from the JSON response');
          assert(linesEl.hidden === false, 'scenario1: lines region revealed');
          assert(linesEl.children.length === 1, 'scenario1: one line rendered');
          assert(totalEl.textContent.indexOf('20.00') !== -1, 'scenario1: grand total region updated');

          var status = doc.getElementById('thallo-shop-status');
          assert(status !== null, 'scenario1: the shared status region was created');
          assert(status.textContent.length > 0, 'scenario1: an aria-live announcement was made');
          assert(status._focusCount >= 1, 'scenario1: focus moved to the status region');

          console.log('scenario1 OK');
        })()

        // ------------------------------------------------------------------
        // Scenario 2: checkout quote — updates quote regions.
        // ------------------------------------------------------------------

        .then(async function scenario2() {
          var doc = new Doc();
          var form = el('form', { action: '/_shop/checkout/quote' }, [el('button', { type: 'submit' })]);
          doc.body.appendChild(form);

          var subtotalEl = el('span', { 'data-shop-quote-subtotal': '' });
          var shippingEl = el('span', { 'data-shop-quote-shipping': '' });
          var taxEl = el('span', { 'data-shop-quote-tax': '' });
          var totalEl = el('span', { 'data-shop-quote-total': '' });
          doc.body.appendChild(el('div', {}, [subtotalEl, shippingEl, taxEl, totalEl]));

          var calls = [];
          var totals = { subtotal: 1000, shipping_total: 500, tax_total: 80, grand_total: 1580 };
          var queue = [{ ok: true, status: 200, data: { totals: totals, shipping_options: [] } }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          loadShopJs(win, doc);
          fireSubmit(form);
          await flush();

          assert(calls.length === 1, 'scenario2: exactly one POST for the quote form');
          assert(subtotalEl.textContent === '1000', 'scenario2: subtotal region updated');
          assert(shippingEl.textContent === '500', 'scenario2: shipping region updated');
          assert(taxEl.textContent === '80', 'scenario2: tax region updated');
          assert(totalEl.textContent === '1580', 'scenario2: grand total region updated');

          console.log('scenario2 OK');
        })

        // ------------------------------------------------------------------
        // Scenario 3: an ambiguous rejected fetch — exactly one POST, no native
        // fallback, a retry control appears; explicit retry is the ONLY second
        // POST and preserves the checkout idempotency key.
        // ------------------------------------------------------------------

        .then(async function scenario3() {
          var doc = new Doc();
          var key = el('input', { type: 'hidden', name: 'idempotency_key', value: 'idem-key-abc123' });
          var form = el('form', { action: '/_shop/checkout/place' }, [key, el('button', { type: 'submit' })]);
          doc.body.appendChild(form);

          var calls = [];
          var queue = [{ reject: true, error: new Error('simulated network failure') }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          loadShopJs(win, doc);

          var evt = fireSubmit(form);
          assert(evt.defaultPrevented === true, 'scenario3: interception engaged before the failure');
          await flush();

          assert(calls.length === 1, 'scenario3: exactly one POST — no automatic retry after ambiguity');
          assert(
            calls[0].opts.headers['X-Idempotency-Key'] === 'idem-key-abc123',
            'scenario3: first attempt carries the key',
          );
          assert(key.value === 'idem-key-abc123', 'scenario3: the idempotency key field is untouched');

          var retry = form.parentNode.querySelector('[data-shop-retry]');
          assert(retry !== null, 'scenario3: an explicit retry control was injected');
          assert(retry.hidden === false, 'scenario3: the retry control is visible');
          assert(typeof retry.onclick === 'function', 'scenario3: the retry control is wired to resubmit');

          var status = doc.getElementById('thallo-shop-status');
          assert(status.textContent.length > 0, 'scenario3: an aria-live announcement was made for the failure');
          assert(status._focusCount >= 1, 'scenario3: focus moved to the status/error region');

          // shop.js itself never re-fires the submit — the browser is the only thing that
          // could ever re-dispatch 'submit' on this form, and defaultPrevented was already set
          // on the FIRST attempt (asserted above), so no native/automatic second POST can occur
          // through this event at all. Confirm the count is still exactly one before the
          // EXPLICIT retry below is exercised.
          assert(calls.length === 1, 'scenario3: still exactly one POST before any explicit retry');

          // Explicit retry: queue a success this time, then "click" the retry control.
          queue.push({ ok: true, status: 200, data: { action: 'manual' } });
          retry.onclick();
          await flush();

          assert(calls.length === 2, 'scenario3: the explicit retry is the ONLY second POST');
          assert(calls[1].url === '/_shop/checkout/place', 'scenario3: retry resubmits the same endpoint');
          assert(
            calls[1].opts.headers['X-Idempotency-Key'] === 'idem-key-abc123',
            'scenario3: retry preserves the SAME idempotency key',
          );

          console.log('scenario3 OK');
        })

        // ------------------------------------------------------------------
        // Scenario 4: a checkout redirect action navigates top-level.
        // ------------------------------------------------------------------

        .then(async function scenario4() {
          var doc = new Doc();
          var form = el('form', { action: '/_shop/checkout/place' }, [el('button', { type: 'submit' })]);
          doc.body.appendChild(form);

          var calls = [];
          var redirectUrl = 'https://pay.example.test/session/abc';
          var queue = [{ ok: true, status: 200, data: { action: 'redirect', redirect_url: redirectUrl } }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          loadShopJs(win, doc);
          fireSubmit(form);
          await flush();

          assert(win.location.href === redirectUrl, 'scenario4: redirect action navigates top-level');

          console.log('scenario4 OK');
        })

        // ------------------------------------------------------------------
        // Scenario 5: a validation error (422) announces the message but never
        // retries or falls back — and never shows the ambiguous-failure retry UI
        // (that is reserved for network-level ambiguity, not a clean 4xx).
        // ------------------------------------------------------------------

        .then(async function scenario5() {
          var doc = new Doc();
          var form = el('form', { action: '/_shop/cart/add' }, [el('button', { type: 'submit' })]);
          doc.body.appendChild(form);

          var calls = [];
          var fieldErrors = { errors: { quantity: ['Quantity must be greater than zero.'] } };
          var queue = [{ ok: false, status: 422, data: fieldErrors }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          loadShopJs(win, doc);
          fireSubmit(form);
          await flush();

          assert(calls.length === 1, 'scenario5: exactly one POST for a validation error');
          var status = doc.getElementById('thallo-shop-status');
          assert(
            status.textContent.indexOf('Quantity must be greater than zero.') !== -1,
            'scenario5: the field error is announced',
          );
          assert(
            form.parentNode.querySelector('[data-shop-retry]') === null,
            'scenario5: no retry UI for a clean validation error',
          );

          console.log('scenario5 OK');
        })

        // ------------------------------------------------------------------
        // Scenario 6: interception fails BEFORE any request is sent (fetch
        // missing) — the ONLY situation where native submission is left to
        // happen (defaultPrevented stays false), and no fetch is ever attempted.
        // ------------------------------------------------------------------

        .then(async function scenario6() {
          var doc = new Doc();
          var form = el('form', { action: '/_shop/cart/add' }, [el('button', { type: 'submit' })]);
          doc.body.appendChild(form);

          var calls = [];
          var win = { document: doc, location: { href: '' } }; // no fetch, no FormData

          loadShopJs(win, doc);
          var evt = fireSubmit(form);

          assert(evt.defaultPrevented === false, 'scenario6: native submission proceeds when interception fails');
          assert(calls.length === 0, 'scenario6: no request is ever attempted in this case');

          console.log('scenario6 OK');
        })

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }
}
