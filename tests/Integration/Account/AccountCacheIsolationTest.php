<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Symfony\Component\HttpFoundation\Request;

/**
 * The account chrome's cache-safety boundary: per-visitor identity leaves the server ONLY through
 * the private `/_account/session` endpoint, never through a cacheable page. The `auth-state` block
 * renders BOTH branches server-side (public-account-surface plan Task 2) so the shared page cache
 * stores it byte-identically for every visitor; account.js hydrates it and fails closed, and the
 * asset alias survives a deploy. (The `account-link` block this replaced was physically retired
 * pre-launch; see `Thallo\Account\Console\RetireAccountLinkCommand`. The capability still gates
 * `/_account/*`.)
 */
final class AccountCacheIsolationTest extends AppTestCase
{
    use AccountHttpHelpers;

    protected function tearDown(): void
    {
        $this->cleanupAccountArtifacts();
        parent::tearDown();
    }

    // --- The private session endpoint --------------------------------------------------------

    public function testTheSessionEndpointIsPrivateAndNeverCached(): void
    {
        $response = $this->get('/_account/session');

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function testTheSessionEndpointReportsAnonymousWithoutACookie(): void
    {
        $body = json_decode((string) $this->get('/_account/session')->getContent(), true);

        self::assertFalse($body['data']['authenticated']);
        // Minimal session response (Global Constraints): `{ authenticated: bool }` only —
        // no display_name, no links.
        self::assertSame(['authenticated'], array_keys($body['data']));
    }

    public function testAnAuthenticatedVisitorGetsAuthenticatedTrueOnlyThroughHydration(): void
    {
        $cookies = $this->signInAs('hydrate@example.test');

        $body = json_decode((string) $this->get('/_account/session', $cookies)->getContent(), true);

        self::assertTrue($body['data']['authenticated']);
        self::assertSame(['authenticated'], array_keys($body['data']));
    }

    public function testACacheableCatalogPageIsByteIdenticalForSignedInAndAnonymousVisitors(): void
    {
        // The poison-identity check: a cached page is shared by everyone, so an authenticated
        // request must leave no trace in it — no name, and no Set-Cookie for a cache to store.
        $cookies = $this->signInAs('poison@example.test');

        $anonymous = $this->get('/');
        $authenticated = $this->get('/', $cookies);

        self::assertSame($anonymous->getContent(), $authenticated->getContent());
        self::assertSame([], $authenticated->headers->getCookies());
        self::assertStringNotContainsString('poison@example.test', (string) $authenticated->getContent());
    }

    public function testCacheableRoutesDoNotRunTheSessionCookieMiddleware(): void
    {
        // Structural: if a cacheable route ever gains session_cookie, the response varies by visitor
        // while the cache key does not. Matched by PREFIX, because `session_cookie` and
        // `session_cookie:optional` are the same middleware.
        $cacheable = $this->cacheableRouteMiddleware();
        self::assertNotSame([], $cacheable, 'no cacheable routes found — the guard would be vacuous');

        foreach ($cacheable as $path => $middleware) {
            foreach ($middleware as $entry) {
                self::assertFalse(
                    str_starts_with($entry, 'session_cookie'),
                    "{$path} must not run session_cookie (found '{$entry}')",
                );
            }
        }
    }

    public function testTheSessionEndpointPairsTheCookieAdapterWithOptionalAuth(): void
    {
        // session_cookie only injects the Authorization header; AuthMiddleware sets `user`. Without
        // the pair, hydration reports every visitor anonymous and the header never signs in.
        $middleware = $this->routeMiddlewareFor('GET', '/_account/session');

        self::assertContains('session_cookie:optional', $middleware);
        self::assertContains('auth:optional', $middleware);
    }

    // --- Asset delivery ----------------------------------------------------------------------

    public function testTheAliasRedirectsUncachedAndOnlyTheFingerprintIsImmutable(): void
    {
        // Templates emit the alias, so it must survive a deploy: a cached page holding an old
        // fingerprint would otherwise request a permanent 404 and never hydrate again.
        $alias = $this->get('/_account/assets/account.js');
        self::assertSame(302, $alias->getStatusCode());
        self::assertStringContainsString('no-store', (string) $alias->headers->get('Cache-Control'));

        $hit = $this->get((string) $alias->headers->get('Location'));
        self::assertSame(200, $hit->getStatusCode());
        self::assertStringContainsString('javascript', (string) $hit->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $hit->headers->get('Cache-Control'));

        // A stale or invented fingerprint must 404 rather than serve current bytes under an old hash.
        self::assertSame(404, $this->get('/_account/assets/account-deadbeefdead.js')->getStatusCode());
    }

    public function testTheAssetRegistersExactlyOneRuntimeModuleAndIsInertOnASecondEvaluation(): void
    {
        $source = $this->accountJsSource();
        self::assertStringContainsString("register('auth-state'", $source);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate account.js');
        }
        $this->runNodeHarness($node, $this->accountRuntimeHarness($source), 'account_runtime');
    }

