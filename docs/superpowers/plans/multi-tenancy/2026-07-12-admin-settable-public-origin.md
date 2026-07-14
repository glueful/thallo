# Admin-Settable Public Origin — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let operators set multi-tenancy's public origin (base domain + default hosts) from the admin UI — persisted in `SystemFlags`, hydrated into config at boot — so activating full resolution no longer needs an `.env` edit + blind restart.

**Architecture:** Persist → hydrate → (unchanged) consume. A new framework primitive `ApplicationContext::overrideConfig()` lets a Thallo provider `boot()` override `tenancy.public_origin.*` from `SystemFlags` before the lazy request-time resolver chain is built; every existing consumer keeps reading `config()` unchanged. A process-local origin-revision gate (modeled on the existing `bootId` barrier) refuses activation against stale config; writes share the resolution `EnablementLock` and are allowed only while activation is `INACTIVE`.

**Tech Stack:** PHP 8.3 (Glueful framework + `glueful/tenancy` engine), PostgreSQL, Vue 3 + Nuxt UI Pro admin SPA (Pinia setup stores, `@pinia/colada`, vitest/jsdom).

**Spec:** `docs/superpowers/specs/multi-tenancy/2026-07-12-admin-settable-public-origin-design.md` — read it; this plan implements it verbatim.

## Global Constraints

- **Posture:** HELD — do NOT commit until explicitly told. Work on `dev`. No feature branch.
- **No attribution:** no Claude/Anthropic mention in code, comments, or commit text.
- **PHP style:** `declare(strict_types=1)`, `final` classes, constructor DI, `use`-imports (no inline FQCNs), `composer phpcs` clean (120-char lines). Match surrounding tenancy-pack conventions.
- **SPA style:** setup-store Pinia only; header injection only via `authFetch`; `data-testid` hooks; no `UAuthForm`; vitest + jsdom; never pipe `vue-tsc`/`tsc` through `tail` (it masks the exit code).
- **Postgres-only:** Thallo v1 is PostgreSQL; the `EnablementLock` is a PG advisory lock. Do not add MySQL/SQLite paths.
- **Regression is a hard gate:** with both persisted values unset, every existing tenancy suite must pass **byte-identical** and `config('tenancy.public_origin.*')` must resolve exactly as today.
- **Vendor-first (framework work):** implement + unit-test the framework primitive in `glueful/framework`, then mirror the two edited files into `thallo/vendor/glueful/framework/` so downstream Thallo tasks run against it. Release framework and pin `thallo/composer.json` **last** (Task 12).
- **Enumeration-neutral, structured errors** on all new endpoints.
- **Namespaces/paths:** tenancy-pack code = `Thallo\Tenancy\…` → `packages/thallo-tenancy/src/…`; routes = `packages/thallo-tenancy/routes/enablement.php`; SPA = `admin/src/…`.

---

### Task 1: Framework primitive — `ApplicationContext::overrideConfig()`

**Files:**
- Modify: `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Bootstrap/ApplicationContext.php`
- Test: `/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Unit/Bootstrap/ApplicationContextOverrideTest.php` (create; if an `ApplicationContext` test already exists there, add cases to it)
- Mirror (final step): copy the edited `ApplicationContext.php` into `/Users/michaeltawiahsowah/Sites/glueful/thallo/vendor/glueful/framework/src/Bootstrap/ApplicationContext.php`

**Interfaces:**
- Produces: `ApplicationContext::overrideConfig(string $key, mixed $value): void` — dot-path, **process-local, boot-only** override with precedence `extension defaults < file/env config < process override`. Overrides live in a private `configOverrides` layer, survive `clearConfigCache()`, and win in `getConfig()`. Writing a nested key invalidates the whole top-level namespace cache. Throws `\LogicException` if called after `markBooted()`.

- [ ] **Step 1: Re-confirm the contract (spec call-out).** Read `src/Bootstrap/ApplicationContext.php:97-196` and grep the whole `src/` tree for `overrideConfig|setConfig|configOverrides`. Confirm no winning-override seam exists (only `mergeConfigDefaults` merges *under* file). Confirm `getConfig()` (line 102) memoizes per-key into `$configCache` and `resolveConfigValue()` (line 117) builds `$loadedConfigs[$configName]` as `deepMerge($defaults, $fileConfig)`. Expected: matches the spec's finding.

- [ ] **Step 2: Write the failing test.**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Bootstrap;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use PHPUnit\Framework\TestCase;

final class ApplicationContextOverrideTest extends TestCase
{
    private function context(array $file): ApplicationContext
    {
        $loader = new class ($file) extends ConfigurationLoader {
            /** @param array<string,mixed> $file */
            public function __construct(private readonly array $file)
            {
            }

            public function loadConfig(string $name): array
            {
                return $this->file[$name] ?? [];
            }
        };
        $ctx = new ApplicationContext('/tmp/glueful-config-test', 'testing');
        $ctx->setConfigLoader($loader);

        return $ctx;
    }

    public function testOverrideWinsOverFileConfig(): void
    {
        $ctx = $this->context(['tenancy' => ['public_origin' => ['base_domain' => 'file.example']]]);
        self::assertSame('file.example', $ctx->getConfig('tenancy.public_origin.base_domain'));

        $ctx->overrideConfig('tenancy.public_origin.base_domain', 'override.example');
        self::assertSame('override.example', $ctx->getConfig('tenancy.public_origin.base_domain'));
    }

    public function testOverrideInvalidatesParentAndChildCachedReads(): void
    {
        $ctx = $this->context(['tenancy' => ['public_origin' => ['base_domain' => 'file.example']]]);
        // Prime both the parent-key and child-key caches.
        self::assertSame(['base_domain' => 'file.example'], $ctx->getConfig('tenancy.public_origin'));
        self::assertSame('file.example', $ctx->getConfig('tenancy.public_origin.base_domain'));

        $ctx->overrideConfig('tenancy.public_origin.base_domain', 'override.example');

        self::assertSame('override.example', $ctx->getConfig('tenancy.public_origin.base_domain'));
        self::assertSame(['base_domain' => 'override.example'], $ctx->getConfig('tenancy.public_origin'));
    }

    public function testOverrideSurvivesClearConfigCache(): void
    {
        $ctx = $this->context(['tenancy' => ['public_origin' => ['base_domain' => 'file.example']]]);
        $ctx->overrideConfig('tenancy.public_origin.base_domain', 'override.example');
        $ctx->clearConfigCache();
        self::assertSame('override.example', $ctx->getConfig('tenancy.public_origin.base_domain'));
    }

    public function testOverrideRejectedAfterBoot(): void
    {
        $ctx = $this->context([]);
        $ctx->markBooted();
        $this->expectException(\LogicException::class);
        $ctx->overrideConfig('tenancy.public_origin.base_domain', 'x.example');
    }

    public function testOverrideRejectsAnEmptyDotPath(): void
    {
        $ctx = $this->context([]);
        $this->expectException(\InvalidArgumentException::class);
        $ctx->overrideConfig('tenancy..base_domain', 'x.example');
    }
}
```

- [ ] **Step 3: Run it — expect failure.** `cd /Users/michaeltawiahsowah/Sites/glueful/framework && vendor/bin/phpunit --filter ApplicationContextOverrideTest` → FAIL (`overrideConfig` undefined). If `ConfigurationLoader::loadConfig` is not overridable as written, adapt the fake to the real base signature found in Step 1.

- [ ] **Step 4: Implement.** Add the override layer to `ApplicationContext`.

Add the property beside the others (near line 26):

```php
    /** @var array<string, mixed> Process-local config overrides applied over file/default config. */
    private array $configOverrides = [];
