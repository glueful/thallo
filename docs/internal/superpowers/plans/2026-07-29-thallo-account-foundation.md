# Thallo Account Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A storefront visitor can register, verify by emailed OTP, recover a password, sign in over HttpOnly cookies, see `/account`, and sign out — holding a global Glueful identity with **zero** workspace authority.

**Scope note:** this is the server-rendered identity path only. The account header block, its private hydration endpoint, the asset pipeline and the cache-isolation gates ship separately in `2026-07-29-thallo-account-chrome.md`, so this security-sensitive path gets its own smaller review and release boundary.

**Architecture:** The existing signup pipeline is refactored, not duplicated: a `VerifiedAccountActivator` primitive owns everything up to identity creation, and each purpose supplies a transactional continuation — member signup appends `addMember()`, customer signup appends nothing. A new `packages/thallo-account` pack owns the themed `/account/*` UX and consumes neutral contracts (`StorefrontAccountRegistration`, `StorefrontAccountRecovery`, `AccountNavigationRegistry`) so it never imports `App\Signup`. Sessions ride the framework cookie transport (introduced in 1.73.0); the pack requires ^1.74.0 for its widened username validation.

**Tech Stack:** PHP 8.3+, glueful/framework 1.74.0, Twig via `Thallo\Render\TwigFactory`, PHPUnit 10, PostgreSQL.

## Global Constraints

- **Requires glueful/framework ^1.74.0.** Its username validation is 3–255 (the column width); on 1.73.0 a customer whose email exceeds 30 characters cannot be created at all, because `UserRepository::create()` validates through `UsernameDTO`.
- **Zero authority for shoppers.** After a customer activation there must be no tenant membership, role, permission, or any other scoped authorization row for that identity. This is the acceptance criterion, asserted directly against the tables.
- **The boundary is structural, not remembered.** `CustomerSignupService` must have no code path that reaches `addMember()`. The activator binds `$intent['kind'] === $purpose` **under the row lock**, before either continuation runs.
- **Atomicity is preserved.** Identity creation, profile write, the purpose continuation, `setResults()` and `consume()` stay in ONE `runAsTenant(transaction(...))`, exactly as today. A continuation failure rolls the identity back with it.
- **`thallo-account` must not import `App\Signup`.** It consumes contracts from `thallo-contracts` only. The app implements them.
- **Recovery neutrality is unconditional** — identical status and body for known email, unknown email, and mail-delivery failure, regardless of `security.auth.generic_error_responses`. Delivered by a contract whose result type cannot express the difference.
- **Capability `thallo.accounts`** gates Thallo's themed surfaces only, never the framework's `/auth/*` APIs.
- **The `/account` prefix is not the auth boundary.** `login`, `register`, `verify/{intentUuid}`, `forgot-password`, `reset-password` are anonymous; the shell and everything else require cookie auth. Gating the whole prefix would lock a signed-out visitor out of signing in.
- **Verification is OTP entry, not a magic link.** `SignupMailSender::sendVerification()` mails an OTP and `SignupCoordinator::verify()` takes `(intentUuid, otp)`.
- **The CSRF policy matrix from spec §5 binds every route this plan adds.** The invariant is
  "every cookie-authenticated unsafe route has exactly one approved policy", not "every route
  uses a token":

  | Route class | Authentication | CSRF policy |
  |---|---|---|
  | `GET /account/{login,register,verify,forgot-password,reset-password}` | anonymous | none (safe method) |
  | `POST` login / register / verify / recovery | anonymous | strict same-origin + rate limit (no session token exists yet) |
  | `GET /account` and authenticated pages | `session_cookie` + `auth` | none (safe method) |
  | Account mutations and `POST /account/logout` | `session_cookie` + `auth` | session-bound token |
  | `GET /_account/session` | `session_cookie:optional` + `auth:optional` | none; `private, no-store` — **built by the chrome plan**, listed here so the matrix stays complete |

  `SameSite=Lax` is defence in depth, not the control. Task 5 adds the route-inventory test that
  fails when an unsafe route carries no approved policy — this plan's surface must prove its own
  matrix before the chrome plan begins, since it ships independently.
- **No cacheable page may render an authenticated identity.** This plan adds only uncached `/account/*` routes, so it never places `session_cookie` on a cacheable route. The header chrome, its hydration endpoint and the cache-isolation gates are the companion plan's (`2026-07-29-thallo-account-chrome.md`) — do not add them here.
- **Quality gates per commit:** `vendor/bin/phpunit`, `vendor/bin/phpcs --standard=PSR12` on touched PHP.
- **Commit cadence:** commit at Tasks 3, 5 and 6 only. Never push. No AI/assistant attribution anywhere.

---

## Landscape verified before writing this plan

- `MemberSignupService::activate()` runs `find()` + kind check + consumed short-circuit **outside** the transaction, then inside `runAsTenant(transaction(...))`: `lockForUpdate` → status must be `email_verified` → **member policy checks** (tenant active, `memberSignupEnabled`, `roles->isEligible`) → existing-email handoff → username conflict → `users->create()` → `updateProfile()` → `administration->addMember()` → `setResults()` → `consume()` → `afterCommit` audit `signup.member_activated`. Two catch blocks follow: `USERNAME_CONFLICT` becomes a `conflict` result carrying the continuation token; any other `Throwable` re-checks `emailExists()` and, if true, consumes as `existing_account_handoff`.
- **`SignupCoordinator::verify()` and `continue()` dispatch on kind with a binary ternary** — `kind === 'member' ? members : workspaces`. A third kind silently routes to the workspace branch. It is not a hole today only because `WorkspaceSignupService::provision()` guards `kind === 'workspace'` and throws a 404 — so a customer verifying through the shared route would get a confusing "intent unavailable" instead of an account. **Task 4 fixes this dispatch.**
- `SignupIntentRepository` exposes `create/find/lockForUpdate/transition/updateUsername/setResults/consume`. Kinds in use: `member`, `workspace`.
- Packs register capabilities in `boot()` via `CapabilityRegistry::register(new Capability(id, label:, description:))`, call `loadMigrationsFrom()` **outside** the enable gate, and render themed HTML by injecting `Thallo\Render\TwigFactory` and calling `$env->render('…twig', [...])`.
- Recovery: `EmailVerification::sendPasswordResetEmail(string $email, ?ApplicationContext $context = null): array` and `AccountController::resetPassword()` consumes a reset token via `$this->verifier->consumePasswordResetToken()`.

---

## File Structure

**Modified — `app/Signup/`:**

| File | Responsibility |
|---|---|
| `VerifiedAccountActivator.php` (new) | Everything common to activation, up to and including identity creation, plus the purpose continuation hook. |
| `MemberSignupService.php` | Keeps its public API; `activate()` becomes a thin call into the activator with a member continuation. |
| `CustomerSignupService.php` (new) | Shopper registration: begin/resend/verify, and an activation whose continuation is a no-op. |
| `SignupCoordinator.php` | Three-way kind dispatch instead of a binary ternary. |

**New — `packages/thallo-contracts/src/Account/`:** `StorefrontAccountRegistration.php`, `StorefrontAccountRecovery.php`, `RegistrationResult.php`, `RecoveryResult.php`, `AccountNavigationRegistry.php`, `AccountNavigationItem.php`.

**New — `app/Account/`:** `AppStorefrontAccountRegistration.php`, `AppStorefrontAccountRecovery.php`, `InMemoryAccountNavigationRegistry.php` — the app glue implementing the contracts.

**New — `packages/thallo-account/`:** `composer.json`, `src/AccountServiceProvider.php`, `src/Http/AccountPageController.php`, `src/Http/AccountAuthController.php`, `src/Http/Middleware/AccountSameOriginMiddleware.php`, `src/Contribution/AccountTemplatePathContributor.php`, `routes.php`, `templates/account/*.twig`.

The header block, its asset pipeline and the session endpoint are deliberately **not** here — see `docs/superpowers/plans/2026-07-29-thallo-account-chrome.md`.

**Tests:** `tests/Unit/Signup/VerifiedAccountActivatorTest.php`, `tests/Integration/Signup/CustomerSignupTest.php`, `tests/Integration/Account/AccountContractsTest.php`, `tests/Integration/Account/AccountFlowTest.php`.

---

## Task 1: Extract `VerifiedAccountActivator`

**Files:**
- Create: `app/Signup/VerifiedAccountActivator.php`
- Modify: `app/Signup/MemberSignupService.php`
- Test: `tests/Unit/Signup/VerifiedAccountActivatorTest.php`

**Interfaces:**
- Consumes: `SignupIntentRepository`, `UserDirectory` (whatever `$this->users` is typed as in `MemberSignupService`), `Connection`, `TenantContextRunner`.
- Produces:
  ```php
  VerifiedAccountActivator::activate(
      string $intentUuid,
      string $continuationToken,
      string $purpose,
      callable $afterIdentityCreated,   // fn(string $userUuid, array $intent, string $tenantUuid): void
  ): array
  ```
  Returns `['status' => 'active', 'user_uuid' => …, 'tenant_uuid' => …]`, `['status' => 'consumed', 'outcome' => …]`, or `['status' => 'conflict', 'code' => 'USERNAME_CONFLICT', 'continuation_token' => …, 'errors' => […]]`.

