<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Tests\Support\AppTestCase;
use Thallo\Account\Blocks\AccountBlockTypesContributor;

/**
 * The account form blocks (account-form-blocks plan Task 2): `login-form`, `register-form`,
 * `forgot-password-form` — composable, CACHE-SAFE versions of the themed account forms. The pins
 * under test: byte-identical rendering (no per-visitor state, no CSRF token), NO `return_to` in
 * static markup (the enhance script injects it, so a no-JS submit falls back to the themed page),
 * and only `login-form` carrying the inline-error enhancement surface (hidden credentials node +
 * `account-forms.js`); register/forgot are plain server forms whose neutral flows leave the page.
 */
final class AccountFormBlocksTest extends AppTestCase
{
    public function testTheContributorDefinesAuthStateAndTheThreeFormBlocks(): void
    {
        $slugs = array_map(
            static fn ($definition) => $definition->slug,
            (new AccountBlockTypesContributor())->blockTypeDefinitions(),
        );

        self::assertSame(['auth-state', 'login-form', 'register-form', 'forgot-password-form'], $slugs);
    }

    public function testLoginFormRendersTheFormWithNextHeadingLinksAndEnhancementSurface(): void
    {
        $html = $this->renderBlock('login-form', [
            'heading' => 'Welcome back',
            'next' => '/members/home',
        ]);

        self::assertStringContainsString('action="/account/login"', $html);
        self::assertStringContainsString('name="email"', $html);
        self::assertStringContainsString('name="password"', $html);
        self::assertStringContainsString('<input type="hidden" name="next" value="/members/home">', $html);
        self::assertStringContainsString('Welcome back', $html);
        // show_links defaults true.
        self::assertStringContainsString('href="/account/forgot-password"', $html);
        self::assertStringContainsString('href="/account/register"', $html);
        // The Task-3 enhancement surface: hidden error node + the runtime script + the form marker.
        self::assertStringContainsString('data-account-form="login"', $html);
        self::assertStringContainsString('data-account-error="credentials"', $html);
        self::assertMatchesRegularExpression('/data-account-error="credentials"[^>]*hidden/', $html);
        self::assertStringContainsString('src="/_account/assets/account-forms.js" defer', $html);
        self::assertStringContainsString('href="/_account/assets/account-blocks.css"', $html);
    }

    public function testLoginFormCanHideTheLinksRow(): void
    {
        $html = $this->renderBlock('login-form', ['show_links' => false]);

        self::assertStringNotContainsString('href="/account/forgot-password"', $html);
        self::assertStringNotContainsString('href="/account/register"', $html);
        // The form itself still renders.
        self::assertStringContainsString('action="/account/login"', $html);
    }

    public function testRegisterFormIsAPlainServerFormWithTheFourFields(): void
    {
        $html = $this->renderBlock('register-form', []);

        self::assertStringContainsString('action="/account/register"', $html);
        foreach (['first_name', 'last_name', 'email', 'password'] as $field) {
            self::assertStringContainsString('name="' . $field . '"', $html);
        }
        // Plain: no enhancement surface of any kind.
        self::assertStringNotContainsString('data-account-form', $html);
        self::assertStringNotContainsString('data-account-error', $html);
        self::assertStringNotContainsString('account-forms.js', $html);
    }

    public function testForgotPasswordFormIsAPlainServerEmailForm(): void
    {
        $html = $this->renderBlock('forgot-password-form', []);

        self::assertStringContainsString('action="/account/forgot-password"', $html);
        self::assertStringContainsString('name="email"', $html);
        self::assertStringNotContainsString('data-account-form', $html);
        self::assertStringNotContainsString('data-account-error', $html);
        self::assertStringNotContainsString('account-forms.js', $html);
    }

    public function testEveryFormBlockIsByteIdenticalAndCarriesNoPerVisitorState(): void
    {
        foreach (['login-form', 'register-form', 'forgot-password-form'] as $type) {
            $first = $this->renderBlock($type, ['heading' => 'H']);
            $second = $this->renderBlock($type, ['heading' => 'H']);

            self::assertSame($first, $second, "{$type} must render deterministically");
            // The cache-safety pins: no session CSRF token, and no static return_to — the enhance
            // script injects return_to, so a no-JS submit flows through the themed pages.
            self::assertStringNotContainsString('return_to', $first, "{$type} must not ship return_to");
            self::assertStringNotContainsString('_token', $first, "{$type} must not ship a CSRF token");
            self::assertStringNotContainsString('csrf', strtolower($first), "{$type} must not reference csrf");
        }
    }

