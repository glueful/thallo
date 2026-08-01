# Background Domain Re-verification Implementation Plan

**Revision:** 2 — review findings integrated.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Periodically re-prove ownership of already-verified custom domains and, past a grace boundary, revoke resolution — closing the domain-takeover window without letting a transient DNS blip knock a live custom domain offline.

**Architecture:** The engine (`glueful/extension-contracts` + `glueful/tenancy`) owns the ownership-proof primitive: a `DomainReverificationResult` DTO, a `reverifyDomain()` method that does snapshot → DNS-outside-lock → guarded optimistic apply with grace/threshold revoke + restore, a structured `DnsTxtResult`, folded `tenant_domains` tracking columns + a `revoked` status, and three after-commit events. Thallo owns orchestration: a global single-runner (session advisory-lock) `DomainReverificationSweepJob`, a manual "re-verify now" route, an audit listener, and a diagnostics coherence check.

**Tech Stack:** PHP 8.3+, PostgreSQL (advisory locks, `IS NOT DISTINCT FROM`, `make_interval`), Glueful framework (`Connection::newPdo`/`afterCommit`, `EventService`/`BaseEvent`, `QueueManager`/`Job`, `JobScheduler` via `config/schedule.php`), the slice-2 per-host advisory-lock helper.

## Global Constraints

- **Release chain:** `glueful/extension-contracts` → `glueful/tenancy` → Thallo. Vendor-first: edit `vendor/glueful/{extension-contracts,tenancy}` in place, test live in Thallo, port to source repos later. **This slice batches with slice 2 into one extension release** (`contracts` + `tenancy` `1.3.0`); pin in Thallo only after publish.
- **PostgreSQL-only.** Advisory locks, `IS NOT DISTINCT FROM`, `make_interval`, `now()` are used deliberately.
- **HOLD ALL COMMITS.** Stage only; never `git commit`/`git add`-then-commit until the user gives an explicit go-ahead. Work on `dev`.
- **No AI/Anthropic attribution.** No `Co-Authored-By`, no "Generated with Claude Code". Never stage/commit `CLAUDE.md` (explicit `git add <paths>`). No git tags. No Packagist publishing.
- **PHP style:** `declare(strict_types=1)`, `final` classes, constructor DI, `use`-imports (no inline FQCNs in bodies), `composer phpcs` clean (120-char, warnings fail).
- **DB time is authoritative** for all threshold/grace math (`now()` in SQL), never PHP time.
- **No DNS I/O inside a transaction or advisory lock.** Snapshot → DNS → guarded apply.
- **Config single source:** re-verification config lives under the engine's `tenancy.domains.reverification.*`.
- **Pre-launch folding:** new `tenant_domains` columns go into the engine's `003_CreateTenantDomainsTable`, not an ALTER (see Task 9 for the local-sync procedure).
- **The `verification_token` never appears in an event payload, log line, or diagnostics output.**

---

## File Structure

**Engine — contracts (`vendor/glueful/extension-contracts/src/Tenancy/`):**
- `DomainReverificationResult.php` — CREATE (neutral DTO crossing the boundary).
- `TenantDomainAdministration.php` — MODIFY: add `reverifyDomain()` signature.

**Engine — tenancy (`vendor/glueful/tenancy/src/`):**
- `migrations/003_CreateTenantDomainsTable.php` — MODIFY: fold 4 columns + index.
- `config/tenancy.php` — MODIFY: add `domains.reverification.*`.
- `Models/TenantDomain.php` — MODIFY: `VERIFICATION_REVOKED` const.
- `Resolution/DnsTxtLookup.php` — MODIFY: structured `lookupStructured()` + `DnsTxtResult`; keep `lookup()` wrapper.
- `Resolution/DnsTxtResult.php` — CREATE (engine-local VO).
- `Bridge/ContractTenantDomainAdministration.php` — MODIFY: `reverifyDomain()` impl + `verifyDomain()` alignment.
- `Events/DomainReverificationFailed.php`, `Events/DomainRevoked.php`, `Events/DomainReverified.php` — CREATE.

**Thallo — pack (`packages/thallo-tenancy/src/`):**
- `Reverification/DomainReverificationSweepJob.php` — CREATE.
- `Reverification/DomainReverificationSweep.php` — CREATE: injectable due-selection/per-domain orchestration.
- `Reverification/DomainReverificationSweepLock.php` — CREATE: dedicated-session advisory-lock owner.
- `Reverification/DomainReverificationAuditListener.php` — CREATE.
- `Http/Controllers/TenantDomainController.php` — MODIFY: `reverify()` method.
- `routes/enablement.php` — MODIFY: add reverify route.
- `Enablement/TenancyDiagnostics.php` — MODIFY: domain-proof coherence check.
- `packages/thallo-tenancy/src/TenancyServiceProvider.php` — MODIFY: register sweep/lock/listener dependencies.

**Thallo — app:**
- `config/schedule.php` — MODIFY: register the hourly sweep.
- `app/Providers/ThalloServiceProvider.php` — MODIFY: wire the audit listener via `EventService::addListener`.
- `admin/src/queries/tenantDomains.ts` — MODIFY: tracking fields + reverify mutation.
- `admin/src/pages/workspaces/[uuid]/domains.vue` — MODIFY: revoked/failure status + reverify action.

**Tests (Thallo, live against vendored engine):**
- `tests/Support/RetrofitHarnessTestCase.php` — MODIFY: include the folded domain-proof columns in template freshness.
- `tests/Integration/Tenancy/DomainReverificationTest.php`, `DomainReverificationSweepTest.php`, `DomainReverifyRouteTest.php`, `DomainReverificationDiagnosticsTest.php` — CREATE.
- `admin/src/__tests__/workspaceDomainReverification.spec.ts` — CREATE.

---

### Task 1: Fold `tenant_domains` tracking columns + index + config + `revoked` const

**Files:**
- Modify: `vendor/glueful/tenancy/migrations/003_CreateTenantDomainsTable.php`
- Modify: `vendor/glueful/tenancy/config/tenancy.php`
- Modify: `vendor/glueful/tenancy/src/Models/TenantDomain.php`
- Modify: `tests/Support/RetrofitHarnessTestCase.php`
- Test: `tests/Integration/Tenancy/DomainReverificationTest.php`

**Interfaces:**
- Produces: `tenant_domains.last_checked_at` (ts null), `last_check_status` (string16 null), `consecutive_failures` (int default 0), `first_failure_at` (ts null); index `(verification_status, last_checked_at)`; `TenantDomain::VERIFICATION_REVOKED = 'revoked'`; config keys `tenancy.domains.reverification.{enabled,recheck_interval_hours,revoked_recheck_interval_hours,failure_threshold,grace_hours,batch_size}`.

- [ ] **Step 1: Write the failing schema+config test**

Create `tests/Integration/Tenancy/DomainReverificationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\RetrofitHarnessTestCase;

final class DomainReverificationTest extends RetrofitHarnessTestCase
{
    public function testTenantDomainsHasReverificationColumns(): void
    {
        $cols = $this->connection()->getPDO()
            ->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'tenant_domains'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        foreach (['last_checked_at', 'last_check_status', 'consecutive_failures', 'first_failure_at'] as $col) {
            self::assertContains($col, $cols, "tenant_domains must have {$col}");
        }
    }

    public function testReverificationConfigDefaults(): void
    {
        $c = $this->appContext();
        self::assertTrue((bool) config($c, 'tenancy.domains.reverification.enabled'));
        self::assertSame(12, (int) config($c, 'tenancy.domains.reverification.recheck_interval_hours'));
        self::assertSame(24, (int) config($c, 'tenancy.domains.reverification.revoked_recheck_interval_hours'));
        self::assertSame(3, (int) config($c, 'tenancy.domains.reverification.failure_threshold'));
        self::assertSame(24, (int) config($c, 'tenancy.domains.reverification.grace_hours'));
        self::assertSame(100, (int) config($c, 'tenancy.domains.reverification.batch_size'));
    }

    public function testRevokedConstant(): void
    {
        self::assertSame('revoked', \Glueful\Extensions\Tenancy\Models\TenantDomain::VERIFICATION_REVOKED);
    }
}
```

