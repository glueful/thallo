# Update Notice (phase 4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** an install learns, within a day, that a newer `glueful/thallo-core` exists on Packagist, and administrators see it in the admin with the changelog link and the upgrade command — never an in-place updater.

**Architecture:** a daily scheduled job asks Packagist's public metadata for `glueful/thallo-core`, keeps the newest version an install may move to (a pre-release only when the install itself runs one) in the system flags, and an authenticated admin endpoint reports `{current, latest, available, notesUrl}`, where `current` is what Composer installed, read at request time so the notice clears the moment `composer update` has run. The admin shows a dismissible Home card and a sidebar badge; dismissal is per browser and per version.

**Tech Stack:** PHP 8.3, Glueful framework 1.85.3 (scheduler via `config/schedule.php`, `Glueful\Queue\Job`, `SystemChannel` flags, Symfony HttpClient, `composer/semver`), Vue 3 admin with Nuxt UI, vitest, PHPUnit.

**Spec:** `docs/internal/plans/2026-09-12-composer-updatable-thallo.md` (phase 4) and `docs/internal/DISTRIBUTION.md` decision 11.

## Global Constraints

- No telemetry: the request carries no install identifier; it is a plain GET of `https://repo.packagist.org/p2/glueful/thallo-core.json`.
- `UPDATE_CHECK_ENABLED=false` opts out; the job then does nothing and the endpoint reports `latest: null`.
- Silent on failure: a failed fetch keeps the previous result and logs at debug; it never surfaces to a request.
- One request per install per day; the checker refuses to fetch twice within 20 hours unless forced.
- **Decision 11 amended:** the status is served by an authenticated admin route, not `/admin/config`. `/admin/config` is unauthenticated (it is read before login), and an anonymous endpoint that reveals the installed version is a fingerprint. Record the amendment in `DISTRIBUTION.md`.
- A development checkout (thallo-core is a symlinked path package) reports `current: null`, `development: true`, never checks, never shows a notice.
- Skeleton parity: `config/schedule.php` and `.env.example` change in both copies (`tests/Unit/Skeleton/SkeletonParityTest.php`).
- Regenerate `docs/openapi.json` (`composer docs:openapi`) and the admin's typed client (`pnpm gen:api`) after the new route; the commerce gate test compares the tracked document.

---

### Task 1: Version selection (pure)

**Files:**
- Create: `core/src/Updates/LatestVersion.php`
- Test: `tests/Unit/Updates/LatestVersionTest.php`

**Interfaces:**
- Produces: `LatestVersion::pick(string $current, array $candidates): ?string` — the newest candidate strictly greater than `$current` under Composer semver; `dev-*` candidates are ignored; pre-release candidates count only when `$current` is itself a pre-release; returns null when nothing is newer.

- [ ] **Step 1: Write the failing test**

```php
final class LatestVersionTest extends TestCase
{
    public function testPicksTheNewestStrictlyGreaterVersion(): void
    {
        self::assertSame('1.0.0-beta.22', LatestVersion::pick('1.0.0-beta.21', ['v1.0.0-beta.22', 'v1.0.0-beta.21', 'v1.0.0-beta.20']));
    }
    public function testAPreReleaseInstallMayMoveToStable(): void
    {
        self::assertSame('1.0.0', LatestVersion::pick('1.0.0-beta.21', ['v1.0.0', 'v1.0.0-beta.22']));
    }
    public function testAStableInstallIgnoresPreReleases(): void
    {
        self::assertNull(LatestVersion::pick('1.0.0', ['v1.1.0-beta.1', 'v1.0.0']));
        self::assertSame('1.0.1', LatestVersion::pick('1.0.0', ['v1.0.1', 'v1.1.0-beta.1']));
    }
    public function testDevBranchesAndOlderVersionsAreIgnored(): void
    {
        self::assertNull(LatestVersion::pick('1.0.0-beta.21', ['dev-main', 'v1.0.0-beta.20']));
    }
}
```

- [ ] **Step 2: Run it** — `vendor/bin/phpunit tests/Unit/Updates/LatestVersionTest.php` — FAIL: class not found.
- [ ] **Step 3: Implement** with `Composer\Semver\VersionParser` (`normalize`, `parseStability`) and `Composer\Semver\Comparator::greaterThan`; strip a leading `v` in the returned string.
- [ ] **Step 4: Run it** — PASS. `vendor/bin/phpcs -q core/src/Updates tests/Unit/Updates`.
- [ ] **Step 5: Commit** `feat(updates): LatestVersion picks the newest version an install may move to`.

### Task 2: The release feed and the installed version