**Ordering inside the one transaction** (unchanged except where noted):
`lockForUpdate` → **assert `kind === $purpose` (NEW, under the lock)** → status must be `email_verified` → existing-email handoff → username conflict → create identity → profile → **`$afterIdentityCreated(...)`** → `setResults` → `consume`. Member policy checks move into the continuation; because it is one transaction, a policy failure rolls the identity back exactly as a pre-creation throw did.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Signup;

use App\Signup\SignupException;
use App\Signup\VerifiedAccountActivator;
use App\Tests\Support\AppTestCase;

final class VerifiedAccountActivatorTest extends AppTestCase
{
    /** Seeds an email-verified intent of the given kind and returns its uuid. */
    private function intent(string $kind, string $email = 'shopper@example.test'): string
    {
        return $this->seedSignupIntent([
            'kind' => $kind,
            'email' => $email,
            'username' => 'shopper' . substr(md5($email), 0, 6),
            'status' => 'email_verified',
            'tenant_uuid' => $this->tenantUuid(),
        ]);
    }

    public function testTheContinuationRunsAfterIdentityCreationAndInsideTheTransaction(): void
    {
        $activator = $this->activator();
        $seen = [];

        $result = $activator->activate(
            $this->intent('customer'),
            'continuation-token',
            'customer',
            function (string $userUuid, array $intent, string $tenantUuid) use (&$seen): void {
                // The identity already exists when the continuation runs, which is what lets a
                // member continuation attach a membership to it.
                $seen = ['user_uuid' => $userUuid, 'kind' => $intent['kind'], 'tenant' => $tenantUuid];
            },
        );

        self::assertSame('active', $result['status']);
        self::assertSame($result['user_uuid'], $seen['user_uuid']);
        self::assertSame('customer', $seen['kind']);
    }

    public function testAFailingContinuationRollsTheIdentityBack(): void
    {
        // The whole point of one transaction: a membership that cannot be granted must not
        // leave a half-made account behind.
        $activator = $this->activator();
        $intentUuid = $this->intent('member', 'rollback@example.test');

        try {
            $activator->activate($intentUuid, 'continuation-token', 'member', function (): void {
                throw new SignupException('Workspace signup policy changed before activation.', 409);
            });
            self::fail('Expected the continuation failure to propagate.');
        } catch (SignupException) {
            // expected
        }

        self::assertFalse($this->userExistsByEmail('rollback@example.test'));
        self::assertSame('email_verified', $this->signupIntentStatus($intentUuid), 'intent must not be consumed');
    }

    public function testAKindMismatchIsRefusedBeforeAnyContinuationRuns(): void
    {
        // The structural boundary: a customer intent can never reach the member continuation,
        // and vice versa. Asserted under the row lock, so a concurrent activation cannot slip
        // between the check and the continuation.
        $activator = $this->activator();
        $ran = false;

        $this->expectException(SignupException::class);
        try {
            $activator->activate($this->intent('customer'), 'tok', 'member', function () use (&$ran): void {
                $ran = true;
            });
        } finally {
            self::assertFalse($ran, 'the member continuation must never see a customer intent');
        }
    }

    /**
     * The kind binding moved UNDER the row lock precisely so a concurrent activation cannot
     * change the row between the check and the work. A single-threaded assertion cannot
     * demonstrate that; this one holds the intent's row lock in one connection while a second
     * process attempts the mismatched activation.
     *
     * This only tests anything because the kind decision happens under the lock: with a
     * pre-lock rejection the child would fail without ever reaching the parent's held row.
     *
     * Follows the pgsql-gated pattern the commerce suite already uses
     * (`Customers\AddressBookConcurrencyTest`): skip unless a PostgreSQL lane is configured,
     * `proc_open` a child for connection B, and assert on the JSON it prints.
     */
    public function testTheKindBindingHoldsUnderConcurrentActivation(): void
    {
        if (getenv('THALLO_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires a PostgreSQL lane for true row-lock interleaving.');
        }

        $intentUuid = $this->intent('customer', 'race@example.test');
        [$connection, $context] = $this->pgContext();

        $connection->getTransactionManager()->begin();
        // A holds the intent row; B's activation must block on it rather than reading a stale kind.
        $this->lockIntentRow($connection, $intentUuid);

        $process = $this->spawnActivationChild($intentUuid, purpose: 'member');
        usleep(300_000);

        // Prove the child is BLOCKED on the parent's row rather than already finished. Without
        // this, a pre-lock rejection produces the same final JSON and the test passes while
        // demonstrating nothing about the lock.
        self::assertTrue(proc_get_status($process)['running'], 'the child must be waiting on the row lock');

        $connection->getTransactionManager()->commit();

        $result = $this->childResult($process);

        self::assertSame('App\Signup\SignupException', $result['exceptionClass']);
        self::assertFalse($this->userExistsByEmail('race@example.test'));
        self::assertSame(0, $this->membershipCountFor($this->anyUserUuidOrEmpty()));
    }

    public function testAConsumedIntentReturnsItsRecordedOutcomeWithoutCreatingASecondIdentity(): void
    {
        $activator = $this->activator();
        $intentUuid = $this->intent('customer', 'twice@example.test');
        $activator->activate($intentUuid, 'tok', 'customer', static fn (): null => null);

        $second = $activator->activate($intentUuid, 'tok', 'customer', static fn (): null => null);

        self::assertSame('consumed', $second['status']);
        self::assertSame(1, $this->countUsersByEmail('twice@example.test'));
    }

    public function testAnExistingEmailBecomesAHandoffRatherThanASecondAccount(): void
    {
        $this->seedUser('taken@example.test');
        $activator = $this->activator();

        $result = $activator->activate($this->intent('customer', 'taken@example.test'), 'tok', 'customer', static fn (): null => null);

        self::assertSame('consumed', $result['status']);
        self::assertSame('existing_account_handoff', $result['outcome']);
    }

    public function testAUsernameConflictReturnsTheContinuationTokenForRetry(): void
    {
        $this->seedUser('other@example.test', username: 'takenname01');
        $activator = $this->activator();
        $intentUuid = $this->seedSignupIntent([
            'kind' => 'customer',
            'email' => 'fresh@example.test',
            'username' => 'takenname01',
            'status' => 'email_verified',
            'tenant_uuid' => $this->tenantUuid(),
        ]);

        $result = $activator->activate($intentUuid, 'continuation-token', 'customer', static fn (): null => null);

        self::assertSame('conflict', $result['status']);
        self::assertSame('USERNAME_CONFLICT', $result['code']);
        self::assertSame('continuation-token', $result['continuation_token']);
    }
}
```

The helpers (`seedSignupIntent`, `seedUser`, `userExistsByEmail`, `countUsersByEmail`, `signupIntentStatus`, `tenantUuid`, `activator`) go on this test class or `AppTestCase` — write them against the real `SignupIntentRepository` and the user directory `MemberSignupService` already injects, not new abstractions.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Signup/VerifiedAccountActivatorTest.php`
Expected: FAIL — `Class "App\Signup\VerifiedAccountActivator" not found`.

- [ ] **Step 3: Write the activator**

`app/Signup/VerifiedAccountActivator.php` — move the body of `MemberSignupService::activate()` verbatim, with three changes: the kind check moves **under** `lockForUpdate` and compares against `$purpose`; `addMember()` and the member policy block become `$afterIdentityCreated(...)`; the audit record is the continuation's job.

