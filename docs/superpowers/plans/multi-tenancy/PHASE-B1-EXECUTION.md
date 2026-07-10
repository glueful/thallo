# SP1 Phase B1 — Execution Record

Status: **complete, green, commits held.**

## Result

| Task | Deliverable | Tests |
|---|---|---|
| B1.1 | `thallo-tenancy` pack scaffold + `thallo.tenancy` capability + activation | 2/2 |
| B1.2 | `thallo_system_flags` migration + `SystemFlags` K/V store | 3/3 |
| B1.3 | `ThalloTenantTables` owned-table registry + retrofit metadata | 6/6 |
| B1.4 | `registerTenantTables()` compound boot gate | 3/3 |

Full Thallo suite: **1537 pass, 1 pre-existing skip**, phpcs clean. With the dev-link active the
system-channel regression additionally proved (live, not skipped) that `thallo_system_flags` writes
stay unstamped through the framework A2 insert hook + tenancy stamper.

## Dev-linkage (LOCAL ONLY — never committed)

The pack hard-depends on `glueful/extension-contracts` and soft-depends on `glueful/tenancy`
(runtime container-binding check, never in composer `require`). To exercise the A2/A3/A4 chain from
Thallo before the framework/contracts/tenancy releases are cut, link the local sources into
Thallo's gitignored `vendor/`:

```bash
cd vendor/glueful
mv framework framework.orig-1.66.3            && ln -s ../../../framework framework
mv extension-contracts extension-contracts.orig-1.0.0 && ln -s ../../../extensions/contracts extension-contracts
ln -s ../../../extensions/tenancy tenancy
```

`glueful/tenancy` is deliberately **not** added to composer: its own `require` pins
`glueful/extension-contracts: "dev-dev"`, which conflicts with `glueful/email-notification`'s
`^1.0.0` and would destabilise Thallo's whole tree. So it is reached only via the symlink.

### Making tenancy autoloadable for the scoping regressions

`tests/bootstrap.php` is intentionally **left untouched** — hardcoding a soft-dep PSR-4 path there
makes main-suite behavior depend on a developer's filesystem shape. The scoping regression tests
guard on `class_exists(TenantInsertStamper)` and **skip cleanly** when tenancy is not autoloadable
(the committed default — deterministic).

For local runs (and the B2 guard-as-oracle harness), register the namespace via an **explicit
opt-in** rather than an unconditional bootstrap line. Recommended B2 form — an env-flag gate so the
default (no flag) stays deterministic:

```php
// tests/bootstrap.php — opt-in, keyed on an EXPLICIT env flag (not filesystem presence).
if (($_ENV['THALLO_TENANCY_DEV_LINK'] ?? getenv('THALLO_TENANCY_DEV_LINK')) === '1') {
    $tenancySrc = dirname(__DIR__) . '/vendor/glueful/tenancy/src';
    if (is_dir($tenancySrc)) {
        $loader->addPsr4('Glueful\\Extensions\\Tenancy\\', $tenancySrc);
    }
}
```

Run with `THALLO_TENANCY_DEV_LINK=1 composer test`. (Decide at B2 whether this env gate is worth
committing, or stays a local helper.)

### Reversal before release

Restore the real vendored packages before cutting releases / pinning real versions:

```bash
cd vendor/glueful
rm framework extension-contracts tenancy
mv framework.orig-1.66.3 framework
mv extension-contracts.orig-1.0.0 extension-contracts
```

## Implementation corrections vs. the B1 plan

1. **Migration is namespace-less** — every sibling pack migration is namespace-less (loader
   convention); the plan's `namespace Thallo\Tenancy\Migrations;` would have diverged.
2. **`AppTestCase` accessor** — base exposes `appContext()`, not the plan's `context()`.
3. **Container has no `set()`** — Glueful's container (`has/get/with/reset/load`, process-shared)
   can't bind a fake mid-test. Used the plan's authorized fallback: `registerTenantTables()` takes
   injectable `registry`/`flags` seams (default to container lookups); the test injects the fake.
4. **Provider construction** — `ServiceProvider::__construct(ContainerInterface $app)` is required;
   the test passes `$this->container()`.
5. **Harness order-independence (added):** `thallo_system_flags` backs a shared+memoized
   `SystemFlags`; without harness cleanup a test that enables tenancy would leak scoping-on
   suite-wide (arming the stamper). Added a `hasTable`-guarded truncation + `SystemFlags::clearCache()`
   to `AppTestCase::setUp()`, mirroring the existing `SettingsStore`/`regions` handling.
6. **Test-migration runner** — registered `packages/thallo-tenancy/migrations` in
   `scripts/run-test-migrations.php` (harness hand-registers each pack's path).
7. **phpcs line-length** — wrapped the `row()` `@return` shape docblock + one long `foreach`.

## Committed-state contents (all held for explicit go-ahead)

- `packages/thallo-tenancy/` (pack)
- root `composer.json` + `composer.lock` (path repo + require + lock entry)
- `config/extensions.php` (provider activation)
- `scripts/run-test-migrations.php` (test-migration path)
- `tests/Unit/Tenancy/`, `tests/Integration/Tenancy/` (B1 tests)
- `tests/Support/AppTestCase.php` (system-flags cleanup)

**Not committed:** `tests/bootstrap.php` (untouched); `vendor/` symlinks (gitignored, local dev
only); dev-DB/test-DB migrations (local-only).
