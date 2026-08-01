# Thallo Account Chrome and Hydration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An operator can place an account link in the header or footer, and it shows the signed-in visitor's name — without any per-visitor state ever entering a shared page cache.

**Architecture:** The block renders a universal, signed-out shell that the page cache stores safely; a `ThalloRuntime` module hydrates it from a `private, no-store` session endpoint. The endpoint is the single place per-visitor identity leaves the server.

**Prerequisite:** `2026-07-29-thallo-account-foundation.md` must be complete — this plan needs the `thallo-account` pack, its capability and a working sign-in.

**Tech Stack:** PHP 8.3+, glueful/framework ^1.74.0 (the floor the foundation plan sets), Twig, ThalloRuntime, PHPUnit 10, node for the runtime harness.

## Global Constraints

- **Cacheable routes never run `session_cookie` and never emit `Set-Cookie`.** `ShopPageCache` and `RenderPageCache` never parse cookies, so a cached page is shared by every visitor: a server-rendered name would be served to the next stranger.
- **The block shell is byte-identical for everyone.** Identity arrives only through hydration.
- **`GET /_account/session` carries `['session_cookie:optional', 'auth:optional']`** — both. The cookie adapter publishes no identity; `AuthMiddleware` sets `user`. With the adapter alone the block stays permanently signed out.
- **Hydration fails closed.** Any non-200, any envelope without `data`, any fetch rejection leaves the signed-out shell exactly as rendered — never a half-painted menu.
- **Block templates resolve as `blocks/{type}.twig`** against the theme chain. Starter block definitions carry no custom-template field.
- **Templates emit the stable asset alias, never a fingerprint.** Only the exact fingerprinted URL is `immutable`.
- **Quality gates per commit:** `vendor/bin/phpunit`, `vendor/bin/phpcs --standard=PSR12` on touched PHP.
- **A capability flip must purge cached pages, in both directions.** A page cached while
  `thallo.accounts` was enabled keeps the account shell and its script tag while the backing routes
  start returning 404 — permanently broken chrome on a cached page. The capability is deploy-time
  config, so there is **no flip event to listen for**: this is a boot-time persisted-state
  reconciler with its **own** untagged marker (never Commerce's), invoked **outside** the capability
  gate so it still fires on the boot where the capability turned off. It invalidates
  `thallo:render:page` **and** `thallo:shop:catalog` — Commerce's own reconciler purges only the
  first, which is a gap for account chrome because the header also renders on shop pages, which
  live in the catalog cache — and purges the edge only when the edge is enabled.
- **Commit cadence:** one commit at the end of Task 3. Never push. No AI/assistant attribution anywhere.

---

## Task 1: Block type, palette entry and the universal shell

**Files:**
- Create: `packages/thallo-account/src/Blocks/AccountBlockTypesContributor.php`, `templates/blocks/account-link.twig`
- Modify: `packages/thallo-account/src/AccountServiceProvider.php`, `app/Content/Regions/RegionDefinitions.php`
- Test: `tests/Integration/Account/AccountCacheIsolationTest.php`

**Interfaces:**
- Consumes: the `thallo-account` pack and its capability from the foundation plan.
- Produces: the `account-link` starter block type, its header/footer palette entries, and `templates/blocks/account-link.twig` — a universal signed-out shell carrying a `data-account-link` hook. The asset is Task 2's; the session endpoint is Task 3's.

**Why hydration rather than server-rendered identity:** `ShopPageCache` and `RenderPageCache` never parse cookies, so a cached page is shared by every visitor. A server-rendered "Hi, Ada" would be served to the next stranger.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Tests\Support\AppTestCase;

final class AccountCacheIsolationTest extends AppTestCase
{
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
        self::assertNull($body['data']['display_name']);
    }

    public function testAnAuthenticatedVisitorSeesTheirOwnNameOnlyThroughHydration(): void
    {
        $cookies = $this->signInAs('hydrate@example.test');

        $body = json_decode((string) $this->get('/_account/session', cookies: $cookies)->getContent(), true);

        self::assertTrue($body['data']['authenticated']);
        self::assertNotNull($body['data']['display_name']);
    }

    public function testACacheableCatalogPageIsByteIdenticalForSignedInAndAnonymousVisitors(): void
    {
        // The poison-identity check: a cached page is shared by everyone, so an authenticated
        // request must leave no trace in it — no name, and no Set-Cookie for a cache to store.
        $cookies = $this->signInAs('poison@example.test');

        $anonymous = $this->get('/');
        $authenticated = $this->get('/', cookies: $cookies);

        self::assertSame($anonymous->getContent(), $authenticated->getContent());
        self::assertSame([], $authenticated->headers->getCookies());
        self::assertStringNotContainsString('poison@example.test', (string) $authenticated->getContent());
    }

    public function testCacheableRoutesDoNotRunTheSessionCookieMiddleware(): void
    {
        // Structural: if a cacheable route ever gains session_cookie, the response varies by
        // visitor while the cache key does not. Matched by PREFIX, because `session_cookie` and
        // `session_cookie:optional` are the same middleware and an exact-match assertion would
        // wave the parameterised form through.
        foreach ($this->cacheableRouteMiddleware() as $path => $middleware) {
            foreach ($middleware as $entry) {
                self::assertFalse(
                    str_starts_with((string) $entry, 'session_cookie'),
                    "{$path} must not run session_cookie (found '{$entry}')"
                );
            }
        }
    }

    public function testTheSessionEndpointPairsTheCookieAdapterWithOptionalAuth(): void
    {
        // session_cookie only injects the Authorization header; AuthMiddleware is what sets the
        // `user` attribute this endpoint reads. Without the pair, hydration reports every
        // visitor anonymous and the header never signs in.
        $middleware = $this->routeMiddlewareFor('GET', '/_account/session');

        self::assertContains('session_cookie:optional', $middleware);
        self::assertContains('auth:optional', $middleware);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Account/AccountCacheIsolationTest.php`
Expected: FAIL — `/_account/session` returns 404.

```php
    public function show(Request $request): Response
    {
        $user = $request->attributes->get('user');
        $authenticated = is_array($user) && ($user['uuid'] ?? '') !== '';

        $response = Response::success([
            'authenticated' => $authenticated,
            'display_name' => $authenticated ? $this->displayName($user) : null,
            'links' => $authenticated ? $this->navigation->items() : [],
        ], 'Session state');

        // Never shared: this response is the ONE place per-visitor identity leaves the server.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
```

Route it with **`['session_cookie:optional', 'auth:optional']`** — both, in that order.
`SessionCookieMiddleware` only adapts the cookie into an `Authorization` header and marks
`auth_transport`; it deliberately populates no identity, because `AuthMiddleware` owns `user`
and `auth.user`. With `session_cookie:optional` alone this endpoint would read a `user`
attribute nobody set and report every visitor as anonymous — the header block would then stay
permanently signed out no matter who is looking at it. `auth:optional` (not `auth`) is what lets
a lapsed cookie degrade to anonymous instead of 401-ing a page's chrome.

- [ ] **Step 3: Contribute the block type and its palette entry**

A block template alone renders nothing: an operator can only place a block that is a registered
**starter block type**, and a region only offers types listed in its **palette**. Both are needed
or the header block is unreachable through the UI.

1. `src/Blocks/AccountBlockTypesContributor.php` — declare the `account-link` type (id, label,
   schema), following `ShopBlockTypesContributor`'s interface exactly. **Do not declare a
   template path**: `RenderContextExtension` always resolves `blocks/{type}.twig` against the
   theme chain, and starter block definitions carry no custom-template field. The file must
   therefore be `templates/blocks/account-link.twig` — one directory deeper, or under any other
   name, and the block renders as a logged "no template at blocks/account-link.twig" miss.
2. Register it idempotently in `boot()` inside the capability gate, mirroring
   `CommerceIntegrationServiceProvider::registerShopBlockTypeContributor()` — including its
   already-registered short-circuit, so a second boot does not stack contributors.
3. Add `'account-link'` to the header and footer palettes in
   `app/Content/Regions/RegionDefinitions.php`, beside the commerce-owned `mini-cart` and
   `wishlist-link` entries, with a comment naming `thallo.accounts` as its owning capability —
   matching how those two are annotated.

- [ ] **Step 4: Write the block shell and its runtime module**

`templates/blocks/account-link.twig` renders a **universal** shell: a "Sign in" link, plus
an account menu that ships `hidden` and empty, carrying a `data-account-link` hook. That shell is
what the shared page cache stores, so it must be byte-identical for every visitor.

`assets/account.js` hydrates it, and must be a `ThalloRuntime` consumer rather than a
`DOMContentLoaded` script — on runtime pages the core drives enhancement, and a listener-based
script would never run:

```js
  function enhanceAccountLink(root) {
    fetch('/_account/session', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) { return res.ok && res.status === 200 ? res.json() : null; })
      .then(function (payload) {
        var data = payload && payload.data;
        // Fail closed: on any error the shell stays as rendered (signed out), never a
        // half-populated menu. Anonymous is the safe reading of "we could not tell".
        if (!data || data.authenticated !== true) { return; }
        paintAccountMenu(root, data.display_name, data.links || []);
      })
      .catch(function () { /* leave the signed-out shell in place */ });
  }

  if (window.ThalloRuntime) {
    window.ThalloRuntime.register('account-link', {
      selector: '[data-account-link]',
      enhance: enhanceAccountLink,
    });
  }
```

---

## Task 2: Asset delivery

**Files:**
- Create: `packages/thallo-account/src/Assets/AccountAssetMap.php`, `src/Http/AccountAssetController.php`, `assets/account.js`
- Modify: `packages/thallo-account/routes.php`, `src/AccountServiceProvider.php`

**Interfaces:**
- Consumes: the block shell from Task 1.
- Produces: `GET /_account/assets/account.js` (stable alias, 302, `no-store`) and `GET /_account/assets/account-<hash>.js` (immutable). No Twig helper — the alias is a constant the block template hardcodes.

- [ ] **Step 1: Build the asset delivery path**

"Serve it like `shop.js`" is four concrete pieces, not a instruction. Mirror
`Thallo\Commerce\Shop\ShopAssetMap` and the `/_shop/assets/{file}` route:

1. `src/Assets/AccountAssetMap.php` — content-hash fingerprinting, `fingerprintedName('account.js')`
   returning `account-<hash>.js` or null when the file is missing.
2. `src/Http/AccountAssetController.php` — `serve(string $file)`: resolves against the map, sends
   the bytes with `Content-Type: application/javascript` and a long-lived immutable
   `Cache-Control` for a fingerprinted hit; **404 for an unknown or stale fingerprint**, because
   serving current bytes under an old hash would poison an immutable cache entry.
3. A DI binding for both in the pack provider, and the route
   `$router->get('/_account/assets/{file}', [AccountAssetController::class, 'serve']);`
   registered inside the capability gate.
3. A **stable logical alias** plus a redirect, mirroring how `shop.js` is delivered:
   `GET /_account/assets/account.js` responds `302` (uncached, `Cache-Control: no-store`) to the
   current fingerprinted URL, and only the exact fingerprinted path gets `immutable`. Templates
   emit the ALIAS, never the fingerprint directly.

   This is not a stylistic choice. A cached page holding yesterday's fingerprint would request a
   URL that now 404s — permanently, for as long as that page stays cached — so the block would
   silently stop hydrating after every deploy. The alias means a stale page still resolves; the
   fingerprint means a fresh one caches forever.

   The alias is a constant, so the block template hardcodes it — matching how Commerce's shop
   templates reference their asset route rather than introducing a Twig helper for a fixed
   string. Because the tag lives in the block template, a page without an `account-link` block
   emits no script at all and pays nothing:

   ```twig
   <script defer src="/_account/assets/account.js"></script>
   ```

Include the same exactly-once IIFE guard and catch-up enhance pass `shop.js` uses: a
defer-delivered script that registers after the runtime core has already booted must still
enhance what is on the page, or the block silently never hydrates.

- [ ] **Step 2: Test the delivery path and the module contract**

```php
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

        // A stale or invented fingerprint must 404 rather than serve current bytes under an old
        // hash — that would pin wrong content into an immutable cache entry.
        self::assertSame(404, $this->get('/_account/assets/account-deadbeefdead.js')->getStatusCode());
        self::assertSame(404, $this->get('/_account/assets/../../.env')->getStatusCode());
    }

    public function testTheScriptTagIsEmittedOnlyWhenTheBlockIsPresent(): void
    {
        $withBlock = $this->renderRegionWith(['account-link']);
        $withoutBlock = $this->renderRegionWith([]);

        // The alias, not a fingerprint — that is what makes a cached page survive a deploy.
        self::assertStringContainsString('/_account/assets/account.js', $withBlock);
        self::assertStringNotContainsString('/_account/assets/', $withoutBlock);
    }

    public function testTheAssetRegistersExactlyOneRuntimeModuleAndIsInertOnASecondEvaluation(): void
    {
        // Mirrors ShopJsRuntimeTest: evaluate the real file under node with a stub runtime,
        // assert it registers 'account-link' once, and that a second evaluation adds no second
        // registration and no second fetch.
        $source = file_get_contents(dirname(__DIR__, 3) . '/packages/thallo-account/assets/account.js');
        self::assertStringContainsString("register('account-link'", (string) $source);

        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate account.js');
        }
        $this->runNodeHarness($node, $this->accountRuntimeHarness((string) $source), 'account_runtime');
    }

    public function testHydrationFailsClosedOnAnErrorResponse(): void
    {
        // A 500 or an envelope without data must leave the signed-out shell exactly as rendered,
        // never a half-painted menu. Same discipline as the wishlist store's reconcile guard.
        $node = $this->findNode();
        if ($node === null) {
            self::markTestSkipped('node not available to evaluate account.js');
        }
        $this->runNodeHarness($node, $this->accountFailClosedHarness(), 'account_fail_closed');
    }