```php
<?php

declare(strict_types=1);

namespace App\Signup;

use Glueful\Database\Connection;

/**
 * Everything an activation does regardless of WHY the account is being created, up to and
 * including identity creation — then a purpose-specific continuation, then commit.
 *
 * Extracting this is what makes "a shopper gets identity, not authority" structural: customer
 * activation passes a continuation that does nothing, so there is no code path from it to
 * `addMember()`. The alternative — one service that remembers to skip a line — is a boundary
 * that holds only until somebody edits it.
 *
 * The purpose/kind binding is asserted UNDER the row lock, before either continuation can run:
 * checking it before the lock (as the pre-extraction code did) leaves a window in which a
 * concurrent activation changes the row between the check and the work.
 */
final class VerifiedAccountActivator
{
    public function __construct(
        private readonly SignupIntentRepository $intents,
        private readonly UserDirectory $users,
        private readonly Connection $connection,
        private readonly TenantContextRunner $tenants,
    ) {
    }

    /**
     * @param callable(string,array<string,mixed>,string):void $afterIdentityCreated
     * @return array<string,mixed>
     */
    public function activate(
        string $intentUuid,
        string $continuationToken,
        string $purpose,
        callable $afterIdentityCreated,
    ): array {
        // The initial read establishes ONLY the tenant to run inside. Every decision -- kind,
        // status, consumed outcome -- is made under the row lock below. Deciding here would put
        // the purpose check outside the lock again, which is the window this extraction closes:
        // a concurrent activation can change kind or status between this read and the work.
        $preRead = $this->intents->find($intentUuid);
        if ($preRead === null) {
            throw new SignupException('Signup intent is unavailable.', 404);
        }
        $tenantUuid = (string) ($preRead['tenant_uuid'] ?? '');

        try {
            return $this->tenants->runAsTenant($tenantUuid, function () use (
                $intentUuid,
                $tenantUuid,
                $purpose,
                $afterIdentityCreated
            ): array {
                return $this->connection->transaction(function () use (
                    $intentUuid,
                    $tenantUuid,
                    $purpose,
                    $afterIdentityCreated
                ): array {
                    $intent = $this->intents->lockForUpdate($intentUuid);
                    if ($intent === null || ($intent['kind'] ?? null) !== $purpose) {
                        // THE boundary. A mismatched purpose is indistinguishable from a missing
                        // intent, and it is decided here -- holding the row -- so a concurrent
                        // activation cannot have changed the kind since it was read.
                        throw new SignupException('Signup intent is unavailable.', 404);
                    }
                    if (($intent['status'] ?? null) === 'consumed') {
                        // Idempotent replay: the recorded outcome, read under the lock so two
                        // concurrent activations agree on which one won.
                        return ['status' => 'consumed', 'outcome' => $intent['completion_outcome']];
                    }
                    if (($intent['status'] ?? null) !== 'email_verified') {
                        throw new SignupException('Signup intent cannot be activated.', 409);
                    }

                    $email = (string) $intent['email'];
                    if ($this->users->emailExists($email)) {
                        $this->intents->consume($intentUuid, 'existing_account_handoff');
                        return ['status' => 'consumed', 'outcome' => 'existing_account_handoff'];
                    }
                    $username = (string) $intent['username'];
                    if ($this->users->usernameExists($username)) {
                        throw new SignupException('Username is no longer available.', 409, [
                            'username' => 'Choose another username.',
                        ], 'USERNAME_CONFLICT');
                    }

                    $userUuid = $this->users->create([
                        'username' => $username,
                        'email' => $email,
                        'password' => (string) $intent['password_hash'],
                        'status' => 'active',
                        'email_verified_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    $this->users->updateProfile($userUuid, [
                        'first_name' => (string) $intent['first_name'],
                        'last_name' => (string) $intent['last_name'],
                    ]);

                    // Purpose-specific work INSIDE the same transaction: a failure here rolls
                    // the identity back rather than leaving an account with half its grants.
                    $afterIdentityCreated($userUuid, $intent, $tenantUuid);

                    $this->intents->setResults($intentUuid, $userUuid, $tenantUuid);
                    $this->intents->consume($intentUuid, 'activated');

                    return ['status' => 'active', 'user_uuid' => $userUuid, 'tenant_uuid' => $tenantUuid];
                });
            });
        } catch (SignupException $exception) {
            if ($exception->errorCode === 'USERNAME_CONFLICT') {
                return [
                    'status' => 'conflict',
                    'code' => 'USERNAME_CONFLICT',
                    'continuation_token' => $continuationToken,
                    'errors' => $exception->errors,
                ];
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->users->emailExists((string) $preRead['email'])) {
                $this->intents->consume($intentUuid, 'existing_account_handoff');
                return ['status' => 'consumed', 'outcome' => 'existing_account_handoff'];
            }
            throw $exception;
        }
    }
}
```

Type `$users` and `$tenants` to whatever `MemberSignupService` already declares — copy those declarations rather than inventing names.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Signup/VerifiedAccountActivatorTest.php`
Expected: PASS (6 tests).

---

## Task 2: Refactor `MemberSignupService` onto the activator

**Files:**
- Modify: `app/Signup/MemberSignupService.php`
- Test: the existing member-signup suite, unchanged

**Interfaces:**
- Consumes: `VerifiedAccountActivator::activate()` (Task 1).
- Produces: `MemberSignupService::activate(string $intentUuid, string $continuationToken): array` — same signature and same return shapes as before.

**The gate:** every existing member-signup test must pass **without modification**. If one needs editing, the refactor changed behavior and is wrong.

- [ ] **Step 1: Establish the baseline**

Run: `vendor/bin/phpunit --filter=Signup`
Record the passing count. This is the number that must not move.

- [ ] **Step 2: Rewrite `activate()` as a continuation**

```php
    /** @return array<string,mixed> */
    public function activate(string $intentUuid, string $continuationToken): array
    {
        return $this->activator->activate(
            $intentUuid,
            $continuationToken,
            'member',
            function (string $userUuid, array $intent, string $tenantUuid) use ($intentUuid): void {
                // Workspace policy is re-checked HERE, under the same row lock and inside the
                // same transaction, so a policy that changed mid-flight rolls the identity back
                // instead of granting a membership the workspace no longer permits.
                $tenant = $this->administration->getTenant($this->context, $tenantUuid);
                $role = $this->config->memberSignupRole($tenantUuid);
                if (
                    ($tenant['status'] ?? null) !== 'active'
                    || !$this->config->memberSignupEnabled($tenantUuid)
                    || !$this->roles->isEligible($tenantUuid, $role)
                ) {
                    throw new SignupException('Workspace signup policy changed before activation.', 409);
                }

                $this->administration->addMember($this->context, $tenantUuid, $userUuid, $role);

                $this->connection->afterCommit(fn () => $this->audit->record(
                    'signup.member_activated',
                    $userUuid,
                    $tenantUuid,
                    ['intent_uuid' => $intentUuid, 'role' => $role],
                ));
            },
        );
    }