```

Apply overrides in `resolveConfigValue()` — after `$config = $this->loadedConfigs[$configName];` (line 134), before the segment traversal:

```php
        $config = $this->loadedConfigs[$configName];

        // Process override layer wins over file/default config (precedence:
        // extension defaults < file/env < override). Applied here so overrides
        // survive clearConfigCache() (which only empties the loaded/cached layers).
        if (array_key_exists($configName, $this->configOverrides)) {
            $override = $this->configOverrides[$configName];
            $config = is_array($config) && is_array($override)
                ? self::deepMerge($config, $override)
                : $override;
        }
```

Add the method (place after `mergeConfigDefaults()`):

```php
    /**
     * Apply a process-local config override that wins over file/env/default config.
     *
     * Boot-only: overrides shape the container's view of config once, before the app is
     * booted. Calling after {@see markBooted()} is a programming error — mid-request config
     * changes would create split-brain services that read config at different times.
     *
     * A nested key invalidates the entire top-level namespace cache (including cached parent
     * reads), and the override persists across {@see clearConfigCache()}.
     */
    public function overrideConfig(string $key, mixed $value): void
    {
        if ($this->booted) {
            throw new \LogicException(
                'overrideConfig() must be called before boot completes (after markBooted()).'
            );
        }

        $segments = explode('.', $key);
        if ($segments === [] || in_array('', $segments, true)) {
            throw new \InvalidArgumentException('Config override keys must be non-empty dot paths.');
        }
        $configName = array_shift($segments);

        if ($segments === []) {
            $this->configOverrides[$configName] = $value;
        } else {
            $nested = $value;
            foreach (array_reverse($segments) as $segment) {
                $nested = [$segment => $nested];
            }
            $existing = $this->configOverrides[$configName] ?? [];
            $this->configOverrides[$configName] = is_array($existing)
                ? self::deepMerge($existing, $nested)
                : $nested;
        }

        // Invalidate the whole top-level namespace so parent + child cached reads re-resolve.
        unset($this->loadedConfigs[$configName]);
        foreach (array_keys($this->configCache) as $cachedKey) {
            if ($cachedKey === $configName || str_starts_with($cachedKey, $configName . '.')) {
                unset($this->configCache[$cachedKey]);
            }
        }
    }
```

- [ ] **Step 5: Run tests + phpcs.** `vendor/bin/phpunit --filter ApplicationContextOverrideTest` → PASS. `composer phpcs -- src/Bootstrap/ApplicationContext.php` → clean.

- [ ] **Step 6: Regression.** `vendor/bin/phpunit --filter ApplicationContext` (all context tests) → PASS — no existing config behavior changed.

- [ ] **Step 7: Mirror into Thallo's vendored copy** so downstream tasks run against it: copy `framework/src/Bootstrap/ApplicationContext.php` → `thallo/vendor/glueful/framework/src/Bootstrap/ApplicationContext.php`.

- [ ] **Step 8: Commit — SKIP (HELD).**

---

### Task 2: Framework — `Framework::boot()` calls `markBooted()`

**Files:**
- Modify: `/Users/michaeltawiahsowah/Sites/glueful/framework/src/Framework.php`
- Test: `/Users/michaeltawiahsowah/Sites/glueful/framework/tests/Integration/FrameworkBootTest.php` (extend the existing `testFrameworkBootsSuccessfully`)
- Mirror (final step): copy edited `Framework.php` into `thallo/vendor/glueful/framework/src/Framework.php`

**Interfaces:**
- Consumes: `ApplicationContext::markBooted()` (Task 1's guard depends on it being called).
- Produces: after a full `Framework::boot()`, `$context->isBooted() === true`.

- [ ] **Step 1: Locate the boot completion point.** Read `Framework.php` `boot()`; find where all boot phases finish (after `$extensions->boot()`) and the framework marks itself booted. Confirm `$context->markBooted()` has no current caller (grep `markBooted`).

- [ ] **Step 2: Write the failing assertion.** In the existing
`tests/Integration/FrameworkBootTest.php::testFrameworkBootsSuccessfully()`, immediately after the
current framework boot assertion, add:

```php
self::assertTrue($app->getContext()->isBooted());
```

- [ ] **Step 3: Run it — expect failure** (context not booted).
`vendor/bin/phpunit --filter testFrameworkBootsSuccessfully`.

- [ ] **Step 4: Implement.** In `Framework::boot()`, after all boot phases complete and immediately before the framework's own booted flag is set (and before returning), add:

```php
        $context->markBooted();
```

Use the actual local variable/accessor for the `ApplicationContext` in that scope (from Step 1).

- [ ] **Step 5: Run tests + phpcs** → PASS + clean.

- [ ] **Step 6: Mirror `Framework.php`** into `thallo/vendor/glueful/framework/src/Framework.php`.

- [ ] **Step 7: Commit — SKIP (HELD).**

---

### Task 3: `PublicOriginStore` — persistence, hydration, revision gate

**Files:**
- Create: `packages/thallo-tenancy/src/PublicOrigin/PublicOriginStore.php`
- Test: `tests/Integration/Tenancy/PublicOriginStoreTest.php` (mirror the real Postgres harness used by `tests/Integration/Tenancy/SystemFlagsTest.php` and `tests/Unit/Tenancy/Resolution/ResolutionActivationStoreTest.php`; the package has no separate `packages/thallo-tenancy/tests` tree)

**Interfaces:**
- Consumes: `Thallo\Tenancy\System\SystemFlags` (`get/put/forget/clearCache`), `Glueful\Database\Connection` (`transaction`), `Glueful\Bootstrap\ApplicationContext` (`overrideConfig`, `getConfig`).
- Produces:
  - `persistedBaseDomain(): ?string`
  - `persistedHosts(): array` (`list<string>`)
  - `fallbackBaseDomain()` / `fallbackHosts()` — file/env values captured before hydration.
  - `desiredBaseDomain()` / `desiredHosts()` — persisted override when present, otherwise fallback.
  - `appliedBaseDomain()` / `appliedHosts()` — the values hydrated into this process.
  - `hydrate(): void` — boot-only: capture applied values and `overrideConfig` for whichever persisted value is set.
  - `isStale(): bool` — force-fresh revision read vs process-hydrated revision.
  - `assertFreshForActivation(): void` — throws `EnablementException` when stale.
  - `writeChanged(?string $baseDomain, array $hosts): bool` — normalized inputs assumed; no-op + `false` when unchanged; else persists all three keys + fresh revision in one transaction, returns `true`.

- [ ] **Step 1: Write the failing test** (uses the real Postgres test connection + a real `SystemFlags`; mirror the existing harness):

```php
public function testWriteChangedPersistsAndBumpsRevisionOnlyWhenChanged(): void
{
    $store = $this->makeStore(); // ApplicationContext + SystemFlags + Connection from harness
    self::assertNull($store->persistedBaseDomain());

    self::assertTrue($store->writeChanged('apex.example', ['apex.example', 'alt.example']));
    self::assertSame('apex.example', $store->persistedBaseDomain());
    self::assertSame(['apex.example', 'alt.example'], $store->persistedHosts());
    $rev1 = $this->flags->get('tenancy.public_origin.revision');
    self::assertNotNull($rev1);

    // Unchanged write: no revision bump, returns false.
    self::assertFalse($store->writeChanged('apex.example', ['apex.example', 'alt.example']));
    self::assertSame($rev1, $this->flags->get('tenancy.public_origin.revision'));

    // Changed write: bumps.
    self::assertTrue($store->writeChanged('apex.example', ['apex.example']));
    self::assertNotSame($rev1, $this->flags->get('tenancy.public_origin.revision'));
}