```

The harness helpers follow `tests/Integration/Commerce/ShopJsRuntimeTest.php` — reuse its prelude
(`Doc`, `el`, `makeFetch`, `loadShopJs` equivalents) rather than writing a new stub DOM.

---

## Task 3: The private session endpoint and cache-isolation gates

**Files:**
- Create: `packages/thallo-account/src/Http/AccountSessionController.php`
- Modify: `packages/thallo-account/routes.php`
- Create: `packages/thallo-account/src/CapabilityFlipPurge.php`
- Modify: `packages/thallo-account/src/AccountServiceProvider.php` (reconciler invocation, outside the gate)
- Test: `tests/Integration/Account/AccountCacheIsolationTest.php`,
  `tests/Integration/Account/AccountCapabilityFlipPurgeTest.php`

**Interfaces:**
- Consumes: the block shell from Task 1, the runtime module from Task 2, and `AccountNavigationRegistry` from the foundation plan.
- Produces: `GET /_account/session` with `['session_cookie:optional', 'auth:optional']` and `Cache-Control: private, no-store`; `CapabilityFlipPurge` and its boot-time invocation.

- [ ] **Step 1: Reconcile cached chrome on a capability flip**

**Not a listener — there is no flip event to listen for.** The capability is deploy-time config, so
`Thallo\Commerce\Shop\CapabilityFlipPurge` solves it as a **boot-time persisted-state
reconciler**: an untagged marker key records the last-seen state, and a mismatch on boot means the
state changed while nothing was running to observe it. Create the account-owned equivalent —
its own class with its own marker, never sharing Commerce's:

```php
// packages/thallo-account/src/CapabilityFlipPurge.php
final class CapabilityFlipPurge
{
    // Its OWN marker. Sharing Commerce's would make whichever pack booted second see no flip.
    public const MARKER_KEY = 'thallo:accounts:capability-state';