**Files:**
- Create: `core/src/Updates/ReleaseFeed.php` (interface), `core/src/Updates/PackagistReleaseFeed.php`, `core/src/Updates/InstalledVersion.php`
- Create: `tests/Support/ScriptedReleaseFeed.php`
- Test: `tests/Unit/Updates/PackagistReleaseFeedTest.php`, `tests/Unit/Updates/InstalledVersionTest.php`

**Interfaces:**
- `ReleaseFeed::versions(string $package): list<string>` — every published version string of the package, newest first as Packagist lists them; throws on transport or shape failure.
- `PackagistReleaseFeed::__construct(?HttpClientInterface $http = null)`; reads `https://repo.packagist.org/p2/{package}.json`, which is "minified": every entry after the first inherits keys it omits, so the reader walks the list carrying the previous entry forward, collecting `version`.
- `InstalledVersion::of(string $package): array{version: ?string, development: bool}` — `Composer\InstalledVersions::getPrettyVersion()` with the leading `v` stripped; `development` is true when the package is not installed or its install path is a symlink (a Composer path repository, the dev monorepo).
- `ScriptedReleaseFeed` implements `ReleaseFeed`; constructed with a list or a `\Throwable` to throw; records calls.

- [ ] **Step 1: Failing tests.** Feed test uses `Symfony\Component\HttpClient\MockHttpClient` with a minified body (two entries, the second omitting `name`) and asserts `['v1.0.0-beta.22', 'v1.0.0-beta.21']`; a 500 response throws. Installed-version test: `of('glueful/thallo-core')` in this repo reports `development: true`; `of('nonexistent/package')` reports `version: null, development: true`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS; phpcs. **Step 5: Commit** `feat(updates): Packagist release feed and installed-version reader`.

### Task 3: UpdateChecker — check, store, status

**Files:**
- Create: `core/src/Updates/UpdateChecker.php`, `core/src/Updates/UpdateStatus.php` (readonly DTO with `toArray()`)
- Modify: `core/src/Settings/SystemKeys.php` (add `'update.'` to `PREFIXES`), `core/config/thallo.php` (`update_check` block), `.env.example` and `skeleton/.env.example` (`UPDATE_CHECK_ENABLED=true`)
- Modify: `core/src/Providers/CoreServiceProvider.php` (DI: `ReleaseFeed` → `PackagistReleaseFeed`, `UpdateChecker` shared autowire)
- Test: `tests/Integration/Updates/UpdateCheckerTest.php` (AppTestCase; constructs the checker with `ScriptedReleaseFeed` and an explicit current version)

**Interfaces:**
- `UpdateChecker::__construct(ApplicationContext $context, ReleaseFeed $feed, SystemChannel $flags, ?string $currentVersion = null, ?bool $development = null)` — the last two default to `InstalledVersion::of($package)`.
- `check(bool $force = false): UpdateStatus` — returns `status()` unchanged when disabled, when development, or when the last check is under 20 hours old and not forced; otherwise fetches, stores `update.latest` (or clears it), `update.checked_at` (ISO 8601); on any throwable stores `update.failed_at` and returns the previous status.
- `status(): UpdateStatus{current: ?string, latest: ?string, available: bool, development: bool, enabled: bool, checkedAt: ?string, notesUrl: string}`; `available` = `latest !== null && current !== null && Comparator::greaterThan(latest, current)`.
- Config: `thallo.update_check = ['enabled' => (bool) env('UPDATE_CHECK_ENABLED', true), 'package' => 'glueful/thallo-core', 'notes_url' => 'https://github.com/glueful/thallo/blob/main/CHANGELOG.md']`.

- [ ] **Step 1: Failing tests:** a check stores the newest movable version and reports `available`; a second check within 20 hours does not call the feed, `force` does; a feed failure keeps the stored latest and records `failed_at`; disabled (`bootAppWithConfigOverride('thallo', ['update_check' => ['enabled' => false]])`) never calls the feed and reports `enabled: false`; development never calls the feed; after the install moves to the latest, `status()` reports `available: false` without a new check.
- [ ] **Step 2–5:** RED, implement, GREEN, phpcs, commit `feat(updates): UpdateChecker stores the newest movable version and reports status`.

### Task 4: The scheduled job and the console command

**Files:**
- Create: `core/src/Updates/UpdateCheckJob.php` (extends `Glueful\Queue\Job`; `handle()` resolves `UpdateChecker` and calls `check()` inside try/catch), `core/src/Updates/Console/UpdateCheckCommand.php` (`#[AsCommand(name: 'thallo:update:check')]`, `--force`, prints the status as a two-column table)
- Modify: `config/schedule.php` and `skeleton/config/schedule.php` (job `update_check`, `'0 4 * * *'`, `enabled => env('UPDATE_CHECK_ENABLED', true)`, maintenance queue, timeout 60, retry 0), `core/src/Providers/CoreServiceProvider.php` (command DI + `commands([...])`)
- Test: `tests/Unit/Updates/UpdateCheckJobTest.php` (a failing checker never throws out of `handle()`), `tests/Integration/Console/UpdateCheckCommandTest.php` (CommandTester with a scripted feed via the container: output names current and latest; exit 0)