public function testStaleWhenRevisionChangesAfterHydration(): void
{
    $store = $this->makeStore();               // constructor captures current (null) revision
    self::assertFalse($store->isStale());      // no persisted revision yet
    $store->writeChanged('apex.example', ['apex.example']); // bumps revision this process hydrated null
    self::assertTrue($store->isStale());
    $this->expectException(EnablementException::class);
    $store->assertFreshForActivation();
}

public function testHydrateOverridesConfigWhenSet(): void
{
    $this->flags->put('tenancy.public_origin.base_domain', 'apex.example');
    $this->flags->put('tenancy.public_origin.default_hosts', 'apex.example,alt.example');
    $store = $this->makeStore();
    $store->hydrate();
    self::assertSame('apex.example', $this->context->getConfig('tenancy.public_origin.base_domain'));
    self::assertSame(
        ['apex.example', 'alt.example'],
        $this->context->getConfig('tenancy.public_origin.default_hosts')
    );
}

public function testClearingPersistedBaseFallsBackToThePreHydrationConfig(): void
{
    $this->contextWithConfig('tenancy.public_origin.base_domain', 'fallback.example');
    $this->flags->put('tenancy.public_origin.base_domain', 'persisted.example');
    $store = $this->makeStore();
    $store->hydrate();

    self::assertSame('fallback.example', $store->fallbackBaseDomain());
    self::assertSame('persisted.example', $store->desiredBaseDomain());
    self::assertSame('persisted.example', $store->appliedBaseDomain());

    $store->writeChanged(null, ['fallback.example']);
    self::assertSame('fallback.example', $store->desiredBaseDomain());
    self::assertSame('persisted.example', $store->appliedBaseDomain());
}
```

- [ ] **Step 2: Run — expect failure** (class missing).

- [ ] **Step 3: Implement.**

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\PublicOrigin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Thallo\Tenancy\Enablement\EnablementException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Persists the admin-set public origin (base domain + default hosts) in SystemFlags and
 * hydrates it over config at boot. A process-local revision, captured at construction,
 * gates resolution activation against config the running process never loaded.
 */
final class PublicOriginStore
{
    private const KEY_BASE = 'tenancy.public_origin.base_domain';
    private const KEY_HOSTS = 'tenancy.public_origin.default_hosts';
    private const KEY_REVISION = 'tenancy.public_origin.revision';

    /** Revision this process observed at construction (boot) — process-local, like bootId. */
    private readonly ?string $hydratedRevision;
    private readonly ?string $fallbackBaseDomain;
    /** @var list<string> */
    private readonly array $fallbackHosts;
    private ?string $appliedBaseDomain = null;
    /** @var list<string> */
    private array $appliedHosts = [];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemFlags $flags,
        private readonly Connection $connection,
    ) {
        $fallbackBase = $this->context->getConfig(self::KEY_BASE);
        $this->fallbackBaseDomain = is_string($fallbackBase) && $fallbackBase !== ''
            ? $fallbackBase
            : null;
        $fallbackHosts = $this->context->getConfig(self::KEY_HOSTS, []);
        $this->fallbackHosts = array_values(array_filter(
            is_array($fallbackHosts) ? $fallbackHosts : [],
            'is_string'
        ));
        $revision = $this->flags->get(self::KEY_REVISION);
        $this->hydratedRevision = ($revision === null || $revision === '') ? null : $revision;
    }

    public function persistedBaseDomain(): ?string
    {
        $value = $this->flags->get(self::KEY_BASE);

        return ($value === null || $value === '') ? null : $value;
    }

    /** @return list<string> */
    public function persistedHosts(): array
    {
        $raw = (string) $this->flags->get(self::KEY_HOSTS);

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($h) => $h !== ''));
    }

    public function fallbackBaseDomain(): ?string
    {
        return $this->fallbackBaseDomain;
    }

    /** @return list<string> */
    public function fallbackHosts(): array
    {
        return $this->fallbackHosts;
    }

    public function desiredBaseDomain(): ?string
    {
        return $this->persistedBaseDomain() ?? $this->fallbackBaseDomain;
    }

    /** @return list<string> */
    public function desiredHosts(): array
    {
        $persisted = $this->persistedHosts();
        return $persisted !== [] ? $persisted : $this->fallbackHosts;
    }

    public function appliedBaseDomain(): ?string
    {
        return $this->appliedBaseDomain;
    }

    /** @return list<string> */
    public function appliedHosts(): array
    {
        return $this->appliedHosts;
    }

    /** Boot-only: override config with the persisted values so every config() consumer sees them. */
    public function hydrate(): void
    {
        $base = $this->persistedBaseDomain();
        $this->appliedBaseDomain = $base ?? $this->fallbackBaseDomain;
        if ($base !== null) {
            $this->context->overrideConfig(self::KEY_BASE, $base);
        }
        $hosts = $this->persistedHosts();
        $this->appliedHosts = $hosts !== [] ? $hosts : $this->fallbackHosts;
        if ($hosts !== []) {
            $this->context->overrideConfig(self::KEY_HOSTS, $hosts);
        }
    }

    public function isStale(): bool
    {
        $this->flags->clearCache(); // a remote HTTP/CLI process may have written a new revision
        $current = (string) $this->flags->get(self::KEY_REVISION);

        return !hash_equals((string) $this->hydratedRevision, $current);
    }

    public function assertFreshForActivation(): void
    {
        if ($this->isStale()) {
            throw new EnablementException(
                'Public origin changed since this process started — restart required before activating.'
            );
        }
    }

    /**
     * Persist normalized values, bumping the revision only when they changed. Returns whether a
     * write occurred. Caller is responsible for normalization/validation and for holding the lock.
     *
     * @param list<string> $hosts
     */
    public function writeChanged(?string $baseDomain, array $hosts): bool
    {
        if ($baseDomain === $this->persistedBaseDomain() && $hosts === $this->persistedHosts()) {
            return false;
        }

        $this->connection->transaction(function () use ($baseDomain, $hosts): void {
            if ($baseDomain === null) {
                $this->flags->forget(self::KEY_BASE);
            } else {
                $this->flags->put(self::KEY_BASE, $baseDomain);
            }
            $this->flags->put(self::KEY_HOSTS, implode(',', $hosts));
            $this->flags->put(self::KEY_REVISION, bin2hex(random_bytes(16)));
        });

        return true;
    }
}
```

- [ ] **Step 4: Run tests + phpcs** → PASS + clean.

- [ ] **Step 5: Commit — SKIP (HELD).**

---

### Task 4: `PublicOriginService` — validation (Pin 3), status, save (lock + step-gate)

**Files:**
- Create: `packages/thallo-tenancy/src/PublicOrigin/PublicOriginService.php`
- Create: `packages/thallo-tenancy/src/PublicOrigin/PublicOriginValidationException.php`
- Create: `packages/thallo-tenancy/src/PublicOrigin/PublicOriginWriteConflict.php`
- Test: `tests/Integration/Tenancy/PublicOriginServiceTest.php`
- Test: `tests/Integration/Tenancy/PublicOriginConcurrencyTest.php` (two independent PostgreSQL sessions; mirror `tests/Unit/Tenancy/Enablement/EnablementLockTest.php`)

