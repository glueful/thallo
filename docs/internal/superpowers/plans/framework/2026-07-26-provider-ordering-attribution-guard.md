# Framework: Declarative Provider Ordering, Package Attribution, Protected Providers — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** One framework release supplying the three general seams the modules-not-extensions
spec (§8, `thallo/docs/superpowers/specs/2026-07-25-modules-not-extensions-design.md`) requires:
(1) one declarative provider order shared by container compilation, live discovery, cache
generation, and cached boot; (2) type-agnostic provider→composer-package attribution for
permission `managed_by`; (3) a protected-provider guard refusing generic enable/disable for
flow-managed providers.

**Architecture:** A static-metadata interface (`DeclaresLoadOrder`) readable from class strings
without construction; a pure topological orderer applied inside `ProviderClassResolver` (the
single resolution path already shared by `ExtensionManager` and `ContainerFactory`), so every
phase inherits the same order; `sortProviders()` pins declarative participants and keeps
instance-level `OrderedProvider` for third-party boot-only ordering; a `PackageManifest`
ownership map that scans ALL installed packages (any type) for `extra.glueful.provider`; a
config-driven refusal check consulted by the three framework-owned activation-mutation
surfaces. Host-owned mutation surfaces adopt the same guard explicitly.

**Tech Stack:** PHP 8.3, PHPUnit 10, framework repo `/Users/michaeltawiahsowah/Sites/glueful/framework` (branch `dev`).

## Global Constraints

- Framework-generality rule (spec §2): nothing Thallo-specific; every seam must be sensible for
  any host. No references to Thallo anywhere in framework code or tests.
- Backward compatibility: hosts using neither new contract see byte-identical behavior.
  `OrderedProvider` keeps its exact current semantics for non-declarative providers; the
  declarative pinning is a no-op when no provider implements `DeclaresLoadOrder`.
- Cycles in the declarative contract are errors that fail resolution (and therefore cache
  generation and production boot) — never logged fallbacks. Instance-level `OrderedProvider`
  keeps its existing logged-fallback behavior (documented legacy), but that fallback may never
  disturb the resolver-established relative order of declarative participants.
- `writeCacheNow($explicitClasses)` applies the declarative order too. The framework guarantees
  ordering for an explicit list; the caller remains responsible for supplying a complete list.
- Standards: `composer run phpcs` (PSR-12) and `composer run analyse` must pass; new classes
  get full native types + docblocks in the existing house style.
- Commit style: no AI/Claude attribution anywhere. Commit on `dev` directly. Never push.
- CHANGELOG: every task's behavior lands under `## [Unreleased]` as it merges (release cut is a
  separate, later step via the release skill — expected 1.72.0).

## File Structure

- Create: `src/Extensions/DeclaresLoadOrder.php` (interface)
- Create: `src/Extensions/ProviderOrderer.php` (pure orderer)
- Create: `src/Extensions/ProviderOrderCycleException.php`
- Create: `src/Extensions/ProtectedProviders.php`
- Modify: `src/Extensions/ProviderClassResolver.php` (apply orderer)
- Modify: `src/Extensions/ExtensionManager.php` (`sortProviders()` pinning and safe fallback;
  explicit-cache ordering; `packageNameFor()` delegation)
- Modify: `src/Extensions/PackageManifest.php` (`providerOwnership()`)
- Modify: `src/Console/Commands/Extensions/EnableCommand.php`,
  `src/Console/Commands/Extensions/DisableCommand.php`, `src/Controllers/ExtensionsController.php`
  (guard consultation)
- Modify: `config/extensions.php` (framework config: `protected` key + docblock)
- Tests: `tests/Unit/Extensions/ProviderOrdererTest.php`,
  `tests/Unit/Extensions/ProviderOrderParityTest.php`,
  `tests/Unit/Extensions/ProviderOwnershipTest.php`,
  `tests/Unit/Extensions/ProtectedProvidersTest.php`; modify
  `tests/Integration/Console/Extensions/ExtensionCliTest.php` and
  `tests/Unit/Controllers/ExtensionsControllerTest.php`

---

### Task 1: `DeclaresLoadOrder` contract + pure orderer