- [ ] Steps as above; commit `feat(updates): daily update check job and thallo:update:check`.

### Task 5: The admin endpoint

**Files:**
- Create: `core/src/Http/Controllers/UpdateStatusController.php` (`#[ApiOperation]`, `#[ApiResponse(200, schema: UpdateStatusData::class)]`), `core/src/Http/Responses/UpdateStatusData.php` (doc-only DTO, same folder convention as `HealthResultData`)
- Modify: `core/routes/admin.php` (`GET /update-status` in the same group as `/health`, `content_permission:system.access`, named `thallo.admin.update_status`), `core/src/Providers/CoreServiceProvider.php` (DI)
- Test: `tests/Integration/Http/UpdateStatusApiTest.php` — anonymous 401; route pinned by `findRoute()` with the permission middleware; an API-key actor with `system.access` gets `{current, latest, available, development, enabled, notesUrl}` matching `UpdateStatusData` (`assertDataMatchesDtoShape`)
- Then: `composer docs:openapi` (regenerates `docs/openapi.json` and `docs/index.html`), `tests/Integration/Commerce/AdminOpenApiGateTest.php` stays green.

- [ ] Steps as above; commit `feat(updates): GET /v1/admin/update-status for administrators`.

### Task 6: The admin UI

**Files:**
- Modify: `admin/src/api/core-schema.d.ts` via `pnpm gen:api`
- Create: `admin/src/queries/updates.ts` (`fetchUpdateStatus`, `useUpdateStatus()` keyed `['utilities', 'update-status']`, `staleTime` one hour), `admin/src/composables/useUpdateNotice.ts` (pure: `dismissedKey = 'thallo.update.dismissed'`; `isDismissed(latest)`, `dismiss(latest)`, `shouldShow(status, dismissed)` = available and not development and latest !== dismissed), `admin/src/components/UpdateAvailableCard.vue` (UCard: "Thallo {latest} is available", current version, link to notesUrl, the command `composer update && php glueful thallo:provision` in a code block, Dismiss button)
- Modify: `admin/src/pages/index.vue` (card after the welcome header, when `shouldShow`), `admin/src/layouts/default.vue` (badge `Update` on the `Health` utility item when `shouldShow`, next to the Submissions badge seam), `admin/src/pages/utilities/health/index.vue` (runtime list gains `Thallo` = current and `Latest` = latest when known)
- Test: `admin/src/__tests__/useUpdateNotice.spec.ts` (shouldShow matrix; dismissal is version-keyed: dismissing beta.22 does not hide beta.23), `admin/src/__tests__/updateCard.spec.ts` (renders versions, link and command; Dismiss stores the version)

- [ ] Steps: write specs (RED), implement, `pnpm test`, `pnpm lint`, `pnpm type-check`; commit `feat(admin): update notice — Home card, Health badge, version in Health`.

### Task 7: Docs and charter

**Files:**
- Modify: `docs/upgrading.md` (section "Knowing when to upgrade": what the notice is, the schedule, the opt-out, the command), `docs/internal/DISTRIBUTION.md` (decision 11 amended: authenticated `GET /v1/admin/update-status`, why), `CHANGELOG.md` `[Unreleased]` → Added, `docs/internal/plans/2026-09-12-composer-updatable-thallo.md` (status line: phase 4 implemented)
- [ ] Commit `docs(updates): the update notice, decision 11 amended`.

### Task 8: Gates

- [ ] `composer phpcs`, `composer boundaries`, full `composer test` (one gate at a time), `pnpm build` in `admin/`, the skeleton smoke (the new schedule entry parses; `thallo:update:check` runs in the template and reports `enabled: true`, `latest` from Packagist or null when offline).
- [ ] Then beta.22 is cut on top (bake, anchor commit, verify, split), pushed to the mirrors before the dev tag.

## Self-review

- Spec coverage: daily check with opt-out (T3, T4), stored in flags (T3), exposed to admins (T5, amended from `/admin/config`), badge and card with changelog link and command (T6), no updater (constraint), acceptance "shows a new version within a day and hides once current" (T3 status reads the installed version at request time; T6 dismissal is version-keyed).
- Placeholders: none. Types: `UpdateStatus` fields match `UpdateStatusData` and the admin `UpdateStatus` interface (`current`, `latest`, `available`, `development`, `enabled`, `checkedAt`, `notesUrl`).