**Interfaces:**
- Consumes: `PublicOriginStore` (Task 3); `Thallo\Tenancy\Resolution\ResolutionActivationStore` (`step()`); `Thallo\Tenancy\Enablement\EnablementLock` (`withLock`); `Glueful\Extensions\Tenancy\Resolution\HostNormalizer` (`normalize`, `validateForRegistration`); `Glueful\Extensions\Tenancy\Exceptions\InvalidHostException`; `ApplicationContext` (`getConfig`).
- Produces:
  - `status()` returns desired `base_domain`/`default_hosts`, boot-applied `applied_base_domain`/`applied_default_hosts`, sources, step, and restart status.
  - `save(?string $baseDomain, array $hosts): array` — validates then, under the lock, re-reads the step (409 unless `INACTIVE`), writes if changed, returns `status()`. Throws `PublicOriginValidationException` (422) / `PublicOriginWriteConflict` (409) / `EnablementLockedException` (409).

- [ ] **Step 1: Write the failing tests** (real harness). `makeService()` must construct the shared
store from the supplied fallback config and call `hydrate()` before constructing the service, matching
provider boot; this makes desired/applied assertions meaningful:

```php
public function testStatusReportsEffectiveValuesAndSource(): void
{
    // config-file base only, no flags -> source 'config'; hosts unset -> 'unset'
    $svc = $this->makeService(['tenancy' => ['public_origin' => ['base_domain' => 'file.example']]]);
    $status = $svc->status();
    self::assertSame('file.example', $status['base_domain']);
    self::assertSame('config', $status['base_domain_source']);
    self::assertSame('unset', $status['default_hosts_source']);
}

public function testSaveRejectsInvalidHostsWithFieldScoped422(): void
{
    $svc = $this->makeService([]);
    try {
        $svc->save('apex.example', ['1.2.3.4']); // IP
        self::fail('expected validation exception');
    } catch (PublicOriginValidationException $e) {
        self::assertArrayHasKey('default_hosts', $e->errors);
    }
}

public function testSaveAcceptsApexAsDefaultHostButRejectsReservedSubdomain(): void
{
    $svc = $this->makeService([]);
    $svc->save('apex.example', ['apex.example']);            // apex allowed
    self::assertSame(['apex.example'], $svc->status()['default_hosts']);

    $this->expectException(PublicOriginValidationException::class);
    $svc->save('apex.example', ['www.apex.example']);        // reserved label rejected
}

public function testSaveRejectedWhenActivationNotInactive(): void
{
    $this->activationStore->compareAndSet(
        ResolutionActivationStep::INACTIVE,
        ResolutionActivationStep::MAPPING_HOSTS
    );
    $svc = $this->makeService([]);
    $this->expectException(PublicOriginWriteConflict::class);
    $svc->save('apex.example', ['apex.example']);
}

public function testHostOrderIsNotASemanticChange(): void
{
    $svc = $this->makeService([]);
    $first = $svc->save('apex.example', ['z.example', 'apex.example']);
    $revision = $this->flags->get('tenancy.public_origin.revision');
    $second = $svc->save('apex.example', ['apex.example', 'z.example']);
    self::assertSame($revision, $this->flags->get('tenancy.public_origin.revision'));
    self::assertSame($first['base_domain'], $second['base_domain']);
}

public function testChangedSaveReturnsDesiredValuesAndPreservesAppliedSnapshotUntilRestart(): void
{
    $svc = $this->makeService([
        'tenancy' => ['public_origin' => [
            'base_domain' => 'fallback.example',
            'default_hosts' => ['fallback.example'],
        ]],
    ]);
    $status = $svc->save('new.example', ['new.example']);
    self::assertSame('new.example', $status['base_domain']);
    self::assertSame(['new.example'], $status['default_hosts']);
    self::assertSame('fallback.example', $status['applied_base_domain']);
    self::assertSame(['fallback.example'], $status['applied_default_hosts']);
    self::assertTrue($status['origin_restart_required']);
}

public function testContendingSessionGetsLockConflict(): void
{
    $holder = $this->connection()->newPdo();
    self::assertTrue($holder->query('SELECT pg_try_advisory_lock(4823710)')->fetchColumn());
    $threw = false;
    try {
        $this->makeService([])->save('apex.example', ['apex.example']);
    } catch (EnablementLockedException) {
        $threw = true;
    } finally {
        $holder->exec('SELECT pg_advisory_unlock(4823710)');
    }
    self::assertTrue($threw);
}

public function testWriteFirstMakesActivationRefuseStaleOriginWithoutChangingStep(): void
{
    $service = $this->makeService([]);
    $service->save('apex.example', ['apex.example']);
    try {
        $this->activation()->advance();
        self::fail('expected restart-required exception');
    } catch (EnablementException) {
    }
    self::assertSame(ResolutionActivationStep::INACTIVE, $this->activationStore->step());
}
```

- [ ] **Step 2: Run — expect failure.**

- [ ] **Step 3: Implement the exceptions.**

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\PublicOrigin;

final class PublicOriginValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors field => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Public origin validation failed.');
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\PublicOrigin;

final class PublicOriginWriteConflict extends \RuntimeException
{
}
```

- [ ] **Step 4: Implement the service.**

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\PublicOrigin;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
use Glueful\Extensions\Tenancy\Exceptions\InvalidHostException;
use Thallo\Tenancy\Enablement\EnablementLock;
use Thallo\Tenancy\Resolution\ResolutionActivationStep;
use Thallo\Tenancy\Resolution\ResolutionActivationStore;

final class PublicOriginService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PublicOriginStore $store,
        private readonly ResolutionActivationStore $activation,
        private readonly EnablementLock $lock,
    ) {
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        // Refresh persisted flags before deriving desired values/source; applied values remain the
        // immutable boot snapshot until restart.
        $stale = $this->store->isStale();
        $base = $this->store->desiredBaseDomain();
        $hosts = $this->store->desiredHosts();

        return [
            'base_domain' => is_string($base) && $base !== '' ? $base : null,
            'default_hosts' => $hosts,
            'applied_base_domain' => $this->store->appliedBaseDomain(),
            'applied_default_hosts' => $this->store->appliedHosts(),
            'base_domain_source' => $this->source($this->store->persistedBaseDomain() !== null, $base),
            'default_hosts_source' => $this->source($this->store->persistedHosts() !== [], $hosts),
            'step' => $this->activation->step()->value,
            'origin_restart_required' => $stale,
        ];
    }

    /**
     * @param list<string> $hosts
     * @return array<string,mixed>
     */
    public function save(?string $baseDomain, array $hosts): array
    {
        $normalizedBase = $this->normalizeBase($baseDomain);
        $proposedBase = $normalizedBase ?? $this->normalizeBase($this->store->fallbackBaseDomain());
        $reserved = $this->context->getConfig('tenancy.public_origin.reserved_labels', []);
        $proposed = ['base_domain' => $proposedBase, 'reserved_labels' => $reserved];
        $normalizedHosts = $this->normalizeHosts($hosts, $proposed);

        return $this->lock->withLock(function () use ($normalizedBase, $normalizedHosts): array {
            if ($this->activation->step() !== ResolutionActivationStep::INACTIVE) {
                throw new PublicOriginWriteConflict(
                    'Public origin cannot be changed while workspace resolution is activating or active.'
                );
            }
            $this->store->writeChanged($normalizedBase, $normalizedHosts);

            return $this->status();
        });
    }

    private function source(bool $fromFlag, mixed $effective): string
    {
        if ($fromFlag) {
            return 'flag';
        }

        return ($effective === null || $effective === '' || $effective === []) ? 'unset' : 'config';
    }

    private function normalizeBase(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }
        if (str_contains($input, ':')) {
            throw new PublicOriginValidationException(['base_domain' => 'A port or address form is not allowed.']);
        }
        try {
            return HostNormalizer::normalize($input);
        } catch (InvalidHostException $e) {
            throw new PublicOriginValidationException(['base_domain' => $e->getMessage()]);
        }
    }

    /**
     * @param list<string> $inputs
     * @param array<string,mixed> $proposedOrigin
     * @return list<string>
     */
    private function normalizeHosts(array $inputs, array $proposedOrigin): array
    {
        $normalized = [];
        foreach ($inputs as $input) {
            if (!is_string($input) || trim($input) === '') {
                continue;
            }
            if (str_contains($input, ':')) {
                throw new PublicOriginValidationException(
                    ['default_hosts' => "A port or address form is not allowed: {$input}"]
                );
            }
            try {
                $host = HostNormalizer::normalize($input);
                HostNormalizer::validateForRegistration($host, $proposedOrigin, true);
            } catch (InvalidHostException $e) {
                throw new PublicOriginValidationException(['default_hosts' => $e->getMessage()]);
            }
            $normalized[$host] = true;
        }
        $hosts = array_keys($normalized);
        if ($hosts === []) {
            throw new PublicOriginValidationException(['default_hosts' => 'At least one host is required.']);
        }

        sort($hosts);
        return $hosts;
    }
}
```