    public function __construct(
        private readonly CacheStore $cache,
        private readonly ?EdgeCacheInterface $edge = null,
    ) {
    }

    public function reconcile(bool $enabled): void
    {
        $current = $enabled ? 'on' : 'off';
        $last = $this->cache->get(self::MARKER_KEY);
        if ($last === $current) {
            return;
        }

        // Marker absent (first boot ever, or the local store was flushed) -> record, no purge:
        // a flushed cache holds nothing stale. The marker is UNTAGGED so a purge can never
        // delete its own bookkeeping.
        if (is_string($last)) {
            // BOTH local tags. Commerce's own reconciler purges only `thallo:render:page`, which
            // is a gap for account chrome: the header renders on shop pages too, and those are
            // held in the shop catalog cache. Purging one leaves the other serving a shell whose
            // routes now 404.
            $this->cache->invalidateTags(['thallo:render:page', 'thallo:shop:catalog']);

            if ($this->edge !== null && $this->edge->isEnabled()) {
                // Install-wide structural flip: the blunt primitive is the right one, and it is
                // skipped entirely when the edge is disabled.
                $this->edge->purgeAll();
            }
        }

        $this->cache->set(self::MARKER_KEY, $current);
    }
}
```

Invoke it in `boot()` **outside the capability gate** — it must fire precisely on the boot where
the capability turned *off*, which is a boot where the gated branch does not run. Soft-resolve
everything, mirroring `reconcileCapabilityState()`: no `CacheStore` binding (CLI or pre-migration
boots) means skip, and a skipped reconcile only defers the purge to the next fully-wired boot
because the marker is advanced solely by `reconcile()` itself.

- [ ] **Step 2: Test the reconciler in both directions**

**Toggling a capability inside a booted application proves nothing.** Routes, block contributors
and template paths are registered during `boot()`; flipping the registry afterwards neither
unregisters what is already there nor registers what is not. A test that renders a page after an
in-process toggle is asserting against the boot it already had.

Follow the split the commerce suite already uses. **Unit-test the reconciler directly** against a
real tagged cache, a real marker and a recording edge — that is where the flip logic lives:

```php
final class AccountCapabilityFlipPurgeTest extends AppTestCase
{
    private function reconciler(?RecordingEdgeCache $edge = null): CapabilityFlipPurge
    {
        return new CapabilityFlipPurge($this->cache(), $edge);
    }