    public function testACapabilityOffBootFallsBackToTheMissingTemplatePath(): void
    {
        $off = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.accounts' => false],
        ]);

        try {
            $container = $off->getContainer();
            $env = $container->get(\Thallo\Render\TwigFactory::class)->environment();
            self::assertFalse(
                $env->getLoader()->exists('blocks/login-form.twig'),
                'login-form must not resolve while thallo.accounts is disabled',
            );

            /** @var \Thallo\Render\RenderContextExtension $extension */
            $extension = $container->get(\Thallo\Render\RenderContextExtension::class);
            $extension->resetPerRenderState();
            $extension->setBlockAnnotations(false);
            $extension->setLocale('en');
            $html = $extension->blocks($env, ['entry' => null, 'site' => []], [
                ['id' => 'loginformoff1', 'type' => 'login-form', 'data' => []],
            ]);

            self::assertStringNotContainsString('action="/account/login"', $html);
            self::assertMatchesRegularExpression(
                '/no template for block "login-form"|Missing block template: blocks\/login-form\.twig/',
                $html,
            );
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // --- account-forms.js runtime (Task 3), evaluated in node against a stub DOM --------------

    public function testFormsRuntimeInjectsReturnToRegistersOnceAndSkipsPlainForms(): void
    {
        $source = $this->accountFormsJsSource();
        self::assertStringContainsString("register('account-forms'", $source);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate account-forms.js');
        }
        $this->runNodeHarness($node, $this->injectionHarness($source), 'forms_injection');
    }

    public function testErrorReturnRevealsFocusesRefillsAndStripsTheParam(): void
    {
        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate account-forms.js');
        }
        $this->runNodeHarness($node, $this->errorReturnHarness($this->accountFormsJsSource()), 'forms_error');
    }

    public function testUnknownCodesExpiredAndForeignStashesAreInert(): void
    {
        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate account-forms.js');
        }
        $this->runNodeHarness($node, $this->inertCasesHarness($this->accountFormsJsSource()), 'forms_inert');
    }

    // --- helpers ------------------------------------------------------------------------------

    private function accountFormsJsSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/packages/thallo-account/assets/account-forms.js',
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

    private function runNodeHarness(string $node, string $harnessJs, string $suffix): void
    {
        $file = sys_get_temp_dir() . '/thallo_account_forms_' . $suffix . '_' . getmypid() . '.mjs';
        file_put_contents($file, $harnessJs);
        try {
            $out = [];
            $code = 0;
            exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
            $output = implode("\n", $out);
            self::assertSame(0, $code, "forms harness failed:\n" . $output);
            self::assertStringContainsString('ALL_PASS', $output);
        } finally {
            @unlink($file);
        }
    }

    /** The stub DOM + runtime + storage shared by the scenarios (AccountCacheIsolationTest's idiom). */
    private function harnessPrelude(string $source): string
    {
        $src = json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        var SRC = {$src};
        function fail(m) { console.error('FAIL: ' + m); process.exit(1); }
        function assert(c, m) { if (!c) { fail(m); } }

        function El(attrs) {
          this._attrs = Object.assign({}, attrs || {});
          this._map = {}; this._all = {}; this._children = []; this._handlers = {};
          this.value = ''; this._focused = false;
        }
        El.prototype.setAttribute = function (k, v) { this._attrs[k] = String(v); };
        El.prototype.removeAttribute = function (k) { delete this._attrs[k]; };
        El.prototype.getAttribute = function (k) { return this._attrs[k] == null ? null : this._attrs[k]; };
        El.prototype.hasAttribute = function (k) { return this._attrs[k] != null; };
        El.prototype.appendChild = function (c) { this._children.push(c); return c; };
        El.prototype.querySelector = function (sel) { return this._map[sel] || null; };
        El.prototype.querySelectorAll = function (sel) { return this._all[sel] || []; };
        El.prototype.addEventListener = function (type, fn) {
          (this._handlers[type] = this._handlers[type] || []).push(fn);
        };
        El.prototype.dispatch = function (type) {
          var list = this._handlers[type] || [];
          for (var i = 0; i < list.length; i++) { list[i]({}); }
        };
        El.prototype.focus = function () { this._focused = true; };

        function makeStorage(initial) {
          var m = Object.assign({}, initial || {});
          return {
            getItem: function (k) { return m[k] == null ? null : m[k]; },
            setItem: function (k, v) { m[k] = String(v); },
            removeItem: function (k) { delete m[k]; },
            _map: m,
          };
        }

        function makeRuntime(registered) {
          return {
            register: function (name, mod) { registered.push({ name: name, mod: mod }); },
            enhance: function (root) {
              for (var i = 0; i < registered.length; i++) {
                var mod = registered[i].mod;
                var nodes = root && root.querySelectorAll ? root.querySelectorAll(mod.selector) : [];
                for (var j = 0; j < nodes.length; j++) { mod.enhance(nodes[j]); }
              }
            },
          };
        }

        // One login-form block instance: root > form > email input, plus the hidden error node.
        function makeLoginRoot() {
          var email = new El({ name: 'email' });
          var form = new El({});
          form._map = { 'input[name="email"]': email };
          var err = new El({ 'data-account-error': 'credentials', hidden: '', role: 'alert' });
          var root = new El({ 'data-account-form': 'login' });
          root._map = { form: form, '[data-account-error="credentials"]': err };
          return { root: root, form: form, email: email, err: err };
        }

        function makeWin(opts) {
          var docEl = new El({});
          docEl._all = opts.all || {};
          var doc = {
            readyState: 'complete',
            documentElement: docEl,
            addEventListener: function () {},
            createElement: function () { return new El({}); },
            querySelectorAll: function (sel) { return docEl._all[sel] || []; },
          };
          var historyCalls = [];
          var base = { host: 'shop.test', pathname: '/signin', search: '', hash: '' };
          var win = {
            location: Object.assign(base, opts.location || {}),
            sessionStorage: opts.storage,
            history: { replaceState: function (s, t, url) { historyCalls.push(url); } },
            ThalloRuntime: null,
          };
          return { win: win, doc: doc, docEl: docEl, historyCalls: historyCalls };
        }

        function evalForms(win, doc) {
          var fn = new Function('window', 'document', SRC);
          fn(win, doc);
        }

        function flush() { return new Promise(function (r) { setTimeout(r, 0); }); }
        function returnToOf(form) {
          for (var i = 0; i < form._children.length; i++) {
            if (form._children[i].getAttribute('name') === 'return_to') { return form._children[i]; }
          }
          return null;
        }
        JS;
    }

    private function injectionHarness(string $source): string
    {
        return $this->harnessPrelude($source) . "\n\n" . <<<'JS'
        (async function () {
          // Scenario: registration + catch-up enhance injects return_to into the LOGIN form only;
          // a second evaluation is inert; submit stashes ONLY the email under host+path+form.
          var instance = makeLoginRoot();
          var registerForm = new El({});
          var store = makeStorage({});
          var ctx = makeWin({ storage: store, all: { '[data-account-form="login"]': [instance.root] } });
          var registered = [];
          ctx.win.ThalloRuntime = makeRuntime(registered);

          evalForms(ctx.win, ctx.doc);
          assert(registered.length === 1, 'one runtime module registered');
          assert(registered[0].name === 'account-forms', 'module name');
          await flush();

          var injected = returnToOf(instance.form);
          assert(injected !== null, 'return_to injected into the login form');
          assert(injected.getAttribute('type') === 'hidden', 'return_to is hidden');
          assert(injected.getAttribute('value') === '/signin', 'return_to carries the pathname');
          assert(returnToOf(registerForm) === null, 'a plain (register/forgot) form receives nothing');

          // Exactly-once: a second script evaluation registers nothing new.
          evalForms(ctx.win, ctx.doc);
          await flush();
          assert(registered.length === 1, 'second evaluation is inert');
          assert(instance.form._children.length === 1, 'no duplicate return_to');

          // Submit stashes only the email, keyed by host + pathname + form.
          instance.email.value = 'shopper@example.test';
          instance.form.dispatch('submit');
          var raw = store.getItem('thallo:account:refill:shop.test:/signin:login');
          assert(raw !== null, 'submit stashed the email');
          var parsed = JSON.parse(raw);
          assert(parsed.email === 'shopper@example.test', 'stash carries the email');
          assert(typeof parsed.t === 'number', 'stash carries a timestamp');
          assert(!('password' in parsed), 'stash never carries a password');

          console.log('ALL_PASS');
        })().catch(function (e) { fail(String(e && e.stack || e)); });
        JS;
    }

    private function errorReturnHarness(string $source): string
    {
        return $this->harnessPrelude($source) . "\n\n" . <<<'JS'
        (async function () {
          // Scenario: ?account_error=credentials reveals the node, focuses it, refills the email
          // (consume-once) and strips the param — keeping unrelated query parts.
          var key = 'thallo:account:refill:shop.test:/signin:login';
          var store = makeStorage({});
          store.setItem(key, JSON.stringify({ email: 'shopper@example.test', t: Date.now() }));
          var instance = makeLoginRoot();
          var ctx = makeWin({
            storage: store,
            location: { search: '?keep=1&account_error=credentials' },
            all: { '[data-account-form="login"]': [instance.root] },
          });
          var registered = [];
          ctx.win.ThalloRuntime = makeRuntime(registered);

          evalForms(ctx.win, ctx.doc);
          await flush();

          assert(!instance.err.hasAttribute('hidden'), 'error node revealed');
          assert(instance.err._focused === true, 'focus moved to the error node');
          assert(instance.email.value === 'shopper@example.test', 'email refilled from the stash');
          assert(store.getItem(key) === null, 'stash consumed on refill');
          assert(ctx.historyCalls.length === 1, 'replaceState called once');
          assert(ctx.historyCalls[0] === '/signin?keep=1', 'only account_error stripped: ' + ctx.historyCalls[0]);

          // A later error return finds no stash: revealed again, but nothing to refill.
          instance.email.value = '';
          var second = makeLoginRoot();
          var ctx2 = makeWin({
            storage: store,
            location: { search: '?account_error=credentials' },
            all: { '[data-account-form="login"]': [second.root] },
          });
          ctx2.win.ThalloRuntime = makeRuntime([]);
          evalForms(ctx2.win, ctx2.doc);
          await flush();
          assert(!second.err.hasAttribute('hidden'), 'error node revealed on the second return');
          assert(second.email.value === '', 'consumed stash refills nothing the second time');
          assert(ctx2.historyCalls[0] === '/signin', 'param stripped to the bare pathname');

          console.log('ALL_PASS');
        })().catch(function (e) { fail(String(e && e.stack || e)); });
        JS;
    }

    private function inertCasesHarness(string $source): string
    {
        return $this->harnessPrelude($source) . "\n\n" . <<<'JS'
        (async function () {
          // Scenario A: an UNKNOWN code reveals nothing — and still strips the param.
          var a = makeLoginRoot();
          var ctxA = makeWin({
            storage: makeStorage({}),
            location: { search: '?account_error=surprise' },
            all: { '[data-account-form="login"]': [a.root] },
          });
          ctxA.win.ThalloRuntime = makeRuntime([]);
          evalForms(ctxA.win, ctxA.doc);
          await flush();
          assert(a.err.hasAttribute('hidden'), 'unknown code reveals nothing');
          assert(ctxA.historyCalls.length === 1, 'unknown code still strips the param');

          // Scenario B: an EXPIRED stash refills nothing (and is purged on read).
          var key = 'thallo:account:refill:shop.test:/signin:login';
          var storeB = makeStorage({});
          storeB.setItem(key, JSON.stringify({ email: 'old@example.test', t: Date.now() - (6 * 60 * 1000) }));
          var b = makeLoginRoot();
          var ctxB = makeWin({
            storage: storeB,
            location: { search: '?account_error=credentials' },
            all: { '[data-account-form="login"]': [b.root] },
          });
          ctxB.win.ThalloRuntime = makeRuntime([]);
          evalForms(ctxB.win, ctxB.doc);
          await flush();
          assert(b.email.value === '', 'expired stash refills nothing');
          assert(storeB.getItem(key) === null, 'expired stash purged');
          assert(!b.err.hasAttribute('hidden'), 'error still revealed (only the refill expired)');

          // Scenario C: another custom login page on the SAME host cannot read /signin's stash.
          var storeC = makeStorage({});
          storeC.setItem(key, JSON.stringify({ email: 'mine@example.test', t: Date.now() }));
          var c = makeLoginRoot();
          var ctxC = makeWin({
            storage: storeC,
            location: { pathname: '/other-login', search: '?account_error=credentials' },
            all: { '[data-account-form="login"]': [c.root] },
          });
          ctxC.win.ThalloRuntime = makeRuntime([]);
          evalForms(ctxC.win, ctxC.doc);
          await flush();
          assert(c.email.value === '', 'a different page cannot refill another page\'s email');
          assert(storeC.getItem(key) !== null, 'the /signin stash stays unconsumed');

          console.log('ALL_PASS');
        })().catch(function (e) { fail(String(e && e.stack || e)); });
        JS;
    }

    /** @param array<string,mixed> $data */
    private function renderBlock(string $type, array $data): string
    {
        $env = $this->container()->get(\Thallo\Render\TwigFactory::class)->environment();

        /** @var \Thallo\Render\RenderContextExtension $extension */
        $extension = $this->container()->get(\Thallo\Render\RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setBlockAnnotations(false);
        $extension->setLocale('en');

        return $extension->blocks($env, ['entry' => null, 'site' => []], [
            ['id' => 'formblock0001', 'type' => $type, 'data' => $data],
        ]);
    }
}