> Source-confirmed: `validateForRegistration()` throws
> `Glueful\Extensions\Tenancy\Exceptions\InvalidHostException`. Keep that exact import and remove the
> speculative `\DomainException` catch.

- [ ] **Step 5: Run tests + phpcs** → PASS + clean.

- [ ] **Step 6: Commit — SKIP (HELD).**

---

### Task 5: Register services + hydrate at boot (Pin 4)

**Files:**
- Modify: `packages/thallo-tenancy/src/TenancyServiceProvider.php` (`Thallo\Tenancy\TenancyServiceProvider` — the pack provider, `services()` + `boot()`)
- Test: `tests/Integration/Tenancy/PublicOriginHydrationTest.php`

**Interfaces:**
- Consumes: `PublicOriginStore`, `PublicOriginService` (register as `shared`); `ApplicationContext::overrideConfig` (Task 1).
- Produces: after this provider's `boot()`, persisted public origin wins over file/env config; unset ⇒ file/env stands.

- [ ] **Step 1: Verify the hydration site.** Reconfirm that `Thallo\Tenancy\TenancyServiceProvider::boot()` runs whenever the tenancy pack is active and before request-time resolution. The source-verified service DSL is the existing `['class' => ..., 'shared' => true, 'autowire' => true]` shape; use it directly. Confirm the container + DB are available in `boot()` (SystemFlags already resolves there).

- [ ] **Step 2: Write the failing test.**

```php
public function testHydrationMakesPersistedOriginWinOverConfigFile(): void
{
    $this->flags->put('tenancy.public_origin.base_domain', 'apex.example');
    $this->flags->put('tenancy.public_origin.default_hosts', 'apex.example');
    // config file provides a different value
    $ctx = $this->bootTenancyPack(['tenancy' => ['public_origin' => ['base_domain' => 'file.example']]]);
    self::assertSame('apex.example', $ctx->getConfig('tenancy.public_origin.base_domain'));
}

public function testUnsetOriginLeavesConfigFileUntouched(): void
{
    $ctx = $this->bootTenancyPack(['tenancy' => ['public_origin' => ['base_domain' => 'file.example']]]);
    self::assertSame('file.example', $ctx->getConfig('tenancy.public_origin.base_domain'));
}
```

- [ ] **Step 3: Register services.** In `TenancyServiceProvider::services()`, add `PublicOriginStore` and `PublicOriginService` as shared/autowired, matching the existing DSL (e.g. how `ResolutionActivationStore` is registered):

```php
        PublicOriginStore::class => [
            'class' => PublicOriginStore::class,
            'shared' => true,
            'autowire' => true,
        ],
        PublicOriginService::class => [
            'class' => PublicOriginService::class,
            'shared' => true,
            'autowire' => true,
        ],
```

(Use the exact array shape the provider already uses; add `use` imports.)

- [ ] **Step 4: Hydrate in boot().** In `TenancyServiceProvider::boot()`, before returning, resolve the store and hydrate:

```php
        $context->getContainer()->get(PublicOriginStore::class)->hydrate();
```

Place it where other boot-time container work happens; it must run inside the framework boot window (before `markBooted()`), which provider `boot()` always is.

- [ ] **Step 5: Run tests + phpcs** → PASS + clean.

- [ ] **Step 6: Regression.** Run the tenancy suite subset touching resolution + subdomain routing; confirm with both flags unset nothing changes.

- [ ] **Step 7: Commit — SKIP (HELD).**

---

### Task 6: `FullResolutionActivation` — revision gate, status flag, failed-state reset

**Files:**
- Modify: `packages/thallo-tenancy/src/Resolution/FullResolutionActivation.php`
- Modify: `packages/thallo-tenancy/src/Resolution/ResolutionActivationStore.php`
- Modify: `packages/thallo-tenancy/src/TenancyServiceProvider.php` (explicit activation factory)
- Test: `tests/Integration/Tenancy/FullResolutionActivationResetTest.php` (+ add focused cases to the existing resolution tests)

**Interfaces:**
- Consumes: `PublicOriginStore::assertFreshForActivation()` / `isStale()`; `TenantDomainAdministration::listDomains()` / `releaseDomain()`; `HostNormalizer::normalize`.
- Produces:
  - `status()` gains `'origin_restart_required' => bool`.
  - `advance()` calls `assertFreshForActivation()` **before** its failure-recording `try`; `retry()` calls it before `store->retry()`.
  - `ResolutionActivationStore::resetFromFailed(): bool` — atomic, `FAILED`→`INACTIVE`, clears failure/failed-from/awaiting-boot, does **not** touch `tenancy.resolution`.
  - `FullResolutionActivation::resetFailed(): array` — under the lock, only from `FAILED`: release configured required-host mappings from the default tenant, clear route cache, then `resetFromFailed()`.

- [ ] **Step 1: Inject `PublicOriginStore` into `FullResolutionActivation` and wire the explicit factory.** Add as the last constructor parameter (keeps existing positional deps intact for direct tests):

```php
        private readonly ?PublicOriginStore $origin = null,
```

`FullResolutionActivation` is built by `TenancyServiceProvider::makeFullResolutionActivation()`, not
autowiring. Update that factory to pass the shared store; otherwise the optional dependency silently
stays null and the production revision gate is inert:

```php
            $tenants,
            $container->get(PublicOriginStore::class),
```

Add a real-container assertion that
`$container->get(FullResolutionActivation::class)->status()` reports a changed store revision as
`origin_restart_required=true`; this locks in the factory wiring rather than only testing direct
construction.

Update `status()`:

```php
        return [
            'step' => $step->value,
            'mode' => $this->readiness->mode($this->context),
            'failure' => $this->store->failure(),
            'fresh_boot_required' => $step === ResolutionActivationStep::AWAITING_FRESH_BOOT,
            'origin_restart_required' => $this->origin?->isStale() ?? false,
        ];
```

- [ ] **Step 2: Write failing tests.**