```

Add `VerifiedAccountActivator $activator` to the constructor and register it in the app's service provider beside `MemberSignupService`.

- [ ] **Step 3: Run the member suite unchanged**

Run: `vendor/bin/phpunit --filter=Signup`
Expected: the exact count from Step 1, all passing, **with no test file edited**.

- [ ] **Step 4: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS at the pre-refactor total.

---

## Task 3: `CustomerSignupService` and the coordinator's third kind

**Files:**
- Create: `app/Signup/CustomerSignupService.php`
- Modify: `app/Signup/SignupCoordinator.php`, `app/Signup/SignupInput.php`
- Test: `tests/Integration/Signup/CustomerSignupTest.php`
- Test fixture: `tests/fixtures/customer_signup_race_child.php`

**Interfaces:**
- Consumes: `VerifiedAccountActivator` (Task 1), `SignupIntentRepository`, `SignupThrottle`, `SignupVerifier`, `SignupMailSender`.
- Produces:
  - `CustomerSignupService::begin(array $input, string $ip): array` — always `['accepted' => true, 'intent_uuid' => string]`, whether the email is new, already registered, or the email channel is unavailable.
  - `CustomerSignupService::activate(string $intentUuid, string $continuationToken): array`
  - `SignupCoordinator` dispatching `customer` to `CustomerSignupService`.

**Why the coordinator must change:** its `verify()` and `continue()` use a binary ternary — `kind === 'member' ? members : workspaces`. A `customer` intent falls to the workspace branch, whose own kind guard then throws "Signup intent is unavailable" (404). A shopper would verify a correct OTP and be told their signup does not exist.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Signup;

use App\Signup\SignupCoordinator;
use App\Signup\SignupException;
use App\Tests\Support\AppTestCase;

final class CustomerSignupTest extends AppTestCase
{
    public function testAVerifiedCustomerIntentActivatesThroughTheCoordinator(): void
    {
        // Before the third branch existed this fell through to workspace provisioning, whose
        // kind guard 404s — a correct OTP answered with "your signup does not exist".
        [$intentUuid, $otp] = $this->beginCustomerSignup('shopper@example.test');

        $result = $this->app(SignupCoordinator::class)->verify($intentUuid, $otp);

        self::assertSame('active', $result['status']);
        self::assertTrue($this->userExistsByEmail('shopper@example.test'));
    }

    public function testACustomerActivationGrantsNoWorkspaceAuthority(): void
    {
        // The acceptance criterion of this whole plan.
        [$intentUuid, $otp] = $this->beginCustomerSignup('noauthority@example.test');
        $result = $this->app(SignupCoordinator::class)->verify($intentUuid, $otp);
        $userUuid = (string) $result['user_uuid'];

        foreach ($this->authorityTables() as $table => $column) {
            self::assertSame(
                0,
                (int) db($this->context)->table($table)->where($column, '=', $userUuid)->count(),
                "{$table} must hold no rows for a shopper"
            );
        }
    }

    public function testAMemberIntentStillActivatesAsAMember(): void
    {
        // The dispatch change must not disturb the existing branch.
        [$intentUuid, $otp] = $this->beginMemberSignup('member@example.test');

        $result = $this->app(SignupCoordinator::class)->verify($intentUuid, $otp);

        self::assertSame('active', $result['status']);
        self::assertGreaterThan(0, $this->membershipCountFor((string) $result['user_uuid']));
    }

    public function testACustomerIntentCannotBeDrivenThroughTheMemberService(): void
    {
        [$intentUuid] = $this->beginCustomerSignup('crosswire@example.test');

        $this->expectException(SignupException::class);
        $this->app(\App\Signup\MemberSignupService::class)->activate($intentUuid, 'tok');
    }


    /**
     * Two intents for the SAME address, both past EVERY duplicate read in
     * UserRepository::create(), both inserting. The database decides; the loser must land on
     * existing_account_handoff rather than a 500, and neither may leave authority rows behind.
     *
     * The barrier is a PostgreSQL BEFORE INSERT trigger, not a service-level hook. That placement
     * is load-bearing: UserRepository::create() repeats emailExists() and usernameExists() after
     * the activator's pre-check, so pausing above the repository would let the child observe the
     * parent's committed row and never exercise the unique constraint.
     */
    public function testConcurrentActivationsForOneEmailCreateExactlyOneUser(): void
    {
        if (getenv('THALLO_TEST_DB_DRIVER') !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL for a real unique-constraint race.');
        }

        [$a, $otpA] = $this->beginCustomerSignup('twice@example.test');
        [$b, $otpB] = $this->beginCustomerSignup('twice@example.test');

        $suffix = (string) getmypid();
        $applicationName = 'thallo_customer_race_' . $suffix;
        $function = 'thallo_test_pause_customer_insert_' . $suffix;
        $trigger = 'thallo_test_customer_insert_' . $suffix;
        $lockExpression = "hashtextextended('thallo:customer-email-race:{$suffix}', 0)";

        // A SECOND real connection owns the session-level advisory lock. The trigger blocks only
        // the child connection (identified by application_name), after UserRepository has issued
        // the INSERT and therefore after both of its duplicate reads.
        $control = $this->secondConnection()->getPDO();
        $child = null;
        $lockHeld = false;
        try {
            $control->exec(
                "CREATE FUNCTION {$function}() RETURNS trigger AS \$\$
                 BEGIN
                     IF current_setting('application_name', true) = '{$applicationName}'
                        AND NEW.email = 'twice@example.test' THEN
                         PERFORM pg_advisory_lock({$lockExpression});
                         PERFORM pg_advisory_unlock({$lockExpression});
                     END IF;
                     RETURN NEW;
                 END;
                 \$\$ LANGUAGE plpgsql"
            );
            $control->exec(
                "CREATE TRIGGER {$trigger} BEFORE INSERT ON users
                 FOR EACH ROW EXECUTE FUNCTION {$function}()"
            );
            $control->exec("SELECT pg_advisory_lock({$lockExpression})");
            $lockHeld = true;

            $child = $this->launchVerificationChild(
                intentUuid: $b,
                otp: $otpB,
                applicationName: $applicationName,
            );

            // Poll pg_stat_activity until the child is waiting inside the trigger. This is the
            // proof that it passed the activator check AND UserRepository's repeated
            // emailExists()/usernameExists() checks. A timeout fails rather than weakening the
            // test into an ordinary pre-check race.
            $this->waitForAdvisoryLockWait($control, $applicationName, $child);

            // Parent inserts and commits while the child is parked immediately before its own
            // physical insert. Releasing the advisory lock then makes the child's INSERT hit the
            // database unique constraint deterministically.
            $first = $this->app(SignupCoordinator::class)->verify($a, $otpA);
            $control->exec("SELECT pg_advisory_unlock({$lockExpression})");
            $lockHeld = false;
            $second = $this->collectVerificationChild($child);
            $child = null;
        } finally {
            // Never strand a process or session lock after a failed assertion.
            if ($lockHeld) {
                $control->exec("SELECT pg_advisory_unlock({$lockExpression})");
            }
            if ($child !== null) {
                $this->terminateVerificationChild($child);
            }
            $control->exec("DROP TRIGGER IF EXISTS {$trigger} ON users");
            $control->exec("DROP FUNCTION IF EXISTS {$function}()");
        }

        // Exactly one user, whichever won.
        self::assertSame(1, $this->countUsersByEmail('twice@example.test'));

        // Both reached a deterministic outcome and no unique violation escaped as a 500.
        self::assertNull($second['exceptionClass'], (string) $second['message']);
        $outcomes = [$first['status'], $second['status']];
        sort($outcomes);
        self::assertSame(['active', 'consumed'], $outcomes);
        self::assertSame('existing_account_handoff', $second['status'] === 'consumed'
            ? $second['outcome']
            : $first['outcome']);

        // And still no authority for either.
        foreach ($this->authorityTables() as $table => $column) {
            self::assertSame(
                0,
                (int) db($this->context)->table($table)->where($column, '=', $this->userUuidFor('twice@example.test'))->count(),
                "{$table} must hold no rows for a shopper"
            );
        }
    }

    public function testTheUsernameIsTheEmail(): void
    {
        // No derivation, so no collision handling and no retry: the email is already unique, and
        // a shopper who wants something else can change it from their account later.
        [$intentUuid, $otp] = $this->beginCustomerSignup('username@example.test');

        $this->app(SignupCoordinator::class)->verify($intentUuid, $otp);

        self::assertSame('username@example.test', $this->usernameFor('username@example.test'));
    }

    public function testFirstAndLastNameAreStoredSeparately(): void
    {
        [$intentUuid, $otp] = $this->beginCustomerSignup('names@example.test', first: 'Ada', last: 'Lovelace');

        $result = $this->app(SignupCoordinator::class)->verify($intentUuid, $otp);
        $profile = $this->profileFor((string) $result['user_uuid']);

        self::assertSame('Ada', $profile['first_name']);
        self::assertSame('Lovelace', $profile['last_name']);
    }

    public function testRegistrationResponsesAreShapeIdenticalForNewAndExistingEmails(): void
    {
        // Enumeration neutrality at the service boundary: the caller cannot tell whether the
        // address was already registered.
        $this->seedUser('already@example.test');

        $fresh = $this->app(\App\Signup\CustomerSignupService::class)->begin(
            ['email' => 'brandnew@example.test', 'password' => 'sufficiently-long-secret', 'first_name' => 'New', 'last_name' => 'Shopper'],
            '203.0.113.10',
        );
        $taken = $this->app(\App\Signup\CustomerSignupService::class)->begin(
            ['email' => 'already@example.test', 'password' => 'sufficiently-long-secret', 'first_name' => 'Old', 'last_name' => 'Shopper'],
            '203.0.113.10',
        );

        // Same keys, same values except the opaque uuid — nothing distinguishes the two.
        self::assertSame(['accepted', 'intent_uuid'], array_keys($fresh));
        self::assertSame(array_keys($fresh), array_keys($taken));
        self::assertTrue($fresh['accepted']);
        self::assertTrue($taken['accepted']);
        self::assertNotSame($fresh['intent_uuid'], $taken['intent_uuid']);
    }
}
```

Implement the test-local process helpers rather than leaving the names above as assumed APIs:

- `secondConnection()` is the exact non-pooled PostgreSQL `Connection` factory already used by
  `ShopCheckoutRaceTest`; do not reuse the primary connection because the advisory lock must live
  in a separate session.
- `launchVerificationChild()` uses `proc_open()` with
  `tests/fixtures/customer_signup_race_child.php`, matching
  `ShopCheckoutRaceTest::launchRaceChild()`. The fixture mirrors the process environment into
  `$_ENV`, boots the real testing app, runs
  `SET application_name = <the validated alphanumeric/underscore argument>` on that app's
  `Connection`, calls `SignupCoordinator::verify($intentUuid, $otp)`, and writes one JSON result
  containing `status`, `outcome`, `exceptionClass`, and `message`.
- `waitForAdvisoryLockWait()` polls `pg_stat_activity` on the control connection for that exact
  `application_name` with `wait_event_type = 'Lock'` and `wait_event = 'advisory'`, using a bounded
  deadline. It also asserts `proc_get_status($childProcess)['running']` while polling. Timing out or
  observing an exited child is a test failure, never permission to continue.
- `collectVerificationChild()` and `terminateVerificationChild()` mirror the existing race
  harnesses: drain/close both pipes, require parseable JSON, close the process, and include stderr
  in any assertion failure. The `finally` block releases the advisory lock before termination so a
  healthy child is never stranded.

The trigger and function names are PID-suffixed and are dropped in `finally`; the trigger filters
the one test email and child `application_name`, so the parent INSERT is never delayed. Reaching
the `pg_stat_activity` advisory wait is the red/green authority that the child passed
`UserRepository::create()`'s duplicate reads and reached the physical INSERT boundary.

`authorityTables()` returns the authority map, pinned to the tables that exist today (verified
against the schema): `['tenant_memberships' => 'user_uuid', 'user_roles' => 'user_uuid',
'user_permissions' => 'user_uuid']`. Add any further scoped-assignment table the app or Aegis
introduces — a table missed here is authority this test cannot see. Assert the map is non-empty
before looping, so a future rename that empties it fails loudly instead of passing vacuously.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Signup/CustomerSignupTest.php`
Expected: FAIL — `Class "App\Signup\CustomerSignupService" not found`.

- [ ] **Step 3: Add a validated customer input shape**

`SignupInput::anonymous()` runs `EmailDTO`, `UsernameDTO`, an 8-character password floor and two
name fields, throwing a 422 `SignupException` carrying per-field errors. A shopper sends no
username, so calling it would fail on a field they were never shown. Add a sibling that
validates exactly what the storefront form collects:

```php
    /**
     * @param array<string,mixed> $input {email, password, first_name, last_name}
     * @return array{email:string,password:string,first_name:string,last_name:string}
     */
    public static function customer(array $input): array
    {
        $errors = [];
        try {
            $email = strtolower(EmailDTO::from(['email' => $input['email'] ?? ''])->email);
        } catch (ValidationException) {
            $email = '';
            $errors['email'] = 'Enter a valid email address.';
        }
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must contain at least 8 characters.';
        }
        // First and last name are separate fields, each run through the same name() helper the
        // member form uses, so one validator owns the shape.
        $first = self::name($input['first_name'] ?? null, 'first_name', $errors);
        $last = self::name($input['last_name'] ?? null, 'last_name', $errors);
        if ($errors !== []) {
            throw new SignupException('Signup details are invalid.', 422, $errors);
        }

        return [
            'email' => $email,
            'password' => $password,
            'first_name' => $first,
            'last_name' => $last,
        ];
    }
