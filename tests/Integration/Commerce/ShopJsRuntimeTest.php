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
 *
 * The full byte contract runs in BOTH delivery configurations (shopjs-on-runtime spec §3):
 * standalone (runtime-absent fallback, shop.js self-drives) and with the theme-runtime core
 * evaluated first (shop.js registers nine `shop-*` modules and the core's boot drives
 * enhancement) — see {@see runByteContract()}. The runtime-specific tests below cover the
 * registration surface, exactly-once re-execution, init() delegation, per-component
 * containment, and the canvas-stage guarantee.
 */
final class ShopJsRuntimeTest extends AppTestCase
{
    private function shopJs(): string
    {
        return (string) file_get_contents(
            $this->appContext()->getBasePath() . '/packages/thallo-commerce/assets/shop.js'
        );
    }

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

    public function testFormInterceptionJsonUpdatesFocusAriaLiveDoubleSubmitAndAmbiguousRetryStandalone(): void
    {
        $this->runByteContract(null);
    }

    public function testFormInterceptionJsonUpdatesFocusAriaLiveDoubleSubmitAndAmbiguousRetryWithRuntimePresent(): void
    {
        $this->runByteContract($this->runtimeJs());
    }

    /**
     * The parity gate (shopjs-on-runtime spec §3): EVERY assertion of the original byte
     * contract, verbatim, against the given driver — `null` runs shop.js standalone (the
     * runtime-absent fallback), a runtime source evaluates the theme-runtime core FIRST so
     * the core's registration + deferred boot drives enhancement instead.
     */
    private function runByteContract(?string $runtimeSrc): void
    {
        $src = $this->shopJs();
        // One real assertion even without node — the public surface the harness (and any future
        // consumer) hooks into must exist.
        self::assertStringContainsString('window.thalloShop', $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $suffix = $runtimeSrc === null ? 'contract_standalone' : 'contract_runtime';
        $this->runNodeHarness($node, $this->harness($src, $runtimeSrc), $suffix);
    }

    /**
     * Shared node-harness prelude: the minimal DOM stub (elements, attribute/class-selector
     * matcher, document with a documentElement scan root), the recording fetch mock, the
     * FormData fake, and the loaders that evaluate the real shop.js — and, when the dual
     * configuration asks for it, the real theme-runtime core FIRST — against them. Every
     * runtime test in this file exercises the exact same stubs.
     */
    private function harnessPrelude(string $shopJsSrc, ?string $runtimeSrc = null): string
    {
        $src = json_encode($shopJsSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $runtime = $runtimeSrc === null
            ? 'null'
            : json_encode($runtimeSrc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

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
          // tag, .class chains, and [attr]/[attr="value"] parts — class support exists for
          // the runtime core's own '.thallo-preview-block' canvas probe.
          var m = compound.match(/^([a-zA-Z0-9_-]*)((?:\\.[a-zA-Z0-9_-]+)*)((?:\\[[^\\]]+\\])*)\$/);
          if (!m) { return false; }
          var tag = m[1];
          var classPart = m[2];
          var attrsPart = m[3];
          if (tag && el.tagName !== tag) { return false; }
          if (classPart) {
            var wanted = classPart.split('.').filter(Boolean);
            var have = String(el.className || '').split(/\\s+/);
            for (var c = 0; c < wanted.length; c++) {
              if (have.indexOf(wanted[c]) === -1) { return false; }
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
          // documentElement wraps body so the runtime core's boot pass —
          // enhance(document.documentElement) — scans the same tree the tests build. Its
          // dataset keeps the color-mode module's hard gate closed (colorModeEnabled unset).
          this.documentElement = new Element('html');
          this.documentElement.dataset = {};
          this.body = new Element('body');
          this.documentElement.appendChild(this.body);
          this.readyState = 'complete';
          this._listeners = {};
        }
        Doc.prototype.getElementById = function (id) { return findById(this.body, id); };
        Doc.prototype.createElement = function (tag) { return new Element(tag); };
        Doc.prototype.addEventListener = function (type, fn) {
          (this._listeners[type] = this._listeners[type] || []).push(fn);
        };
        // The wishlist store broadcasts its state change as a document CustomEvent
        // (storefront-v1 spec §5) — dispatch delivers it to the listeners above.
        Doc.prototype.dispatchEvent = function (event) {
          var listeners = this._listeners[event && event.type] || [];
          for (var i = 0; i < listeners.length; i++) { listeners[i](event); }
          return true;
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

        // null in the standalone configuration; the real theme-runtime core source in the
        // runtime-present configuration.
        var runtimeSrc = $runtime;

        function loadRuntime(win, doc, consoleStub) {
          // 'console' is a parameter so the core's containment log
          // (window.console && console.error) hits the stub a test passes in.
          var fn = new Function('window', 'document', 'console', runtimeSrc);
          fn(win, doc, consoleStub || console);
        }

        // Loads the page the way a served storefront does: theme-runtime core first (when
        // this configuration ships one), then shop.js, then a microtask flush so the core's
        // deferred boot — Promise.resolve().then(boot) when readyState is not 'loading' —
        // has enhanced everything before any assertion runs.
        async function loadPage(win, doc, consoleStub) {
          if (runtimeSrc) { loadRuntime(win, doc, consoleStub); }
          loadShopJs(win, doc);
          await flush();
        }
        JS;
    }

    /**
     * Build the self-checking byte-contract node harness around the real shop.js source.
     * With a runtime source, every scenario evaluates the theme-runtime core before shop.js
     * (via loadPage) and the core's boot drives enhancement; the scenario ASSERTIONS are
     * identical in both configurations.
     */
    private function harness(string $shopJsSrc, ?string $runtimeSrc = null): string
    {
        return $this->harnessPrelude($shopJsSrc, $runtimeSrc) . "\n\n" . <<<JS
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

          await loadPage(win, doc);

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

          await loadPage(win, doc);
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

          await loadPage(win, doc);

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

          await loadPage(win, doc);
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

          await loadPage(win, doc);
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

          await loadPage(win, doc);
          var evt = fireSubmit(form);

          assert(evt.defaultPrevented === false, 'scenario6: native submission proceeds when interception fails');
          assert(calls.length === 0, 'scenario6: no request is ever attempted in this case');

          console.log('scenario6 OK');
        })

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    public function testTwoMiniCartShellsCoalesceToOneCartFetchAndBothRegionsPaint(): void
    {
        $src = $this->shopJs();
        // One real assertion even without node — the surface the harness hooks into must exist.
        self::assertStringContainsString('window.thalloShop', $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness($node, $this->coalescingHarness($src), 'coalesce');
    }

    public function testSecondEvaluationOfShopJsIsBehaviorallyInert(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString('window.thalloShop', $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness($node, $this->secondEvalHarness($src), 'second_eval');

        // Supplementary structural check only — the red/green authority is the behavioral
        // harness above (same export object, unchanged listener count, one fetch across evals).
        self::assertStringContainsString('/* shop-runtime:start */', $src);
    }

    public function testMiniCartDrawerToggleBindsOnceAndDrivesAriaExpanded(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString('window.thalloShop', $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness($node, $this->drawerToggleHarness($src), 'drawer_toggle');
    }

    public function testProductBuyStepperBoundsAndExponentAwarePriceLabel(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString("register('shop-buy'", $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness($node, $this->buyStepperHarness($src), 'buy_stepper');
    }

    /** Write a harness to a temp file, run it under node, and assert it prints ALL_PASS. */
    private function runNodeHarness(string $node, string $harnessJs, string $suffix): void
    {
        $file = sys_get_temp_dir() . '/thallo_shop_js_' . $suffix . '_' . getmypid() . '.mjs';
        file_put_contents($file, $harnessJs);
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

    /**
     * Harness for the coalesced mini-cart hydration (shopjs-on-runtime spec §2.2): two
     * shells trigger ONE GET /_shop/cart and ONE document-wide paint; a re-init while the
     * fetch is in flight joins it; once settled, a re-init fetches fresh state.
     */
    private function coalescingHarness(string $shopJsSrc): string
    {
        return $this->harnessPrelude($shopJsSrc) . "\n\n" . <<<JS
        // ------------------------------------------------------------------
        // TWO mini-cart shells (each with its own count region) plus a header
        // count badge OUTSIDE any shell — cart regions are document-wide.
        // ------------------------------------------------------------------

        (async function coalescing() {
          var doc = new Doc(); // readyState 'complete' — init() runs at eval time

          var count1 = el('span', { 'data-shop-cart-count': '' });
          doc.body.appendChild(el('div', { 'data-shop-mini-cart': '' }, [count1]));
          var count2 = el('span', { 'data-shop-cart-count': '' });
          doc.body.appendChild(el('div', { 'data-shop-mini-cart': '' }, [count2]));
          var headerCount = el('span', { 'data-shop-cart-count': '' });
          doc.body.appendChild(headerCount);

          var calls = [];
          var queue = [{ ok: true, status: 200, data: { item_count: 3, items: [] } }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          loadShopJs(win, doc);

          assert(calls.length === 1, 'coalesce: exactly one GET /_shop/cart despite two shells');
          assert(calls[0].url === '/_shop/cart', 'coalesce: the single hydration fetch targets /_shop/cart');

          // A re-init WHILE the first fetch is still in flight (another shell enhancing
          // concurrently) must join the pending fetch, not start a second request.
          win.thalloShop.init();
          assert(calls.length === 1, 'coalesce: a re-init while in flight joins the pending fetch');

          await flush();

          assert(count1.textContent === '3', 'coalesce: first shell count region painted');
          assert(count1.hidden === false, 'coalesce: a non-empty cart reveals the count badge');
          assert(count2.textContent === '3', 'coalesce: second shell count region painted');
          assert(headerCount.textContent === '3', 'coalesce: header count outside any shell painted');

          // The slot clears on settle: a later enhance (e.g. a freshly inserted shell
          // re-running init()) fetches FRESH cart state, matching today's semantics.
          calls.length = 0;
          queue.push({ ok: true, status: 200, data: { item_count: 5, items: [] } });
          win.thalloShop.init();
          assert(calls.length === 1, 'coalesce: after settle a re-init issues a fresh cart fetch');
          await flush();
          assert(headerCount.textContent === '5', 'coalesce: the settled-slot refetch painted fresh state');

          console.log('coalescing OK');
        })()

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    /**
     * Harness for the mini-cart drawer disclosure (mini-cart-in-the-chrome, 2026-07-27):
     * the toggle gains exactly ONE click listener (inner `data-shop-cart-toggle-bound`
     * marker), clicks flip aria-expanded (shop.css keys the panel's visibility off it),
     * Escape closes an open drawer, and re-running the sweep never stacks listeners.
     */
    private function drawerToggleHarness(string $shopJsSrc): string
    {
        return $this->harnessPrelude($shopJsSrc) . "\n\n" . <<<JS
        (async function drawerToggle() {
          var doc = new Doc(); // readyState 'complete' — init() runs at eval time

          var badge = el('span', { 'data-shop-cart-count': '' });
          badge.hidden = true; // template ships the badge hidden (zero is noise)
          var toggle = el('button', { 'data-shop-cart-toggle': '', 'aria-expanded': 'false' }, [badge]);
          var panel = el('div', { 'data-shop-cart-drawer': '' });
          panel.hidden = true; // the template ships the drawer hidden (shop.css makes it authoritative)
          var shell = el('div', { 'data-shop-mini-cart': '' }, [toggle, panel]);
          doc.body.appendChild(shell);

          var calls = [];
          var queue = [{ ok: true, status: 200, data: { item_count: 0, items: [] } }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          loadShopJs(win, doc);
          await flush();

          assert(badge.hidden === true, 'drawer: an empty cart keeps the count badge hidden after paint');
          assert(toggle.getAttribute('data-shop-cart-toggle-bound') === '1',
            'drawer: the toggle carries the inner bound marker after enhancement');
          var clicks = toggle._listeners['click'] || [];
          assert(clicks.length === 1, 'drawer: exactly one click listener after first enhance');

          clicks[0]({});
          assert(toggle.getAttribute('aria-expanded') === 'true', 'drawer: a click opens (aria-expanded true)');
          assert(panel.hidden === false, 'drawer: opening CLEARS the panel hidden attribute');
          clicks[0]({});
          assert(toggle.getAttribute('aria-expanded') === 'false', 'drawer: a second click closes');
          assert(panel.hidden === true, 'drawer: closing restores the panel hidden attribute');

          clicks[0]({});
          assert(toggle.getAttribute('aria-expanded') === 'true', 'drawer: reopened for the Escape case');
          var keydowns = shell._listeners['keydown'] || [];
          assert(keydowns.length === 1, 'drawer: exactly one Escape handler on the shell');
          keydowns[0]({ key: 'Escape' });
          assert(toggle.getAttribute('aria-expanded') === 'false', 'drawer: Escape closes the open drawer');

          // Re-running the sweep (fresh blocks inserted elsewhere → init()) must not
          // stack listeners — the inner marker is the guard, same layer as bindForm's.
          win.thalloShop.init();
          assert((toggle._listeners['click'] || []).length === 1,
            'drawer: re-init does not stack toggle listeners');
          assert((shell._listeners['keydown'] || []).length === 1,
            'drawer: re-init does not stack Escape handlers');

          console.log('drawer toggle OK');
        })()

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    /**
     * Harness for the product buy area (storefront-v1 Task 6): the stepper clamps the REAL
     * quantity input to 1–99, and the [data-shop-buy-price] label recomputes qty × minor via
     * CHECKED integer math + Intl.NumberFormat with the exponent the form carries — while a
     * malformed/absent exponent or an unsafe product leaves the server-rendered label
     * untouched, and a variant switch re-reads the SELECTED option's data-price-minor.
     */
    private function buyStepperHarness(string $shopJsSrc): string
    {
        return $this->harnessPrelude($shopJsSrc) . "\n\n" . <<<JS
        function buyFixture(doc, formAttrs, priceText, selectChildren) {
          var minus = el('button', { type: 'button', 'data-shop-qty-minus': '' });
          var plus = el('button', { type: 'button', 'data-shop-qty-plus': '' });
          var qty = el('input', {
            type: 'number', name: 'quantity', min: '1', max: '99', step: '1', value: '1',
          });
          var price = el('span', { 'data-shop-buy-price': '' });
          price.textContent = priceText;
          var submit = el('button', { type: 'submit' }, [price]);
          var children = [];
          var select = null;
          if (selectChildren) {
            select = el('select', { name: 'variant_uuid' }, selectChildren);
            children.push(select);
          }
          children.push(minus, qty, plus, submit);
          formAttrs.action = '/_shop/cart/add';
          formAttrs['data-shop-buy'] = '';
          var form = el('form', formAttrs, children);
          doc.body.appendChild(form);
          return { form: form, minus: minus, plus: plus, qty: qty, price: price, select: select };
        }

        function newWin(doc) {
          return {
            document: doc, location: { href: '' }, fetch: makeFetch([], []), FormData: FakeFormData,
          };
        }

        // ------------------------------------------------------------------
        // Scenario 1: stepper bounds + USD label math (exponent 2).
        // ------------------------------------------------------------------

        (async function stepperBoundsAndUsd() {
          var doc = new Doc();
          doc.documentElement.lang = 'en-US'; // deterministic Intl locale for the assertions
          var fx = buyFixture(doc, {
            'data-currency': 'USD', 'data-currency-exponent': '2', 'data-price-minor': '70000',
          }, '$700.00', null);
          var win = newWin(doc);

          loadShopJs(win, doc);
          await flush();

          assert(fx.form.getAttribute('data-shop-buy-bound') === '1',
            'buy: the form carries the inner bound marker after enhancement');
          var minusClicks = fx.minus._listeners['click'] || [];
          var plusClicks = fx.plus._listeners['click'] || [];
          assert(minusClicks.length === 1 && plusClicks.length === 1,
            'buy: each stepper button bound exactly once');

          minusClicks[0]({});
          assert(fx.qty.value === '1', 'buy: minus at 1 stays 1');

          plusClicks[0]({});
          plusClicks[0]({});
          assert(fx.qty.value === '3', 'buy: plus stepped 1 -> 3');
          assert(fx.price.textContent.indexOf('2,100') !== -1,
            'buy: USD label recomputed (70000 x 3 -> contains 2,100), got: ' + fx.price.textContent);

          fx.qty.value = '98';
          plusClicks[0]({});
          plusClicks[0]({});
          assert(fx.qty.value === '99', 'buy: plus caps at 99');

          // Re-running the sweep must not stack listeners (inner marker, bindForm's layer).
          win.thalloShop.init();
          assert((fx.minus._listeners['click'] || []).length === 1
            && (fx.plus._listeners['click'] || []).length === 1,
            'buy: re-init does not stack stepper listeners');

          console.log('scenario stepper+USD OK');
        })()

        // ------------------------------------------------------------------
        // Scenario 2: JPY (exponent 0) — grouping, no decimals.
        // ------------------------------------------------------------------

        .then(async function jpyLabel() {
          var doc = new Doc();
          doc.documentElement.lang = 'en-US';
          var fx = buyFixture(doc, {
            'data-currency': 'JPY', 'data-currency-exponent': '0', 'data-price-minor': '500',
          }, 'JPY-SERVER-LABEL', null);
          var win = newWin(doc);

          loadShopJs(win, doc);
          await flush();

          (fx.plus._listeners['click'] || [])[0]({});
          assert(fx.qty.value === '2', 'jpy: plus stepped 1 -> 2');
          assert(fx.price.textContent.indexOf('1,000') !== -1,
            'jpy: label contains 1,000 (500 x 2, exponent 0), got: ' + fx.price.textContent);
          assert(fx.price.textContent.indexOf('.') === -1,
            'jpy: a zero-exponent currency renders no decimals, got: ' + fx.price.textContent);

          console.log('scenario JPY OK');
        })

        // ------------------------------------------------------------------
        // Scenario 3: KWD (exponent 3) — three decimals.
        // ------------------------------------------------------------------

        .then(async function kwdLabel() {
          var doc = new Doc();
          doc.documentElement.lang = 'en-US';
          var fx = buyFixture(doc, {
            'data-currency': 'KWD', 'data-currency-exponent': '3', 'data-price-minor': '1250',
          }, 'KWD-SERVER-LABEL', null);
          var win = newWin(doc);

          loadShopJs(win, doc);
          await flush();

          (fx.plus._listeners['click'] || [])[0]({});
          assert(fx.price.textContent.indexOf('2.500') !== -1,
            'kwd: label contains 2.500 (1250 x 2, exponent 3), got: ' + fx.price.textContent);

          console.log('scenario KWD OK');
        })

        // ------------------------------------------------------------------
        // Scenario 4: guards — absent exponent, malformed exponent, unsafe
        // product: the stepper still steps, the label NEVER changes.
        // ------------------------------------------------------------------

        .then(async function labelGuards() {
          var doc = new Doc();
          doc.documentElement.lang = 'en-US';
          var absent = buyFixture(doc, {
            'data-currency': 'USD', 'data-price-minor': '70000',
          }, 'ABSENT-EXPONENT-LABEL', null);
          var malformed = buyFixture(doc, {
            'data-currency': 'USD', 'data-currency-exponent': 'banana', 'data-price-minor': '70000',
          }, 'MALFORMED-EXPONENT-LABEL', null);
          var unsafe = buyFixture(doc, {
            // MAX_SAFE_INTEGER: x2 leaves Number.isSafeInteger territory — never displayed.
            'data-currency': 'USD', 'data-currency-exponent': '2', 'data-price-minor': '9007199254740991',
          }, 'UNSAFE-PRODUCT-LABEL', null);
          var win = newWin(doc);

          loadShopJs(win, doc);
          await flush();

          (absent.plus._listeners['click'] || [])[0]({});
          assert(absent.qty.value === '2', 'guards: the stepper still steps without an exponent');
          assert(absent.price.textContent === 'ABSENT-EXPONENT-LABEL',
            'guards: an absent exponent leaves the server label untouched');

          (malformed.plus._listeners['click'] || [])[0]({});
          assert(malformed.price.textContent === 'MALFORMED-EXPONENT-LABEL',
            'guards: a malformed exponent leaves the server label untouched');

          (unsafe.plus._listeners['click'] || [])[0]({});
          assert(unsafe.price.textContent === 'UNSAFE-PRODUCT-LABEL',
            'guards: minor x qty past Number.MAX_SAFE_INTEGER leaves the server label untouched');

          console.log('scenario guards OK');
        })

        // ------------------------------------------------------------------
        // Scenario 5: a variant switch re-reads the SELECTED option's
        // data-price-minor and recomputes from the NEW minor price.
        // ------------------------------------------------------------------

        .then(async function variantSwitch() {
          var doc = new Doc();
          doc.documentElement.lang = 'en-US';
          var optA = el('option', { value: 'var-a', 'data-price-minor': '1000' });
          var optB = el('option', { value: 'var-b', 'data-price-minor': '2500' });
          var fx = buyFixture(doc, {
            'data-currency': 'USD', 'data-currency-exponent': '2',
          }, 'SELECT-SERVER-LABEL', [optA, optB]);
          fx.select.value = 'var-a';
          var win = newWin(doc);

          loadShopJs(win, doc);
          await flush();

          var changes = fx.select._listeners['change'] || [];
          assert(changes.length === 1, 'switch: the variant select bound exactly once');

          fx.select.value = 'var-b';
          changes[0]({});
          assert(fx.price.textContent.indexOf('25.00') !== -1,
            'switch: the label recomputed from the NEW option minor price (2500 -> 25.00), got: '
              + fx.price.textContent);

          fx.select.value = 'var-a';
          changes[0]({});
          assert(fx.price.textContent.indexOf('10.00') !== -1,
            'switch: switching back recomputes from var-a (1000 -> 10.00), got: ' + fx.price.textContent);

          console.log('scenario variant switch OK');
        })

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    /**
     * Harness proving a second evaluation of shop.js is behaviorally INERT (every shop
     * block template emits its own script tag, so the file WILL be evaluated repeatedly):
     * same export object, no extra DOMContentLoaded listener, init runs once.
     */
    private function secondEvalHarness(string $shopJsSrc): string
    {
        return $this->harnessPrelude($shopJsSrc) . "\n\n" . <<<JS
        // ------------------------------------------------------------------
        // Variant 1 (readyState 'loading'): the second evaluation keeps the SAME
        // export object and attaches NO extra DOMContentLoaded listener.
        // ------------------------------------------------------------------

        (async function secondEvalWhileLoading() {
          var doc = new Doc();
          doc.readyState = 'loading';
          var win = { document: doc, location: { href: '' } };

          loadShopJs(win, doc);
          assert(
            win.thalloShop && typeof win.thalloShop.init === 'function',
            'guard: first eval exposed window.thalloShop',
          );
          assert(
            (doc._listeners['DOMContentLoaded'] || []).length === 1,
            'guard: first eval attached exactly one DOMContentLoaded listener',
          );

          // The HARNESS tags the export object — shop.js itself never writes __tag.
          win.thalloShop.__tag = 'first';

          loadShopJs(win, doc); // second evaluation of the same source

          assert(
            win.thalloShop.__tag === 'first',
            'guard: second eval must keep the SAME export object (no replacement)',
          );
          assert(
            (doc._listeners['DOMContentLoaded'] || []).length === 1,
            'guard: second eval must not attach another DOMContentLoaded listener',
          );

          console.log('secondEvalWhileLoading OK');
        })()

        // ------------------------------------------------------------------
        // Variant 2 (readyState 'complete', mini-cart shell present): init ran
        // ONCE — exactly one cart fetch across BOTH evaluations.
        // ------------------------------------------------------------------

        .then(async function secondEvalAfterComplete() {
          var doc = new Doc(); // readyState 'complete'
          var count = el('span', { 'data-shop-cart-count': '' });
          doc.body.appendChild(el('div', { 'data-shop-mini-cart': '' }, [count]));

          var calls = [];
          var queue = [{ ok: true, status: 200, data: { item_count: 2, items: [] } }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          loadShopJs(win, doc);
          await flush(); // first hydration fully settles (any in-flight slot is clear)

          assert(calls.length === 1, 'guard: first eval hydrated the mini-cart once');
          assert(count.textContent === '2', 'guard: first eval painted the count region');

          loadShopJs(win, doc); // second eval AFTER settle — must not re-run init()
          await flush();

          assert(calls.length === 1, 'guard: exactly one cart fetch across both evaluations');

          console.log('secondEvalAfterComplete OK');
        })

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    public function testRuntimePresentRegistersNineModulesAndCoreDrivesEnhancement(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString("register('shop-form'", $src);
        self::assertStringContainsString("register('shop-add-to-cart'", $src);
        self::assertStringContainsString("register('shop-buy'", $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness(
            $node,
            $this->runtimeRegistrationHarness($this->shopJs(), $this->runtimeJs()),
            'runtime_registration'
        );
    }

    public function testSecondScriptExecutionIsANoOp(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString('/* shop-runtime:start */', $src);
        self::assertStringContainsString('/* shop-runtime:end */', $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness(
            $node,
            $this->runtimeSecondExecutionHarness($this->shopJs(), $this->runtimeJs()),
            'runtime_second_exec'
        );
    }

    public function testInitDelegatesToRuntimeEnhanceOnRuntimePages(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString('ThalloRuntime.enhance(document.documentElement)', $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness(
            $node,
            $this->runtimeInitDelegationHarness($this->shopJs(), $this->runtimeJs()),
            'runtime_init_delegate'
        );
    }

    public function testSameModuleSiblingContainment(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString("register('shop-product-grid'", $src);
        self::assertStringContainsString("register('shop-featured-product'", $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness(
            $node,
            $this->runtimeContainmentHarness($this->shopJs(), $this->runtimeJs()),
            'runtime_containment'
        );
    }

    public function testCanvasStageRunsNoShopBehavior(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString("register('shop-mini-cart'", $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness(
            $node,
            $this->runtimeCanvasHarness($this->shopJs(), $this->runtimeJs()),
            'runtime_canvas'
        );
    }

    public function testDeferDeliveryAsSeparateScriptTasksStillEnhancesShopModules(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString("register('shop-mini-cart'", $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness(
            $node,
            $this->runtimeSeparateTasksHarness($this->shopJs(), $this->runtimeJs()),
            'runtime_separate_tasks'
        );
    }

    /**
     * Harness proving that with the runtime present, shop.js registers its nine modules on
     * the core (probed via the duplicate-name throw), attaches NO DOMContentLoaded listener
     * of its own (a DELTA from the post-runtime-eval snapshot — the core registers its own
     * when readyState is 'loading', so an absolute zero would be wrong), and that firing
     * the core's boot is what enhances the shop shells.
     */
    private function runtimeRegistrationHarness(string $shopJsSrc, string $runtimeSrc): string
    {
        return $this->harnessPrelude($shopJsSrc, $runtimeSrc) . "\n\n" . <<<JS
        (async function registration() {
          var doc = new Doc();
          doc.readyState = 'loading'; // the CORE attaches its own DOMContentLoaded boot here

          var count = el('span', { 'data-shop-cart-count': '' });
          doc.body.appendChild(el('div', { 'data-shop-mini-cart': '' }, [count]));

          var calls = [];
          var queue = [{ ok: true, status: 200, data: { item_count: 4, items: [] } }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          loadRuntime(win, doc);
          var snapshot = (doc._listeners['DOMContentLoaded'] || []).length;
          assert(snapshot === 1, 'registration: the core attached its own DOMContentLoaded boot listener');

          loadShopJs(win, doc);

          assert(
            (doc._listeners['DOMContentLoaded'] || []).length === snapshot,
            'registration: shop.js attached NO DOMContentLoaded listener of its own (delta zero)'
          );
          assert(
            win.thalloShop && typeof win.thalloShop.init === 'function',
            'registration: the window.thalloShop export survives on runtime pages'
          );

          var names = ['shop-form', 'shop-gallery', 'shop-buy', 'shop-mini-cart',
            'shop-product-grid', 'shop-featured-product', 'shop-add-to-cart',
            'shop-wishlist', 'shop-wishlist-page'];
          for (var i = 0; i < names.length; i++) {
            var threw = false;
            try {
              win.ThalloRuntime.register(names[i], { selector: 'x', enhance: function () {} });
            } catch (e) {
              threw = true;
              assert(
                String(e && e.message).indexOf('already registered') !== -1,
                'registration: the duplicate probe for ' + names[i] + ' threw the duplicate-name error'
              );
            }
            assert(threw, 'registration: module ' + names[i] + ' is registered (duplicate probe throws)');
          }

          assert(calls.length === 0, 'registration: nothing hydrated before the core boot fired');

          // The core drives enhancement: firing its DOMContentLoaded boot enhances the shell.
          var boots = doc._listeners['DOMContentLoaded'] || [];
          for (var b = 0; b < boots.length; b++) { boots[b](); }
          await flush();

          assert(calls.length === 1, 'registration: the boot pass hydrated the mini-cart (one cart fetch)');
          assert(calls[0].url === '/_shop/cart', 'registration: the boot-driven hydration targeted /_shop/cart');
          var shell = doc.querySelector('[data-shop-mini-cart]');
          assert(
            (' ' + (shell.getAttribute('data-thallo-enhanced') || '') + ' ').indexOf(' shop-mini-cart ') !== -1,
            'registration: the core marked the shell with shop-mini-cart'
          );
          assert(count.textContent === '4', 'registration: the boot-driven hydration painted the count');

          console.log('registration OK');
        })()

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    /**
     * Harness proving a SECOND execution of shop.js on a runtime page is a no-op: the
     * exactly-once guard returns before the registration block, so no duplicate-name throw
     * escapes, the export object is the FIRST execution's (reference equality via a
     * harness-set tag), and exactly one cart fetch happens across both executions.
     */
    private function runtimeSecondExecutionHarness(string $shopJsSrc, string $runtimeSrc): string
    {
        return $this->harnessPrelude($shopJsSrc, $runtimeSrc) . "\n\n" . <<<JS
        (async function secondExecution() {
          var doc = new Doc(); // readyState 'complete' — the core boots via microtask
          var count = el('span', { 'data-shop-cart-count': '' });
          doc.body.appendChild(el('div', { 'data-shop-mini-cart': '' }, [count]));

          var calls = [];
          var queue = [{ ok: true, status: 200, data: { item_count: 2, items: [] } }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          loadRuntime(win, doc);
          loadShopJs(win, doc);

          // Premise: the first execution registered the shop modules on the runtime — the
          // second execution below would hit the duplicate-name throw WITHOUT the guard.
          var premiseThrew = false;
          try {
            win.ThalloRuntime.register('shop-form', { selector: 'x', enhance: function () {} });
          } catch (e) { premiseThrew = true; }
          assert(premiseThrew, 'second-exec: the first execution registered shop-form on the runtime');

          // The HARNESS tags the export object — shop.js itself never writes __tag.
          win.thalloShop.__tag = 'first';

          var secondThrew = false;
          try {
            loadShopJs(win, doc); // second execution (every shop block template emits a script tag)
          } catch (e) { secondThrew = true; }
          assert(!secondThrew, 'second-exec: no duplicate-name registration throw escaped');
          assert(
            win.thalloShop.__tag === 'first',
            'second-exec: window.thalloShop is the FIRST execution object (no replacement)'
          );

          await flush(); // the core's deferred boot

          assert(calls.length === 1, 'second-exec: exactly one cart fetch across both executions');
          assert(count.textContent === '2', 'second-exec: the single hydration painted the count');

          console.log('secondExecution OK');
        })()

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    /**
     * Harness proving window.thalloShop.init() delegates to the core's enhance() on runtime
     * pages: a shell inserted AFTER boot is enhanced (core marker stamped) with exactly one
     * fresh cart fetch, while already-enhanced components are skipped via the core's
     * markers — the previously bound form gains no second submit listener.
     */
    private function runtimeInitDelegationHarness(string $shopJsSrc, string $runtimeSrc): string
    {
        return $this->harnessPrelude($shopJsSrc, $runtimeSrc) . "\n\n" . <<<JS
        (async function initDelegation() {
          var doc = new Doc();
          var form = el('form', { action: '/_shop/cart/add' }, [el('button', { type: 'submit' })]);
          doc.body.appendChild(form);
          var count = el('span', { 'data-shop-cart-count': '' });
          var shell1 = el('div', { 'data-shop-mini-cart': '' }, [count]);
          doc.body.appendChild(shell1);

          var calls = [];
          var queue = [{ ok: true, status: 200, data: { item_count: 1, items: [] } }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          await loadPage(win, doc); // runtime core + shop.js + boot flush (fetch slot settled)

          assert(calls.length === 1, 'init-delegate: boot hydrated the initial shell once');
          assert(
            (form._listeners['submit'] || []).length === 1,
            'init-delegate: boot bound the cart form exactly once'
          );

          // A freshly inserted shell + re-init: the core's markers, not a direct sweep, drive.
          var shell2 = el('div', { 'data-shop-mini-cart': '' });
          doc.body.appendChild(shell2);
          calls.length = 0;
          queue.push({ ok: true, status: 200, data: { item_count: 6, items: [] } });

          win.thalloShop.init();

          assert(calls.length === 1, 'init-delegate: exactly ONE new cart fetch (slot settled, fresh state)');
          assert(calls[0].url === '/_shop/cart', 'init-delegate: the re-init fetch targeted /_shop/cart');
          assert(
            (' ' + (shell2.getAttribute('data-thallo-enhanced') || '') + ' ').indexOf(' shop-mini-cart ') !== -1,
            'init-delegate: the inserted shell was enhanced as shop-mini-cart'
          );
          assert(
            (form._listeners['submit'] || []).length === 1,
            'init-delegate: the previously enhanced form was NOT re-bound (listener count unchanged)'
          );

          await flush();
          assert(count.textContent === '6', 'init-delegate: the re-init painted fresh cart state document-wide');

          console.log('initDelegation OK');
        })()

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    /**
     * Harness proving per-component containment among SAME-module siblings: the first
     * product-grid shell's enhance throws (only for the 'data-source' read — the core's
     * markerHas() reads data-thallo-enhanced OUTSIDE its try, so a blanket getAttribute
     * throw would abort the whole pass uncaught), yet the sibling grid still hydrates AND a
     * module registered AFTER shop-product-grid (shop-featured-product) still enhances.
     */
    private function runtimeContainmentHarness(string $shopJsSrc, string $runtimeSrc): string
    {
        return $this->harnessPrelude($shopJsSrc, $runtimeSrc) . "\n\n" . <<<JS
        (async function containment() {
          var doc = new Doc();
          var grid1 = el('div', { 'data-shop-block': 'product-grid' });
          // Throws ONLY for data-source (the first attribute hydrateProductGrid reads);
          // every other attribute — including the core's data-thallo-enhanced marker read,
          // which happens outside the containment try — behaves normally.
          grid1.getAttribute = function (name) {
            if (name === 'data-source') { throw new Error('simulated component failure'); }
            return Element.prototype.getAttribute.call(this, name);
          };
          var grid2 = el('div', { 'data-shop-block': 'product-grid' });
          var featured = el('div', { 'data-shop-block': 'featured-product' });
          doc.body.appendChild(grid1);
          doc.body.appendChild(grid2);
          doc.body.appendChild(featured);

          var consoleErrors = [];
          var consoleStub = { error: function () { consoleErrors.push(arguments); } };

          var calls = [];
          var queue = [
            { ok: true, status: 200, data: { items: [] } },
            { ok: true, status: 200, data: { product: null } },
          ];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
            console: consoleStub,
          };

          loadRuntime(win, doc, consoleStub);
          // Real defer delivery (separate script tasks): the core's boot fires at this
          // task boundary with zero shop modules registered, so shop.js's marker-gated
          // catch-up pass is the SINGLE pass enhancing the shop shells below. Same-task
          // evaluation would run boot AND catch-up — and since a FAILED component stays
          // unmarked by design, both passes would retry it and log twice.
          await new Promise(function (r) { setImmediate(r); });
          loadShopJs(win, doc);
          await flush(); // shop.js's scheduled catch-up pass

          assert(calls.length === 2, 'containment: the sibling grid AND the later featured module fetched');
          assert(
            calls[0].url.indexOf('/_shop/blocks/product-grid') === 0,
            'containment: the SECOND grid hydrated despite its throwing sibling'
          );
          assert(
            calls[1].url.indexOf('/_shop/blocks/featured-product') === 0,
            'containment: a module registered AFTER shop-product-grid still enhanced'
          );
          assert(
            (' ' + (grid2.getAttribute('data-thallo-enhanced') || '') + ' ').indexOf(' shop-product-grid ') !== -1,
            'containment: the sibling grid carries the shop-product-grid marker'
          );
          assert(
            (' ' + (featured.getAttribute('data-thallo-enhanced') || '') + ' ')
              .indexOf(' shop-featured-product ') !== -1,
            'containment: the featured shell carries the shop-featured-product marker'
          );
          assert(
            grid1.getAttribute('data-thallo-enhanced') === null,
            'containment: the failed shell has NO data-thallo-enhanced marker'
          );
          assert(consoleErrors.length === 1, 'containment: the core logged the contained failure exactly once');

          console.log('containment OK');
        })()

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    /**
     * Harness proving the canvas stage runs NO shop behavior end-to-end: with a
     * .thallo-preview-block present, neither the core's boot NOR an explicit
     * window.thalloShop.init() (the runtime-aware delegation is what restores this
     * guarantee) fetches, binds, or marks anything shop-owned.
     */
    private function runtimeCanvasHarness(string $shopJsSrc, string $runtimeSrc): string
    {
        return $this->harnessPrelude($shopJsSrc, $runtimeSrc) . "\n\n" . <<<JS
        (async function canvasStage() {
          var doc = new Doc();
          var preview = el('div');
          preview.className = 'thallo-preview-block'; // the core's canvas probe matches this
          doc.body.appendChild(preview);

          var form = el('form', { action: '/_shop/cart/add' }, [el('button', { type: 'submit' })]);
          doc.body.appendChild(form);
          doc.body.appendChild(el('div', { 'data-shop-mini-cart': '' }));
          doc.body.appendChild(el('div', { 'data-shop-block': 'product-grid' }));

          var calls = [];
          var queue = []; // nothing may fetch — any call would also trip the count asserts
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          function anyShopMarker(node) {
            var marker = node.attrs ? node.attrs['data-thallo-enhanced'] : null;
            if (marker && marker.indexOf('shop-') !== -1) { return true; }
            var kids = node.children || [];
            for (var i = 0; i < kids.length; i++) {
              if (anyShopMarker(kids[i])) { return true; }
            }
            return false;
          }
          function assertNoShopBehavior(when) {
            assert(calls.length === 0, 'canvas: zero shop fetches ' + when);
            assert(
              (form._listeners['submit'] || []).length === 0,
              'canvas: the cart form has no submit listener ' + when
            );
            assert(
              form.getAttribute('data-shop-bound') === null,
              'canvas: the cart form carries no data-shop-bound marker ' + when
            );
            assert(
              !anyShopMarker(doc.documentElement),
              'canvas: no element carries a shop-* enhanced marker ' + when
            );
          }

          await loadPage(win, doc); // runtime core + shop.js + boot flush

          assertNoShopBehavior('after boot');

          win.thalloShop.init(); // the runtime-aware delegation restores the canvas guarantee
          await flush();
          assertNoShopBehavior('after an explicit window.thalloShop.init()');

          console.log('canvasStage OK');
        })()

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    /**
     * Regression harness for REAL defer delivery (2026-07-27 review finding): on a served
     * page runtime.js and shop.js execute as SEPARATE script tasks, and the microtask
     * checkpoint between tasks fires the core's Promise.resolve() boot BEFORE shop.js's
     * task registers its six modules — so shop.js must schedule its own marker-gated
     * catch-up pass or nothing shop-owned ever enhances. `loadPage` is deliberately NOT
     * used here: its single-task evaluation is exactly the configuration that masked the
     * bug. The two-shells fixture also pins the coalescing contract under CORE-driven
     * per-shell hydrateMiniCart invocations (the only configuration exercising the
     * in-flight-slot join shell-by-shell): two shells, BOTH painted, exactly ONE
     * GET /_shop/cart.
     */
    private function runtimeSeparateTasksHarness(string $shopJsSrc, string $runtimeSrc): string
    {
        return $this->harnessPrelude($shopJsSrc, $runtimeSrc) . "\n\n" . <<<JS
        (async function separateTasks() {
          var doc = new Doc();
          doc.readyState = 'interactive'; // what defer scripts actually observe at eval time

          var form = el('form', { action: '/_shop/cart/add' }, [el('button', { type: 'submit' })]);
          doc.body.appendChild(form);
          var count1 = el('span', { 'data-shop-cart-count': '' });
          doc.body.appendChild(el('div', { 'data-shop-mini-cart': '' }, [count1]));
          var count2 = el('span', { 'data-shop-cart-count': '' });
          doc.body.appendChild(el('div', { 'data-shop-mini-cart': '' }, [count2]));

          var calls = [];
          var queue = [{ ok: true, status: 200, data: { item_count: 3, items: [] } }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          loadRuntime(win, doc);
          // The task boundary between the two script executions: setImmediate schedules
          // a MACROtask, so every pending microtask — the core's Promise.resolve() boot,
          // which fires with zero shop modules registered — flushes first, exactly like
          // the checkpoint between two defer <script> tasks in a real browser.
          await new Promise(function (r) { setImmediate(r); });

          loadShopJs(win, doc);
          await new Promise(function (r) { setImmediate(r); });
          await flush();

          assert(
            (form._listeners['submit'] || []).length === 1,
            'separate-tasks: the cart form got its submit listener despite the pre-registration boot'
          );
          var cartFetches = calls.filter(function (c) { return c.url === '/_shop/cart'; });
          assert(
            cartFetches.length === 1,
            'separate-tasks: exactly ONE GET /_shop/cart despite two shells (core-driven coalescing)'
          );
          assert(calls.length === 1, 'separate-tasks: no other fetch was issued');
          assert(count1.textContent === '3', 'separate-tasks: first mini-cart shell painted');
          assert(count2.textContent === '3', 'separate-tasks: second mini-cart shell painted');

          console.log('separateTasks OK');
        })()

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    // ==================================================================
    // Wishlist store + hearts + badges + page (storefront-v1 Task 8, spec §5)
    // ==================================================================

    public function testWishlistStorageAdapterFailuresAreContainedAndFailClosed(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString('thallo:wishlist:v1:', $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness($node, $this->wishlistStorageHarness($src), 'wishlist_storage');
    }

    public function testWishlistStorePrimitivesOrderBoundAndPublishOnlyAfterASuccessfulWrite(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString('thallo:wishlist-changed', $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness($node, $this->wishlistPrimitivesHarness($src), 'wishlist_primitives');
    }

    public function testWishlistHeartsAndBadgesTrackTheSingleStoreAuthority(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString("register('shop-wishlist'", $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness($node, $this->wishlistUiHarness($src), 'wishlist_ui');
    }

    public function testWishlistPageHydrationReconciliationAndRaces(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString("register('shop-wishlist-page'", $src);
        self::assertStringContainsString('/_shop/wishlist/items?', $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness($node, $this->wishlistPageHarness($src), 'wishlist_page');
    }

    public function testWishlistCrossTabStorageEventReSanitizesAndRepublishes(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString("'storage'", $src);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate shop.js');
        }

        $this->runNodeHarness($node, $this->wishlistCrossTabHarness($src), 'wishlist_cross_tab');
    }

    /**
     * Shared wishlist fixtures: a fake localStorage whose every operation can be made to throw
     * INDEPENDENTLY (the adapter contract), a CustomEvent shim, a window carrying a `storage`
     * listener registry (cross-tab), a deferrable fetch (so a response can be settled at an
     * exact point in a race), and the DOM fixtures the modules discover.
     */
    private function wishlistPrelude(): string
    {
        return <<<JS

        var SCOPE = 'scopeAAAAAAAA';
        var KEY = 'thallo:wishlist:v1:' + SCOPE;

        /** A pinned product-uuid shape: exactly 12 alphanumeric chars, like the endpoint's. */
        function pid(n) { return ('prod' + n + 'xxxxxxxxxxxx').slice(0, 12); }

        var A = pid('a');
        var B = pid('b');
        var C = pid('c');
        var D = pid('d');

        function fakeStorage(initialRaw, failures) {
          return {
            map: initialRaw === null || initialRaw === undefined ? {} : (function () {
              var m = {}; m[KEY] = initialRaw; return m;
            })(),
            failures: failures || {},
            getItem: function (k) {
              if (this.failures.getItem) { throw new Error('getItem denied'); }
              return Object.prototype.hasOwnProperty.call(this.map, k) ? this.map[k] : null;
            },
            setItem: function (k, v) {
              if (this.failures.setItem) { throw new Error('setItem denied'); }
              this.map[k] = String(v);
            },
            removeItem: function (k) {
              if (this.failures.removeItem) { throw new Error('removeItem denied'); }
              delete this.map[k];
            },
          };
        }

        function stored(storage) {
          var raw = storage.map[KEY];
          return raw === undefined ? null : JSON.parse(raw);
        }

        function CustomEventStub(type, init) {
          this.type = type;
          this.detail = (init && init.detail) || null;
        }

        /** A fetch whose every call is settled EXPLICITLY — the only way to pin a race. */
        function deferredFetch(calls) {
          var pending = [];
          function fetchStub(url, opts) {
            calls.push({ url: url, opts: opts });
            var settle;
            var promise = new Promise(function (resolve) { settle = resolve; });
            pending.push({
              url: url,
              resolveWith: function (data) {
                settle({ ok: true, status: 200, json: function () { return Promise.resolve(data); } });
              },
              // An HTTP error whose body still parses as JSON — the framework serves JSON
              // error envelopes, so a 500/422 response is NOT a network rejection.
              resolveWithStatus: function (status, data) {
                settle({
                  ok: status >= 200 && status < 300,
                  status: status,
                  json: function () { return Promise.resolve(data); },
                });
              },
            });
            return promise;
          }
          fetchStub.pending = pending;
          return fetchStub;
        }

        function wishWin(doc, storage, fetchFn) {
          var listeners = {};
          return {
            document: doc,
            location: { href: '' },
            fetch: fetchFn,
            FormData: FakeFormData,
            localStorage: storage,
            CustomEvent: CustomEventStub,
            _windowListeners: listeners,
            addEventListener: function (type, fn) {
              (listeners[type] = listeners[type] || []).push(fn);
            },
          };
        }

        function fireStorage(win, key, newValue) {
          var listeners = win._windowListeners['storage'] || [];
          for (var i = 0; i < listeners.length; i++) {
            listeners[i]({ type: 'storage', key: key, newValue: newValue });
          }
          return listeners.length;
        }

        function recordEvents(doc) {
          var events = [];
          doc.addEventListener('thallo:wishlist-changed', function (event) { events.push(event); });
          return events;
        }

        function heart(uuid, name) {
          var btn = el('button', {
            type: 'button',
            'data-shop-wishlist-toggle': '',
            'data-product-uuid': uuid,
            'aria-pressed': 'false',
            'aria-label': 'Save ' + name + ' to wishlist',
          });
          btn.hidden = true; // every server-rendered heart ships hidden (spec §5)
          return btn;
        }

        function badge() {
          var span = el('span', { 'data-shop-wishlist-count': '' });
          span.textContent = '0';
          span.hidden = true; // the badge ships hidden — a zero count is noise
          return span;
        }

        /** A scoped page root: the ONE authority hearts/badges/pages read the scope from. */
        function scopedRoot(doc, children) {
          var root = el('section', { 'data-shop-scope': SCOPE }, children || []);
          doc.body.appendChild(root);
          return root;
        }

        function pageFixture(doc, withScope) {
          var status = el('p', { 'data-shop-wishlist-status': '' });
          status.textContent = 'Loading your saved items…';
          var empty = el('div', { 'data-shop-wishlist-empty': '' });
          empty.hidden = true;
          var grid = el('ul', { 'data-shop-wishlist-grid': '' });
          grid.hidden = true;
          var attrs = { 'data-shop-wishlist-page': '', 'aria-busy': 'true' };
          if (withScope !== false) { attrs['data-shop-scope'] = SCOPE; }
          var root = el('section', attrs, [status, empty, grid]);
          doc.body.appendChild(root);
          return { root: root, status: status, empty: empty, grid: grid };
        }

        function cardJson(uuid, name) {
          return {
            uuid: uuid,
            name: name,
            url: '/shop/products/' + uuid,
            cover_url: null,
            rating: null,
            price_formatted: '\$10.00',
            compare_at_formatted: null,
            category_name: null,
            cart_mode: 'options',
            direct_variant_uuid: null,
          };
        }

        function cardNames(grid) {
          var names = [];
          for (var i = 0; i < grid.children.length; i++) {
            var link = grid.children[i].querySelector('.shop-grid__name');
            names.push(link ? link.textContent : '(no name)');
          }
          return names;
        }
        JS;
    }

    /**
     * Storage-adapter contract (spec §5): a valid stored list round-trips and the hearts
     * REVEAL; corrupt JSON resets to `[]`; a throwing `getItem` or a throwing init `setItem`
     * fails CLOSED (the heart stays hidden — never an inert control) without preventing any
     * other shop.js module from enhancing.
     */
    private function wishlistStorageHarness(string $shopJsSrc): string
    {
        return $this->harnessPrelude($shopJsSrc) . $this->wishlistPrelude() . "\n\n" . <<<JS
        (async function validStoredListRoundTrips() {
          var doc = new Doc();
          var saved = heart(A, 'Alpha');
          var unsaved = heart(B, 'Beta');
          scopedRoot(doc, [saved, unsaved]);

          var storage = fakeStorage(JSON.stringify([A]));
          var win = wishWin(doc, storage, makeFetch([], []));

          loadShopJs(win, doc);
          await flush();

          assert(saved.hidden === false, 'storage: a ready store REVEALS the heart');
          assert(saved.getAttribute('aria-pressed') === 'true', 'storage: a stored uuid renders pressed');
          assert(unsaved.getAttribute('aria-pressed') === 'false', 'storage: an unsaved uuid renders unpressed');
          // ready ONLY after the sanitized value round-trips through setItem.
          assert(JSON.stringify(stored(storage)) === JSON.stringify([A]),
            'storage: initialization wrote the sanitized value back');

          console.log('validStoredListRoundTrips OK');
        })()

        .then(async function corruptJsonResetsToEmpty() {
          var doc = new Doc();
          var btn = heart(A, 'Alpha');
          scopedRoot(doc, [btn]);

          var storage = fakeStorage('{ not json at all');
          var win = wishWin(doc, storage, makeFetch([], []));

          loadShopJs(win, doc);
          await flush();

          assert(JSON.stringify(stored(storage)) === '[]', 'corrupt: a corrupt payload resets to []');
          assert(btn.hidden === false, 'corrupt: recovery still yields a ready store (heart revealed)');
          assert(btn.getAttribute('aria-pressed') === 'false', 'corrupt: nothing is saved after a reset');

          console.log('corruptJsonResetsToEmpty OK');
        })

        .then(async function hostileValuesAreSanitized() {
          var doc = new Doc();
          var btn = heart(A, 'Alpha');
          scopedRoot(doc, [btn]);

          var hostile = [A, A, 'not-a-uuid!', 42, null, B];
          for (var i = 0; i < 200; i++) { hostile.push(pid('h' + i)); }
          var storage = fakeStorage(JSON.stringify(hostile));
          var win = wishWin(doc, storage, makeFetch([], []));

          loadShopJs(win, doc);
          await flush();

          var list = stored(storage);
          assert(list.length === 100, 'sanitize: a hostile/legacy list is clamped to 100, got ' + list.length);
          assert(list[0] === A && list[1] === B, 'sanitize: duplicates/malformed values drop, order preserved');

          console.log('hostileValuesAreSanitized OK');
        })

        .then(async function getItemThrowsFailsClosedAndContainsTheFailure() {
          var doc = new Doc();
          var btn = heart(A, 'Alpha');
          scopedRoot(doc, [btn]);
          // A mini-cart shell in the SAME document: a denied storage backend must never stop
          // another module from enhancing (containment).
          var count = el('span', { 'data-shop-cart-count': '' });
          doc.body.appendChild(el('div', { 'data-shop-mini-cart': '' }, [count]));

          var calls = [];
          var queue = [{ ok: true, status: 200, data: { item_count: 2, items: [] } }];
          var storage = fakeStorage(JSON.stringify([A]), { getItem: true });
          var win = wishWin(doc, storage, makeFetch(queue, calls));

          loadShopJs(win, doc);
          await flush();

          assert(btn.hidden === true, 'denied: a throwing getItem leaves the heart HIDDEN (fail closed)');
          assert(btn.getAttribute('aria-pressed') === 'false', 'denied: no false persistence is shown');
          assert(count.textContent === '2', 'denied: the mini-cart module still enhanced (containment)');

          console.log('getItemThrowsFailsClosedAndContainsTheFailure OK');
        })

        .then(async function setItemThrowsOnInitIsNotReady() {
          var doc = new Doc();
          var btn = heart(A, 'Alpha');
          scopedRoot(doc, [btn]);

          var storage = fakeStorage(JSON.stringify([A]), { setItem: true });
          var win = wishWin(doc, storage, makeFetch([], []));

          loadShopJs(win, doc);
          await flush();

          assert(btn.hidden === true, 'unwritable: a failed init round-trip never reveals the heart');
          assert(win.thalloShop.wishlist(SCOPE).ready() === false, 'unwritable: the store is not ready');
          // A click on a heart that was never revealed/bound changes nothing.
          var clicks = btn._listeners['click'] || [];
          assert(clicks.length === 0, 'unwritable: an unready store binds no toggle listener');

          console.log('setItemThrowsOnInitIsNotReady OK')
        })

        .then(async function noScopeNeverInitializes() {
          var doc = new Doc();
          var btn = heart(A, 'Alpha');
          doc.body.appendChild(btn); // NO [data-shop-scope] anywhere — commerce absent/inactive

          var storage = fakeStorage(JSON.stringify([A]));
          var win = wishWin(doc, storage, makeFetch([], []));

          loadShopJs(win, doc);
          await flush();

          assert(btn.hidden === true, 'no-scope: without a scope the wishlist never initializes');
          assert(stored(storage) !== null ? JSON.stringify(stored(storage)) === JSON.stringify([A]) : true,
            'no-scope: storage is never rewritten');

          console.log('noScopeNeverInitializes OK');
        })

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    /**
     * Store primitives (spec §5): `add` unshifts newest-first and drops the OLDEST at 100, a
     * duplicate `add` is a NO-OP, `toggle` DELEGATES to add/remove, remove-then-re-add lands at
     * the front, and every publish happens only AFTER a successful write — with the exact
     * `thallo:wishlist-changed` `{scope, uuids}` event shape.
     */
    private function wishlistPrimitivesHarness(string $shopJsSrc): string
    {
        return $this->harnessPrelude($shopJsSrc) . $this->wishlistPrelude() . "\n\n" . <<<JS
        (async function primitives() {
          var doc = new Doc();
          scopedRoot(doc, [heart(A, 'Alpha')]);
          var storage = fakeStorage('[]');
          var win = wishWin(doc, storage, makeFetch([], []));
          var events = recordEvents(doc);

          loadShopJs(win, doc);
          await flush();

          var store = win.thalloShop.wishlist(SCOPE);
          assert(store.ready() === true, 'primitives: the store is ready');
          assert(typeof store.add === 'function' && typeof store.remove === 'function'
            && typeof store.toggle === 'function', 'primitives: add/remove/toggle are exposed');

          assert(store.add(A) === true, 'primitives: add persists');
          assert(store.add(B) === true, 'primitives: a second add persists');
          assert(JSON.stringify(store.uuids()) === JSON.stringify([B, A]),
            'primitives: add UNSHIFTS (newest first), got ' + JSON.stringify(store.uuids()));
          assert(JSON.stringify(stored(storage)) === JSON.stringify([B, A]),
            'primitives: the newest-first order is what persisted');

          // Duplicate add: a NO-OP — no reorder, no write, no event (defensive API surface;
          // the UI only ever toggles).
          var eventsBefore = events.length;
          var storedBefore = JSON.stringify(stored(storage));
          assert(store.add(B) === false, 'primitives: adding an already-saved uuid is a NO-OP');
          assert(JSON.stringify(store.uuids()) === JSON.stringify([B, A]),
            'primitives: a duplicate add never reorders');
          assert(events.length === eventsBefore, 'primitives: a NO-OP add publishes nothing');
          assert(JSON.stringify(stored(storage)) === storedBefore, 'primitives: a NO-OP add writes nothing');

          // toggle DELEGATES: present -> remove, absent -> add.
          store.toggle(B);
          assert(JSON.stringify(store.uuids()) === JSON.stringify([A]), 'primitives: toggle removed the present uuid');
          store.toggle(B);
          assert(JSON.stringify(store.uuids()) === JSON.stringify([B, A]),
            'primitives: remove-then-re-add lands at the FRONT (that IS newest-first)');

          // Exact event shape.
          var last = events[events.length - 1];
          assert(last.type === 'thallo:wishlist-changed', 'primitives: the event name is pinned');
          assert(last.detail && last.detail.scope === SCOPE, 'primitives: detail.scope is the storage scope');
          assert(JSON.stringify(last.detail.uuids) === JSON.stringify([B, A]),
            'primitives: detail.uuids is the new list');

          console.log('primitives OK');
        })()

        .then(async function boundedAtOneHundredDroppingTheOldest() {
          var doc = new Doc();
          scopedRoot(doc, [heart(A, 'Alpha')]);
          var storage = fakeStorage('[]');
          var win = wishWin(doc, storage, makeFetch([], []));

          loadShopJs(win, doc);
          await flush();

          var store = win.thalloShop.wishlist(SCOPE);
          for (var i = 0; i < 100; i++) { store.add(pid('n' + i)); }
          assert(store.uuids().length === 100, 'bound: exactly 100 saved');
          assert(store.uuids()[99] === pid('n0'), 'bound: the OLDEST sits at the tail');

          store.add(D);
          var list = store.uuids();
          assert(list.length === 100, 'bound: still 100 after overflow');
          assert(list[0] === D, 'bound: the newest is at the front');
          assert(list.indexOf(pid('n0')) === -1, 'bound: the OLDEST was dropped from the tail');
          assert(JSON.stringify(stored(storage)) === JSON.stringify(list), 'bound: the bounded list persisted');

          console.log('boundedAtOneHundred OK');
        })

        .then(async function publishOnlyAfterASuccessfulWrite() {
          var doc = new Doc();
          var btn = heart(A, 'Alpha');
          scopedRoot(doc, [btn]);
          var count = badge();
          doc.body.appendChild(count);
          var storage = fakeStorage('[]');
          var win = wishWin(doc, storage, makeFetch([], []));
          var events = recordEvents(doc);

          loadShopJs(win, doc);
          await flush();

          var store = win.thalloShop.wishlist(SCOPE);
          store.add(A);
          assert(events.length === 1, 'write-first: a successful write published exactly once');

          // The quota now refuses every write: the toggle must change NOTHING the user can see.
          storage.failures.setItem = true;
          assert(store.toggle(B) === false, 'write-first: a failed write reports failure');
          assert(JSON.stringify(store.uuids()) === JSON.stringify([A]),
            'write-first: a failed write leaves the in-memory list untouched');
          assert(events.length === 1, 'write-first: a failed write publishes NOTHING');
          assert(btn.getAttribute('aria-pressed') === 'true', 'write-first: the heart still shows the true state');
          assert(count.textContent === '1', 'write-first: the badge still shows the true count');

          console.log('publishOnlyAfterASuccessfulWrite OK');
        })

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    /**
     * Hearts + badges (spec §5): one store authority drives every heart and count on the page —
     * `aria-pressed` and the product-specific label swap on toggle, the badge hidden at zero and
     * counting above it, and two hearts for the SAME product converge.
     */
    private function wishlistUiHarness(string $shopJsSrc): string
    {
        return $this->harnessPrelude($shopJsSrc) . $this->wishlistPrelude() . "\n\n" . <<<JS
        (async function heartsAndBadges() {
          var doc = new Doc();
          var gridHeart = heart(A, 'Alpha Widget');
          var detailHeart = heart(A, 'Alpha Widget'); // the SAME product, a second surface
          var otherHeart = heart(B, 'Beta Widget');
          var count = badge();
          scopedRoot(doc, [gridHeart, detailHeart, otherHeart, count]);

          var storage = fakeStorage('[]');
          var win = wishWin(doc, storage, makeFetch([], []));

          loadShopJs(win, doc);
          await flush();

          assert(count.hidden === true, 'badge: hidden at zero');
          assert(gridHeart.hidden === false && detailHeart.hidden === false,
            'hearts: revealed behind a ready store');

          var clicks = gridHeart._listeners['click'] || [];
          assert(clicks.length === 1, 'hearts: exactly one click listener');
          clicks[0]({});

          assert(gridHeart.getAttribute('aria-pressed') === 'true', 'hearts: the clicked heart is pressed');
          assert(gridHeart.getAttribute('aria-label') === 'Remove Alpha Widget from wishlist',
            'hearts: the label swaps to the product-specific REMOVE label, got: '
              + gridHeart.getAttribute('aria-label'));
          assert(detailHeart.getAttribute('aria-pressed') === 'true',
            'hearts: the other surface for the SAME product converged (one authority)');
          assert(otherHeart.getAttribute('aria-pressed') === 'false', 'hearts: an unrelated product is untouched');
          assert(count.hidden === false && count.textContent === '1', 'badge: revealed with the count at 1');

          clicks[0]({});
          assert(gridHeart.getAttribute('aria-pressed') === 'false', 'hearts: a second click un-saves');
          assert(gridHeart.getAttribute('aria-label') === 'Save Alpha Widget to wishlist',
            'hearts: the label swaps back to the SAVE label');
          assert(count.hidden === true && count.textContent === '0', 'badge: hidden again at zero');

          // Re-running the sweep must not stack listeners (the inner-marker layer).
          win.thalloShop.init();
          assert((gridHeart._listeners['click'] || []).length === 1, 'hearts: re-init does not stack listeners');

          console.log('heartsAndBadges OK');
        })()

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    /**
     * The wishlist page (spec §5): cards paint in STORED order through `buildProductCard`,
     * `aria-busy` clears only AFTER settle, the empty state appears only when the settled list is
     * empty (never a flash), an applicable response removes ONLY the uuids its own request
     * snapshot asked about, and every race — a toggle during flight, a stale generation —
     * ignores the answer and schedules exactly ONE fresh reconciliation.
     */
    private function wishlistPageHarness(string $shopJsSrc): string
    {
        return $this->harnessPrelude($shopJsSrc) . $this->wishlistPrelude() . "\n\n" . <<<JS
        (async function paintsInStoredOrderAndRemovesOnlyOmittedUuids() {
          var doc = new Doc();
          var fx = pageFixture(doc);
          var storage = fakeStorage(JSON.stringify([A, B, C]));
          var calls = [];
          var fetchStub = deferredFetch(calls);
          var win = wishWin(doc, storage, fetchStub);
          var events = recordEvents(doc);

          loadShopJs(win, doc);
          await flush();

          assert(calls.length === 1, 'page: exactly one resolution request');
          assert(calls[0].url === '/_shop/wishlist/items?uuids[]=' + A + '&uuids[]=' + B + '&uuids[]=' + C,
            'page: the request carries the stored uuids in order, got ' + calls[0].url);
          assert(fx.root.getAttribute('aria-busy') === 'true', 'page: aria-busy stays true while in flight');
          assert(fx.empty.hidden === true, 'page: the empty state NEVER flashes before settle');

          // B is omitted by the response — the endpoint IS the reconciliation authority.
          fetchStub.pending[0].resolveWith({ items: [cardJson(A, 'Alpha'), cardJson(C, 'Gamma')] });
          await flush();

          assert(fx.root.getAttribute('aria-busy') === 'false', 'page: aria-busy cleared AFTER settle');
          assert(fx.grid.hidden === false, 'page: the grid is revealed');
          assert(JSON.stringify(cardNames(fx.grid)) === JSON.stringify(['Alpha', 'Gamma']),
            'page: cards painted in stored order, got ' + JSON.stringify(cardNames(fx.grid)));
          assert(fx.empty.hidden === true, 'page: a non-empty settled list never shows the empty state');
          assert(JSON.stringify(stored(storage)) === JSON.stringify([A, C]),
            'page: ONLY the omitted uuid was removed, got ' + JSON.stringify(stored(storage)));
          assert(events.length === 1, 'page: the reconciliation removal published exactly once');

          // The painted cards are live: their hearts are bound to the same store.
          var painted = fx.grid.children[0].querySelector('[data-shop-wishlist-toggle]');
          assert(painted !== null, 'page: a painted card carries a heart');
          assert(painted.hidden === false && painted.getAttribute('aria-pressed') === 'true',
            'page: a painted heart is revealed and pressed (it IS saved)');
          (painted._listeners['click'] || [])[0]({});
          await flush();
          assert(JSON.stringify(stored(storage)) === JSON.stringify([C]),
            'page: un-saving from a painted card persists');
          assert(JSON.stringify(cardNames(fx.grid)) === JSON.stringify(['Gamma']),
            'page: the removed card left the grid');

          console.log('paintsInStoredOrder OK');
        })()

        .then(async function emptyStoreShowsTheEmptyStateWithoutAnyRequest() {
          var doc = new Doc();
          var fx = pageFixture(doc);
          var storage = fakeStorage('[]');
          var calls = [];
          var win = wishWin(doc, storage, deferredFetch(calls));

          loadShopJs(win, doc);
          await flush();

          assert(calls.length === 0, 'empty: an empty store never queries the endpoint');
          assert(fx.empty.hidden === false, 'empty: the settled-empty list shows the empty state');
          assert(fx.grid.hidden === true, 'empty: the grid stays hidden');
          assert(fx.root.getAttribute('aria-busy') === 'false', 'empty: aria-busy cleared');

          console.log('emptyStore OK');
        })

        .then(async function deniedStorageNeverShowsAFalseEmptyState() {
          var doc = new Doc();
          var fx = pageFixture(doc);
          var storage = fakeStorage(JSON.stringify([A]), { getItem: true });
          var calls = [];
          var win = wishWin(doc, storage, deferredFetch(calls));

          loadShopJs(win, doc);
          await flush();

          assert(calls.length === 0, 'denied: nothing to resolve without a readable store');
          assert(fx.empty.hidden === true, 'denied: a denied store is NOT "nothing saved yet"');
          assert(fx.root.getAttribute('aria-busy') === 'false', 'denied: the page still settles (no stuck spinner)');
          assert(fx.status.hidden === false && fx.status.textContent.length > 0,
            'denied: the status region explains why');

          console.log('deniedStorage OK');
        })

        .then(async function aToggleDuringFlightIgnoresTheAnswerAndRefetchesOnce() {
          var doc = new Doc();
          var fx = pageFixture(doc);
          var storage = fakeStorage(JSON.stringify([A, B]));
          var calls = [];
          var fetchStub = deferredFetch(calls);
          var win = wishWin(doc, storage, fetchStub);

          loadShopJs(win, doc);
          await flush();
          assert(calls.length === 1, 'race: the initial resolution is in flight');

          // A heart toggled elsewhere on the page WHILE the fetch is in flight.
          var store = win.thalloShop.wishlist(SCOPE);
          store.add(D);
          assert(JSON.stringify(store.uuids()) === JSON.stringify([D, A, B]), 'race: the in-flight toggle persisted');

          // The stale answer would have removed B — it must be IGNORED (the revision moved).
          fetchStub.pending[0].resolveWith({ items: [cardJson(A, 'Alpha')] });
          await flush();

          assert(JSON.stringify(store.uuids()) === JSON.stringify([D, A, B]),
            'race: a superseded response never removes anything, got ' + JSON.stringify(store.uuids()));
          assert(calls.length === 2, 'race: EXACTLY one fresh reconciliation was scheduled, got ' + calls.length);
          assert(calls[1].url === '/_shop/wishlist/items?uuids[]=' + D + '&uuids[]=' + A + '&uuids[]=' + B,
            'race: the refetch asks from CURRENT state, got ' + calls[1].url);
          assert(fx.root.getAttribute('aria-busy') === 'true', 'race: the page has NOT settled on a stale answer');
          assert(fx.empty.hidden === true, 'race: no empty flash mid-race');

          fetchStub.pending[1].resolveWith({
            items: [cardJson(D, 'Delta'), cardJson(A, 'Alpha'), cardJson(B, 'Beta')],
          });
          await flush();

          assert(calls.length === 2, 'race: the settled reconciliation schedules nothing further');
          assert(fx.root.getAttribute('aria-busy') === 'false', 'race: the page settles on the FRESH answer');
          assert(JSON.stringify(cardNames(fx.grid)) === JSON.stringify(['Delta', 'Alpha', 'Beta']),
            'race: the fresh answer painted, got ' + JSON.stringify(cardNames(fx.grid)));

          console.log('toggleDuringFlight OK');
        })

        .then(async function aStaleGenerationIsIgnored() {
          var doc = new Doc();
          var fx = pageFixture(doc);
          var storage = fakeStorage(JSON.stringify([A, B]));
          var calls = [];
          var fetchStub = deferredFetch(calls);
          var win = wishWin(doc, storage, fetchStub);

          loadShopJs(win, doc);
          await flush();

          var store = win.thalloShop.wishlist(SCOPE);
          store.reconcile(); // a SECOND, newer reconciliation supersedes the page's first
          await flush();
          assert(calls.length === 2, 'generation: two requests are in flight');

          // The NEWEST generation answers first and applies…
          fetchStub.pending[1].resolveWith({ items: [cardJson(A, 'Alpha'), cardJson(B, 'Beta')] });
          await flush();
          assert(JSON.stringify(store.uuids()) === JSON.stringify([A, B]), 'generation: the latest answer applied');
          assert(fx.root.getAttribute('aria-busy') === 'true',
            'generation: the PAGE has not settled — its own request was superseded');

          // …then the STALE one lands, omitting B. It must never be applied — and the page
          // schedules exactly ONE fresh reconciliation instead of settling on it.
          fetchStub.pending[0].resolveWith({ items: [cardJson(A, 'Alpha')] });
          await flush();

          assert(JSON.stringify(store.uuids()) === JSON.stringify([A, B]),
            'generation: a stale generation never removes, got ' + JSON.stringify(store.uuids()));
          assert(JSON.stringify(stored(storage)) === JSON.stringify([A, B]),
            'generation: storage is untouched by the stale answer');
          assert(calls.length === 3, 'generation: exactly one fresh reconciliation followed, got ' + calls.length);
          assert(fx.root.getAttribute('aria-busy') === 'true', 'generation: still busy until the fresh answer');

          fetchStub.pending[2].resolveWith({ items: [cardJson(A, 'Alpha'), cardJson(B, 'Beta')] });
          await flush();
          assert(fx.root.getAttribute('aria-busy') === 'false', 'generation: the fresh answer settled the page');
          assert(JSON.stringify(cardNames(fx.grid)) === JSON.stringify(['Alpha', 'Beta']),
            'generation: the fresh answer painted, got ' + JSON.stringify(cardNames(fx.grid)));

          console.log('staleGeneration OK');
        })

        .then(async function aServerErrorEnvelopeNeverWipesTheSavedList() {
          var doc = new Doc();
          var fx = pageFixture(doc);
          var storage = fakeStorage(JSON.stringify([A, B]));
          var calls = [];
          var fetchStub = deferredFetch(calls);
          var win = wishWin(doc, storage, fetchStub);
          var events = recordEvents(doc);

          loadShopJs(win, doc);
          await flush();
          assert(calls.length === 1, 'error500: the resolution request went out');

          // A transient 500 whose body is the framework's JSON error envelope — it parses
          // fine, and without the res.ok guard every snapshot uuid would look "omitted".
          fetchStub.pending[0].resolveWithStatus(500, { success: false, message: 'Server error' });
          await flush();

          assert(JSON.stringify(stored(storage)) === JSON.stringify([A, B]),
            'error500: localStorage is untouched, got ' + JSON.stringify(stored(storage)));
          assert(events.length === 0, 'error500: an error response never publishes a removal');
          assert(fx.root.getAttribute('aria-busy') === 'false', 'error500: the page still settles (no stuck spinner)');
          assert(fx.empty.hidden === true, 'error500: a failed resolution is NOT an empty wishlist');
          assert(fx.status.hidden === false && fx.status.textContent.indexOf('could not load') !== -1,
            'error500: the failure status is shown, got "' + fx.status.textContent + '"');

          console.log('errorEnvelope500 OK');
        })

        .then(async function aValidationEnvelopeWithEmptyItemsRemovesNothing() {
          var doc = new Doc();
          var fx = pageFixture(doc);
          var storage = fakeStorage(JSON.stringify([A, B, C]));
          var calls = [];
          var fetchStub = deferredFetch(calls);
          var win = wishWin(doc, storage, fetchStub);
          var events = recordEvents(doc);

          loadShopJs(win, doc);
          await flush();
          assert(calls.length === 1, 'error422: the resolution request went out');

          // The endpoint's own 422 envelope carries `items: []` — the WORST impostor: it is
          // exactly the shape that means "everything was omitted" on a 200.
          fetchStub.pending[0].resolveWithStatus(422, {
            error: 'uuids[] must be a list of at most 100 product uuids.',
            items: [],
          });
          await flush();

          assert(JSON.stringify(stored(storage)) === JSON.stringify([A, B, C]),
            'error422: NOTHING was removed, got ' + JSON.stringify(stored(storage)));
          assert(events.length === 0, 'error422: an error response never publishes a removal');
          assert(fx.root.getAttribute('aria-busy') === 'false', 'error422: the page still settles');
          assert(fx.empty.hidden === true, 'error422: a 422 is NOT an empty wishlist');
          assert(fx.status.hidden === false && fx.status.textContent.indexOf('could not load') !== -1,
            'error422: the failure status is shown, got "' + fx.status.textContent + '"');

          console.log('errorEnvelope422 OK');
        })

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }

    /**
     * Cross-tab convergence (spec §5): the adapter listens for the browser `storage` event,
     * re-sanitizes the changed value, and publishes the SAME state event — so hearts, badges and
     * the page in this tab converge on what the other tab persisted.
     */
    private function wishlistCrossTabHarness(string $shopJsSrc): string
    {
        return $this->harnessPrelude($shopJsSrc) . $this->wishlistPrelude() . "\n\n" . <<<JS
        (async function crossTab() {
          var doc = new Doc();
          var btn = heart(A, 'Alpha');
          var count = badge();
          scopedRoot(doc, [btn, count]);

          var storage = fakeStorage('[]');
          var win = wishWin(doc, storage, makeFetch([], []));
          var events = recordEvents(doc);

          loadShopJs(win, doc);
          await flush();

          assert(btn.getAttribute('aria-pressed') === 'false', 'cross-tab: nothing saved initially');

          // Another tab saved A (and left one hostile value in the payload).
          var listeners = fireStorage(win, KEY, JSON.stringify([A, 'not-a-uuid!', A]));
          assert(listeners === 1, 'cross-tab: the adapter listens for the storage event');
          await flush();

          assert(btn.getAttribute('aria-pressed') === 'true', 'cross-tab: this tab converged on the new state');
          assert(count.hidden === false && count.textContent === '1',
            'cross-tab: the value was RE-SANITIZED (duplicate/malformed dropped) and the badge repainted');
          var last = events[events.length - 1];
          assert(last && last.detail && last.detail.scope === SCOPE,
            'cross-tab: the same state event is published');
          assert(JSON.stringify(last.detail.uuids) === JSON.stringify([A]),
            'cross-tab: the published list is the sanitized one');

          // An unrelated key must not disturb this store.
          fireStorage(win, 'some:other:key', JSON.stringify([B]));
          await flush();
          assert(btn.getAttribute('aria-pressed') === 'true', 'cross-tab: an unrelated key changes nothing');
          assert(count.textContent === '1', 'cross-tab: an unrelated key never repaints the badge');

          console.log('crossTab OK');
        })()

        .then(function () { console.log('ALL_PASS'); })
        .catch(function (e) { console.error('FAIL: uncaught ' + (e && e.stack ? e.stack : e)); process.exit(1); });
        JS;
    }
}