- [ ] **Step 2: Extend the throwaway-template freshness check, then run RED**

Extend `RetrofitHarnessTestCase::templateHasLifecycleColumns()` to query both tables and return true only when the existing two `tenants` lifecycle columns **and** all four `tenant_domains` re-verification columns exist. A stale template must be dropped and rebuilt before this suite boots; do not mutate the shared suite database:

```php
        $tenantCount = $template->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_name='tenants' "
            . "AND column_name IN ('deleted_from_status','purge_after')"
        )->fetchColumn();
        $domainCount = $template->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_name='tenant_domains' "
            . "AND column_name IN ('last_checked_at','last_check_status','consecutive_failures','first_failure_at')"
        )->fetchColumn();
        return (int) $tenantCount === 2 && (int) $domainCount === 4;
```

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=DomainReverificationTest tests/Integration/Tenancy/DomainReverificationTest.php`
Expected: FAIL — columns/config/const absent.

- [ ] **Step 3: Fold the columns + index into `003`**

In `vendor/glueful/tenancy/migrations/003_CreateTenantDomainsTable.php`, inside the `createTable` closure, add after `verified_at`:

```php
            $table->timestamp('verified_at')->nullable();
            // Background re-verification (slice 3): last check time/outcome, and the drift counter
            // + first-failure marker that gate grace-then-revoke. Folded pre-launch, not an ALTER.
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_status', 16)->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->timestamp('first_failure_at')->nullable();
```

And add the sweep index alongside the existing indexes:

```php
            $table->index('status');
            $table->index(['verification_status', 'last_checked_at']);
```

- [ ] **Step 4: Add the config keys**

In `vendor/glueful/tenancy/config/tenancy.php`, extend (or add) the `domains` key so it carries both the slice-2 cooldown key and the new re-verification block:

```php
    'domains' => [
        'release_cooldown_days' => (int) env('TENANCY_HOST_COOLDOWN_DAYS', 30),
        'reverification' => [
            'enabled' => (bool) env('TENANCY_REVERIFICATION_ENABLED', true),
            'recheck_interval_hours' => (int) env('TENANCY_REVERIFICATION_INTERVAL_HOURS', 12),
            'revoked_recheck_interval_hours' => (int) env('TENANCY_REVERIFICATION_REVOKED_INTERVAL_HOURS', 24),
            'failure_threshold' => (int) env('TENANCY_REVERIFICATION_FAILURE_THRESHOLD', 3),
            'grace_hours' => (int) env('TENANCY_REVERIFICATION_GRACE_HOURS', 24),
            'batch_size' => (int) env('TENANCY_REVERIFICATION_BATCH_SIZE', 100),
        ],
    ],
```

(If slice 2's `domains.release_cooldown_days` already exists as a flat key, merge — do not duplicate the `domains` key.)

- [ ] **Step 5: Add the `revoked` constant**

In `vendor/glueful/tenancy/src/Models/TenantDomain.php`, after `VERIFICATION_VERIFIED`:

```php
    public const VERIFICATION_VERIFIED = 'verified';
    public const VERIFICATION_REVOKED = 'revoked';
```

(`isPubliclyResolvable()` is unchanged — it already gates on `=== VERIFICATION_VERIFIED`, so `revoked` stops resolving automatically.)

- [ ] **Step 6: Rebuild the disposable template from zero, then run GREEN**

```bash
THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit \
  --filter=DomainReverificationTest tests/Integration/Tenancy/DomainReverificationTest.php
```
Expected: PASS (3 tests).

- [ ] **Step 7: Stage (HOLD)**

```bash
git add vendor/glueful/tenancy/migrations/003_CreateTenantDomainsTable.php \
        vendor/glueful/tenancy/config/tenancy.php \
        vendor/glueful/tenancy/src/Models/TenantDomain.php \
        tests/Support/RetrofitHarnessTestCase.php \
        tests/Integration/Tenancy/DomainReverificationTest.php
# HOLD.
```

---

### Task 2: Structured `DnsTxtResult` + `DnsTxtLookup` + `verifyDomain()` alignment

**Files:**
- Create: `vendor/glueful/tenancy/src/Resolution/DnsTxtResult.php`
- Modify: `vendor/glueful/tenancy/src/Resolution/DnsTxtLookup.php`
- Modify: `vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php`
- Test: `tests/Integration/Tenancy/DomainReverificationTest.php` (append)

**Interfaces:**
- Produces:
  - `DnsTxtResult` — `public readonly string $status` (`'success'|'error'`), `public readonly array $records`; helper `isError(): bool`.
  - `DnsTxtLookup::lookupStructured(string $name): DnsTxtResult`; `lookup(): list<string>` kept as a wrapper (records on success, `[]` on error).
  - `verifyDomain()` stamps `last_checked_at`/`last_check_status` on both success and failure, but leaves `consecutive_failures = 0` / `first_failure_at = null` on a pending failure.
- Consumes: `dns_get_record()`.

- [ ] **Step 1: Write the failing test**

Append to `DomainReverificationTest.php` (drives `verifyDomain` via an injected fake DNS). First add a small fake near the test:

```php
    public function testVerifyDomainStampsCheckMetadataOnSuccessAndFailure(): void
    {
        $c = $this->appContext();
        $admin = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantAdministration::class);
        $tenant = $admin->create($c, 'dv-' . strtolower(\Glueful\Helpers\Utils::generateNanoID(6)), 'DV', \Glueful\Helpers\Utils::generateNanoID(12));
        $this->cleanupTenants[] = $tenant;

        // addDomain sets a token + pending status.
        $host = strtolower(\Glueful\Helpers\Utils::generateNanoID(8)) . '.reverify.test';
        $add = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration::class)
            ->addDomain($c, $tenant, $host);

        // Failing verify (no matching TXT) stamps check metadata but not the drift counter.
        $domains = $this->domainAdminWithDns([]); // fake DNS returns no records
        self::assertSame(
            \Glueful\Extensions\Tenancy\Models\TenantDomain::VERIFICATION_PENDING,
            $domains->verifyDomain($c, $add['uuid'])
        );
        $row = $this->domainRow($host);
        self::assertSame('mismatch', $row['last_check_status']);
        self::assertNotNull($row['last_checked_at']);
        self::assertSame(0, (int) $row['consecutive_failures']);
        self::assertNull($row['first_failure_at']);

        // A fresh pending domain with matching proof transitions to verified and records DB check time.
        $second = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration::class)
            ->addDomain($c, $tenant, 'ok-' . $host);
        $token = $this->tokenOf($second['uuid']);
        self::assertSame(
            \Glueful\Extensions\Tenancy\Models\TenantDomain::VERIFICATION_VERIFIED,
            $this->domainAdminWithDns([$token])->verifyDomain($c, $second['uuid'])
        );
        $verified = $this->domainRow('ok-' . $host);
        self::assertSame('verified', $verified['last_check_status']);
        self::assertNotNull($verified['last_checked_at']);
    }
```

Add helpers to the class: `$cleanupTenants` (array) with a `tearDown` deleting tenant + its domains; `domainRow(string $host): array` (raw `SELECT * FROM tenant_domains WHERE host=?`); and `domainAdminWithDns(array $records)` that constructs `ContractTenantDomainAdministration` with a fake `DnsTxtLookup` subclass whose `lookupStructured()` returns `new DnsTxtResult('success', $records)` (and a `ReleasedHostRepository`). Model the fake on the existing `DnsTxtLookup` seam.

- [ ] **Step 2: Run it to verify it fails**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=testVerifyDomainStampsCheckMetadataOnSuccessAndFailure`
Expected: FAIL — `lookupStructured`/`DnsTxtResult` absent; `verifyDomain` doesn't stamp metadata.