```

Add a test asserting an invalid email, a 7-character password, a blank first name and a blank last
name each produce a 422 naming that field, and that no username field is ever required.

- [ ] **Step 4: Write `CustomerSignupService`**

Model `begin()` on `MemberSignupService::begin()` — same throttle, same verifier, same mail sender, same intent creation — with three differences: `'kind' => 'customer'`, no workspace/role resolution, and `'username' => $email`. Framework 1.74.0 widened username validation to the column width (3–255), so a long address is valid.

**The `emailExists()` check is an optimisation, not the guarantee.** It is a read, so two intents for the same address can both pass it before either insert commits. The **database unique constraint is the authority**, and the loser's recovery already exists: the activator's outer `catch (\Throwable)` re-checks `emailExists()` after the transaction has rolled back and consumes the intent as `existing_account_handoff`. That is why the pre-check needs no locking and why there is no retry — the losing intent resolves to the same outcome it would have reached had the winner registered a minute earlier. What must be true is that the constraint violation never escapes as a 500, which the race test below pins.

```php
    /**
     * Mirrors MemberSignupService::begin() -- same throttle, same intent row, same neutral
     * return -- minus the workspace policy, and with the email used as the username.
     *
     * Every path returns `['accepted' => true, 'intent_uuid' => ...]`. An already-registered
     * address gets the existing-account notice and a consumed intent, exactly as member signup
     * does, so the response cannot be used to test whether an email is registered. When the
     * email channel is unavailable the uuid is opaque rather than real, which is
     * indistinguishable to the caller.
     *
     * @param array<string,mixed> $input {email, password, first_name, last_name}
     * @return array{accepted: true, intent_uuid: string}
     */
    public function begin(array $input, string $ip): array
    {
        // SignupInput::anonymous() cannot be reused: it validates a UsernameDTO, and a shopper
        // never supplies a username. customer() validates what a shopper DOES send and derives
        // the rest, so registration input still goes through one validator rather than ad-hoc
        // string handling in a service.
        $data = SignupInput::customer($input);
        $email = $data['email'];
        $password = $data['password'];

        if (!$this->throttle->allowIntent('customer', $ip, $email)) {
            throw new SignupException('Signup request limit reached.', 429);
        }
        if (!$this->config->emailChannelAvailable()) {
            // No channel means no OTP can ever arrive; the opaque id keeps that indistinguishable.
            return ['accepted' => true, 'intent_uuid' => $this->opaqueRequestId()];
        }

        $tenantUuid = $this->singleStore->resolve();
        $intentUuid = $this->intents->create([
            'kind' => 'customer',
            'origin' => 'anonymous',
            'email' => $email,
            // The username IS the email. Both columns are varchar(255), and email uniqueness is
            // enforced by the check below, so this needs no derivation, no collision handling and
            // no retry. A shopper who wants a different username can change it from their
            // account later; nothing here has to invent a name on their behalf.
            'username' => $email,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'password_hash' => (new PasswordHasher())->hash($password),
            'tenant_uuid' => $tenantUuid,
            'desired_slug' => null,
            'workspace_name' => null,
            'result_user_uuid' => null,
            'result_tenant_uuid' => null,
            'request_ip_hash' => $this->throttle->hashIdentifier($ip),
            'expires_at' => $this->expiresAt(),
        ]);

        if ($this->users->emailExists($email)) {
            try {
                $this->mail->sendExistingAccountNotice($intentUuid, $email);
            } finally {
                $this->intents->consume($intentUuid, 'existing_account_handoff');
            }

            return ['accepted' => true, 'intent_uuid' => $intentUuid];
        }

        try {
            $this->verifier->issue($intentUuid, $email);
        } catch (\Throwable $exception) {
            $this->intents->hardDelete($intentUuid);
            throw $exception;
        }

        return ['accepted' => true, 'intent_uuid' => $intentUuid];
    }


    /** @return array<string,mixed> */
    public function activate(string $intentUuid, string $continuationToken): array
    {
        // No continuation: a shopper receives identity and nothing else. There is deliberately
        // no branch here that could reach addMember(). And no retry loop: the username is the
        // email, which the emailExists() check has already established is unclaimed, so there is
        // no derived name for a concurrent signup to collide with.
        return $this->activator->activate(
            $intentUuid,
            $continuationToken,
            'customer',
            function (string $userUuid, array $intent, string $tenantUuid) use ($intentUuid): void {
                $this->connection->afterCommit(fn () => $this->audit->record(
                    'signup.customer_activated',
                    $userUuid,
                    $tenantUuid,
                    ['intent_uuid' => $intentUuid],
                ));
            },
        );
    }
```

- [ ] **Step 5: Give the coordinator a third branch**

In `SignupCoordinator::verify()`, replace the binary ternary with an explicit dispatch:

```php
        return match ($intent['kind'] ?? null) {
            'member' => $this->members->activate($intentUuid, $token),
            'customer' => $this->customers->activate($intentUuid, $token),
            'workspace' => $this->workspaces->provision($intentUuid, $token),
            // An unknown kind must not fall through to whichever branch happens to be last.
            default => throw new SignupException('Signup intent is unavailable.', 404),
        };
```

Do the same in `continue()`, where `customer` throws — shoppers have no username-change operation:

```php
        return match ($intent['kind'] ?? null) {
            'member' => $this->members->continue($intentUuid, $token, $operationId, $operation, $payload),
            'workspace' => $this->workspaces->continue($intentUuid, $token, $operationId, $operation, $payload),
            default => throw new SignupException('Signup intent is unavailable.', 404),
        };
```

Add `CustomerSignupService $customers` to the constructor and register it.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/Signup tests/Unit/Signup`
Expected: PASS.

- [ ] **Step 7: Full gates, then commit**

```bash
vendor/bin/phpunit && vendor/bin/phpcs --standard=PSR12 app/Signup tests/Unit/Signup tests/Integration/Signup
git add app/Signup tests/Unit/Signup tests/Integration/Signup tests/fixtures/customer_signup_race_child.php
git commit -m "feat(signup): extract a verified-account activator and add customer signup

Activation splits into a shared primitive plus a purpose continuation. Everything up
to identity creation is common; member signup appends the workspace policy check and
addMember(), customer signup appends nothing. That makes 'a shopper gets identity,
not authority' structural — there is no code path from customer activation to
addMember() — rather than a line somebody must remember not to add.

The intent kind is now bound to the purpose UNDER the row lock rather than in a
pre-lock read, closing the window where a concurrent activation could change the row
between the check and the work. The continuation runs inside the same transaction, so
a policy failure rolls the identity back with it.

SignupCoordinator's kind dispatch becomes an explicit match. It was a binary ternary
where a third kind fell through to workspace provisioning, whose own guard then 404s —
a shopper would have answered a correct OTP and been told their signup did not exist.
An unknown kind now fails closed instead of landing on whichever branch was last."
```

---

## Task 4: Account contracts and app glue

**Files:**
- Create: `packages/thallo-contracts/src/Account/{StorefrontAccountRegistration,StorefrontAccountRecovery,RegistrationResult,RecoveryResult,RecoveryVerification,AccountNavigationRegistry,AccountNavigationItem}.php`
- Create: `app/Account/{AppStorefrontAccountRegistration,AppStorefrontAccountRecovery,InMemoryAccountNavigationRegistry}.php`
- Test: `tests/Integration/Account/AccountContractsTest.php`

**Interfaces:**
- Consumes: `CustomerSignupService`, `SignupCoordinator` (Task 3); `EmailVerification::sendPasswordResetEmail(string $email, ?ApplicationContext $context = null): array`.
- Produces:
  ```php
  interface StorefrontAccountRegistration {
      public function begin(string $email, string $password, string $firstName, string $lastName, string $ip): RegistrationResult;
      public function resend(string $intentUuid, string $ip): void;
      public function verify(string $intentUuid, string $otp): RegistrationResult;
  }
  interface StorefrontAccountRecovery {
      public function begin(string $email, string $ip): RecoveryResult;
      public function verify(string $email, string $otp): RecoveryVerification;
      public function complete(string $resetToken, string $newPassword): RecoveryResult;
  }
  ```
  `RegistrationResult` is readonly with `bool $pendingVerification`, `?string $intentUuid`, `?string $userUuid`. `RecoveryResult` is readonly with a single `bool $accepted` — **no field can distinguish unknown email from delivery failure.** `RecoveryVerification` is readonly with `bool $verified` and `?string $resetToken` (null unless verified).

