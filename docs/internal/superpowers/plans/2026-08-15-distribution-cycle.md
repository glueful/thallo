# Distribution Cycle Implementation Plan

> **For agentic workers:** Executed INLINE (session subagent budget exhausted; background agents ruled out). Charter: `docs/internal/DISTRIBUTION.md` (amended + corrected 2026-08-15) — its §2/§3 decisions bind; its §4 checklist is this plan's source. Steps use checkbox syntax; ledger at `.superpowers/sdd/2026-08-15-distribution-cycle/progress.md`.

**Goal:** Everything between the current tree and an immutable `v1.0.0-beta.1` a stranger can `composer create-project --prefer-dist --no-dev` into a working Thallo — ending at the human tag/publish sitting plus the artifact gate.

**Architecture of the posture split (investigated 2026-08-15):** `config/testing/extensions.php` currently DERIVES from base (`require ../extensions.php` minus the enforcement provider) — trimming base would silently trim the suite. Therefore: the testing overlay pins its own explicit everything-on list first; a new `config/development/extensions.php` overlay carries the dogfood dev posture (export-ignored); only then does base become the distribution default (tier 1 + the Subscriptions exception). The framework loads `config/{env}/{file}.php` overlays per environment; `enabled` stays a literal list everywhere (CLI-writer constraint).

## Global Constraints

- Charter decisions 1–9 bind verbatim (immutable tags; installed-but-disabled; 13 path-local modules; no wizard; release commit contains built `public/admin`; `/admin` + `docs/internal` stay export-ignored).
- Gates: full PHP suite 3319 tests/71 skipped (auto-backgrounds past the 600s tool ceiling — expected), SPA 138 files/2224, phpcs, boundaries; never concurrent across stacks.
- Nothing is pushed, tagged, or published by the assistant — the human owns the tag/Packagist/repo-public sitting.

---

### Task 1: Posture split (extensions config)

- [ ] Pin `config/testing/extensions.php` to an EXPLICIT everything-on list (current dogfood set minus enforcement; preserve the `THALLO_TENANCY_DEV_LINK` opt-in shape and the shield docblock).
- [ ] Create `config/development/extensions.php` — the dogfood posture for the dev server (everything-on incl. whatever enforcement state dev has), documented as repo-only.
- [ ] Trim base `config/extensions.php` to tier 1 + `SubscriptionsServiceProvider` (drop Commerce, Payvia, Meilisearch lines); keep the protected map + comments verbatim.
- [ ] `.gitattributes`: export-ignore `config/development/`; verify `tests/` posture; confirm `config/testing/` disposition (ships or not — decide: it ships, it's inert outside APP_ENV=testing, and create-project users may run the suite; record the decision).
- [ ] Full PHP suite green (proves the overlay pin held) + phpcs + boundaries.
- [ ] Commit `feat(distribution): tiered extensions posture with dev/test overlays`.

### Task 2: Distribution-defaults CI lane

- [ ] `composer test:distribution` script + `scripts/distribution-smoke` runner: set aside the testing overlay, run the smoke subset (InertnessTest, PackSkeletonTest, clean-install baseline/boot/route tests, a subscriptions-enabled assertion) against the TRIMMED base, restore the overlay (finally-safe).
- [ ] Wire a `distribution-defaults` lane into `.github/workflows/ci.yml` (shard rules satisfied).
- [ ] Run it locally green. Commit `feat(ci): distribution-defaults smoke lane`.

### Task 3: Admin SPA release bake (HARD GATE machinery)

- [ ] `scripts/release-bake` — `pnpm install/build` in admin/ → `git add -f public/admin` → refuses to run on a dirty tree, prints what it staged.
- [ ] `scripts/verify-dist-archive` — `git archive HEAD` piped to tar listing: asserts `public/admin/index.html` PRESENT; `admin/`, `docs/internal/`, `config/development/` ABSENT; exits nonzero otherwise.
- [ ] Rehearse: run bake on a throwaway commit, run verify against it, then `git reset` the throwaway — machinery proven without tagging.
- [ ] Commit the scripts + a `docs/internal/RELEASING.md` runbook (bake → release commit → tag → verify → push → Packagist; corrections = beta.N+1). Commit `feat(distribution): admin release bake and dist-archive verification`.

### Task 4: `.env.example` + composer self-containment

- [ ] `.env.example` fresh-install pass: APP_KEY placeholder + generation instructions, DB/mail sections, `EXTENSIONS_INSTALL_ENABLED` prod stance, LOUD `BASE_URL` block (required; payment-link HTTPS/no-port precision, both directions), no dogfood values.
- [ ] Audit `composer.json` `repositories`/scripts for anything escaping the repo (packages/* relative OK; sibling `../` must be gone) and fix.
- [ ] Suite spot-checks if config touched. Commit `chore(distribution): fresh-install env template and composer self-containment`.

### Task 5: Public docs set

- [ ] README rewrite: what Thallo is, exact requirements (PHP/PostgreSQL/extensions/web server/queue/cron/permissions), quickstart (`create-project --prefer-dist --no-dev` → `.env` → `migrate:run` → admin user → login), extension install guide pointer.
- [ ] `docs/production.md`: the operational-obligations MATRIX by capability/tier (charter item's full row list), required/recommended flags.
- [ ] `docs/limitations.md`: single platform merchant account; Paystack constraints; link analytics exclusion.
- [ ] `docs/upgrading.md`: composer update + compiled-container clearing + migration step + release-notes pointer; semver expectations.
- [ ] `SECURITY.md` + support channels stanza in README.
- [ ] Commit `docs(distribution): public README, production, limitations, upgrading, security`.

### Task 6: Changelog curation + docs/internal disposition + seed content

- [ ] CHANGELOG: condense pre-release chronology into an initial-release product entry; PRESERVE durable security/migration/upgrade content; no HISTORY.md (charter).
- [ ] `docs/internal/` per-file disposition list (stays public / graduates / relocates) written into the ledger for the human's sign-off — default keep-public, flag only genuine candidates.
- [ ] Seed content decision executed (default theme + starter blocks already ship; decide sample homepage: minimal "It works" page seeded by first-run docs, not a demo store).
- [ ] Commit `docs(distribution): curated changelog and launch content decisions`.

### Task 7: First-run rehearsal (pre-tag)

- [ ] On this machine: fresh scratch DB, simulate the trimmed fresh install (base config, no dev overlay) following ONLY the new README sequence; file and fix papercuts in-cycle.
- [ ] Record the transcript summary in the ledger. Commit fixes if any.

### Task 8: Release candidate + THE HUMAN SITTING + artifact gate

- [ ] Run release-bake → release commit (version identity per repo conventions) → verify-dist-archive green.
- [ ] STOP — hand to the human: tag `v1.0.0-beta.1` on the release commit, push, repo-public decision, Packagist submit.
- [ ] After publication: artifact clean-machine gate — `composer create-project --prefer-dist --no-dev glueful/thallo <scratch> v1.0.0-beta.1` in an empty dir, no sibling repos; verify no escaping symlinks, admin loads, first-run sequence completes on public docs only.
- [ ] Website-from-tag gate + announcement: the human's follow-on project; corrections become `beta.2`.