- [ ] **Step 3: Create `DnsTxtResult`**

Create `vendor/glueful/tenancy/src/Resolution/DnsTxtResult.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution;

/** Engine-local structured DNS TXT lookup outcome. NOT an extension contract. */
final class DnsTxtResult
{
    /** @param list<string> $records */
    public function __construct(
        public readonly string $status,
        public readonly array $records = [],
    ) {
    }

    public function isError(): bool
    {
        return $this->status === 'error';
    }
}
```

- [ ] **Step 4: Add `lookupStructured()` and keep `lookup()` as a wrapper**

Replace the body of `vendor/glueful/tenancy/src/Resolution/DnsTxtLookup.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution;

/** Injectable DNS TXT lookup used by domain verification and re-verification. */
class DnsTxtLookup
{
    /** Structured lookup distinguishing a failed query from a successful-but-empty one. */
    public function lookupStructured(string $name): DnsTxtResult
    {
        $records = dns_get_record($name, DNS_TXT);
        if ($records === false) {
            return new DnsTxtResult('error');
        }

        $values = [];
        foreach ($records as $record) {
            $value = $record['txt'] ?? null;
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return new DnsTxtResult('success', $values);
    }

    /**
     * Legacy list API — records on success, [] on error (lossy; lifecycle code must use
     * lookupStructured()).
     *
     * @return list<string>
     */
    public function lookup(string $name): array
    {
        return $this->lookupStructured($name)->records;
    }
}
```

- [ ] **Step 5: Align `verifyDomain()`**

In `ContractTenantDomainAdministration.php`, rewrite `verifyDomain()` to accept **pending domains only**. Verified/revoked domains must go through `reverifyDomain()` so the grace/recovery lifecycle cannot be bypassed. Use the structured lookup and DB time for every timestamp; a pending failure resets the drift fields defensively. Add a private classification helper (reused by Task 4):

```php
    public function verifyDomain(ApplicationContext $c, string $domainUuid): string
    {
        $domain = TenantDomain::query($c)->where('uuid', $domainUuid)->first();
        if ($domain === null) {
            throw new \RuntimeException('Tenant domain was not found.');
        }
        if ((string) $domain->verification_status !== TenantDomain::VERIFICATION_PENDING) {
            throw new \DomainException('Only pending domains may use initial verification; use re-verification instead.');
        }
        $outcome = $this->classify($domain->host, (string) $domain->verification_token);

        if ($outcome === 'verified') {
            $stmt = db($c)->getPDO()->prepare(
                "UPDATE tenant_domains SET verification_status = 'verified', verified_at = now(), "
                . "last_check_status = 'verified', last_checked_at = now(), consecutive_failures = 0, "
                . 'first_failure_at = NULL, updated_at = now() WHERE uuid = ?'
            );
            $stmt->execute([$domainUuid]);

            return TenantDomain::VERIFICATION_VERIFIED;
        }

        $stmt = db($c)->getPDO()->prepare(
            'UPDATE tenant_domains SET last_check_status = ?, last_checked_at = now(), '
            . 'consecutive_failures = 0, first_failure_at = NULL, updated_at = now() WHERE uuid = ?'
        );
        $stmt->execute([$outcome, $domainUuid]);

        return TenantDomain::VERIFICATION_PENDING;
    }

    /** DNS re-check classification for a host/token pair. Returns 'verified'|'mismatch'|'dns_error'. */
    private function classify(string $host, string $token): string
    {
        $result = $this->dns->lookupStructured('_thallo-verify.' . $host);
        if ($result->isError()) {
            return 'dns_error';
        }

        return in_array($token, $result->records, true) ? 'verified' : 'mismatch';
    }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=DomainReverificationTest`
Expected: PASS.

- [ ] **Step 7: Stage (HOLD)**

```bash
git add vendor/glueful/tenancy/src/Resolution/DnsTxtResult.php \
        vendor/glueful/tenancy/src/Resolution/DnsTxtLookup.php \
        vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php \
        tests/Integration/Tenancy/DomainReverificationTest.php
# HOLD.
```

---

### Task 3: `DomainReverificationResult` DTO + the three engine events

**Files:**
- Create: `vendor/glueful/extension-contracts/src/Tenancy/DomainReverificationResult.php`
- Create: `vendor/glueful/tenancy/src/Events/DomainReverificationFailed.php`
- Create: `vendor/glueful/tenancy/src/Events/DomainRevoked.php`
- Create: `vendor/glueful/tenancy/src/Events/DomainReverified.php`
- Test: `tests/Integration/Tenancy/DomainReverificationTest.php` (append)

**Interfaces:**
- Produces:
  - `DomainReverificationResult(string $outcome, ?string $verificationStatus, string $transition, int $consecutiveFailures, ?string $checkedAt)` — public readonly props; `outcome ∈ verified|mismatch|dns_error|stale|ineligible`, `transition ∈ none|revoked|restored`.
  - `DomainReverificationFailed(domainUuid, tenantUuid, host, outcome, consecutiveFailures, verificationStatus)`; `DomainRevoked(...)`; `DomainReverified(...)` — all extend framework `BaseEvent`, all **without** the token.

- [ ] **Step 1: Write the failing test**

Append:

```php
    public function testResultDtoAndEventsCarryNoToken(): void
    {
        $r = new \Glueful\Extensions\Contracts\Tenancy\DomainReverificationResult(
            'verified', 'verified', 'restored', 0, '2026-07-11 00:00:00'
        );
        self::assertSame('restored', $r->transition);
        self::assertSame(0, $r->consecutiveFailures);

        $e = new \Glueful\Extensions\Tenancy\Events\DomainRevoked(
            'domAAAAAAAAA', 'tenAAAAAAAAA', 'a.example.com', 'mismatch', 3, 'revoked'
        );
        self::assertSame('a.example.com', $e->host);
        self::assertSame(3, $e->consecutiveFailures);
        $props = get_object_vars($e);
        self::assertArrayNotHasKey('verificationToken', $props);
        self::assertArrayNotHasKey('token', $props);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=testResultDtoAndEventsCarryNoToken`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create the contract DTO**

Create `vendor/glueful/extension-contracts/src/Tenancy/DomainReverificationResult.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Contracts\Tenancy;

/**
 * Neutral outcome of a single domain re-verification. A status string alone cannot express
 * stale (row changed under us) or ineligible (not a token-bearing verified|revoked domain), so
 * callers get this DTO instead.
 */
final class DomainReverificationResult
{
    /**
     * @param 'verified'|'mismatch'|'dns_error'|'stale'|'ineligible' $outcome
     * @param 'none'|'revoked'|'restored' $transition
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $verificationStatus,
        public readonly string $transition,
        public readonly int $consecutiveFailures,
        public readonly ?string $checkedAt,
    ) {
    }
}
```

- [ ] **Step 4: Create the three events**

Create `vendor/glueful/tenancy/src/Events/DomainReverificationFailed.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Events;

use Glueful\Events\Contracts\BaseEvent;

/** A re-verification check did not prove ownership (mismatch or dns_error). No token in payload. */
final class DomainReverificationFailed extends BaseEvent
{
    public function __construct(
        public readonly string $domainUuid,
        public readonly string $tenantUuid,
        public readonly string $host,
        public readonly string $outcome,
        public readonly int $consecutiveFailures,
        public readonly string $verificationStatus,
    ) {
        parent::__construct();
    }
}
```