```php
public function testAdvanceRefusesWhenOriginStaleAndLeavesStepUnchanged(): void
{
    // origin store reports stale; step is INACTIVE
    $activation = $this->makeActivation(originStale: true);
    try {
        $activation->advance();
        self::fail('expected restart-required exception');
    } catch (EnablementException $exception) {
        self::assertStringContainsString('restart required', $exception->getMessage());
    }
    self::assertSame(ResolutionActivationStep::INACTIVE, $this->store->step());
    self::assertNull($this->store->failure());
    self::assertTrue($activation->status()['origin_restart_required']);
}

public function testResetFailedReleasesOnlyConfiguredRequiredHostsAndReturnsInactive(): void
{
    $this->configureHosts(['apex.example']);
    // default tenant has two domains: the required one + an unrelated one
    $this->seedDefaultTenantDomains(['apex.example', 'other.example']);
    $this->forceFailedState();
    $status = $this->makeActivation()->resetFailed();
    self::assertSame('inactive', $status['step']);
    self::assertSame(['other.example'], $this->remainingDefaultTenantHosts()); // apex released, other kept
}

public function testResetFailedStaysFailedWhenCleanupThrows(): void
{
    $this->configureHosts(['apex.example']);
    $activation = $this->makeActivation(domainsThatThrowOnRelease: true);
    $this->forceFailedState();
    try {
        $activation->resetFailed();
        self::fail('expected failure');
    } catch (\Throwable) {
    }
    self::assertSame('failed', $this->store->step()->value);
}
```

- [ ] **Step 3: Run — expect failure.**