    public function testHydrationFailsClosedOnAnErrorResponse(): void
    {
        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate account.js');
        }
        $this->runNodeHarness($node, $this->accountFailClosedHarness($this->accountJsSource()), 'account_fail_closed');
    }

    public function testHydrationCoalescesOneFetchAndSwapsBothAttributesAcrossMultipleInstances(): void
    {
        // Global Constraints: "one session request per document" — a page with the block
        // placed in BOTH header and footer must still fire exactly one /_account/session
        // fetch, and every instance reflects the same authenticated result.
        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate account.js');
        }
        $this->runNodeHarness(
            $node,
            $this->accountCoalescingHarness($this->accountJsSource()),
            'account_coalescing',
        );
    }

    // --- The auth-state block's server-rendered defaults --------------------------------------

    public function testAuthStateBlockRendersBothSlotsWithDefaultsAndLinksAccountJs(): void
    {
        $env = $this->container()->get(\Thallo\Render\TwigFactory::class)->environment();
        self::assertTrue($env->getLoader()->exists('blocks/auth-state.twig'));

        /** @var \Thallo\Render\RenderContextExtension $extension */
        $extension = $this->container()->get(\Thallo\Render\RenderContextExtension::class);
        $extension->resetPerRenderState();
        $extension->setBlockAnnotations(false);
        $extension->setLocale('en');
        $html = $extension->blocks($env, ['entry' => null, 'site' => []], [
            ['id' => 'authstateb01', 'type' => 'auth-state', 'data' => ['signed_out' => [], 'signed_in' => []]],
        ]);

        self::assertStringContainsString('data-auth-state', $html);
        // Fail-closed defaults: anonymous visible (no hidden/inert), authenticated starts
        // hidden+inert — the UA [hidden] rule governs visibility, no `display` CSS involved.
        self::assertStringContainsString('<div data-auth-when="anonymous">', $html);
        self::assertStringContainsString('<div data-auth-when="authenticated" hidden inert>', $html);
        self::assertStringContainsString('src="/_account/assets/account.js" defer', $html);
    }

    // --- Capability flip across separate boots -----------------------------------------------

    public function testACapabilityOffBootStill404sTheSessionEndpointAndFallsBackForAuthStateChrome(): void
    {
        // Control on the ENABLED (primary) boot: the auth-state template IS in the chain.
        $enabledEnv = $this->container()->get(\Thallo\Render\TwigFactory::class)->environment();
        self::assertTrue(
            $enabledEnv->getLoader()->exists('blocks/auth-state.twig'),
            'sanity: enabled boot serves the account pack templates',
        );

        $off = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.accounts' => false],
        ]);

        try {
            $container = $off->getContainer();

            // The pack template dir left the Twig loader entirely — no chrome renders.
            $env = $container->get(\Thallo\Render\TwigFactory::class)->environment();
            self::assertFalse(
                $env->getLoader()->exists('blocks/auth-state.twig'),
                'auth-state must not resolve while thallo.accounts is disabled',
            );

            /** @var \Thallo\Render\RenderContextExtension $extension */
            $extension = $container->get(\Thallo\Render\RenderContextExtension::class);
            $extension->resetPerRenderState();
            $extension->setBlockAnnotations(false);
            $extension->setLocale('en');
            $html = $extension->blocks($env, ['entry' => null, 'site' => []], [
                ['id' => 'authstateb02', 'type' => 'auth-state', 'data' => ['signed_out' => [], 'signed_in' => []]],
            ]);
            self::assertStringNotContainsString('data-auth-state', $html);
            self::assertStringNotContainsString('account.js', $html);
            self::assertMatchesRegularExpression(
                '/no template for block "auth-state"|Missing block template: blocks\/auth-state\.twig/',
                $html,
                'a stored auth-state block falls to blocks()\' ordinary missing-template fallback',
            );

            $response = (new Application($off))->handle(
                Request::create('/_account/session', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
            );
            self::assertSame(404, $response->getStatusCode());
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    // --- Helpers -----------------------------------------------------------------------------

    /** @return array<string, list<string>> cacheable "METHOD /path" => middleware list */
    private function cacheableRouteMiddleware(): array
    {
        $result = [];
        foreach ($this->router()->getAllRoutes() as $route) {
            $middleware = array_map('strval', (array) ($route['middleware'] ?? []));
            foreach ($middleware as $entry) {
                if (str_contains($entry, 'ShopPageCache') || str_contains($entry, 'RenderPageCache')) {
                    $result[$route['method'] . ' ' . $route['path']] = $middleware;
                    break;
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function routeMiddlewareFor(string $method, string $path): array
    {
        $route = $this->findRoute($method, $path);

        return $route === null ? [] : array_map('strval', (array) ($route['middleware'] ?? []));
    }

    private function accountJsSource(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/packages/thallo-account/assets/account.js');
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
        $file = sys_get_temp_dir() . '/thallo_account_js_' . $suffix . '_' . getmypid() . '.mjs';
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

    /** The stub DOM + runtime + fetch shared by both scenarios. */
    private function harnessPrelude(string $source): string
    {
        $src = json_encode($source, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        var SRC = {$src};
        function fail(m) { console.error('FAIL: ' + m); process.exit(1); }
        function assert(c, m) { if (!c) { fail(m); } }

        function El(attrs) {
          this._attrs = Object.assign({}, attrs || {});
          this._map = {}; this._all = {}; this._text = ''; this._children = [];
        }
        El.prototype.setAttribute = function (k, v) { this._attrs[k] = String(v); };
        El.prototype.removeAttribute = function (k) { delete this._attrs[k]; };
        El.prototype.getAttribute = function (k) { return this._attrs[k] == null ? null : this._attrs[k]; };
        El.prototype.hasAttribute = function (k) { return this._attrs[k] != null; };
        El.prototype.appendChild = function (c) { this._children.push(c); return c; };
        El.prototype.querySelector = function (sel) { return this._map[sel] || null; };
        El.prototype.querySelectorAll = function (sel) { return this._all[sel] || []; };
        Object.defineProperty(El.prototype, 'textContent', {
          get: function () { return this._text; },
          set: function (v) { this._text = String(v); },
        });

        function makeFetch(behaviors, calls) {
          return function (url, opts) {
            calls.push({ url: url, opts: opts });
            var b = behaviors.shift();
            if (!b) { return Promise.reject(new Error('no fetch behavior queued for ' + url)); }
            if (b.reject) { return Promise.reject(new Error('network error')); }
            return Promise.resolve({
              ok: b.ok, status: b.status, json: function () { return Promise.resolve(b.data); },
            });
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

        function evalAccount(win, doc, fetchFn) {
          var fn = new Function('window', 'document', 'fetch', SRC);
          fn(win, doc, fetchFn);
        }

        function flush() { return new Promise(function (r) { setTimeout(r, 0); }); }
        JS;
    }

    /** JS helper (embedded in each harness below) building one auth-state instance's stub DOM. */
    private function authStateFixtureJs(): string
    {
        return <<<'JS'
        function makeAuthStateRoot() {
          var anon = new El({});
          var authed = new El({ hidden: '', inert: '' });
          var root = new El({ 'data-auth-state': '' });
          root._map = {
            '[data-auth-when="anonymous"]': anon,
            '[data-auth-when="authenticated"]': authed,
          };
          return { root: root, anon: anon, authed: authed };
        }
        JS;
    }

    private function accountRuntimeHarness(string $source): string
    {
        return $this->harnessPrelude($source) . "\n\n" . $this->authStateFixtureJs() . "\n\n" . <<<'JS'
        var registered = [];
        var calls = [];
        var instance = makeAuthStateRoot();
        var docEl = new El({});
        docEl._all = { '[data-auth-state]': [instance.root] };
        var doc = {
          readyState: 'complete',
          documentElement: docEl,
          addEventListener: function () {},
          createElement: function () { return new El({}); },
          querySelectorAll: function (sel) { return docEl._all[sel] || []; },
        };
        var win = { thalloAccount: undefined };
        win.ThalloRuntime = makeRuntime(registered);
        var fetchFn = makeFetch([
          { ok: true, status: 200, data: { data: { authenticated: false } } },
        ], calls);

        evalAccount(win, doc, fetchFn); // first: registers + one catch-up enhance -> one fetch
        evalAccount(win, doc, fetchFn); // second: the window guard makes it inert

        flush().then(function () {
          assert(registered.length === 1, 'expected exactly one registration, got ' + registered.length);
          assert(registered[0].name === 'auth-state', 'expected auth-state, got ' + registered[0].name);
          assert(calls.length === 1, 'expected exactly one fetch, got ' + calls.length);
          console.log('ALL_PASS');
        }).catch(function (e) { fail(String(e && e.stack || e)); });
        JS;
    }

    private function accountFailClosedHarness(string $source): string
    {
        return $this->harnessPrelude($source) . "\n\n" . $this->authStateFixtureJs() . "\n\n" . <<<'JS'
        var registered = [];
        var calls = [];
        var instance = makeAuthStateRoot();
        var docEl = new El({});
        docEl._all = { '[data-auth-state]': [instance.root] };
        var doc = {
          readyState: 'complete',
          documentElement: docEl,
          addEventListener: function () {},
          createElement: function () { return new El({}); },
          querySelectorAll: function (sel) { return docEl._all[sel] || []; },
        };
        var win = { thalloAccount: undefined };
        win.ThalloRuntime = makeRuntime(registered);
        var fetchFn = makeFetch([{ ok: false, status: 500, data: null }], calls);

        evalAccount(win, doc, fetchFn);

        flush().then(function () { return flush(); }).then(function () {
          // Fail closed: a 500 leaves BOTH branches exactly as server-rendered.
          assert(!instance.anon.hasAttribute('hidden'), 'anonymous branch must stay visible on a failed hydration');
          assert(!instance.anon.hasAttribute('inert'), 'anonymous branch must stay non-inert on a failed hydration');
          assert(instance.authed.hasAttribute('hidden'), 'authenticated branch must stay hidden on a failed hydration');
          assert(instance.authed.hasAttribute('inert'), 'authenticated branch must stay inert on a failed hydration');
          assert(calls.length === 1, 'expected exactly one fetch, got ' + calls.length);
          console.log('ALL_PASS');
        }).catch(function (e) { fail(String(e && e.stack || e)); });
        JS;
    }

    private function accountCoalescingHarness(string $source): string
    {
        return $this->harnessPrelude($source) . "\n\n" . $this->authStateFixtureJs() . "\n\n" . <<<'JS'
        var registered = [];
        var calls = [];
        var a = makeAuthStateRoot();
        var b = makeAuthStateRoot();
        var docEl = new El({});
        docEl._all = { '[data-auth-state]': [a.root, b.root] };
        var doc = {
          readyState: 'complete',
          documentElement: docEl,
          addEventListener: function () {},
          createElement: function () { return new El({}); },
          querySelectorAll: function (sel) { return docEl._all[sel] || []; },
        };
        var win = { thalloAccount: undefined };
        win.ThalloRuntime = makeRuntime(registered);
        // Exactly ONE behavior queued: a second fetch call would hit "no fetch behavior
        // queued" and surface as an uncaught rejection — the strongest possible proof that
        // two instances share a single request.
        var fetchFn = makeFetch([
          { ok: true, status: 200, data: { data: { authenticated: true } } },
        ], calls);

        evalAccount(win, doc, fetchFn);

        flush().then(function () { return flush(); }).then(function () {
          assert(calls.length === 1, 'expected exactly one fetch for two instances, got ' + calls.length);
          [a, b].forEach(function (inst, i) {
            assert(inst.anon.hasAttribute('hidden'), 'instance ' + i + ': anonymous must be hidden once authenticated');
            assert(inst.anon.hasAttribute('inert'), 'instance ' + i + ': anonymous must be inert once authenticated');
            assert(!inst.authed.hasAttribute('hidden'), 'instance ' + i + ': authenticated must be revealed');
            assert(!inst.authed.hasAttribute('inert'), 'instance ' + i + ': authenticated must be revealed (inert)');
          });
          console.log('ALL_PASS');
        }).catch(function (e) { fail(String(e && e.stack || e)); });
        JS;
    }
}