Create `vendor/glueful/tenancy/src/Events/DomainRevoked.php` and `Events/DomainReverified.php` — identical shape, class names `DomainRevoked` / `DomainReverified`, with docblocks:
- `DomainRevoked`: "A domain crossed grace+threshold and was revoked (verified → revoked). No token in payload."
- `DomainReverified`: "A revoked domain proved ownership again (revoked → verified). No token in payload."

Both have the same constructor signature and `parent::__construct();` body as `DomainReverificationFailed`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=testResultDtoAndEventsCarryNoToken`
Expected: PASS.

- [ ] **Step 6: Stage (HOLD)**

```bash
git add vendor/glueful/extension-contracts/src/Tenancy/DomainReverificationResult.php \
        vendor/glueful/tenancy/src/Events/ \
        tests/Integration/Tenancy/DomainReverificationTest.php
# HOLD.
```

---

### Task 4: `reverifyDomain()` primitive — snapshot → DNS → guarded apply

**Files:**
- Modify: `vendor/glueful/extension-contracts/src/Tenancy/TenantDomainAdministration.php`
- Modify: `vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php`
- Test: `tests/Integration/Tenancy/DomainReverificationTest.php` (append)

**Interfaces:**
- Produces: `TenantDomainAdministration::reverifyDomain(ApplicationContext $c, string $domainUuid): DomainReverificationResult`.
  - Eligible only for token-bearing `verified|revoked` domains; else `ineligible` (unknown status also warning-logged).
  - DNS lookup happens outside the txn/lock; the guarded apply takes `lockHost(host)` then `SELECT … FOR UPDATE`, and applies only if `host`/`verification_token`/`verification_status`/`last_checked_at` still match the snapshot (else `stale`).
  - Success resets counters + restores `revoked → verified` (`transition=restored`, emit `DomainReverified`); routine `verified → verified` is silent. Failure increments the counter, sets `first_failure_at` if null, emits `DomainReverificationFailed`, and revokes iff threshold **and** grace (DB time) both met (`verified → revoked`, emit `DomainRevoked`).
- Consumes: `ReleasedHostRepository::lockHost` (slice 2), `DnsTxtLookup`, `EventService`, `Connection::afterCommit`, the three events, `DomainReverificationResult`, `classify()` (Task 2).

- [ ] **Step 1: Write the failing tests**

Append several cases (use the injected-DNS admin + DB row helpers from Task 2):

```php
    public function testReverifyRestoresRevokedOnSuccess(): void
    {
        $c = $this->appContext();
        [$tenant, $domainUuid, $host] = $this->seedVerifiedDomain();
        // Force it revoked with an open failure window.
        $this->connection()->getPDO()->prepare(
            "UPDATE tenant_domains SET verification_status='revoked', consecutive_failures=5, " .
            "first_failure_at = now() - interval '48 hours' WHERE uuid = ?"
        )->execute([$domainUuid]);

        $token = $this->tokenOf($domainUuid);
        $domains = $this->domainAdminWithDns([$token]); // DNS now proves ownership
        $result = $domains->reverifyDomain($c, $domainUuid);

        self::assertSame('verified', $result->outcome);
        self::assertSame('restored', $result->transition);
        $row = $this->domainRow($host);
        self::assertSame('verified', $row['verification_status']);
        self::assertSame(0, (int) $row['consecutive_failures']);
        self::assertNull($row['first_failure_at']);
    }

    public function testReverifyRevokesOnlyWhenThresholdAndGraceMet(): void
    {
        $c = $this->appContext();
        [$tenant, $domainUuid, $host] = $this->seedVerifiedDomain();
        // 2 prior failures, first failure 48h ago; grace(24h) met, threshold(3) reached on this check.
        $this->connection()->getPDO()->prepare(
            "UPDATE tenant_domains SET consecutive_failures=2, first_failure_at = now() - interval '48 hours' WHERE uuid = ?"
        )->execute([$domainUuid]);

        $domains = $this->domainAdminWithDns([]); // mismatch
        $result = $domains->reverifyDomain($c, $domainUuid);

        self::assertSame('mismatch', $result->outcome);
        self::assertSame('revoked', $result->transition);
        self::assertSame('revoked', $this->domainRow($host)['verification_status']);
    }

    public function testReverifyDoesNotRevokeWithinGrace(): void
    {
        $c = $this->appContext();
        [$tenant, $domainUuid, $host] = $this->seedVerifiedDomain();
        // Threshold reached but first failure only 1h ago → grace NOT met.
        $this->connection()->getPDO()->prepare(
            "UPDATE tenant_domains SET consecutive_failures=5, first_failure_at = now() - interval '1 hour' WHERE uuid = ?"
        )->execute([$domainUuid]);

        $result = $this->domainAdminWithDns([])->reverifyDomain($c, $domainUuid);
        self::assertSame('none', $result->transition);
        self::assertSame('verified', $this->domainRow($host)['verification_status']);
    }

    public function testReverifyIneligibleForTokenlessDomain(): void
    {
        $c = $this->appContext();
        $admin = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantAdministration::class);
        $tenant = $admin->create($c, 'op-' . strtolower(\Glueful\Helpers\Utils::generateNanoID(6)), 'OP', \Glueful\Helpers\Utils::generateNanoID(12));
        $this->cleanupTenants[] = $tenant;
        $host = strtolower(\Glueful\Helpers\Utils::generateNanoID(8)) . '.reverify.test';
        $domainUuid = $this->container()->get(\Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration::class)
            ->addPreverifiedDomain($c, $tenant, $host); // no token

        $result = $this->domainAdminWithDns([])->reverifyDomain($c, $domainUuid);
        self::assertSame('ineligible', $result->outcome);
    }
```

Add helpers `seedVerifiedDomain(): array` (create tenant, addDomain, force `verification_status='verified'` + a token) and `tokenOf(string $domainUuid): string`.

The same test class must also cover: `dns_error`; unknown-status warning; pending/tokenless ineligibility; stale no-op after host, token, status, timestamp, or row deletion changes; DNS invoked at transaction level zero; two concurrent applies producing one persisted increment; routine verified success emitting no event; failure emitting only `DomainReverificationFailed`; threshold+grace emitting failed then revoked after commit; revoked success emitting only reverified; and transaction rollback emitting nothing.

- [ ] **Step 2: Run it to verify it fails**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=DomainReverificationTest`
Expected: FAIL — `reverifyDomain` not declared/implemented.

- [ ] **Step 3: Add the contract signature**

In `vendor/glueful/extension-contracts/src/Tenancy/TenantDomainAdministration.php`, add (with a `use` for the DTO already in that namespace, so no import needed):

```php
    /**
     * Re-prove ownership of a token-bearing verified|revoked domain. DNS I/O happens outside any
     * lock; the apply is guarded by optimistic concurrency. Grace-then-revoke on drift; restore on
     * recovered proof. Returns a neutral result (ineligible/stale are not statuses).
     */
    public function reverifyDomain(ApplicationContext $c, string $domainUuid): DomainReverificationResult;
```

- [ ] **Step 4: Implement `reverifyDomain()`**

In `ContractTenantDomainAdministration.php`, add imports:

```php
use Glueful\Extensions\Contracts\Tenancy\DomainReverificationResult;
use Glueful\Extensions\Tenancy\Events\DomainReverificationFailed;
use Glueful\Extensions\Tenancy\Events\DomainReverified;
use Glueful\Extensions\Tenancy\Events\DomainRevoked;
use Glueful\Events\EventService;
use Psr\Log\LoggerInterface;
```

Ensure the constructor has `ReleasedHostRepository $cooldown` (from slice 2) and add an optional `?LoggerInterface $logger = null`. Implement:

