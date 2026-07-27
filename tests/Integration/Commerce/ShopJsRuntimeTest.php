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
 * evaluated first (shop.js registers six `shop-*` modules and the core's boot drives
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

          var toggle = el('button', { 'data-shop-cart-toggle': '', 'aria-expanded': 'false' });
          var panel = el('div', { 'data-shop-cart-drawer': '' });
          var shell = el('div', { 'data-shop-mini-cart': '' }, [toggle, panel]);
          doc.body.appendChild(shell);

          var calls = [];
          var queue = [{ ok: true, status: 200, data: { item_count: 0, items: [] } }];
          var win = {
            document: doc, location: { href: '' }, fetch: makeFetch(queue, calls), FormData: FakeFormData,
          };

          loadShopJs(win, doc);
          await flush();

          assert(toggle.getAttribute('data-shop-cart-toggle-bound') === '1',
            'drawer: the toggle carries the inner bound marker after enhancement');
          var clicks = toggle._listeners['click'] || [];
          assert(clicks.length === 1, 'drawer: exactly one click listener after first enhance');

          clicks[0]({});
          assert(toggle.getAttribute('aria-expanded') === 'true', 'drawer: a click opens (aria-expanded true)');
          clicks[0]({});
          assert(toggle.getAttribute('aria-expanded') === 'false', 'drawer: a second click closes');

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

    public function testRuntimePresentRegistersSixModulesAndCoreDrivesEnhancement(): void
    {
        $src = $this->shopJs();
        self::assertStringContainsString("register('shop-form'", $src);
        self::assertStringContainsString("register('shop-add-to-cart'", $src);

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
     * Harness proving that with the runtime present, shop.js registers its six modules on
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

          var names = ['shop-form', 'shop-gallery', 'shop-mini-cart',
            'shop-product-grid', 'shop-featured-product', 'shop-add-to-cart'];
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
}