**The middle step is not optional.** `EmailVerification::sendPasswordResetEmail()` mails an OTP, and the reset token only comes from `verifyPasswordResetOTP(string $email, string $providedOTP): ?array`, which returns null on a bad code and otherwise a payload carrying the token that `AccountController::resetPassword()` consumes. A contract with only `begin()` and `complete()` gives a visitor no way to turn the emailed code into the token `complete()` demands — the flow would dead-end at the inbox. `verify()` returning `$verified: false` covers both a wrong code and an unknown email, so it leaks nothing either.

**Why `RecoveryResult` is shaped that way:** `glueful/users`' `AccountController::forgotPassword()` returns the neutral body only when `security.auth.generic_error_responses` is enabled, declares a `404` in its own OpenAPI attributes, and takes a third path on delivery failure. A storefront cannot inherit a neutrality that a host config can switch off, so the contract makes the distinction unrepresentable and the glue collapses all three outcomes.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Account\StorefrontAccountRecovery;
use Thallo\Contracts\Account\StorefrontAccountRegistration;

final class AccountContractsTest extends AppTestCase
{
    public function testRecoveryIsNeutralForKnownAndUnknownEmails(): void
    {
        $this->seedUser('known@example.test');
        $recovery = $this->app(StorefrontAccountRecovery::class);

        $known = $recovery->begin('known@example.test', '203.0.113.10');
        $unknown = $recovery->begin('nobody@example.test', '203.0.113.10');

        self::assertTrue($known->accepted);
        self::assertTrue($unknown->accepted);
        self::assertEquals($known, $unknown, 'the two results must be indistinguishable');
    }

    public function testRecoveryStaysNeutralWithGenericErrorResponsesDisabled(): void
    {
        // The users extension only returns a neutral body when this flag is on. The storefront
        // cannot inherit a neutrality a host config can switch off.
        $this->setConfig('security.auth.generic_error_responses', false);
        $this->seedUser('known2@example.test');
        $recovery = $this->app(StorefrontAccountRecovery::class);

        self::assertEquals(
            $recovery->begin('known2@example.test', '203.0.113.10'),
            $recovery->begin('nobody2@example.test', '203.0.113.10'),
        );
    }

    public function testRecoveryReportsAcceptedEvenWhenDeliveryFails(): void
    {
        $this->makeMailDeliveryFail();
        $recovery = $this->app(StorefrontAccountRecovery::class);
        $this->seedUser('known3@example.test');

        self::assertTrue($recovery->begin('known3@example.test', '203.0.113.10')->accepted);
    }