```php
    public function reverifyDomain(ApplicationContext $c, string $domainUuid): DomainReverificationResult
    {
        // 1. Snapshot — no txn/lock.
        $snap = TenantDomain::query($c)->where('uuid', $domainUuid)->first();
        if ($snap === null) {
            return new DomainReverificationResult('ineligible', null, 'none', 0, null);
        }
        $status = (string) $snap->verification_status;
        $token = (string) ($snap->verification_token ?? '');
        $eligible = [TenantDomain::VERIFICATION_VERIFIED, TenantDomain::VERIFICATION_REVOKED];
        if ($token === '' || !in_array($status, $eligible, true)) {
            if (!in_array($status, [TenantDomain::VERIFICATION_PENDING, ...$eligible], true)) {
                $this->logger?->warning('tenancy.reverify.unknown_status', [
                    'domain_uuid' => $domainUuid,
                    'verification_status' => $status,
                ]);
            }
            return new DomainReverificationResult('ineligible', $status, 'none', (int) $snap->consecutive_failures, null);
        }
        $host = (string) $snap->host;
        $snapLastChecked = $snap->last_checked_at !== null ? (string) $snap->last_checked_at : null;

        // 2. DNS outside any lock.
        $outcome = $this->classify($host, $token); // 'verified'|'mismatch'|'dns_error'

        // 3+4+5. Guarded apply.
        return db($c)->transaction(function () use (
            $c, $domainUuid, $host, $token, $status, $snapLastChecked, $outcome
        ): DomainReverificationResult {
            $this->cooldown->lockHost($c, $host); // host lock BEFORE row lock (matches release path)

            $sql = 'SELECT uuid, tenant_uuid, host, verification_token, verification_status, '
                . 'last_checked_at, consecutive_failures, first_failure_at FROM tenant_domains WHERE uuid = ?';
            if (db($c)->getDriverName() !== 'sqlite') {
                $sql .= ' FOR UPDATE';
            }
            $stmt = db($c)->getPDO()->prepare($sql);
            $stmt->execute([$domainUuid]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $rowLastChecked = ($row === false) ? null
                : ($row['last_checked_at'] !== null ? (string) $row['last_checked_at'] : null);
            $stale = $row === false
                || (string) $row['host'] !== $host
                || (string) $row['verification_token'] !== $token
                || (string) $row['verification_status'] !== $status
                || $rowLastChecked !== $snapLastChecked;
            if ($stale) {
                return new DomainReverificationResult(
                    'stale',
                    $row === false ? null : (string) $row['verification_status'],
                    'none',
                    $row === false ? 0 : (int) $row['consecutive_failures'],
                    null
                );
            }

            $tenantUuid = (string) $row['tenant_uuid'];

            if ($outcome === 'verified') {
                $transition = $status === TenantDomain::VERIFICATION_REVOKED ? 'restored' : 'none';
                $restoreSql = $transition === 'restored'
                    ? ", verification_status = 'verified', verified_at = now()"
                    : '';
                $stmt = db($c)->getPDO()->prepare(
                    "UPDATE tenant_domains SET last_check_status = 'verified', last_checked_at = now(), "
                    . "consecutive_failures = 0, first_failure_at = NULL, updated_at = now(){$restoreSql} "
                    . 'WHERE uuid = ? RETURNING last_checked_at'
                );
                $stmt->execute([$domainUuid]);
                $checkedAt = (string) $stmt->fetchColumn();

                if ($transition === 'restored') {
                    $this->dispatchAfterCommit($c, new DomainReverified(
                        $domainUuid, $tenantUuid, $host, 'verified', 0, TenantDomain::VERIFICATION_VERIFIED
                    ));
                }
                // Routine verified→verified emits nothing.
                return new DomainReverificationResult(
                    'verified', TenantDomain::VERIFICATION_VERIFIED, $transition, 0, $checkedAt
                );
            }

            // Increment and evaluate against the same DB-time statement. RETURN 1/0, never a
            // PostgreSQL boolean string ('f' is truthy when cast by PHP).
            $threshold = (int) config($c, 'tenancy.domains.reverification.failure_threshold', 3);
            $graceHours = (int) config($c, 'tenancy.domains.reverification.grace_hours', 24);
            $eval = db($c)->getPDO()->prepare(
                'WITH updated AS (UPDATE tenant_domains SET last_check_status = ?, last_checked_at = now(), '
                . 'consecutive_failures = consecutive_failures + 1, '
                . 'first_failure_at = COALESCE(first_failure_at, now()), updated_at = now() '
                . 'WHERE uuid = ? RETURNING consecutive_failures, first_failure_at, last_checked_at) '
                . 'SELECT consecutive_failures, last_checked_at, CASE WHEN consecutive_failures >= ? '
                . 'AND (now() - first_failure_at) >= make_interval(hours => ?) THEN 1 ELSE 0 END AS revoke_ready '
                . 'FROM updated'
            );
            $eval->execute([$outcome, $domainUuid, $threshold, $graceHours]);
            $ev = $eval->fetch(\PDO::FETCH_ASSOC);
            $failures = (int) $ev['consecutive_failures'];
            $revokeReady = (int) $ev['revoke_ready'] === 1;
            $checkedAt = (string) $ev['last_checked_at'];

            $transition = 'none';
            if ($status === TenantDomain::VERIFICATION_VERIFIED && $revokeReady) {
                $stmt = db($c)->getPDO()->prepare(
                    "UPDATE tenant_domains SET verification_status = 'revoked', updated_at = now() WHERE uuid = ?"
                );
                $stmt->execute([$domainUuid]);
                $transition = 'revoked';
            }

            $resultStatus = $transition === 'revoked'
                ? TenantDomain::VERIFICATION_REVOKED
                : $status;

            // Every unsuccessful check emits Failed (incl. while already revoked).
            $this->dispatchAfterCommit($c, new DomainReverificationFailed(
                $domainUuid, $tenantUuid, $host, $outcome, $failures, $resultStatus
            ));
            if ($transition === 'revoked') {
                $this->dispatchAfterCommit($c, new DomainRevoked(
                    $domainUuid, $tenantUuid, $host, $outcome, $failures, TenantDomain::VERIFICATION_REVOKED
                ));
            }

            return new DomainReverificationResult(
                $outcome, $resultStatus, $transition, $failures, $checkedAt
            );
        });
    }

    private function dispatchAfterCommit(ApplicationContext $c, object $event): void
    {
        db($c)->afterCommit(static function () use ($c, $event): void {
            $container = $c->getContainer();
            if ($container->has(EventService::class)) {
                $container->get(EventService::class)->dispatch($event);
            }
        });
    }
```

The `[TenantDomain::VERIFICATION_PENDING, ...$eligible]` spread is the set of known statuses; anything else triggers the warning. Every returned `checkedAt` is the exact value written and returned by PostgreSQL.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=DomainReverificationTest`
Expected: PASS (all primitive cases).

- [ ] **Step 6: phpcs + stage (HOLD)**

```bash
composer phpcs -- vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php
git add vendor/glueful/extension-contracts/src/Tenancy/TenantDomainAdministration.php \
        vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php \
        tests/Integration/Tenancy/DomainReverificationTest.php