**Files:**
- Create: `src/Extensions/DeclaresLoadOrder.php`
- Create: `src/Extensions/ProviderOrderCycleException.php`
- Create: `src/Extensions/ProviderOrderer.php`
- Test: `tests/Unit/Extensions/ProviderOrdererTest.php`

**Interfaces:**
- Produces: `DeclaresLoadOrder::loadAfter(): array` + `loadPriority(): int` (STATIC — readable
  from class strings), `ProviderOrderer::order(array $classes): array`,
  `ProviderOrderCycleException` (extends `\RuntimeException`, carries every provider blocked by
  the cycle; it does not falsely claim that downstream blocked providers are cycle members).
- Consumed by Tasks 2's resolver application and pinning.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Extensions;

use Glueful\Extensions\DeclaresLoadOrder;
use Glueful\Extensions\ProviderOrderCycleException;
use Glueful\Extensions\ProviderOrderer;
use PHPUnit\Framework\TestCase;

final class OrdererFixtureA
{
}

final class OrdererFixtureB implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [OrdererFixtureA::class];
    }

    public static function loadPriority(): int
    {
        return 0;
    }
}

final class OrdererFixtureC implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return ['Vendor\\Absent\\Provider'];
    }

    public static function loadPriority(): int
    {
        return -10;
    }
}

final class OrdererFixtureCycleX implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [OrdererFixtureCycleY::class];
    }

    public static function loadPriority(): int
    {
        return 0;
    }
}

final class OrdererFixtureCycleY implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [OrdererFixtureCycleX::class];
    }

    public static function loadPriority(): int
    {
        return 0;
    }
}

final class OrdererFixtureCycleTail implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [OrdererFixtureCycleX::class];
    }

    public static function loadPriority(): int
    {
        return 0;
    }
}

final class OrdererFixtureSelfCycle implements DeclaresLoadOrder
{
    public static function loadAfter(): array
    {
        return [self::class];
    }

    public static function loadPriority(): int
    {
        return 0;
    }
}

final class ProviderOrdererTest extends TestCase
{
    public function testNoMetadataPreservesInputOrder(): void
    {
        $in = [OrdererFixtureA::class, 'Vendor\\Missing\\Cls'];
        self::assertSame($in, ProviderOrderer::order($in));
    }

    public function testAfterEdgeReordersAcrossTheList(): void
    {
        // B declares after:A but appears first — the orderer must move it after A.
        $out = ProviderOrderer::order([OrdererFixtureB::class, OrdererFixtureA::class]);
        self::assertSame([OrdererFixtureA::class, OrdererFixtureB::class], $out);
    }

    public function testAbsentEdgeTargetIsIgnored(): void
    {
        $out = ProviderOrderer::order([OrdererFixtureC::class, OrdererFixtureA::class]);
        // C's absent edge is ignored; its negative priority pulls it first anyway.
        self::assertSame([OrdererFixtureC::class, OrdererFixtureA::class], $out);
    }

    public function testPriorityBreaksTiesThenOriginalPosition(): void
    {
        // C (priority -10) precedes B (0); both unconstrained relative to each other.
        $out = ProviderOrderer::order(
            [OrdererFixtureA::class, OrdererFixtureB::class, OrdererFixtureC::class]
        );
        self::assertSame(
            [OrdererFixtureC::class, OrdererFixtureA::class, OrdererFixtureB::class],
            $out
        );
    }

    public function testCycleThrowsNamingTheCycleAndDownstreamBlockedProvidersAccurately(): void
    {
        try {
            ProviderOrderer::order([
                OrdererFixtureCycleX::class,
                OrdererFixtureCycleY::class,
                OrdererFixtureCycleTail::class,
            ]);
            self::fail('Expected a provider-order cycle.');
        } catch (ProviderOrderCycleException $e) {
            self::assertSame([
                OrdererFixtureCycleX::class,
                OrdererFixtureCycleY::class,
                OrdererFixtureCycleTail::class,
            ], $e->blockedProviders);
            self::assertStringContainsString('blocked by a load-order cycle', $e->getMessage());
        }
    }

    public function testSelfDependencyIsACycleRatherThanAnIgnoredEdge(): void
    {
        $this->expectException(ProviderOrderCycleException::class);
        ProviderOrderer::order([OrdererFixtureSelfCycle::class]);
    }