    /** Seeds one entry under each tag the reconciler must clear. */
    private function seedTaggedPages(): void
    {
        $this->cache()->set('page:home', '<shell/>', 3600, ['thallo:render:page']);
        $this->cache()->set('page:shop', '<shell/>', 3600, ['thallo:shop:catalog']);
    }

    public function testAnAbsentMarkerRecordsStateWithoutPurging(): void
    {
        // First boot ever, or a flushed store: nothing stale can be cached, so a purge is pure
        // cost. Documented limitation -- a flush coinciding with a flip misses the edge purge.
        $this->cache()->delete(CapabilityFlipPurge::MARKER_KEY);
        $this->seedTaggedPages();

        $this->reconciler()->reconcile(enabled: true);

        self::assertNotNull($this->cache()->get('page:home'));
        self::assertSame('on', $this->cache()->get(CapabilityFlipPurge::MARKER_KEY));
    }

    public function testAnUnchangedStatePurgesNothing(): void
    {
        // Every boot calls reconcile(); only a CHANGE may purge, or each deploy flushes the page
        // cache for nothing.
        $this->reconciler()->reconcile(enabled: true);
        $this->seedTaggedPages();

        $this->reconciler()->reconcile(enabled: true);

        self::assertNotNull($this->cache()->get('page:home'));
        self::assertNotNull($this->cache()->get('page:shop'));
    }