# HOLD.
```

---

### Task 5: Injectable sweep + dedicated-session lock + scheduled job

**Files:**
- Create: `packages/thallo-tenancy/src/Reverification/DomainReverificationSweepJob.php`
- Create: `packages/thallo-tenancy/src/Reverification/DomainReverificationSweep.php`
- Create: `packages/thallo-tenancy/src/Reverification/DomainReverificationSweepLock.php`
- Modify: `packages/thallo-tenancy/src/TenancyServiceProvider.php`
- Modify: `config/schedule.php` — hourly registration.
- Test: `tests/Integration/Tenancy/DomainReverificationSweepTest.php`

**Interfaces:**
- Produces: an injectable `DomainReverificationSweep` for deterministic selection/orchestration tests; `DomainReverificationSweepLock` owning the independent PostgreSQL session lock; and a thin queue job that composes them using the inherited protected `Job::$context`.
- Consumes: `TenantDomainAdministration::reverifyDomain` (Task 4), `Connection::newPdo`, config, `Job` base.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Tenancy/DomainReverificationSweepTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Tests\Support\RetrofitHarnessTestCase;
use Thallo\Tenancy\Reverification\DomainReverificationSweep;

final class DomainReverificationSweepTest extends RetrofitHarnessTestCase
{
    public function testSweepSelectsOnlyDueDomainsAndIsolatesPerDomainFailures(): void
    {
        // Seed active/suspended/deleted/operator-disabled tenants and verified/revoked/pending,
        // due/not-due/tokenless domains. Inject a fake TenantDomainAdministration that records
        // UUIDs and throws for one. Assert only due active/suspended, operator-active domains are
        // called, revoked uses its slower cadence, ordering is NULLS FIRST, batch_size is obeyed,
        // processing continues after the fake failure, and the returned error list names it.
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=DomainReverificationSweepTest`
Expected: FAIL — sweep service absent.

- [ ] **Step 3: Create the injectable sweep service**

`DomainReverificationSweep` takes `Connection` and `TenantDomainAdministration`. Its `run(ApplicationContext $c): list<string>` executes the due-domain query from the prior draft and calls the injected administration service per UUID, collecting errors while continuing. Keep DNS entirely behind the injected fake in this test. Selection must require tenant status `active|suspended`, `deleted_at IS NULL`, and domain `status='active'` in addition to token/status/cadence predicates.

- [ ] **Step 4: Create and test the dedicated-session lock**

`DomainReverificationSweepLock::run(callable $work): bool` creates one independent `Connection::newPdo()` session, attempts `pg_try_advisory_lock(hashtextextended('tenancy:reverify:sweep', 0))`, returns `false` without calling the callback when held elsewhere, and always verifies `pg_advisory_unlock(...)` in `finally` before discarding the PDO. Add real PostgreSQL tests proving mutual exclusion from a second session and release after both success and exception.

- [ ] **Step 5: Create the thin queue job**

Create `packages/thallo-tenancy/src/Reverification/DomainReverificationSweepJob.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Reverification;

use Glueful\Queue\Job;

/**
 * Global, single-runner sweep that re-verifies due custom domains. Single-runner protection is a
 * SESSION-scoped pg_try_advisory_lock on a dedicated, non-pooled PDO (NOT the purge ledger's durable
 * leases) — process death releases it automatically. Per-domain failures are isolated; the job
 * fails after the batch so the queue records an operational failure.
 */
final class DomainReverificationSweepJob extends Job
{
    public function handle(): void
    {
        $c = $this->context;
        if (!(bool) config($c, 'tenancy.domains.reverification.enabled', true)) {
            return;
        }
        $lock = app($c, DomainReverificationSweepLock::class);
        $lock->run(function () use ($c): void {
            $errors = app($c, DomainReverificationSweep::class)->run($c);
            if ($errors !== []) {
                throw new \RuntimeException('Domain re-verification failed for: ' . implode(', ', $errors));
            }
        });
    }
}
```

(`ORDER BY … NULLS FIRST` with `make_interval` is PostgreSQL — matches the Postgres-only constraint. `Connection::newPdo()` was source-verified to return an independent PDO.)

- [ ] **Step 6: Register services**

Register `DomainReverificationSweep` and `DomainReverificationSweepLock` as shared services in `packages/thallo-tenancy/src/TenancyServiceProvider.php`. The scheduler reconstructs the job; do not add an unnecessary job service definition.

- [ ] **Step 7: Register the schedule**

In `config/schedule.php`, add a `jobs[]` entry:

```php
        [
            'name' => 'domain_reverification_sweep',
            'schedule' => '0 * * * *',
            'handler_class' => 'Thallo\\Tenancy\\Reverification\\DomainReverificationSweepJob',
            'parameters' => [],
            'description' => 'Re-verify due custom domains (DNS TXT drift / takeover detection)',
            'enabled' => env('TENANCY_REVERIFICATION_ENABLED', true),
            'queue' => $maintenanceQueue,
            'timeout' => 300,
            'retry_attempts' => 1,
        ],
```

- [ ] **Step 8: Run the tests**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=DomainReverificationSweepTest`
Expected: PASS. The test matrix covers kill-switch-before-lock, lock contention, unlock after success/exception, verified/revoked cadence, lifecycle/domain-status exclusions, NULLS FIRST, batch limit, and per-domain continuation with aggregate failure.

- [ ] **Step 9: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/src/Reverification/DomainReverificationSweepJob.php \
        packages/thallo-tenancy/src/Reverification/DomainReverificationSweep.php \
        packages/thallo-tenancy/src/Reverification/DomainReverificationSweepLock.php \
        packages/thallo-tenancy/src/TenancyServiceProvider.php \
        config/schedule.php \
        tests/Integration/Tenancy/DomainReverificationSweepTest.php
# HOLD.
```

---

### Task 6: Manual "re-verify now" route (tenant-bound, actor-audited)

**Files:**
- Modify: `vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php`
- Modify: `packages/thallo-tenancy/src/Http/Controllers/TenantDomainController.php`
- Modify: `packages/thallo-tenancy/routes/enablement.php`
- Modify: `admin/src/queries/tenantDomains.ts`
- Modify: `admin/src/pages/workspaces/[uuid]/domains.vue`
- Test: `tests/Integration/Tenancy/DomainReverifyRouteTest.php`
- Test: `admin/src/__tests__/workspaceDomainReverification.spec.ts`

**Interfaces:**
- Produces: the tracking fields in the existing domain projection; `TenantDomainController::reverify()` with the existing non-revealing guard and neutral lifecycle audit; the route; query mutation; and revoked/failing admin presentation.
- Consumes: `TenantDomainAdministration::reverifyDomain`, the existing `targetMatches()` and `actor(Request)` helpers, and the controller's existing nullable `Thallo\Contracts\Tenancy\TenancyLifecycleAudit`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Tenancy/DomainReverifyRouteTest.php` extending `RetrofitHarnessTestCase` — drive the controller directly, asserting a foreign target returns the non-revealing 404 and an owned verified/revoked target returns 200. Assert the neutral audit spy receives actor UUID, **tenant UUID as the target**, and domain UUID/host in context. Also assert the domain projection carries all tracking fields, a successful transition purges the tenant host cache, and the old `verify` action rejects verified/revoked rows.

- [ ] **Step 2: Run it to verify it fails**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=DomainReverifyRouteTest`
Expected: FAIL — `reverify` method absent.

- [ ] **Step 3: Add the controller method**

First extend the engine bridge's existing domain `projection()` with `last_checked_at`, `last_check_status`, and `consecutive_failures`. Then add the controller method, reusing its existing audit dependency and private `actor(Request)` helper:

```php
    public function reverify(Request $request, string $uuid): Response
    {
        if ($this->domains === null) {
            return $this->unavailable();
        }
        $domain = $this->domains->getDomain($this->context, $uuid);
        if ($domain === null || !$this->targetMatches((string) $domain['tenant_uuid'])) {
            // Non-revealing: identical to the existing domain actions.
            return Response::notFound('Tenant domain was not found.');
        }

        // Audit the accepted request before DNS/runtime work; mutateDomain retains the existing
        // exception mapping and purges tenant-host caches after a successful resolution change.
        $this->audit?->record(
            'domain.reverification_requested',
            $this->actor($request),
            (string) $domain['tenant_uuid'],
            [
                'domain_uuid' => $uuid,
                'host' => $domain['host'],
            ]
        );

        return $this->mutateDomain($uuid, function () use ($uuid): array {
            $result = $this->domains->reverifyDomain($this->context, $uuid);
            return [
                'outcome' => $result->outcome,
                'verification_status' => $result->verificationStatus,
                'transition' => $result->transition,
                'consecutive_failures' => $result->consecutiveFailures,
                'checked_at' => $result->checkedAt,
            ];
        });
    }