    public function testTheRecoveryResultCannotExpressWhyItFailed(): void
    {
        // Structural: a future caller cannot start branching on a reason that does not exist.
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(\Thallo\Contracts\Account\RecoveryResult::class))->getProperties(),
        );

        self::assertSame(['accepted'], $properties);
    }

    public function testRecoveryRoundTripsFromOtpToANewPassword(): void
    {
        $this->seedUser('reset@example.test', password: 'old-password-value');
        $recovery = $this->app(StorefrontAccountRecovery::class);

        $recovery->begin('reset@example.test', '203.0.113.10');
        $verification = $recovery->verify('reset@example.test', $this->lastPasswordResetOtpFor('reset@example.test'));

        self::assertTrue($verification->verified);
        self::assertNotNull($verification->resetToken);

        self::assertTrue($recovery->complete($verification->resetToken, 'brand-new-password')->accepted);
        self::assertTrue($this->passwordMatches('reset@example.test', 'brand-new-password'));
    }

    public function testAWrongRecoveryOtpVerifiesNothingAndYieldsNoToken(): void
    {
        $this->seedUser('badotp@example.test');
        $recovery = $this->app(StorefrontAccountRecovery::class);
        $recovery->begin('badotp@example.test', '203.0.113.10');

        $verification = $recovery->verify('badotp@example.test', '000000');

        self::assertFalse($verification->verified);
        self::assertNull($verification->resetToken);
    }

    public function testAnUnknownEmailAndAWrongCodeAreIndistinguishableAtVerification(): void
    {
        $recovery = $this->app(StorefrontAccountRecovery::class);

        self::assertEquals(
            $recovery->verify('nobody@example.test', '123456'),
            $recovery->verify('alsonobody@example.test', '654321'),
        );
    }

    public function testAResetTokenIsSingleUse(): void
    {
        $this->seedUser('replay@example.test');
        $recovery = $this->app(StorefrontAccountRecovery::class);
        $recovery->begin('replay@example.test', '203.0.113.10');
        $token = $recovery->verify('replay@example.test', $this->lastPasswordResetOtpFor('replay@example.test'))->resetToken;

        self::assertTrue($recovery->complete((string) $token, 'first-new-password')->accepted);
        // A leaked link must not be usable twice.
        self::assertFalse($recovery->complete((string) $token, 'second-new-password')->accepted);
    }

    public function testCompletingARecoveryRevokesExistingSessions(): void
    {
        // Whoever forced the reset must lose the access the reset exists to revoke.
        $this->seedUser('revoke@example.test', password: 'old-password-value');
        $cookies = $this->signInAs('revoke@example.test', 'old-password-value');
        $recovery = $this->app(StorefrontAccountRecovery::class);
        $recovery->begin('revoke@example.test', '203.0.113.10');
        $token = $recovery->verify('revoke@example.test', $this->lastPasswordResetOtpFor('revoke@example.test'))->resetToken;

        $recovery->complete((string) $token, 'brand-new-password');

        self::assertContains($this->get('/account', cookies: $cookies)->getStatusCode(), [302, 401]);
    }

    public function testRegistrationRoundTripsThroughTheContract(): void
    {
        $registration = $this->app(StorefrontAccountRegistration::class);

        $begun = $registration->begin('contract@example.test', 'sufficiently-long-secret', 'Contract', 'Tester', '203.0.113.10');
        self::assertTrue($begun->pendingVerification);
        self::assertNotNull($begun->intentUuid);

        $verified = $registration->verify($begun->intentUuid, $this->lastOtpFor($begun->intentUuid));

        self::assertFalse($verified->pendingVerification);
        self::assertNotNull($verified->userUuid);
    }

    public function testTheAccountPackDoesNotImportAppSignup(): void
    {
        // The module boundary, enforced rather than documented.
        // RecursiveDirectoryIterator, not glob('**/*.php') — PHP's glob does not recurse, so a
        // nested import would slip past the very check that exists to catch it.
        $offenders = [];
        $root = dirname(__DIR__, 3) . '/packages/thallo-account/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && str_contains((string) file_get_contents($file->getPathname()), 'App\\Signup')) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame([], $offenders, 'thallo-account must consume contracts, not App\\Signup');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Account/AccountContractsTest.php`
Expected: FAIL — the contract interfaces do not exist.

- [ ] **Step 3: Write the contracts**

`RecoveryResult` carries exactly one property:

```php
<?php

declare(strict_types=1);

namespace Thallo\Contracts\Account;

/**
 * The outcome of a recovery request, as far as any caller may know: accepted.
 *
 * There is deliberately no field for "unknown email" or "delivery failed". Verification proves
 * nothing about whether an address is registered here, and a storefront that leaked the
 * difference would be an account-existence oracle. Operational failures are logged, not returned.
 */
final class RecoveryResult
{
    public function __construct(public readonly bool $accepted)
    {
    }
}
```

`RegistrationResult`, `AccountNavigationItem` (readonly: `string $id`, `string $label`, `string $path`, `int $order`, `?string $capability`) and the three interfaces follow the same shape. `AccountNavigationRegistry` exposes `register(AccountNavigationItem $item): void` and `items(): array` sorted by `order`.

- [ ] **Step 4: Write the app glue**

`AppStorefrontAccountRecovery` collapses every outcome:

```php
    public function begin(string $email, string $ip): RecoveryResult
    {
        try {
            EmailVerification::sendPasswordResetEmail($email, $this->context);
        } catch (\Throwable $e) {
            // Unknown address, throttled sender, SMTP outage — all identical to the caller.
            // The operator sees it in the log; the visitor sees "check your email" either way.
            $this->logger->warning('Storefront recovery request failed', ['error' => $e->getMessage()]);
        }

        return new RecoveryResult(accepted: true);
    }
```

`verify()` exchanges the emailed OTP for the reset token, and `complete()` consumes it once:

```php
    public function verify(string $email, string $otp): RecoveryVerification
    {
        // Returns null for a bad code AND for an unknown address, so this leaks nothing either.
        $payload = (new EmailVerification(context: $this->context))->verifyPasswordResetOTP($email, $otp);
        // The key is `reset_token`, not `token` (EmailVerification returns
        // ['reset_token' => ..., 'expires_in' => ...]).
        if ($payload === null || !is_string($payload['reset_token'] ?? null)) {
            return new RecoveryVerification(verified: false, resetToken: null);
        }

        return new RecoveryVerification(verified: true, resetToken: (string) $payload['reset_token']);
    }

    public function complete(string $resetToken, string $newPassword): RecoveryResult
    {
        // consumePasswordResetToken() is single-use: a replayed token returns null, so a leaked
        // link cannot be used twice.
        $reset = $this->verifier->consumePasswordResetToken($resetToken);
        if ($reset === null) {
            return new RecoveryResult(accepted: false);
        }

        // setNewPassword() takes a PRE-HASHED password — its docblock says so and it writes the
        // value straight to the column. Passing the plaintext here would store the plaintext.
        // 'uuid' is passed explicitly so a uuid is never sniffed as an email.
        $written = $this->users->setNewPassword(
            (string) $reset['user_uuid'],
            $this->passwordHasher->hash($newPassword),
            'uuid',
        );
        if ($written !== true) {
            // The token is already consumed but the password did not change: reporting success
            // would strand the visitor with old credentials and a dead link.
            $this->logger->error('Storefront recovery could not write the new password', [
                'user_uuid' => (string) $reset['user_uuid'],
            ]);

            return new RecoveryResult(accepted: false);
        }

        // Only after a CONFIRMED write: revoking first would log the visitor out of a password
        // they still have. Whoever forced the reset must lose the access it exists to revoke.
        $this->sessions->revokeAllForUser((string) $reset['user_uuid']);

        return new RecoveryResult(accepted: true);
    }
```

Inject `PasswordHasher` into the glue for that call. Read `AccountController::resetPassword()`
first and reuse its verifier and password write verbatim rather than building a parallel path —
including how it hashes, since `setNewPassword()` does not.

Add a test asserting the stored value is a **verifiable hash, not the plaintext**:
`self::assertNotSame('brand-new-password', $this->storedPasswordHashFor('reset@example.test'));`
alongside the existing `passwordMatches()` assertion. The two together are what catch a plaintext
write — `passwordMatches()` alone would pass if the column held the plaintext and the check
compared plaintext to plaintext. Confirm the
session-revocation method's real name on the framework's session store; if none exists, revoke by
terminating the user's sessions through whatever `SessionCacheManager` exposes, and say so in the
docblock rather than silently skipping it.

`complete()` is the one recovery operation that may return `accepted: false` — an invalid or
replayed token is not an enumeration signal, it is a fact about the token in hand.

Register all four implementations in the app service provider against their contract ids.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/Account/AccountContractsTest.php`
Expected: PASS (11 tests). The `testTheAccountPackDoesNotImportAppSignup` case passes trivially until Task 5 creates the pack, and becomes meaningful then.

---

## Task 5: The `thallo-account` pack

**Files:**
- Create: `packages/thallo-account/composer.json`, `src/AccountServiceProvider.php`, `src/Http/AccountAuthController.php`, `src/Http/AccountPageController.php`, `src/Http/Middleware/AccountSameOriginMiddleware.php`, `src/Contribution/AccountTemplatePathContributor.php`, `routes.php`, `templates/account/{layout,login,register,verify,forgot-password,verify-reset,reset-password,dashboard}.twig`
- Modify: root `composer.json` (path repository + require), `config/serviceproviders.php`
- Test: `tests/Integration/Account/AccountFlowTest.php`

**Interfaces:**
- Consumes: the contracts from Task 4; framework `LoginOrchestrator`, `SessionCookieIssuer`, `SessionLogout`; `Thallo\Render\TwigFactory`.
- Produces: routes `GET|POST /account/{login,register,verify/{intentUuid},forgot-password,verify-reset,reset-password}`, `POST /account/logout`, `GET /account`; capability `thallo.accounts`.

**Route gating:** anonymous routes carry no `session_cookie`; `/account` and any future authenticated page carry `['session_cookie', 'auth']`. Registering the whole prefix behind auth would lock a signed-out visitor out of the login page.

**Login must go through the orchestrator**, never `AuthenticationService` directly — that is what keeps the two-factor gate un-bypassable. On a `twoFactorRequired` outcome the page fails closed with a message; it does not issue a cookie.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Tests\Support\AppTestCase;

final class AccountFlowTest extends AppTestCase
{
    public function testAVisitorRegistersVerifiesSignsInSeesTheAccountAndSignsOut(): void
    {
        // Same-origin provenance is required on every anonymous POST, so the helper sets
        // Sec-Fetch-Site: same-origin. A request without it must 403 (asserted separately).
        $begin = $this->postSameOrigin('/account/register', [
            'email' => 'flow@example.test',
            'password' => 'sufficiently-long-secret',
            'first_name' => 'Flow', 'last_name' => 'Tester',
        ]);
        self::assertSame(302, $begin->getStatusCode());

        $intentUuid = $this->lastSignupIntentUuid();
        $verify = $this->post('/account/verify/' . $intentUuid, ['otp' => $this->lastOtpFor($intentUuid)]);
        self::assertSame(302, $verify->getStatusCode());

        $login = $this->post('/account/login', [
            'email' => 'flow@example.test',
            'password' => 'sufficiently-long-secret',
        ]);
        $cookies = $this->cookiesFrom($login);
        self::assertArrayHasKey('gf_session', $cookies);
        self::assertTrue($cookies['gf_session']->isHttpOnly());

        $dashboard = $this->get('/account', cookies: $cookies);
        self::assertSame(200, $dashboard->getStatusCode());

        $logout = $this->postSameOrigin('/account/logout', [
            '_token' => $this->csrfTokenFor($cookies),
        ], cookies: $cookies);
        self::assertLessThan(time(), $this->cookiesFrom($logout)['gf_session']->getExpiresTime());

        // And the whole point: identity without authority.
        foreach ($this->authorityTables() as $table => $column) {
            self::assertSame(
                0,
                (int) db($this->context)->table($table)->where($column, '=', $this->userUuidFor('flow@example.test'))->count(),
                "{$table} must hold no rows for a shopper"
            );
        }
    }

    public function testEveryUnsafeAccountRouteCarriesAnApprovedCsrfPolicy(): void
    {
        // The matrix as a gate rather than a table: a new POST added to this pack without a
        // policy fails here instead of shipping unprotected. Lives in THIS plan because this
        // plan's routes are the ones being shipped.
        $routes = $this->accountRouteInventory();
        self::assertNotSame([], $routes, 'the inventory must not be empty — an empty loop proves nothing');

        foreach ($routes as $route) {
            if (in_array($route['method'], ['GET', 'HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $anonymous = !in_array('auth', $route['middleware'], true);
            $policy = $anonymous
                // No session exists to bind a token to, so provenance plus a rate limit is it.
                ? $this->hasSameOriginGuard($route) && $this->hasRateLimit($route)
                // Cookie-authenticated mutations: a session-bound token.
                : $this->hasCsrfToken($route);

            self::assertTrue($policy, "{$route['method']} {$route['path']} has no approved CSRF policy");
        }
    }

    public function testAnAnonymousPostWithoutSameOriginProvenanceIsRejected(): void
    {
        // The anonymous half of the matrix: no session exists to bind a token to, so provenance
        // is the whole control. A cross-site form post must not reach registration.
        $response = $this->post('/account/register', [
            'email' => 'crosssite@example.test',
            'password' => 'sufficiently-long-secret',
            'first_name' => 'X', 'last_name' => 'Site',
        ], headers: ['Sec-Fetch-Site' => 'cross-site']);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->userExistsByEmail('crosssite@example.test'));
    }

    public function testAnAuthenticatedMutationWithoutItsTokenIsRejected(): void
    {
        $cookies = $this->signInAs('notoken@example.test');

        $response = $this->postSameOrigin('/account/logout', [], cookies: $cookies);

        // Cookie-authenticated mutations need the session-bound token; provenance alone is not
        // the approved policy for this row of the matrix.
        self::assertContains($response->getStatusCode(), [403, 419, 422]);
    }

    public function testEveryAccountTemplateResolvesThroughTwig(): void
    {
        // A registered route with an unregistered template path is the failure this catches:
        // the pack looks wired, and every page 500s on first render.
        foreach (['login', 'register', 'verify', 'forgot-password', 'verify-reset', 'reset-password', 'dashboard'] as $name) {
            self::assertTrue(
                $this->twigEnvironment()->getLoader()->exists('account/' . $name . '.twig'),
                "account/{$name}.twig does not resolve — is the template path contributor registered?"
            );
        }
    }

    public function testTheAccountShellRequiresACookieButTheLoginPageDoesNot(): void
    {
        // The prefix is not the auth boundary — gating it wholesale would lock a signed-out
        // visitor out of signing in.
        self::assertSame(200, $this->get('/account/login')->getStatusCode());
        self::assertContains($this->get('/account')->getStatusCode(), [302, 401]);
    }

    public function testLoginFailsClosedForATwoFactorEnabledAccount(): void
    {
        $this->seedUserWithTwoFactor('twofa@example.test', 'sufficiently-long-secret');

        $response = $this->post('/account/login', [
            'email' => 'twofa@example.test',
            'password' => 'sufficiently-long-secret',
        ]);

        // No session, no cookie: the storefront has no second-factor step yet, so it must
        // refuse rather than route around the gate.
        self::assertArrayNotHasKey('gf_session', $this->cookiesFrom($response));
    }

    public function testRegistrationIsNeutralForAnAlreadyRegisteredEmail(): void
    {
        $this->seedUser('taken@example.test');

        $fresh = $this->post('/account/register', [
            'email' => 'new@example.test', 'password' => 'sufficiently-long-secret', 'first_name' => 'A', 'last_name' => 'One',
        ]);
        $taken = $this->post('/account/register', [
            'email' => 'taken@example.test', 'password' => 'sufficiently-long-secret', 'first_name' => 'B', 'last_name' => 'Two',
        ]);

        self::assertSame($fresh->getStatusCode(), $taken->getStatusCode());
        self::assertSame($fresh->headers->get('Location'), $taken->headers->get('Location'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/Account/AccountFlowTest.php`
Expected: FAIL — the `/account/*` routes do not exist (404).

- [ ] **Step 3: Scaffold the pack**

`packages/thallo-account/composer.json` mirrors `packages/thallo-commerce/composer.json`: name `glueful/thallo-account`, PSR-4 `Thallo\Account\` → `src/`, `extra.glueful.provider` pointing at `AccountServiceProvider`. Add the path repository and requirement to the root `composer.json`, then `composer update glueful/thallo-account`.

- [ ] **Step 4: Register the pack's template path**

`TwigFactory` resolves only paths a pack has contributed. Without this the routes exist and every
render throws a loader error — the pack would look wired and fail on first request. Mirror
`ShopTemplatePathContributor`:

`TemplatePathContributor` requires three methods — `contributorId()`, `priority()` and
`templatePaths()` returning a `list<string>` of absolute directories. Contributed paths resolve
between the active app theme and the render default, so a theme can override an account template
while the pack still ships a working one:

```php
// src/Contribution/AccountTemplatePathContributor.php
final class AccountTemplatePathContributor implements TemplatePathContributor
{
    public function contributorId(): string
    {
        return 'thallo-account';
    }

    public function priority(): int
    {
        return 100;
    }

    /** @return list<string> */
    public function templatePaths(): array
    {
        return [dirname(__DIR__, 2) . '/templates'];
    }
}
```

Templates are then referenced by path relative to that directory (`account/login.twig`), not by a
Twig namespace.

- [ ] **Step 5: Write the service provider**

```php
    public function boot(ApplicationContext $context): void
    {
        app($context, CapabilityRegistry::class)->register(new Capability(
            'thallo.accounts',
            label: 'Storefront accounts',
            description: 'Themed registration, sign-in and account pages for storefront visitors.',
        ));

        // Routes, templates and the block type register only when the capability is ENABLED.
        // The framework's /auth/* APIs are never gated by it — this switch controls Thallo's
        // product surface, not global identity infrastructure.
        if (app($context, CapabilityRegistry::class)->isEnabled('thallo.accounts')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes.php');

            // Without this the routes exist and every render throws a loader error.
            app($context, RenderContributionRegistry::class)
                ->registerTemplatePaths(new AccountTemplatePathContributor());

            $this->registerAccountBlockTypeContributor($context);
        }
    }
```

- [ ] **Step 6: Implement the CSRF mechanisms the matrix names**

The matrix is only real if these exist. Two policies, each built from what the framework
already ships — do not invent new primitives:

**Anonymous unsafe routes** (login, register, verify, forgot-password, verify-reset,
reset-password) get an `account_same_origin` middleware wrapping the framework's
`Glueful\Auth\Session\SameOriginGuard`:

```php
// src/Http/Middleware/AccountSameOriginMiddleware.php
final class AccountSameOriginMiddleware implements RouteMiddleware
{
    public function __construct(private readonly SameOriginGuard $origin)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Safe methods carry no state change; only unsafe ones need provenance.
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        if (!$this->origin->isSameOrigin($request)) {
            // No session exists yet on these routes, so there is no token to bind -- provenance
            // (fetch metadata, else an exact Origin match) is the control.
            return new JsonResponse(['success' => false, 'message' => 'Request rejected.'], 403);
        }

        return $next($request);
    }
}
```

Register it as alias `account_same_origin` in the pack provider and attach it, with a rate limit,
to every anonymous POST:

```php
    $router->post('/account/login', [AccountAuthController::class, 'login'])
        ->middleware(['account_same_origin', 'rate_limit:10,60']);