    public function testAFlipPurgesBothLocalTags(): void
    {
        $this->reconciler()->reconcile(enabled: true);
        $this->seedTaggedPages();

        $this->reconciler()->reconcile(enabled: false);

        // Both: the header renders on shop pages too, and those live in the catalog cache.
        self::assertNull($this->cache()->get('page:home'));
        self::assertNull($this->cache()->get('page:shop'));
        self::assertSame('off', $this->cache()->get(CapabilityFlipPurge::MARKER_KEY));
    }

    public function testAFlipBackAlsoPurges(): void
    {
        $this->reconciler()->reconcile(enabled: false);
        $this->seedTaggedPages();

        $this->reconciler()->reconcile(enabled: true);

        self::assertNull($this->cache()->get('page:home'));
        self::assertNull($this->cache()->get('page:shop'));
    }

    public function testTheMarkerSurvivesItsOwnPurge(): void
    {
        // The marker is untagged precisely so a purge cannot delete its own bookkeeping -- if it
        // could, every boot would look like a first boot and never purge again.
        $this->reconciler()->reconcile(enabled: true);
        $this->reconciler()->reconcile(enabled: false);

        self::assertSame('off', $this->cache()->get(CapabilityFlipPurge::MARKER_KEY));
    }

    public function testTheEdgeIsPurgedOnAFlipAndOnlyWhenEnabled(): void
    {
        $this->reconciler()->reconcile(enabled: true);

        $disabled = new RecordingEdgeCache(enabled: false);
        $this->reconciler($disabled)->reconcile(enabled: false);
        self::assertSame(0, $disabled->purgeAllCalls);

        $enabled = new RecordingEdgeCache(enabled: true);
        $this->reconciler($enabled)->reconcile(enabled: true);
        self::assertSame(1, $enabled->purgeAllCalls);
    }