```

- [ ] **Step 4: Add the route**

In `routes/enablement.php`, inside the domain group (with `tenant_profile:admin`, `tenant_bootstrap`), next to `POST /domains/{uuid}/verify`:

```php
        $router->post('/domains/{uuid}/reverify', [TenantDomainController::class, 'reverify'])
            ->middleware('content_permission:tenant.domains.manage');
```

- [ ] **Step 5: Add query projection + admin status/action**

Extend `admin/src/queries/tenantDomains.ts` with `last_checked_at`, `last_check_status`, `consecutive_failures`, and a reverify mutation. On the workspace Domains page:

- show **Verify** only for `pending`;
- show **Re-verify now** for `verified|revoked`;
- render `revoked` as non-resolving and show the latest outcome/check time/failure count;
- refresh the domain query after the mutation and render the server error verbatim.

Create `admin/src/__tests__/workspaceDomainReverification.spec.ts` with `data-testid` coverage for pending vs verified/revoked actions, failure metadata, mutation request, and query refresh.

- [ ] **Step 6: Run backend + frontend tests**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=DomainReverifyRouteTest`
Run the focused admin workspace-domain Vitest file.
Expected: PASS.

- [ ] **Step 7: phpcs + stage (HOLD)**

```bash
composer phpcs -- packages/thallo-tenancy/src/Http/Controllers/TenantDomainController.php
git add vendor/glueful/tenancy/src/Bridge/ContractTenantDomainAdministration.php \
        packages/thallo-tenancy/src/Http/Controllers/TenantDomainController.php \
        packages/thallo-tenancy/routes/enablement.php \
        admin/src/queries/tenantDomains.ts \
        'admin/src/pages/workspaces/[uuid]/domains.vue' \
        admin/src/__tests__/workspaceDomainReverification.spec.ts \
        tests/Integration/Tenancy/DomainReverifyRouteTest.php
# HOLD.
```

---

### Task 7: Audit listener (engine events → system-actor audit records)

**Files:**
- Create: `packages/thallo-tenancy/src/Reverification/DomainReverificationAuditListener.php`
- Modify: `packages/thallo-tenancy/src/TenancyServiceProvider.php` and `app/Providers/ThalloServiceProvider.php` (`registerEventListeners()` — map the three events).
- Test: `tests/Integration/Tenancy/DomainReverificationTest.php` (append a listener-writes-audit case) or a focused unit test.

**Interfaces:**
- Produces: `DomainReverificationAuditListener::__invoke(object $event): void` — records `domain.reverification_failed` / `domain.revoked` / `domain.reverified` as **system-actor** audit entries, never including the token.
- Consumes: the three engine events; the neutral `Thallo\Contracts\Tenancy\TenancyLifecycleAudit` seam.

- [ ] **Step 1: Write the failing test**

Append a case dispatching a `DomainRevoked` event through `EventService` and asserting a `domain.revoked` audit record is written with a null/system actor and no token field. If the audit extension isn't present in the test env, assert the listener no-ops without throwing (best-effort), mirroring slice-1 `AuthorityAudit` tests.

- [ ] **Step 2: Run it to verify it fails**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=DomainReverificationTest`
Expected: FAIL — listener absent / not wired.

- [ ] **Step 3: Create the listener**

Create `packages/thallo-tenancy/src/Reverification/DomainReverificationAuditListener.php`:

```php
<?php

declare(strict_types=1);

namespace Thallo\Tenancy\Reverification;

use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;
use Glueful\Extensions\Tenancy\Events\DomainReverificationFailed;
use Glueful\Extensions\Tenancy\Events\DomainReverified;
use Glueful\Extensions\Tenancy\Events\DomainRevoked;

/**
 * Converts engine re-verification events into SYSTEM-actor audit records. Best-effort: no-ops when
 * the audit extension is absent. The verification token is never part of an event, so it can never
 * leak here.
 */
final class DomainReverificationAuditListener
{
    public function __construct(private readonly ?TenancyLifecycleAudit $audit = null)
    {
    }

    public function __invoke(object $event): void
    {
        if ($this->audit === null) {
            return;
        }
        [$action, $domainUuid, $tenantUuid, $host, $outcome, $failures, $status] = $this->map($event);
        if ($action === null) {
            return;
        }
        try {
            $this->audit->record($action, null, $tenantUuid, [
                'domain_uuid' => $domainUuid,
                'host' => $host,
                'outcome' => $outcome,
                'consecutive_failures' => $failures,
                'verification_status' => $status,
            ]);
        } catch (\Throwable) {
            // Best-effort by contract.
        }
    }

    /** @return array{0:?string,1:string,2:string,3:string,4:string,5:int,6:string} */
    private function map(object $event): array
    {
        return match (true) {
            $event instanceof DomainRevoked => ['domain.revoked', $event->domainUuid, $event->tenantUuid, $event->host, $event->outcome, $event->consecutiveFailures, $event->verificationStatus],
            $event instanceof DomainReverified => ['domain.reverified', $event->domainUuid, $event->tenantUuid, $event->host, $event->outcome, $event->consecutiveFailures, $event->verificationStatus],
            $event instanceof DomainReverificationFailed => ['domain.reverification_failed', $event->domainUuid, $event->tenantUuid, $event->host, $event->outcome, $event->consecutiveFailures, $event->verificationStatus],
            default => [null, '', '', '', '', 0, ''],
        };
    }
}
```

- [ ] **Step 4: Register the listener**

In `packages/thallo-tenancy/src/TenancyServiceProvider.php`, register `DomainReverificationAuditListener` with the nullable neutral audit seam. In `app/Providers/ThalloServiceProvider.php` `registerEventListeners()`, add:

```php
        $events->addListener(DomainReverificationFailed::class, '@' . DomainReverificationAuditListener::class);
        $events->addListener(DomainRevoked::class, '@' . DomainReverificationAuditListener::class);
        $events->addListener(DomainReverified::class, '@' . DomainReverificationAuditListener::class);
```

(Add the `use` imports for the three events + the listener at the top of `ThalloServiceProvider.php`, per the provider-use-imports convention.)

- [ ] **Step 5: Run the test to verify it passes**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=DomainReverificationTest`
Expected: PASS.

- [ ] **Step 6: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/src/Reverification/DomainReverificationAuditListener.php \
        packages/thallo-tenancy/src/TenancyServiceProvider.php \
        app/Providers/ThalloServiceProvider.php \
        tests/Integration/Tenancy/DomainReverificationTest.php
# HOLD.
```

---

### Task 8: `thallo:tenancy:diagnose` domain-proof coherence check

**Files:**
- Modify: `packages/thallo-tenancy/src/Enablement/TenancyDiagnostics.php`
- Test: `tests/Integration/Tenancy/DomainReverificationDiagnosticsTest.php`

**Interfaces:**
- Produces: a read-only diagnostics check that fails when any `tenant_domains` row has `verification_status` outside `pending|verified|revoked` or `last_check_status` outside `verified|dns_error|mismatch|NULL`, naming the domain UUID but never its token.
- Consumes: the diagnostics report structure already in `TenancyDiagnostics`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/Tenancy/DomainReverificationDiagnosticsTest.php` extending `RetrofitHarnessTestCase`: seed a domain with an out-of-range `last_check_status` (e.g. `'bogus'`) via raw SQL, run the diagnostics, assert the report flags domain-proof incoherence and includes the domain UUID but not the token. Clean up in `tearDown`.