```

**Cookie-authenticated mutations** (`POST /account/logout` and any future account write) get
`['session_cookie', 'auth', 'csrf']` — the framework's `CSRFMiddleware`, which after 1.73.0 binds
tokens to the session uuid rather than a request fingerprint. Every such form renders the token:

```twig
    <input type="hidden" name="_token" value="{{ csrf_token }}">
```

`AccountPageController` supplies `csrf_token` by calling **`CSRFMiddleware::generateToken($request)`**
— plain, not the explicit-session variant. By the time an account page renders, `auth` has already
set the `user` attribute, so `getSessionId()` extracts `sid`/`session_uuid`/`session_id` itself and
binds correctly. `generateTokenForSession()` exists for login response shaping, where no identity
is attached yet; using it here means passing an id by hand, and passing the user uuid instead of
the session uuid would bind every one of that user's sessions to one token.

The account pages are uncached, so embedding a per-session token is safe here. It would not be on
a cacheable page, which is why the header block hydrates instead.

- [ ] **Step 7: Write the auth controller**

- [ ] **Step 8: Write the pages**

Eight Twig templates extending the theme layout, each a plain form posting to its own route. `verify.twig` is a registration OTP entry form and `verify-reset.twig` is the recovery OTP entry form that exchanges the emailed code for a reset token (the mailer sends a code, not a link, in both flows).

`dashboard.twig` renders the `AccountNavigationRegistry` items rather than a hardcoded menu — it ships with none of its own, and Plan 4 contributes orders and addresses to it without editing this pack. Filter each item by its `capability` field so a section whose capability is disabled disappears without deleting data.

`/account` is an uncached, cookie-authenticated route, so it may safely render the signed-in visitor's name. The prohibition binds *cacheable* pages, which is why the header block — the one piece of account chrome that appears on cached pages — is a universal shell hydrated client-side, and why it ships in the companion chrome plan rather than here.

- [ ] **Step 9: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/Account`
Expected: PASS.

- [ ] **Step 10: Full gates, then commit**

```bash
vendor/bin/phpunit && vendor/bin/phpcs --standard=PSR12 packages/thallo-account/src app/Account packages/thallo-contracts/src/Account
git add packages/thallo-account packages/thallo-contracts/src/Account app/Account composer.json composer.lock config/serviceproviders.php tests/Integration/Account
git commit -m "feat(account): add the storefront account pack and its contracts

thallo-account owns the themed /account/* pages and consumes neutral contracts, never
App\\Signup — a test walks the pack's sources to keep that boundary real rather than
documented. Recovery goes through a result type that cannot express 'unknown email' or
'delivery failed', because the users extension only returns a neutral body when a host
config flag is on, and a storefront cannot inherit a neutrality that can be switched off.

Login runs through the framework's LoginOrchestrator and fails closed on a two-factor
challenge: no session, no cookie. The /account prefix is not the auth boundary — the
login, register, verify and recovery pages are anonymous, everything else needs the
session cookie."
```

---

## Task 6: Documentation and changelog

**Files:**
- Create: `docs/STOREFRONT_ACCOUNTS.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Write the guide**

Cover, in order: enabling `thallo.accounts`; that registration collects email, password, first
name and last name, and that **the username defaults to the email** — with changing it after
sign-in named as a follow-up rather than implied to exist; the `/account/*` routes and which are
anonymous; how registration → OTP verification → activation flows; that shoppers receive identity and no workspace authority; how a pack contributes an account-navigation item; and that account chrome hydrates from `/_account/session` rather than being server-rendered, with the cache reason stated — noting that the block and endpoint themselves ship in `2026-07-29-thallo-account-chrome.md`.

- [ ] **Step 2: Update the changelog**

Add under `## [Unreleased]` → `### Added` an entry covering the activator extraction, customer signup with the email as username, the coordinator's explicit dispatch, the contracts, and the pack's themed pages — leading with the zero-authority guarantee. Do **not** claim the header block or the hydration endpoint: those ship in the chrome plan and get their own entry. Note the framework floor: this pack requires glueful/framework ^1.74.0, whose widened username validation is what lets an email be a username.

- [ ] **Step 3: Full gates**

```bash
vendor/bin/phpunit && vendor/bin/phpcs --standard=PSR12 app packages/thallo-account/src
```

- [ ] **Step 4: Commit**

```bash
git add docs/STOREFRONT_ACCOUNTS.md CHANGELOG.md
git commit -m "docs(account): document storefront accounts

Covers enabling the capability, which /account routes are anonymous, the OTP
verification flow, the zero-authority guarantee for shoppers, and why account chrome
hydrates from a private endpoint instead of being server-rendered."
```

---

## Named follow-up

**Changing your username.** The username defaults to the email and nothing in this plan lets a
visitor change it. That is a deliberate omission, not an oversight: it belongs with the rest of
account/profile editing, which this plan does not build. Named here so "username == email" is
never mistaken for a permanent constraint.

## What this unblocks

The companion chrome plan (`2026-07-29-thallo-account-chrome.md`) and, through it, the commerce
account area and wishlist synchronization. Plans 4 and 5 both need a signed-in visitor, which this
provides — they do not need the header block. Plan 4 additionally owns the guarded, audited endpoints that call Commerce's claim seams — including the fresh-authentication and confirmation gate the email-only historical import requires, which Commerce deliberately ships without a route.
