# Lemma Composable Core — Phase B: Capability Spine — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the capability spine — a `Capability` descriptor + `CapabilityRegistry` (contracts), an engine registry with config-gated enablement (`config/lemma.php` switchboard), and a `GET /v1/admin/capabilities` endpoint reporting the enabled set — so packs can declare capabilities that the admin discovers, without any behavior change.

**Architecture:** Phase B of four (A contracts ✅ → **B capability spine** → C admin registry → D reference extraction). It implements the spec's three-layer model: **Installed** (a pack's ServiceProvider registers a `Capability` into the shared `CapabilityRegistry`), **Enabled** (the `lemma.capabilities` config switchboard gates it; default-on when installed), **Reported** (`GET /v1/admin/capabilities` returns the enabled set). Core itself is **not** a capability, so in Phase B the registry is empty in production (the first real capability arrives with the reference pack in Phase D); the spine is proven with test-fake capabilities.

**Tech Stack:** PHP 8.3, Glueful framework 1.64, `glueful/lemma-contracts` (path package from Phase A), PHPUnit 10.5. Pure-unit tests extend `PHPUnit\Framework\TestCase`; integration/HTTP tests extend `App\Tests\Support\LemmaTestCase` (boots the app; `$this->container()`, `$this->findRoute()`, direct-controller construction). Admin endpoints are tested by constructing the controller directly and asserting on the returned `Glueful\Http\Response`, plus a `findRoute()` assertion for route+middleware (the established pattern in `tests/Integration/Http/AdminConfigApiTest.php`).

## Global Constraints

- **Contracts hold interfaces + DTOs + VOs only** — no engine logic, storage, or I/O in `packages/lemma-contracts`; no `App\*` reference; never a dependency on `glueful/lemma`. New contracts live under `Glueful\Lemma\Contracts\Capability\`.
- **Core is NOT a capability.** Only packs register capabilities. Do not register any core capability; the production endpoint returns an empty list until a pack registers one (Phase D).
- **Capability ids contain dots** (e.g. `lemma.forms`). Never read a per-capability flag via dotted config access — read the whole `lemma.capabilities` array once and index by the full id string.
- **Default-on when installed.** A registered (installed) capability is enabled unless its id is present in `lemma.capabilities` with value `false`.
- **The endpoint reports the ENABLED set only** (id, label, description, requires). Admin-contribution descriptors (nav/routes/settings/field-widgets) are **deferred to the future runtime model** — Phase C's static registry matches by capability id and needs no backend descriptors, so do not build them here or in Phase C.
- **No behavior change** to existing routes/endpoints; all changes are additive.
- **Every new Lemma HTTP controller must be registered in `App\Providers\LemmaServiceProvider::services()`** (with a `use` import).
- **New PHP files are PSR-12 clean** (blank line after `<?php`). Before committing, run `vendor/bin/phpcbf` then `vendor/bin/phpcs` on your new/changed files and ensure phpcs is clean. (`packages/` is outside the default phpcs scope, but `app/`, `config/`, `routes/`, `tests/` are in scope.)
- **Commit gate:** Do **NOT** run `git commit` until the human explicitly authorizes it. Each task's final step keeps its `git add` (staging) but its `git commit` line runs **only after authorization** — until then, stage, stop, and report. This applies to every task below. Never push or open a PR.
- **Glueful is NOT Laravel.** Service definitions are a static `services()` array; `config($context, 'key', $default)`; `Response::success($data, $message)`; resolve via `$this->container()->get(X::class)`.

---

### Task 1: `Capability` VO + `CapabilityRegistry` contract

**Files:**
- Create: `packages/lemma-contracts/src/Capability/Capability.php`
- Create: `packages/lemma-contracts/src/Capability/CapabilityRegistry.php`
- Test: `tests/Unit/Contracts/CapabilityContractTest.php`

**Interfaces:**
- Produces:
  - `Glueful\Lemma\Contracts\Capability\Capability` — a final VO with public readonly props: `string $id`, `list<string> $requires`, `?string $label`, `?string $description`. Constructor: `__construct(string $id, array $requires = [], ?string $label = null, ?string $description = null)`.
  - `Glueful\Lemma\Contracts\Capability\CapabilityRegistry` — interface: `register(Capability $capability): void`, `all(): array` (`list<Capability>`, installed), `enabled(): array` (`list<Capability>`, installed AND enabled), `isEnabled(string $id): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Contracts;