- [ ] **Step 4: Add `resetFromFailed()` to `ResolutionActivationStore`** (mirror `deactivate()` but gate on `FAILED`, don't touch `tenancy.resolution`):

```php
    /** Atomically returns a FAILED machine to INACTIVE, clearing failure state. */
    public function resetFromFailed(): bool
    {
        if ($this->step() !== ResolutionActivationStep::FAILED) {
            return false;
        }

        $this->connection->transaction(function (): void {
            $this->flags->put(self::KEY_STEP, ResolutionActivationStep::INACTIVE->value);
            $this->flags->forget(self::KEY_FAILURE);
            $this->flags->forget('tenancy.resolution_failed_from');
            $this->flags->forget(self::KEY_AWAITING_BOOT);
        });
        $this->flags->clearCache();

        return true;
    }
```

- [ ] **Step 5: Insert the revision gate in `advance()`** — inside the `withLock` closure, after `$step = $this->store->step();` and **before** `try {`:

```php
        return $this->lock->withLock(function (): array {
            $step = $this->store->step();
            $this->origin?->assertFreshForActivation(); // stale => throws out; step NOT recorded as failure
            try {
                // ... existing match unchanged
```

And in `retry()`, before `$this->store->retry()`:

```php
        return $this->lock->withLock(function (): array {
            $this->origin?->assertFreshForActivation();
            if (!$this->store->retry()) {
                throw new EnablementException('Resolution activation is not retryable.');
            }

            return $this->status();
        });
```

> Because `assertFreshForActivation()` is outside the `try/catch`, a stale origin propagates as an `EnablementException` (→ 422 at the controller) without calling `recordFailure()`, leaving `INACTIVE`/failure state untouched — exactly Pin 1.

- [ ] **Step 6: Add `resetFailed()` to `FullResolutionActivation`:**

```php
    /**
     * Recover a FAILED activation: release the configured required-host mappings from the default
     * tenant (resolution is not FULL, so required-host protection is inactive), clear the route
     * cache, then atomically return the machine to INACTIVE. Any cleanup failure leaves FAILED.
     *
     * @return array<string,mixed>
     */
    public function resetFailed(): array
    {
        return $this->lock->withLock(function (): array {
            if ($this->store->step() !== ResolutionActivationStep::FAILED) {
                throw new EnablementException('Resolution activation is not in a failed state.');
            }

            $default = $this->flags->defaultTenantUuid();
            if ($default !== null) {
                $required = $this->normalizedRequiredHosts();
                foreach ($this->domains()->listDomains($this->context, $default) as $domain) {
                    if (in_array($domain['host'], $required, true)) {
                        $this->domains()->releaseDomain($this->context, $domain['uuid']);
                    }
                }
            }

            $container = $this->context->getContainer();
            if ($container->has(RouteCache::class)) {
                $container->get(RouteCache::class)->clear();
            }

            if (!$this->store->resetFromFailed()) {
                throw new EnablementException('Resolution activation state changed concurrently.');
            }

            return $this->status();
        });
    }

    /** @return list<string> */
    private function normalizedRequiredHosts(): array
    {
        $configured = config($this->context, 'tenancy.public_origin.default_hosts', []);
        $hosts = [];
        foreach (is_array($configured) ? $configured : [] as $host) {
            if (is_string($host)) {
                $hosts[] = HostNormalizer::normalize($host);
            }
        }

        return $hosts;
    }
```

Add `use` imports: `HostNormalizer`, and confirm `RouteCache` is already imported (it is, used by `rebuildRoutes()`).

- [ ] **Step 7: Run tests + phpcs** → PASS + clean.

- [ ] **Step 8: Regression** — run the full resolution-activation test file; confirm advance/retry/deactivate paths unchanged when origin is fresh (`origin` null or not stale).

- [ ] **Step 9: Commit — SKIP (HELD).**

---

### Task 7: `PublicOriginController` + routes (GET/PUT public-origin)

**Files:**
- Create: `packages/thallo-tenancy/src/Http/Controllers/PublicOriginController.php`
- Modify: `packages/thallo-tenancy/routes/enablement.php`
- Test: `tests/Integration/Tenancy/PublicOriginApiTest.php` (mirror `tests/Integration/Tenancy/TenancyEnablementApiTest.php`)

**Interfaces:**
- Consumes: `PublicOriginService`; `Glueful\Http\Response`; `Symfony\Component\HttpFoundation\Request`.
- Produces routes (guard stack `auth` + `tenant_system` + `content_permission:tenancy.manage`):
  - `GET /v1/admin/tenancy/public-origin` → `{ public_origin: <status> }`
  - `PUT /v1/admin/tenancy/public-origin` → validated write.

- [ ] **Step 1: Write the failing feature tests** (mirror the harness of the existing resolution/enablement controller tests):

```php
public function testGetReturnsStatusEnvelope(): void
{
    $res = $this->call('GET', '/v1/admin/tenancy/public-origin');   // authed + tenancy.manage
    self::assertSame(200, $res->getStatusCode());
    self::assertArrayHasKey('public_origin', $this->json($res)['data']);
}

public function testPutRejectsInvalidHostWith422(): void
{
    $res = $this->call('PUT', '/v1/admin/tenancy/public-origin', [
        'base_domain' => 'apex.example',
        'default_hosts' => ['*.apex.example'],
    ]);
    self::assertSame(422, $res->getStatusCode());
    self::assertArrayHasKey('default_hosts', $this->json($res)['data'] ?? $this->json($res)['errors']);
}

public function testPutRejectedWith409WhenActivationInProgress(): void
{
    $this->activationStore->compareAndSet(
        ResolutionActivationStep::INACTIVE,
        ResolutionActivationStep::MAPPING_HOSTS
    );
    $res = $this->call('PUT', '/v1/admin/tenancy/public-origin', [
        'base_domain' => 'apex.example',
        'default_hosts' => ['apex.example'],
    ]);
    self::assertSame(409, $res->getStatusCode());
}

public function testPutRejectsMalformedTypesWithoutCoercion(): void
{
    $badBase = $this->call('PUT', '/v1/admin/tenancy/public-origin', [
        'base_domain' => ['apex.example'],
        'default_hosts' => ['apex.example'],
    ]);
    self::assertSame(422, $badBase->getStatusCode());

    $badHost = $this->call('PUT', '/v1/admin/tenancy/public-origin', [
        'base_domain' => 'apex.example',
        'default_hosts' => ['apex.example', 42],
    ]);
    self::assertSame(422, $badHost->getStatusCode());
}
```

- [ ] **Step 2: Run — expect failure.**

- [ ] **Step 3: Implement the controller** (mirror `TenancyEnablementController::confirm` body parsing + the resolution controller's catch shape):

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Http\Controllers;

use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\Enablement\EnablementLockedException;
use Thallo\Tenancy\PublicOrigin\PublicOriginService;
use Thallo\Tenancy\PublicOrigin\PublicOriginValidationException;
use Thallo\Tenancy\PublicOrigin\PublicOriginWriteConflict;

final class PublicOriginController
{
    public function __construct(private readonly PublicOriginService $service)
    {
    }

    public function show(): Response
    {
        return Response::success(['public_origin' => $this->service->status()]);
    }

    public function update(Request $request): Response
    {
        $decoded = json_decode((string) $request->getContent(), true);
        if (!is_array($decoded)) {
            return Response::validation(['body' => 'A JSON object is required.']);
        }
        /** @var array<string,mixed> $body */
        $body = $decoded;
        if (!array_key_exists('base_domain', $body)
            || (!is_string($body['base_domain']) && $body['base_domain'] !== null)) {
            return Response::validation(['base_domain' => 'Must be a hostname or null.']);
        }
        if (!array_key_exists('default_hosts', $body) || !is_array($body['default_hosts'])) {
            return Response::validation(['default_hosts' => 'Must be a list of hostnames.']);
        }
        foreach ($body['default_hosts'] as $host) {
            if (!is_string($host)) {
                return Response::validation(['default_hosts' => 'Every host must be a string.']);
            }
        }
        $base = $body['base_domain'];
        /** @var list<string> $hosts */
        $hosts = array_values($body['default_hosts']);

        try {
            $status = $this->service->save($base, $hosts);
        } catch (PublicOriginValidationException $e) {
            return Response::validation($e->errors);
        } catch (PublicOriginWriteConflict $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (EnablementLockedException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT);
        }

        return Response::success(['public_origin' => $status]);
    }
}
```

- [ ] **Step 4: Register the routes.** In `routes/enablement.php`, inside the existing `/v1/admin` group, mirroring the resolution routes' guard chain:

```php
    $router->get('/tenancy/public-origin', [PublicOriginController::class, 'show'])
        ->middleware('tenant_system')
        ->middleware('content_permission:tenancy.manage');
    $router->put('/tenancy/public-origin', [PublicOriginController::class, 'update'])
        ->middleware('tenant_system')
        ->middleware('content_permission:tenancy.manage');
```

The source-confirmed routes file uses the local `$router` variable and chained `middleware()` calls
shown above; add the `use` import for `PublicOriginController`.

- [ ] **Step 5: Run tests + phpcs** → PASS + clean.

- [ ] **Step 6: Commit — SKIP (HELD).**

---

### Task 8: Resolution reset endpoint

**Files:**
- Modify: `packages/thallo-tenancy/src/Http/Controllers/TenancyResolutionController.php`
- Modify: `packages/thallo-tenancy/routes/enablement.php`
- Test: add to `tests/Integration/Tenancy/PublicOriginApiTest.php` or the existing resolution controller test.

**Interfaces:**
- Consumes: `FullResolutionActivation::resetFailed()` (Task 6).
- Produces: `POST /v1/admin/tenancy/resolution/reset` → `{ resolution: <status> }`; lifecycle conflict and lock conflict are consistently 409 unless the reset succeeds from `FAILED`.

- [ ] **Step 1: Write the failing test.**

```php
public function testResetRejectedWhenNotFailed(): void
{
    $res = $this->call('POST', '/v1/admin/tenancy/resolution/reset'); // step INACTIVE
    self::assertSame(409, $res->getStatusCode());
}

public function testResetReturnsResolutionStatusFromFailed(): void
{
    $this->forceFailedState();
    $res = $this->call('POST', '/v1/admin/tenancy/resolution/reset');
    self::assertSame(200, $res->getStatusCode());
    self::assertSame('inactive', $this->json($res)['data']['resolution']['step']);
}
```

- [ ] **Step 2: Run — expect failure.**

- [ ] **Step 3: Add the controller method** (mirror `deactivate()`'s catch shape):

```php
    public function reset(): Response
    {
        try {
            return Response::success(['resolution' => $this->activation->resetFailed()]);
        } catch (EnablementLockedException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (EnablementException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT, [
                'resolution' => $this->activation->status(),
            ]);
        }
    }
```

- [ ] **Step 4: Register the route** in `routes/enablement.php` beside the other resolution routes:

```php
    $router->post('/tenancy/resolution/reset', [TenancyResolutionController::class, 'reset'])
        ->middleware('tenant_system')
        ->middleware('content_permission:tenancy.manage');
```

- [ ] **Step 5: Run tests + phpcs** → PASS + clean.

- [ ] **Step 6: Commit — SKIP (HELD).**

---

### Task 9: SPA query — `publicOrigin.ts`

**Files:**
- Create: `admin/src/queries/publicOrigin.ts`
- Test: `admin/src/__tests__/publicOrigin.spec.ts`

**Interfaces:**
- Consumes: `authFetch`, `runtimeConfig.apiBase` (mirror `signupSettings.ts`).
- Produces: `PublicOriginStatus` type; `fetchPublicOrigin()`; `savePublicOrigin({ base_domain, default_hosts })`.

- [ ] **Step 1: Write the failing test** (mock `authFetch`, assert GET/PUT URL + body):

```ts
import { describe, expect, it, vi, beforeEach } from 'vitest'
const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))
import { fetchPublicOrigin, savePublicOrigin } from '@/queries/publicOrigin'

describe('publicOrigin query', () => {
  beforeEach(() => authFetch.mockReset())

  it('fetches status', async () => {
    authFetch.mockResolvedValue({ data: { public_origin: { base_domain: 'a.example' } } })
    const s = await fetchPublicOrigin()
    expect(authFetch).toHaveBeenCalledWith('/v1/admin/tenancy/public-origin')
    expect(s.base_domain).toBe('a.example')
  })

  it('saves via PUT', async () => {
    authFetch.mockResolvedValue({ data: { public_origin: { base_domain: 'a.example' } } })
    await savePublicOrigin({ base_domain: 'a.example', default_hosts: ['a.example'] })
    expect(authFetch).toHaveBeenCalledWith(
      '/v1/admin/tenancy/public-origin',
      expect.objectContaining({ method: 'PUT' }),
    )
  })
})
```

- [ ] **Step 2: Run — expect failure.**

- [ ] **Step 3: Implement** (mirror `signupSettings.ts` unwrap + authFetch):

```ts
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export interface PublicOriginStatus {
  base_domain: string | null
  default_hosts: string[]
  applied_base_domain: string | null
  applied_default_hosts: string[]
  base_domain_source: 'flag' | 'config' | 'unset'
  default_hosts_source: 'flag' | 'config' | 'unset'
  step: string
  origin_restart_required: boolean
}

function unwrap(json: any): PublicOriginStatus {
  return (json?.data?.public_origin ?? json?.public_origin) as PublicOriginStatus
}