- [ ] **Step 2: Run it to verify it fails**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=DomainReverificationDiagnosticsTest`
Expected: FAIL — no such check.

- [ ] **Step 3: Add the coherence check**

In `TenancyDiagnostics.php`, add a read-only check to the report assembly:

```php
    /** @return list<array{domain_uuid:string,issue:string}> */
    private function domainProofIncoherences(): array
    {
        $sql = "SELECT uuid, verification_status, last_check_status FROM tenant_domains "
            . "WHERE verification_status NOT IN ('pending','verified','revoked') "
            . "OR (last_check_status IS NOT NULL AND last_check_status NOT IN ('verified','dns_error','mismatch'))";
        $rows = $this->connection->getPDO()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

        $issues = [];
        foreach ($rows as $row) {
            $issues[] = [
                'domain_uuid' => (string) $row['uuid'], // token deliberately NOT selected/exposed
                'issue' => 'incoherent verification/check status',
            ];
        }

        return $issues;
    }
```

Wire its output into the diagnose report (a passing check when empty, a failure listing the domain UUIDs when not), following the report's existing section/status conventions.

- [ ] **Step 4: Run the test to verify it passes**

Run: `THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --filter=DomainReverificationDiagnosticsTest`
Expected: PASS.

- [ ] **Step 5: Stage (HOLD)**

```bash
git add packages/thallo-tenancy/src/Enablement/TenancyDiagnostics.php \
        tests/Integration/Tenancy/DomainReverificationDiagnosticsTest.php
# HOLD.
```

---

### Task 9: Local schema sync, regression sweep, index update

**Files:**
- Modify: `docs/superpowers/specs/multi-tenancy/LIFECYCLE-GAPS-README.md`
- (No shipped code — a local schema-sync procedure + verification.)

**Interfaces:** none (operational + docs).

- [ ] **Step 1: Reconfirm the from-zero disposable schema**

Task 1 already made template freshness and from-zero schema creation an executable prerequisite. Re-run the complete retrofit harness here as the release gate; never fall back to altering the shared suite database:

```bash
THALLO_TENANCY_DEV_LINK=1 vendor/bin/phpunit --testsuite tenancy-retrofit
```
Confirm `information_schema.columns` for `tenant_domains` now contains the four columns and the `(verification_status, last_checked_at)` index exists.

- [ ] **Step 2: Local additive sync for existing dev DBs (throwaway, local-only)**

For an already-migrated local/dev DB that won't be reset, write a **throwaway** local-only script that: records `tenant_domains` row count + a sample of host→tenant mappings; adds only the four nullable/defaulted columns and the index **when absent**; then re-asserts the row count and host mappings are unchanged. Run it, verify, then delete it. Do **not** commit or ship it, and do **not** author an ALTER migration. (The `lemma` DB has no `tenants`/`tenant_domains` yet — its first migration run creates the folded schema directly, so it needs no sync.)

- [ ] **Step 3: Full backend regression both ways**

Run: `composer test`
Run: `THALLO_TENANCY_DEV_LINK=1 composer test`
Expected: PASS in tenancy-off and tenancy-on directions — new re-verification tests green; slice-2 lifecycle/cooldown/purge, public/admin resolution, and prior suites still green.

- [ ] **Step 4: Frontend regression + phpcs**

Run the full admin Vitest suite, then:
Run: `composer phpcs`
Expected: clean (120-char, no warnings). `composer phpcbf` for mechanical fixes.

- [ ] **Step 5: Update the tracking index**

In `docs/superpowers/specs/multi-tenancy/LIFECYCLE-GAPS-README.md`, set slice 3's Status to implemented (HELD) and link the spec + plan:

```markdown
| 3 | **Background domain re-verification** — … | ✅ implemented (HELD, on `dev`) | [spec](2026-07-11-domain-reverification-design.md) | [plan](../../plans/multi-tenancy/2026-07-11-domain-reverification.md) |
```

- [ ] **Step 6: Porting note (do NOT release yet)**

Record for the batched extension release (with slice 2): port `vendor/glueful/extension-contracts` (`DomainReverificationResult`, `reverifyDomain()` signature) and `vendor/glueful/tenancy` (`003` fold + index, `VERIFICATION_REVOKED`, `DnsTxtResult` + structured `DnsTxtLookup`, `reverifyDomain()` + `verifyDomain()` alignment, three events, config keys) into the source repos; publish contracts→tenancy `1.3.0` together with slice 2; then pin in Thallo. No framework release needed.

- [ ] **Step 7: Stage (HOLD)**

```bash
git add docs/superpowers/specs/multi-tenancy/LIFECYCLE-GAPS-README.md
# HOLD — do not commit until the user gives the go-ahead.
```

---

## Self-Review

**1. Spec coverage:**
- §2 primitive (snapshot → DNS-outside-lock → guarded optimistic apply; classification; grace+threshold DB-time revoke; restore; event edges; ineligible/unknown) → Task 4. ✅
- §2 `verifyDomain()` alignment (pending-only; DB-time metadata; pending failure resets drift state) → Task 2. ✅
- §3 structured `DnsTxtResult` + wrapper → Task 2. ✅
- §4 sweep (session advisory lock on dedicated `newPdo`, unlock-verify + discard, kill-switch before lock, tenant-lifecycle gate incl. operator-disabled domains, per-domain isolation → job fails after, NULLS FIRST + batch, verified vs slower revoked cadence) → Task 5. ✅
- §5 config → Task 1; events → Task 3; audit listener + manual route authorization/actor-audit + admin projection/action → Tasks 6, 7. ✅
- §5 diagnose coherence check → Task 8. ✅
- §6 folded columns + index + `revoked` const → Task 1. ✅
- §7 release chain + folded-schema procedure (test reset, retrofit template, local additive sync) → Task 9. ✅
- §8 failure modes → covered across Tasks 4, 5. ✅
- §9 testing → executable primitive edge/concurrency/event tests in Task 4; deterministic sweep + real lock tests in Task 5; backend/admin acceptance in Task 6; both regression directions in Task 9. ✅
- §10 out of scope (notification, TLS, operator-host recheck, pending cadence, history table) → not implemented. ✅

**2. Placeholder scan:** No unresolved placeholders, fabricated provider paths, or dependency guesses. Every runtime accessor and audit seam is pinned to the verified as-built API.

**3. Type consistency:** `reverifyDomain(): DomainReverificationResult` matches between contract (Task 4) and bridge (Task 4). `DomainReverificationResult(outcome, verificationStatus, transition, consecutiveFailures, checkedAt)` consistent across Tasks 3, 4, 6. The three events' constructor `(domainUuid, tenantUuid, host, outcome, consecutiveFailures, verificationStatus)` consistent across Tasks 3, 4, 7. `DnsTxtLookup::lookupStructured(): DnsTxtResult` / `classify()` consistent across Tasks 2, 4. `TenantDomain::VERIFICATION_REVOKED` consistent across Tasks 1, 4, 5.

**One consistency note fixed inline:** Task 4's eligibility uses `[VERIFICATION_PENDING, VERIFICATION_VERIFIED, VERIFICATION_REVOKED]` as the "known" set for the unknown-status warning, while the *eligible* set is only `verified|revoked` — `pending` is known-but-ineligible (returns `ineligible` with no warning), matching §2/§8.

**Review revision 2:** moved folded-schema freshness into Task 1 so engine tests can boot; replaced PHP timestamps/boolean casts with exact PostgreSQL `RETURNING` values; made initial verification pending-only; split sweep orchestration from advisory-lock ownership and removed live DNS from its tests; used `Job::$context`/`appContext()` correctly; retained the neutral lifecycle-audit boundary; added the missing projection/admin action; corrected diagnostics to use its owned `Connection`; and made the concurrency, stale, event-edge, lock, cadence, kill-switch, and per-domain-isolation proofs executable.