    public function testDeterministicAcrossRepeatedRuns(): void
    {
        $in = [OrdererFixtureB::class, OrdererFixtureC::class, OrdererFixtureA::class];
        self::assertSame(ProviderOrderer::order($in), ProviderOrderer::order($in));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Extensions/ProviderOrdererTest.php`
Expected: FAIL — `DeclaresLoadOrder` / `ProviderOrderer` not found.

- [ ] **Step 3: Implement**

`src/Extensions/DeclaresLoadOrder.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * Declarative, cross-phase provider load order. STATIC on purpose: the metadata must be
 * readable from class strings during container compilation and cache generation, without
 * constructing providers. Implementers get ONE order used identically by service-definition
 * compilation, register, boot, diagnostics, and the extensions cache.
 *
 * Contrast {@see OrderedProvider}: instance-level, consulted only by the development-time boot
 * sorter — kept for backward compatibility, but it cannot order the compile/cache phases and
 * MUST NOT be combined with this contract on the same provider.
 */
interface DeclaresLoadOrder
{
    /**
     * Providers that MUST load before this one. Edges naming classes absent from the resolved
     * installation are ignored (soft dependency). A cycle among present providers is a
     * resolution ERROR — it fails cache generation and production boot, never a silent fallback.
     *
     * @return list<class-string>
     */
    public static function loadAfter(): array;

    /** Tie-break within the same dependency level; lower loads first. Default 0. */
    public static function loadPriority(): int;
}
```

`src/Extensions/ProviderOrderCycleException.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

final class ProviderOrderCycleException extends \RuntimeException
{
    /** @param list<class-string> $blockedProviders */
    public function __construct(public readonly array $blockedProviders)
    {
        parent::__construct(
            'Providers blocked by a load-order cycle: ' . implode(', ', $blockedProviders)
            . '. Inspect the affected loadAfter() declarations.'
        );
    }
}
```

`src/Extensions/ProviderOrderer.php` — Kahn topological sort; stable seed = priority
(providers without the interface = 0), then original index; edges only from
`DeclaresLoadOrder` implementers whose targets exist in the input list:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * Pure, deterministic orderer for the merged provider class list (spec: one declarative class
 * order for every phase). No construction, no container, no I/O — safe during container
 * compilation and cache generation. Applied by {@see ProviderClassResolver::resolve()}.
 */
final class ProviderOrderer
{
    /**
     * @param list<class-string> $classes
     * @return list<class-string>
     */
    public static function order(array $classes): array
    {
        // Stable seed: priority ASC, then original position.
        $rows = [];
        foreach ($classes as $i => $class) {
            $prio = is_subclass_of($class, DeclaresLoadOrder::class)
                ? $class::loadPriority()
                : 0;
            $rows[] = [$class, $prio, $i];
        }
        usort($rows, static fn (array $a, array $b): int => [$a[1], $a[2]] <=> [$b[1], $b[2]]);
        $seeded = array_column($rows, 0);

        $present = array_flip($seeded);
        $edges = [];
        $indegree = array_fill_keys($seeded, 0);
        foreach ($seeded as $class) {
            $edges[$class] = [];
        }
        foreach ($seeded as $class) {
            if (!is_subclass_of($class, DeclaresLoadOrder::class)) {
                continue;
            }
            foreach ($class::loadAfter() as $dep) {
                if (isset($present[$dep])) {
                    $edges[$dep][] = $class;
                    $indegree[$class]++;
                }
            }
        }

        // Kahn, consuming the seeded order so unconstrained providers keep their seed position.
        $queue = [];
        foreach ($seeded as $class) {
            if ($indegree[$class] === 0) {
                $queue[] = $class;
            }
        }
        $out = [];
        while ($queue !== []) {
            $class = array_shift($queue);
            $out[] = $class;
            foreach ($edges[$class] as $next) {
                if (--$indegree[$next] === 0) {
                    // Insert respecting seed order among currently ready nodes.
                    $queue[] = $next;
                    usort($queue, static function (string $a, string $b) use ($seeded): int {
                        return array_search($a, $seeded, true) <=> array_search($b, $seeded, true);
                    });
                }
            }
        }

        if (count($out) !== count($seeded)) {
            $blocked = array_values(array_diff($seeded, $out));
            throw new ProviderOrderCycleException($blocked);
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit tests/Unit/Extensions/ProviderOrdererTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Extensions/DeclaresLoadOrder.php src/Extensions/ProviderOrderer.php \
  src/Extensions/ProviderOrderCycleException.php tests/Unit/Extensions/ProviderOrdererTest.php
git commit -m "feat(extensions): DeclaresLoadOrder contract + pure deterministic ProviderOrderer"
```

---

### Task 2: Apply the order in the resolver; pin it through the boot sorter

**Files:**
- Modify: `src/Extensions/ProviderClassResolver.php` (after the combine/dedupe at ~line 35)
- Modify: `src/Extensions/ExtensionManager.php` — `sortProviders()` (~line 288) and
  `writeCacheNow()` (~line 424)
- Test: `tests/Unit/Extensions/ProviderOrderParityTest.php`

**Interfaces:**
- Consumes Task 1's orderer.
- Produces: resolver output is the canonical order for ALL phases; `sortProviders()` never
  changes the relative order of two `DeclaresLoadOrder` implementers, including its legacy
  cycle fallback; an explicit `writeCacheNow($classes)` list is ordered before persistence.

- [ ] **Step 1: Write the failing parity test**

Follow `tests/Unit/Extensions/ExtensionManagerTest.php`'s existing harness idiom for building a
manager/context; the test asserts every observed order agrees, using two fixture providers where the
declarative edge INVERTS the raw merge order:

```php
public function testDeclarativeOrderSurvivesResolverCacheAndBoot(): void
{
    // Fixture providers registered as app provider (Late) + enabled extension (Early),
    // where Late declares loadAfter Early but the raw merge puts Late first.
    // 1) ProviderClassResolver::resolve()->providers — Early before Late.
    // 2) ContainerFactory compilation calls the fixture defs() methods in the same order.
    // 3) extensions:diagnose prints its resolved-provider section in the same order.
    // 4) ExtensionManager::writeCacheNow(); require the cache file — same complete order.
    // 5) writeCacheNow([Late, Early]) explicitly — persists [Early, Late].
    // 6) Fresh manager with the cache present: discover(); boot order records — same order.
    // 7) Uncached dev discover() (cache deleted): registerProviders + sortProviders —
    //    relative order of Early/Late unchanged.
}
```

(Write it concretely against the existing test harness — `ExtensionManagerTest` shows how the
suite fakes `installed.json` candidates, the enabled list, and cache paths. The assertion
matrix above is the contract; the harness plumbing follows the file's existing style. Add a
second fixture case combining declarative participants with legacy `OrderedProvider` edges that
form a legacy cycle. Assert the logged fallback still returns the declarative pair in resolver
order.)

- [ ] **Step 2: Run to verify failure** — the resolver does not yet order, so (1) fails.

- [ ] **Step 3: Implement resolver application**

In `ProviderClassResolver::resolve()` replace the combine line:

```php
        // app providers first, then resolved extensions; dedupe preserving order
        $combined = array_values(array_unique([...$app, ...$extResult->providers]));

        // ONE declarative order for every phase (DeclaresLoadOrder): container compilation,
        // live discovery, cache generation, and cached boot all start from this list.
        // A cycle throws ProviderOrderCycleException — resolution fails loudly everywhere.
        $combined = ProviderOrderer::order($combined);

        return new ResolverResult($combined, $extResult->errors);
```

- [ ] **Step 4: Implement boot-sorter pinning**

In `ExtensionManager::sortProviders()`, after the priority seed rows are built and BEFORE the
`bootAfter()` graph edges, add synthetic chain edges pinning declarative participants to their
incoming (resolver-established) relative order:

```php
        // Pin DeclaresLoadOrder participants: their relative order was fixed by the resolver
        // (the ONE cross-phase order) and the legacy instance-level pass must not move them
        // relative to each other. Chain edges D1->D2->...->Dn preserve it through the topo sort.
        $declarative = array_values(array_filter(
            array_keys($this->providers),
            static fn (string $class): bool => is_subclass_of($class, DeclaresLoadOrder::class)
        ));
        for ($d = 1, $n = count($declarative); $d < $n; $d++) {
            $graph[$declarative[$d - 1]][] = $declarative[$d];
            $in[$declarative[$d]]++;
        }
```

(Place after `$graph`/`$in` initialization, alongside the existing `bootAfter()` edge loop.)

- [ ] **Step 5: Preserve declarative order in the legacy-cycle fallback**

Replace the current priority-only fallback with:

```php
        if (count($ordered) !== count($rows)) {
            // Keep the legacy behavior (drop cyclic bootAfter edges and fall back to the
            // priority seed), then re-apply the cross-phase declarative contract. With no
            // declarative providers this is byte-identical because ProviderOrderer sees no
            // metadata and preserves the priority-seeded input.
            $ordered = ProviderOrderer::order(array_map(static fn (array $row): string => $row[0], $rows));
            $this->log('Circular dependency detected in provider bootAfter(), using priority fallback');
        }
```

This is required even though declarative providers are told not to implement `OrderedProvider`
themselves: a legacy provider can still name a declarative provider in `bootAfter()`, creating a
mixed graph cycle. The fallback must not undo the canonical class order.

- [ ] **Step 6: Order explicit cache lists**

In `writeCacheNow()`:

```php
        $classes = ProviderOrderer::order(
            $providerClasses ?? $this->resolveProviderClasses()
        );
```

The no-argument path is already canonical and this is idempotent. The explicit-list path now
gets the same ordering guarantee, but `writeCacheNow()` does not silently add omitted providers:
list completeness remains the caller's responsibility. The downstream Thallo rollout must
replace tenancy `ExtensionActivation`'s extension-only
`writeCacheNow($resolution->providers)` calls with no-argument `writeCacheNow()` after the
configuration mutation.

- [ ] **Step 7: Run parity test + full extension unit suite**

Run: `vendor/bin/phpunit tests/Unit/Extensions/`
Expected: PASS, including all pre-existing tests (backward-compat: no fixture in the existing
suite implements `DeclaresLoadOrder`, so ordering is unchanged for them).

- [ ] **Step 8: Commit**

```bash
git add src/Extensions/ProviderClassResolver.php src/Extensions/ExtensionManager.php \
  tests/Unit/Extensions/ProviderOrderParityTest.php
git commit -m "feat(extensions): resolver applies the declarative order; boot sorter pins participants"
```

---

### Task 3: Type-agnostic provider→package attribution

**Files:**
- Modify: `src/Extensions/PackageManifest.php` (new `providerOwnership()`)
- Modify: `src/Extensions/ExtensionManager.php` (`packageNameFor()` ~line 193 delegates)
- Test: `tests/Unit/Extensions/ProviderOwnershipTest.php`

**Interfaces:**
- Produces: `PackageManifest::providerOwnership(): array<class-string, string>` — provider FQCN
  → package name, scanning ALL installed packages (ANY type) for `extra.glueful.provider`;
  duplicate provider ownership throws `\RuntimeException`.
- `packageNameFor()` result: package name when owned; `'app'` only when no package declares it.

- [ ] **Step 1: Failing tests** — three cases against the manifest fixture idiom used by
  `ExtensionCatalogTest` (fake `installed.json`): (a) a `type: library` package declaring
  `extra.glueful.provider` attributes its provider; (b) a provider declared by NO package →
  `'app'` via `packageNameFor()`; (c) two packages declaring the SAME provider →
  `\RuntimeException` with both package names in the message. In (c), spell one declaration
  with a leading `\` and one without it to prove duplicate detection happens after the same
  FQCN normalization used by `getCandidates()`.

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Implement** — in `PackageManifest`:

```php
    /**
     * Provider FQCN → owning composer package, from `extra.glueful.provider` across ALL
     * installed packages REGARDLESS of type. Extension candidacy stays type-filtered
     * ({@see getCandidates()}); ownership deliberately does not — a host may ship
     * library-typed provider packages (app-integrated modules) that still deserve stable
     * `managed_by` attribution. Two packages claiming one provider is a fatal
     * configuration error, never a silent last-one-wins.
     *
     * @return array<class-string, string>
     */
    public function providerOwnership(): array
    {
        $owners = [];
        foreach ($this->rawPackages() as $name => $pkg) {
            $glueful = is_array($pkg['extra']['glueful'] ?? null) ? $pkg['extra']['glueful'] : [];
            $provider = $glueful['provider'] ?? null;
            if (!is_string($provider)) {
                continue;
            }
            $provider = ltrim($provider, '\\');
            if (!str_contains($provider, '\\')) {
                continue;
            }
            if (isset($owners[$provider])) {
                throw new \RuntimeException(sprintf(
                    'Provider %s is declared by two packages: %s and %s.',
                    $provider,
                    $owners[$provider],
                    $name
                ));
            }
            $owners[$provider] = (string) $name;
        }

        return $owners;
    }
```

And `ExtensionManager::packageNameFor()` becomes:

```php
    private function packageNameFor(string $providerClass): string
    {
        return (new PackageManifest($this->getContext()))->providerOwnership()[$providerClass]
            ?? 'app';
    }
```

- [ ] **Step 4: Run** `vendor/bin/phpunit tests/Unit/Extensions/ProviderOwnershipTest.php
  tests/Unit/Extensions/AggregatePermissionCatalogTest.php` — PASS (the aggregate test guards
  the existing extension-candidate attribution didn't change).

- [ ] **Step 5: Commit**

```bash
git add src/Extensions/PackageManifest.php src/Extensions/ExtensionManager.php \
  tests/Unit/Extensions/ProviderOwnershipTest.php
git commit -m "feat(extensions): type-agnostic provider package attribution (providerOwnership)"
```

---

### Task 4: Protected-provider activation guard

**Files:**
- Create: `src/Extensions/ProtectedProviders.php`
- Modify: `src/Console/Commands/Extensions/EnableCommand.php` (after provider resolution,
  before current-state short circuit)
- Modify: `src/Console/Commands/Extensions/DisableCommand.php` (after provider resolution,
  before current-state short circuit)
- Modify: `src/Controllers/ExtensionsController.php` (`toggle()`: permission → candidate →
  guard → writability/resolve/write)
- Modify: `config/extensions.php` (framework config — add `'protected' => []` with docblock)
- Create: `tests/Unit/Extensions/ProtectedProvidersTest.php` (pure config/refusal behavior)
- Modify: `tests/Integration/Console/Extensions/ExtensionCliTest.php` (enable + disable)
- Modify: `tests/Unit/Controllers/ExtensionsControllerTest.php` (enable + disable)

**Interfaces:**
- Produces: `ProtectedProviders::refusalFor(ApplicationContext $c, string $provider): ?string`
  — non-null message when `config('extensions.protected')` lists the provider. Shape:
  `['Fully\\Qualified\\Provider' => ['reason' => '...', 'managed_by' => '...']]`.
- `ExtensionStateWriter` stays policy-free (spec §8.3): lifecycle flows keep calling it.
- This task guards the framework-owned CLI and controller surfaces. A host controller that calls
  `ExtensionStateWriter` directly must consult the same guard; the modules rollout explicitly
  adds that check to Thallo's app-owned `ExtensionAdminController`.

- [ ] **Step 1: Failing tests** — (a) unlisted provider → null; (b) listed → message contains
  `reason` and `managed_by`; (c) malformed entry (missing reason) → still refuses with a
  generic message naming `managed_by` or the provider. Add a mutation-surface matrix proving:
  CLI enable, CLI disable, controller enable, and controller disable all refuse, write nothing,
  and return/print the configured reason; an unlisted provider still follows the existing path.
  Run the guard immediately after resolving package/needle → provider and before same-state
  early returns or dependency validation, so a protected provider always gives its ownership
  refusal rather than "already enabled", "not enabled", or an unrelated resolver error.

- [ ] **Step 2: Run to verify failure.**

- [ ] **Step 3: Implement:**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Generic-activation refusal for providers whose enable/disable is OWNED elsewhere — a domain
 * lifecycle flow (e.g. glueful/tenancy's enablement state machine) or a product's
 * bundled-required set. Enforcement lives here, ABOVE the policy-free ExtensionStateWriter,
 * so owning flows keep using the low-level writer directly. Host config shape:
 *
 *   'protected' => [
 *       'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider' => [
 *           'reason' => 'Managed by the tenancy enablement flow — use the workspaces admin.',
 *           'managed_by' => 'glueful/tenancy enablement',
 *       ],
 *   ],
 */
final class ProtectedProviders
{
    public static function refusalFor(ApplicationContext $context, string $provider): ?string
    {
        $map = config($context, 'extensions.protected', []);
        if (!is_array($map) || !array_key_exists($provider, $map)) {
            return null;
        }
        $entry = is_array($map[$provider]) ? $map[$provider] : [];
        $reason = is_string($entry['reason'] ?? null) && $entry['reason'] !== ''
            ? $entry['reason']
            : 'This provider\'s activation is managed outside the generic extension commands.';
        $owner = is_string($entry['managed_by'] ?? null) && $entry['managed_by'] !== ''
            ? ' (managed by: ' . $entry['managed_by'] . ')'
            : '';

        return $reason . $owner;
    }
}
```

Wire into `EnableCommand`/`DisableCommand` immediately after `$providerClass` is resolved and
before reading/short-circuiting on the current enabled list
(`$output->writeln("<error>{$refusal}</error>"); return self::FAILURE;` on non-null). In
`ExtensionsController::toggle()`, keep the permission check first, then resolve candidate →
provider and consult the guard before the host-writability check, proposed-list resolution, or
mutation (`Response::error($refusal, 409)` on non-null).
Add `'protected' => []` with the docblock above to the framework's `config/extensions.php`.

There is no installer mutation to wire: the verified framework installer returns `installed`
and explicitly requires a separate enable action. Do not invent an auto-enable path for this
task.

- [ ] **Step 4: Run**
  `vendor/bin/phpunit tests/Unit/Extensions/ProtectedProvidersTest.php
  tests/Integration/Console/Extensions/ExtensionCliTest.php
  tests/Unit/Controllers/ExtensionsControllerTest.php` — PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Extensions/ProtectedProviders.php src/Console/Commands/Extensions/EnableCommand.php \
  src/Console/Commands/Extensions/DisableCommand.php src/Controllers/ExtensionsController.php \
  config/extensions.php tests/Unit/Extensions/ProtectedProvidersTest.php \
  tests/Integration/Console/Extensions/ExtensionCliTest.php \
  tests/Unit/Controllers/ExtensionsControllerTest.php
git commit -m "feat(extensions): protected-provider guard on generic enable/disable surfaces"
```

---

### Task 5: Changelog + full gates

**Files:**
- Modify: `CHANGELOG.md` (`## [Unreleased]`, three `### Added` bullets: declarative ordering,
  type-agnostic attribution, protected providers — each naming the contract and the
  compatibility posture, in the file's existing voice)

- [ ] **Step 1:** Write the changelog entries.
- [ ] **Step 2:** `composer test` — full suite green.
- [ ] **Step 3:** `composer run phpcs` and `composer run analyse` — clean.
- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: changelog for declarative ordering, attribution, protected providers"
```

## Self-review notes (already applied)

- Spec coverage: §8.1 → Tasks 1–2; §8.2 → Task 3; §8.3 → Task 4; "cycles are resolver errors"
  → Task 1 exception + Task 2 resolver placement (cache generation and cached/production boot
  flow through the resolver, and explicit cache lists run through the orderer); legacy-cycle
  fallback cannot undo declarative order → Task 2 mixed-cycle test; "duplicate provider
  ownership is fatal" → Task 3(c), after normalized-FQCN comparison;
  "ExtensionStateWriter stays policy-free" → Task 4 framework wiring sites plus the explicit
  Thallo-rollout adoption requirement.
- Type consistency: `DeclaresLoadOrder` statics are consumed via `is_subclass_of` +
  `$class::loadAfter()` in both Task 1 (orderer) and Task 2 (pinning) — same access pattern.
- Deliberate scope cut: no diagnose output changes (spec §5.4 defers presentation), no
  OrderedProvider deprecation (back-compat pinned), no Thallo references anywhere in framework
  implementation or tests. The plan names downstream host adoption only as a release
  dependency; it does not modify Thallo from the framework commit.
- After release: Thallo consumes this via the modules-not-extensions rollout (spec §7 step 1 —
  pin the released framework before any type flips).