    public function testTheMarkerKeyIsAccountOwned(): void
    {
        // Sharing Commerce's marker would mean whichever pack booted second sees no flip.
        self::assertNotSame(
            \Thallo\Commerce\Shop\CapabilityFlipPurge::MARKER_KEY,
            CapabilityFlipPurge::MARKER_KEY,
        );
    }
}
```

`RecordingEdgeCache` is a small in-suite fake implementing `EdgeCacheInterface` with a public
`$purgeAllCalls` counter and a fixed `isEnabled()`. Check whether the commerce suite already ships
an equivalent and reuse it rather than adding a second one.

- [ ] **Step 3: Prove the rendered boundary across separate boots**

The rendered-boundary assertions need genuinely separate applications, because registration
happens at boot. Use `AppTestCase::bootAppWithConfigOverride()` directly — it already resets
`RouteManifest`, `ServiceProvider::$loadedRoutes`, and compiled route caches before each dedicated
boot. Do not introduce wrapper helpers.

Enabling the capability makes `account-link` *available*; it does not place one on `/`. Render an
explicit stored block through `RenderContextExtension::blocks()`, exactly like
`StorefrontInertnessTest` does for stored Commerce blocks. That proves the real template-chain
boundary without assuming starter registration mutates region content:

```php
    public function testACapabilityOffBootRendersNoAccountChrome(): void
    {
        $off = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.accounts' => false],
        ]);

        try {
            $container = $off->getContainer();
            $env = $container->get(TwigFactory::class)->environment();
            /** @var RenderContextExtension $render */
            $render = $container->get(RenderContextExtension::class);
            $render->resetPerRenderState();
            $render->setBlockAnnotations(false);
            $render->setLocale('en');

            // This represents content persisted while accounts were enabled. Capability-off
            // removes the pack template path; it never mutates the stored block.
            $html = $render->blocks($env, ['entry' => null, 'site' => []], [[
                'id' => 'account-block-1',
                'type' => 'account-link',
                'data' => [],
            ]]);

            self::assertStringNotContainsString('data-account-link', $html);
            self::assertStringNotContainsString('/_account/assets/account.js', $html);

            $response = (new Application($off))->handle(
                Request::create('/_account/session', 'GET', [], [], [], [
                    'HTTP_ACCEPT' => 'application/json',
                ]),
            );
            self::assertSame(404, $response->getStatusCode());
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }

    public function testACapabilityOnBootRendersTheUniversalShell(): void
    {
        $on = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.accounts' => true],
        ]);

        try {
            $container = $on->getContainer();
            $env = $container->get(TwigFactory::class)->environment();
            self::assertTrue(
                $env->getLoader()->exists('blocks/account-link.twig'),
                'sanity: the enabled boot must contribute the account template path',
            );

            /** @var RenderContextExtension $render */
            $render = $container->get(RenderContextExtension::class);
            $render->resetPerRenderState();
            $render->setBlockAnnotations(false);
            $render->setLocale('en');
            $html = $render->blocks($env, ['entry' => null, 'site' => []], [[
                'id' => 'account-block-1',
                'type' => 'account-link',
                'data' => [],
            ]]);

            self::assertStringContainsString('data-account-link', $html);
            self::assertStringContainsString('/_account/assets/account.js', $html);
        } finally {
            self::resetSharedRepositoryConnection();
        }
    }
```

Add the concrete imports used above (`Glueful\Application`,
`Symfony\Component\HttpFoundation\Request`, `Thallo\Render\TwigFactory`, and
`Thallo\Render\RenderContextExtension`) to `AccountCacheIsolationTest`. The helper itself owns the
known route-file latch reset, so this test must not duplicate or weaken that choreography.

- [ ] **Step 4: Write the session endpoint**

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/Account`
Expected: PASS.

---

- [ ] **Step 6: Full gates, then commit**

```bash
vendor/bin/phpunit && vendor/bin/phpcs --standard=PSR12 packages/thallo-account/src
git add packages/thallo-account app/Content/Regions/RegionDefinitions.php tests/Integration/Account
git commit -m "feat(account): add the account header block and private session hydration

The block renders a universal signed-out shell -- byte-identical for every visitor,
because the page caches never parse cookies and a server-rendered name would be served
to the next stranger. A ThalloRuntime module hydrates it from /_account/session, which
is the single place per-visitor identity leaves the server and is private, no-store.

That endpoint carries session_cookie:optional AND auth:optional: the cookie adapter
publishes no identity, so with the adapter alone the block would stay permanently
signed out. Hydration fails closed on any error, leaving the shell as rendered rather
than a half-painted menu.

Templates emit a stable asset alias that redirects uncached to the current fingerprint;
only the fingerprint is immutable. Emitting the fingerprint directly would leave cached
pages requesting a URL that 404s after every deploy."
```