use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityContractTest extends TestCase
{
    public function testCapabilityValueObjectExposesItsFields(): void
    {
        $cap = new Capability('lemma.forms', ['lemma.collections'], 'Forms', 'Public form submissions');
        self::assertSame('lemma.forms', $cap->id);
        self::assertSame(['lemma.collections'], $cap->requires);
        self::assertSame('Forms', $cap->label);
        self::assertSame('Public form submissions', $cap->description);
    }

    public function testCapabilityDefaults(): void
    {
        $cap = new Capability('lemma.render');
        self::assertSame([], $cap->requires);
        self::assertNull($cap->label);
        self::assertNull($cap->description);
    }

    public function testRegistryContractShape(): void
    {
        self::assertTrue(interface_exists(CapabilityRegistry::class));
        foreach (['register', 'all', 'enabled', 'isEnabled'] as $method) {
            self::assertTrue(
                method_exists(CapabilityRegistry::class, $method),
                "CapabilityRegistry must declare {$method}()"
            );
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Contracts/CapabilityContractTest.php`
Expected: FAIL — `Class "Glueful\Lemma\Contracts\Capability\Capability" not found`.

- [ ] **Step 3: Create the `Capability` VO**

`packages/lemma-contracts/src/Capability/Capability.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Capability;

/**
 * A capability a pack provides — an id (e.g. "lemma.forms"), the capability ids it
 * requires, and human-readable metadata. Pure value object; carries no behavior.
 */
final class Capability
{
    /** @param list<string> $requires Capability ids this one depends on. */
    public function __construct(
        public readonly string $id,
        public readonly array $requires = [],
        public readonly ?string $label = null,
        public readonly ?string $description = null,
    ) {
    }
}
```

- [ ] **Step 4: Create the `CapabilityRegistry` contract**

`packages/lemma-contracts/src/Capability/CapabilityRegistry.php`:
```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Capability;

/**
 * Holds the capabilities registered by installed packs and reports which are enabled.
 * "Installed" = registered here (by a pack's service provider). "Enabled" = installed
 * AND not disabled by the host's capability switchboard. Core registers nothing.
 */
interface CapabilityRegistry
{
    public function register(Capability $capability): void;

    /** @return list<Capability> Every registered (installed) capability. */
    public function all(): array;

    /** @return list<Capability> Installed capabilities that are also enabled. */
    public function enabled(): array;

    public function isEnabled(string $id): bool;
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Contracts/CapabilityContractTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Lint**

Run: `vendor/bin/phpcbf packages/lemma-contracts/src/Capability tests/Unit/Contracts/CapabilityContractTest.php && vendor/bin/phpcs tests/Unit/Contracts/CapabilityContractTest.php`
Expected: phpcs clean on the test (the `packages/` files are out of default scope but were normalized by phpcbf).

- [ ] **Step 7: Stage (commit only when authorized — see Global Constraints)**

```bash
git add packages/lemma-contracts/src/Capability tests/Unit/Contracts/CapabilityContractTest.php
# When authorized:
git commit -m "Add Capability VO + CapabilityRegistry contract"
```

---

### Task 2: `DefaultCapabilityRegistry` engine implementation (config-gated)

**Files:**
- Create: `app/Capabilities/DefaultCapabilityRegistry.php`
- Test: `tests/Unit/Capabilities/DefaultCapabilityRegistryTest.php`

**Interfaces:**
- Consumes: `Glueful\Lemma\Contracts\Capability\Capability`, `Glueful\Lemma\Contracts\Capability\CapabilityRegistry` (Task 1).
- Produces: `App\Capabilities\DefaultCapabilityRegistry implements CapabilityRegistry`. Constructor: `__construct(array $overrides = [])` where `$overrides` is the `id => bool` switchboard map (absent id ⇒ enabled, `false` ⇒ disabled). `register`/`all`/`enabled`/`isEnabled` as in the contract. Pure (no config/IO of its own — the override map is injected), so it is unit-testable directly.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Capabilities;

use App\Capabilities\DefaultCapabilityRegistry;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class DefaultCapabilityRegistryTest extends TestCase
{
    public function testRegistersAndListsAll(): void
    {
        $reg = new DefaultCapabilityRegistry();
        self::assertInstanceOf(CapabilityRegistry::class, $reg);
        self::assertSame([], $reg->all());

        $forms = new Capability('lemma.forms', label: 'Forms');
        $render = new Capability('lemma.render');
        $reg->register($forms);
        $reg->register($render);

        self::assertSame(['lemma.forms', 'lemma.render'], array_map(fn (Capability $c) => $c->id, $reg->all()));
    }

    public function testEnabledByDefaultWhenNoOverride(): void
    {
        $reg = new DefaultCapabilityRegistry(); // empty switchboard => default-on
        $reg->register(new Capability('lemma.forms'));
        self::assertTrue($reg->isEnabled('lemma.forms'));
        self::assertSame(['lemma.forms'], array_map(fn (Capability $c) => $c->id, $reg->enabled()));
    }

    public function testOverrideDisablesACapability(): void
    {
        $reg = new DefaultCapabilityRegistry(['lemma.forms' => false]);
        $reg->register(new Capability('lemma.forms'));
        $reg->register(new Capability('lemma.render'));

        self::assertFalse($reg->isEnabled('lemma.forms'));
        self::assertTrue($reg->isEnabled('lemma.render'));
        self::assertSame(['lemma.render'], array_map(fn (Capability $c) => $c->id, $reg->enabled()));
    }

    public function testUnregisteredIdIsNotEnabled(): void
    {
        $reg = new DefaultCapabilityRegistry();
        self::assertFalse($reg->isEnabled('lemma.nope')); // not installed => not enabled
    }

    public function testExplicitTrueOverrideIsEnabled(): void
    {
        $reg = new DefaultCapabilityRegistry(['lemma.forms' => true]);
        $reg->register(new Capability('lemma.forms'));
        self::assertTrue($reg->isEnabled('lemma.forms'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Capabilities/DefaultCapabilityRegistryTest.php`
Expected: FAIL — `Class "App\Capabilities\DefaultCapabilityRegistry" not found`.

- [ ] **Step 3: Implement the registry**

`app/Capabilities/DefaultCapabilityRegistry.php`:
```php
<?php

declare(strict_types=1);

namespace App\Capabilities;

use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

/**
 * In-memory capability registry. Packs register their Capability during boot; the host's
 * switchboard ($overrides, the `lemma.capabilities` config map keyed by full capability id)
 * decides which installed capabilities are enabled. Absent id => enabled (default-on);
 * `false` => disabled.
 */
final class DefaultCapabilityRegistry implements CapabilityRegistry
{
    /** @var array<string,Capability> */
    private array $capabilities = [];

    /** @param array<string,bool> $overrides Full-capability-id => enabled flag. */
    public function __construct(private readonly array $overrides = [])
    {
    }

    public function register(Capability $capability): void
    {
        $this->capabilities[$capability->id] = $capability;
    }

    /** @return list<Capability> */
    public function all(): array
    {
        return array_values($this->capabilities);
    }

    /** @return list<Capability> */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->capabilities,
            fn (Capability $c): bool => $this->isEnabled($c->id),
        ));
    }

    public function isEnabled(string $id): bool
    {
        return isset($this->capabilities[$id]) && ($this->overrides[$id] ?? true) === true;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Capabilities/DefaultCapabilityRegistryTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Lint**

Run: `vendor/bin/phpcbf app/Capabilities tests/Unit/Capabilities && vendor/bin/phpcs app/Capabilities tests/Unit/Capabilities`
Expected: phpcs clean.

- [ ] **Step 6: Stage (commit only when authorized — see Global Constraints)**

```bash
git add app/Capabilities tests/Unit/Capabilities
# When authorized:
git commit -m "Add DefaultCapabilityRegistry with config-gated enablement"
```

---

### Task 3: Config switchboard + DI binding (factory reading config)

**Files:**
- Modify: `config/lemma.php` (add the `capabilities` switchboard array)
- Modify: `app/Providers/LemmaServiceProvider.php` (add `use` imports, a `makeCapabilityRegistry` factory, and the `CapabilityRegistry` binding)
- Test: `tests/Integration/Capabilities/CapabilityRegistryWiringTest.php`

**Interfaces:**
- Consumes: `App\Capabilities\DefaultCapabilityRegistry` (Task 2); `Glueful\Lemma\Contracts\Capability\CapabilityRegistry` + `Capability` (Task 1); the framework `config($context, 'lemma.capabilities', [])` helper; the existing factory-binding pattern (`PathRenderer::class => ['factory' => [self::class, 'makePathRenderer'], 'shared' => true]` at `LemmaServiceProvider.php:178`).
- Produces: container binding so `$this->container()->get(CapabilityRegistry::class)` returns a **shared** `DefaultCapabilityRegistry` built from the `lemma.capabilities` config map. A new static `LemmaServiceProvider::makeCapabilityRegistry(ContainerInterface $container): DefaultCapabilityRegistry`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Capabilities;

use App\Capabilities\DefaultCapabilityRegistry;
use App\Providers\LemmaServiceProvider;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class CapabilityRegistryWiringTest extends LemmaTestCase
{
    public function testContractResolvesToTheEngineRegistry(): void
    {
        $reg = $this->container()->get(CapabilityRegistry::class);
        self::assertInstanceOf(DefaultCapabilityRegistry::class, $reg);
    }

    public function testRegistryIsSharedSoRegistrationsPersist(): void
    {
        $reg = $this->container()->get(CapabilityRegistry::class);
        $reg->register(new Capability('test.fake', label: 'Fake'));

        // A second resolve must be the SAME instance (shared) and see the registration.
        $again = $this->container()->get(CapabilityRegistry::class);
        self::assertContains('test.fake', array_map(fn (Capability $c) => $c->id, $again->all()));
        // Default config has no override for test.fake => enabled.
        self::assertTrue($again->isEnabled('test.fake'));
    }

    public function testFactoryReadsTheWholeCapabilitiesMapNotDottedKeys(): void
    {
        // Seed a disabled override for a DOTTED id via the public config-defaults seam.
        // config/lemma.php's `capabilities` is empty, so this default surfaces (defaults
        // merge UNDER file config). A correct factory reads the whole `lemma.capabilities`
        // map and sees `test.fake => false`. A buggy dotted-access impl
        // (config('lemma.capabilities.test.fake')) would walk capabilities['test']['fake'],
        // never find the literal-key 'test.fake', fall back to the default, and wrongly
        // ENABLE it — failing this test.
        $this->appContext()->mergeConfigDefaults('lemma', ['capabilities' => ['test.fake' => false]]);

        // Call the factory directly to build a FRESH registry from the (now-seeded) config,
        // bypassing the shared singleton.
        $reg = LemmaServiceProvider::makeCapabilityRegistry($this->container());
        $reg->register(new Capability('test.fake'));

        self::assertFalse(
            $reg->isEnabled('test.fake'),
            'factory must read the whole lemma.capabilities map (full id key), not via dotted access',
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:migrate && vendor/bin/phpunit tests/Integration/Capabilities/CapabilityRegistryWiringTest.php`
Expected: FAIL — the container has no binding for `CapabilityRegistry` (resolution error / not found).

- [ ] **Step 3: Add the `capabilities` switchboard to `config/lemma.php`**

In `/Users/michaeltawiahsowah/Sites/glueful/lemma/config/lemma.php`, add this entry to the returned array (place it after the `'admin' => [...]` block for readability):
```php
    // Capability switchboard for first-party packs. Each installed pack registers a
    // Capability (id like 'lemma.forms') into the CapabilityRegistry; it is ENABLED by
    // default. List a full capability id here as `false` to DISABLE it without
    // uninstalling the pack (routes/jobs/subscribers/admin contributions are gated by
    // enabled state; migrations are not — they run when installed). Keys are full
    // capability ids (with dots); this whole map is read at once, never via dotted access.
    'capabilities' => [
        // 'lemma.forms' => false,
    ],
```

- [ ] **Step 4: Add the factory + binding to `LemmaServiceProvider`**

In `app/Providers/LemmaServiceProvider.php`:

(a) Add imports near the other `use` statements (place with the other `App\` and `Glueful\Lemma\Contracts\` imports):
```php
use App\Capabilities\DefaultCapabilityRegistry;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
```

(b) Add the binding inside the `services()` array (alongside the other factory bindings near `PathRenderer::class`):
```php
            CapabilityRegistry::class => [
                'factory' => [self::class, 'makeCapabilityRegistry'],
                'shared' => true,
            ],
```

(c) Add the static factory method (next to `makePathRenderer`, around `LemmaServiceProvider.php:565`):
```php
    public static function makeCapabilityRegistry(ContainerInterface $container): DefaultCapabilityRegistry
    {
        $context = $container->get(ApplicationContext::class);
        /** @var array<string,bool> $overrides */
        $overrides = (array) config($context, 'lemma.capabilities', []);

        return new DefaultCapabilityRegistry($overrides);
    }
```
(`ContainerInterface` and `ApplicationContext` are already imported in this file — confirm and reuse the existing imports; do not duplicate.)

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Capabilities/CapabilityRegistryWiringTest.php`
Expected: PASS (3 tests).

> The dotted-key test assumes `$container->get(ApplicationContext::class)` (what the factory reads) is the **same** context instance as `$this->appContext()` (what the test seeds) — true when the context registers itself as a shared container service, which the booted test app does. If the test sees `test.fake` as still enabled, confirm that assumption first (the seed must land on the context the factory reads); it is a test-wiring issue, not a factory bug.

- [ ] **Step 6: Lint**

Run: `vendor/bin/phpcbf app/Providers/LemmaServiceProvider.php config/lemma.php tests/Integration/Capabilities && vendor/bin/phpcs app/Providers/LemmaServiceProvider.php config/lemma.php tests/Integration/Capabilities`
Expected: phpcs clean.

- [ ] **Step 7: Stage (commit only when authorized — see Global Constraints)**

```bash
git add config/lemma.php app/Providers/LemmaServiceProvider.php tests/Integration/Capabilities
# When authorized:
git commit -m "Wire CapabilityRegistry to the lemma.capabilities config switchboard"
```

---

### Task 4: `GET /v1/admin/capabilities` endpoint

**Files:**
- Create: `app/Http/Controllers/CapabilityAdminController.php`
- Create: `app/Http/DTOs/Responses/CapabilityListData.php`
- Create: `app/Http/DTOs/Responses/CapabilityData.php`
- Modify: `routes/lemma_admin.php` (register the route)
- Modify: `app/Providers/LemmaServiceProvider.php` (register the controller in `services()`)
- Test: `tests/Integration/Http/CapabilityAdminApiTest.php`

**Interfaces:**
- Consumes: `Glueful\Lemma\Contracts\Capability\CapabilityRegistry` + `Capability` (Tasks 1–3); `Glueful\Http\Response::success(mixed $data, string $message)`; the admin route group (`prefix '/v1/admin'`, group middleware `['auth']`) and the `lemma_permission:<perm>` middleware in `routes/lemma_admin.php`.
- Produces: `App\Http\Controllers\CapabilityAdminController` with `index(): Response` returning `Response::success(['capabilities' => list<array{id,label,description,requires}>], 'Capabilities retrieved.')` for the **enabled** capabilities. Route `GET /v1/admin/capabilities` guarded by `lemma_permission:system.access`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Capabilities\DefaultCapabilityRegistry;
use App\Http\Controllers\CapabilityAdminController;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Capability\Capability;

final class CapabilityAdminApiTest extends LemmaTestCase
{
    public function testReturnsOnlyEnabledCapabilities(): void
    {
        // Hand-build a registry with one ENABLED and one DISABLED fake. This pins the
        // endpoint to enabled(), NOT all(): an index() that returned all() would wrongly
        // include test.disabled and fail this test.
        $registry = new DefaultCapabilityRegistry(['test.disabled' => false]);
        $registry->register(new Capability('test.fake', ['test.dep'], 'Fake', 'A fake capability'));
        $registry->register(new Capability('test.disabled', label: 'Disabled'));

        $controller = new CapabilityAdminController($registry);
        $resp = $controller->index();

        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getContent(), true);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('capabilities', $body['data']);

        $ids = array_map(fn (array $c) => $c['id'], $body['data']['capabilities']);
        self::assertContains('test.fake', $ids);
        self::assertNotContains('test.disabled', $ids); // disabled capability must be excluded

        $fake = null;
        foreach ($body['data']['capabilities'] as $c) {
            if ($c['id'] === 'test.fake') {
                $fake = $c;
            }
        }
        self::assertNotNull($fake);
        self::assertSame('Fake', $fake['label']);
        self::assertSame('A fake capability', $fake['description']);
        self::assertSame(['test.dep'], $fake['requires']);
    }

    public function testRouteIsRegisteredUnderAdminPermission(): void
    {
        $route = $this->findRoute('GET', '/v1/admin/capabilities');
        self::assertNotNull($route, '/v1/admin/capabilities must be registered');
        $middleware = (array) ($route['middleware'] ?? []);
        self::assertContains(
            'lemma_permission:system.access',
            $middleware,
            'capabilities endpoint must require system.access',
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Http/CapabilityAdminApiTest.php`
Expected: FAIL — `Class "App\Http\Controllers\CapabilityAdminController" not found`.

- [ ] **Step 3: Create the doc-only response DTOs**

`app/Http/DTOs/Responses/CapabilityData.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/** Doc-only shape of one enabled capability in the capabilities response. */
final class CapabilityData implements ResponseData
{
    /** @param list<string> $requires */
    public function __construct(
        public readonly string $id,
        public readonly ?string $label,
        public readonly ?string $description,
        public readonly array $requires,
    ) {
    }
}
```

`app/Http/DTOs/Responses/CapabilityListData.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only envelope for the capabilities response
 * ({@see \App\Http\Controllers\CapabilityAdminController::index()}).
 */
final class CapabilityListData implements ResponseData
{
    /** @param list<CapabilityData> $capabilities */
    public function __construct(
        public readonly array $capabilities,
    ) {
    }
}
```

- [ ] **Step 4: Create the controller**

`app/Http/Controllers/CapabilityAdminController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\DTOs\Responses\CapabilityListData;
use Glueful\Http\Response;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;

/**
 * Reports the ENABLED capabilities (installed packs not disabled by the switchboard) for the
 * admin SPA, which mounts only the modules whose required capability is reported here. Read-only.
 * Admin-contribution descriptors (nav/routes/settings/field-widgets) are a future-runtime concern,
 * not part of the V1 payload (the Phase C static registry matches by capability id).
 */
final class CapabilityAdminController
{
    public function __construct(private readonly CapabilityRegistry $capabilities)
    {
    }

    /** GET /v1/admin/capabilities */
    #[ApiOperation(
        summary: 'List enabled capabilities',
        description: 'Capabilities provided by installed packs and not disabled by the '
            . 'lemma.capabilities switchboard. Requires the `system.access` permission.',
        tags: ['Capabilities'],
    )]
    #[ApiResponse(200, schema: CapabilityListData::class, description: 'Enabled capabilities.')]
    public function index(): Response
    {
        $items = array_map(
            static fn (Capability $c): array => [
                'id' => $c->id,
                'label' => $c->label,
                'description' => $c->description,
                'requires' => $c->requires,
            ],
            $this->capabilities->enabled(),
        );

        return Response::success(['capabilities' => array_values($items)], 'Capabilities retrieved.');
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/lemma_admin.php`, add inside the `/v1/admin` group (next to the other read-only system routes such as `/extensions` and `/health`):
```php
    $router->get('/capabilities', [CapabilityAdminController::class, 'index'])
        ->middleware('lemma_permission:system.access');
```
Add the `use App\Http\Controllers\CapabilityAdminController;` import at the top of `routes/lemma_admin.php` if route handlers there are referenced by imported class name (match the existing style in that file — if it already imports the other admin controllers, add this one alongside them).

- [ ] **Step 6: Register the controller in `services()`**

In `app/Providers/LemmaServiceProvider.php`: add the import `use App\Http\Controllers\CapabilityAdminController;` and the service entry (next to the other admin controllers like `HealthAdminController::class`):
```php
            CapabilityAdminController::class => [
                'class' => CapabilityAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
```

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Http/CapabilityAdminApiTest.php`
Expected: PASS (2 tests).

- [ ] **Step 8: Lint**

Run: `vendor/bin/phpcbf app/Http routes/lemma_admin.php app/Providers/LemmaServiceProvider.php tests/Integration/Http/CapabilityAdminApiTest.php && vendor/bin/phpcs app/Http routes/lemma_admin.php app/Providers/LemmaServiceProvider.php tests/Integration/Http/CapabilityAdminApiTest.php`
Expected: phpcs clean.

- [ ] **Step 9: Confirm no regression, then stage (commit only when authorized — see Global Constraints)**

Run: `vendor/bin/phpunit --testsuite Integration`
Expected: PASS (existing admin/route tests unaffected — the change is additive).

```bash
git add app/Http/Controllers/CapabilityAdminController.php app/Http/DTOs/Responses/CapabilityData.php app/Http/DTOs/Responses/CapabilityListData.php routes/lemma_admin.php app/Providers/LemmaServiceProvider.php tests/Integration/Http/CapabilityAdminApiTest.php
# When authorized:
git commit -m "Add GET /v1/admin/capabilities endpoint reporting enabled capabilities"
```

---

## Phase B — Definition of Done

- `Capability` VO + `CapabilityRegistry` contract exist in `glueful/lemma-contracts` (pure, no `App\*`).
- `DefaultCapabilityRegistry` implements config-gated enablement (default-on; switchboard disables by full id), unit-tested.
- `CapabilityRegistry` resolves from the container as a **shared** instance built from `config('lemma.capabilities')`.
- `GET /v1/admin/capabilities` returns the **enabled** capabilities (id/label/description/requires), guarded by `lemma_permission:system.access`; empty in production (no core capability) until a pack registers one.
- `composer test` green; `composer phpcs` green; no behavior change to existing endpoints.

**Deferred (not built here):** the `registerAdminModule` JS registry + capability-gated SPA nav — **Phase C** (which consumes this `/capabilities` endpoint and matches by capability id; it does **not** extend the payload with backend descriptors). Backend admin-contribution descriptors (nav/routes/settings/field-widgets) are a **future-runtime concern** (the V1 static model doesn't consume them). The first real capability registration arrives with the reference pack — **Phase D**.

---

## Self-Review

**Spec coverage (Phase B = spec §5 three-layer model + §9.B):**
- §5 **Installed** (pack registers a `Capability` into the registry) → Task 1 (contract) + Task 2 (`register`) ✅
- §5 **Enabled** (`config/lemma.php` switchboard, default-on) → Task 2 (gating) + Task 3 (config + factory) ✅
- §5 **Reported** (`GET /v1/admin/capabilities` returns enabled) → Task 4 ✅
- §5 "Core is **not** a capability" → enforced by Global Constraints + DoD (no core capability registered; endpoint empty in prod) ✅
- §5 migrations-gated-by-installed note → documented in the `config/lemma.php` comment (Task 3) ✅
- §4.5 capability contracts in `lemma-contracts` → Task 1 ✅
- §4.6 admin-contribution descriptors → deferred to the future runtime model (Phase C's static registry matches by capability id; noted) ✅
- No spec gap within Phase B scope.

**Placeholder scan:** No TBD/TODO. The two "confirm the existing imports / match the existing style" notes (Task 3 step 4c, Task 4 step 5) are not placeholders — they name the exact file and the exact pre-existing pattern (`ContainerInterface`/`ApplicationContext` imports already present; the route file's existing controller-import style) the implementer reuses rather than guesses.

**Type consistency:**
- `Capability` fields (`id`, `requires`, `label`, `description`) are defined in Task 1 and read identically in Tasks 2 (`$c->id`), 3 (`$c->id`), and 4 (`$c->id/label/description/requires`).
- `CapabilityRegistry` methods (`register`, `all`, `enabled`, `isEnabled`) are defined in Task 1 and used identically in Tasks 2–4.
- `DefaultCapabilityRegistry::__construct(array $overrides = [])` (Task 2) matches the factory call `new DefaultCapabilityRegistry($overrides)` (Task 3).
- The factory binding key (`CapabilityRegistry::class`) matches the container resolution in Tasks 3 and 4 tests.
- Namespaces uniform: contracts under `Glueful\Lemma\Contracts\Capability\`; engine under `App\Capabilities\`; controller/DTOs under `App\Http\*`.
- No type referenced that isn't created in a prior task.