const url = `${runtimeConfig.apiBase}/tenancy/public-origin`

export async function fetchPublicOrigin(): Promise<PublicOriginStatus> {
  return unwrap(await authFetch(url))
}

export async function savePublicOrigin(input: {
  base_domain: string | null
  default_hosts: string[]
}): Promise<PublicOriginStatus> {
  return unwrap(await authFetch(url, { method: 'PUT', body: JSON.stringify(input) }))
}
```

- [ ] **Step 4: Run test + type-check** (`pnpm type-check`, exit 0; do NOT pipe through `tail`). Commit — SKIP (HELD).

---

### Task 10: SPA component — `PublicOriginSettings.vue`

**Files:**
- Create: `admin/src/components/tenancy/PublicOriginSettings.vue`
- Test: `admin/src/__tests__/publicOriginSettings.spec.ts`

**Interfaces:**
- Consumes: `fetchPublicOrigin`/`savePublicOrigin` (Task 9).
- Behavior: base-domain `UInput` + hosts editor (textarea → `string[]` split on comma/newline); form fields use desired values; when desired differs from boot-applied values, show the currently applied origin until restart; dirty-aware Save; **freeze (read-only)** when `step !== 'inactive'`; surface `origin_restart_required` as a "restart to continue" note; server errors surfaced; `data-testid`: `public-origin-settings`, `public-origin-base-domain`, `public-origin-hosts`, `public-origin-save`, `public-origin-restart-note`, `public-origin-frozen`.

- [ ] **Step 1: Write the failing test** (mock the query module; mirror `WorkspaceSignupSettings` test patterns — assert on `data-testid`, not Nuxt UI internals):

```ts
// mock fetch to return step 'mapping_hosts' -> frozen; and origin_restart_required true -> note shown
// assert: save button disabled when frozen; restart note rendered; dirty toggles enable save
```

- [ ] **Step 2: Run — expect failure.**

- [ ] **Step 3: Implement** the component — card language mirroring `WorkspaceSignupSettings.vue`; `onMounted` load; computed `frozen = status.step !== 'inactive'`; `dirty`; Save calls `savePublicOrigin` and binds the returned **desired** values without replacing them with the old applied snapshot; disable Save when `frozen || !dirty`; show the restart note and applied values when `status.origin_restart_required`. Setup-store-free (local component state is fine); no `UAuthForm`.

- [ ] **Step 4: Run test + type-check** → PASS, exit 0. Commit — SKIP (HELD).

---

### Task 11: Wire into the workspaces settings page + Resolution reset

**Files:**
- Modify: `admin/src/pages/settings/workspaces/index.vue`
- Modify: `admin/src/components/tenancy/ResolutionPanel.vue`
- Modify: `admin/src/queries/tenancyResolution.ts` (add `origin_restart_required` to `ResolutionStatus`; add a `reset` mutation)
- Test: extend `admin/src/__tests__/tenancyNav.spec.ts`-adjacent resolution tests / add a workspaces-page test if one exists.

**Interfaces:**
- Consumes: `PublicOriginSettings.vue`; the reset endpoint.
- Produces: origin form directly **above** the Resolution panel (shown when multi-workspace mode enabled and resolution not `full`); Resolution panel shows **Reset activation** at `FAILED`; `ResolutionStatus.origin_restart_required` consumed for the "restart to continue" note.

- [ ] **Step 1: Extend `ResolutionStatus`** in `tenancyResolution.ts`:

```ts
export interface ResolutionStatus {
  step: ResolutionStep
  mode: string
  failure: string | null
  fresh_boot_required: boolean
  origin_restart_required: boolean
}
```

Add a `reset` mutation mirroring `activate`/`deactivate`:

```ts
export function resetResolution(): Promise<ResolutionStatus> {
  return postResolution('/tenancy/resolution/reset', '{}')
}
```

and expose it from `useTenancyResolutionMutations()` (mirror the existing `useMutation` + `onSettled: invalidate`).

- [ ] **Step 2: Place the form** in `settings/workspaces/index.vue` directly above `ResolutionPanel`, gated `v-if="enablement?.enabled && resolution?.step !== 'full'"`:

```vue
        <PublicOriginSettings v-if="enablement?.enabled && resolution?.step !== 'full'" />
        <ResolutionPanel ... />
```

- [ ] **Step 3: Add Reset activation** to `ResolutionPanel.vue`: when `status.step === 'failed'`, render a `UButton` (`data-testid="resolution-action-reset"`) that emits `reset`; wire the page to call `resetResolution()`. Surface `status.origin_restart_required` as a restart note in the panel.

- [ ] **Step 4: Update existing resolution tests** for the new `ResolutionStatus` field and the reset control. Run vitest + `pnpm type-check` (exit 0). Commit — SKIP (HELD).

---

### Task 12: Regression sweep + release chain

**Files:** none (verification + process).

- [ ] **Step 1: Byte-identical regression.** With both persisted values unset: run the full Thallo tenancy backend suite and the admin SPA vitest suite. Confirm green and that no pre-existing test changed behavior. Run `composer phpcs` (thallo tenancy pack), `pnpm type-check`, and `pnpm build-only` from `admin/` (all exit 0).

- [ ] **Step 2: Live smoke (thallodev.dev / lemma).** Against the vendored-framework build: set base domain + hosts via the new UI form → observe `origin_restart_required` → restart → Resolution activates through to `full` → create a second workspace. Confirm `.env` `TENANCY_*` still works when the flags are unset.

- [ ] **Step 3: Framework release.** Using the `release` skill, release `glueful/framework` with `overrideConfig()` + `markBooted()` wiring (framework repo commit only; no tags; user publishes).

- [ ] **Step 4: Pin.** After the framework is published, bump `thallo/composer.json`'s `glueful/framework` constraint to the released version and remove the local vendored-copy edits (composer install restores the published files).

- [ ] **Step 5: Docs/tracker.** Tick the `OUTSTANDING.md` follow-up (`Multi-workspace setup: admin-settable resolution hosts`) to shipped with the date + spec/plan links.

- [ ] **Step 6: Commit — only when explicitly told (HELD).**

---

## Self-Review

- **Spec coverage:** Pin 1 (revision gate: Tasks 3 store + 6 advance/retry), Pin 2 (write-gate 409 Task 4/7; `resetFailed` Task 6/8), Pin 3 (normalization Task 4), Pin 4 (hydrate Task 5) — all mapped. Framework primitive + `markBooted` (Tasks 1–2). API (7–8), SPA (9–11), regression + release (12). Source reporting `flag|config|unset` and desired-vs-applied snapshots (Tasks 3–4). Two-session contention/write-first/activation-first outcomes are executable in Task 4; the invariant is no stale activation. Apex-as-host accepted, reserved subdomain rejected, and host ordering normalized away (Task 4).
- **Type consistency:** `origin_restart_required` produced by `FullResolutionActivation::status()` (Task 6) and `PublicOriginService::status()` (Task 4), consumed by `ResolutionStatus`/`PublicOriginStatus` (Tasks 9/11). `writeChanged`/`save`/`resetFailed`/`resetFromFailed` signatures consistent across producer/consumer tasks.
- **No unresolved contract guesses:** source-confirmed details are pinned: `InvalidHostException` is under `...\Exceptions`, the provider uses class/shared/autowire service definitions, `enablement.php` uses `$router` with chained middleware, framework boot coverage extends the real integration harness, and tenancy tests live in the app's `tests/` tree.
