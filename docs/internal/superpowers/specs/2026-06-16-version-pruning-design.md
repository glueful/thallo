# Version Retention / Pruning — Design

**Goal:** Add opt-in, configurable pruning of `entry_versions` history — keep-N and/or
age-based — that prunes old published-version snapshots **without ever touching the
currently-pinned version**, with the `glueful/import-export` bundle as the archival
safety net.

**Status:** ✅ Shipped (2026-06-17) — implemented and reviewed.

**Backlog item:** [POST_V1.md](../../POST_V1.md) §3. Resolves the deferral documented in
[V1_DESIGN.md](../../V1_DESIGN.md) ("Version retention").

> V1_DESIGN: "V1 keeps unlimited published version history by default. Configurable
> pruning is deferred until after export/import is established as the safety net; pruning
> must never run before a portable content bundle can preserve history." Export/import is
> built (`LemmaContentExporter` exports `entry_versions` as-is), so the gate is satisfied.

---

## Definition of behavior (the contract)

> Pruning is **off by default** (current behavior: unlimited history). When an operator
> configures a retention policy, a prune pass deletes `entry_versions` rows that fall
> outside the policy for each `(entry, locale)` lineage — **except** the version currently
> pinned by `entry_publications`, which always survives regardless of policy. Surviving
> rows are never rewritten; history stays as written. Deletion is permanent (recover from
> an export bundle, not from the database).

## Scope decisions (settled from V1_DESIGN + POST_V1)

1. **Opt-in, never on by default.** With no policy configured, pruning is a no-op and
   history stays unlimited — the V1 contract is preserved for anyone who doesn't opt in.
2. **Two policy dimensions, combinable:** `keep` (retain the N most recent versions per
   `(entry, locale)`) and `max_age_days` (delete versions whose `created_at` is older than
   the cutoff). Either, both, or neither. When both are set, a version survives if it
   satisfies **either** rule (keep-N is a floor: the N newest always survive even if old).
3. **The pinned version is sacrosanct — re-checked at delete time, not just selection
   time.** `entry_publications.version_uuid` is excluded from deletion in every case — even
   if it is the oldest row and beyond both `keep` and `max_age_days`. This is the one hard
   invariant; it guarantees the delivery read path and the reference projection always have
   their version row. **Because `rollback()` can re-pin any historical version concurrently,
   the exclusion must hold at the moment of the DELETE, not only when candidates are
   selected** (a version selected as deletable could be pinned by a concurrent rollback
   before the DELETE runs). The actual DELETE therefore carries an in-statement
   `NOT EXISTS (SELECT 1 FROM entry_publications WHERE version_uuid = entry_versions.uuid)`
   guard, so a row that became pinned between selection and delete is skipped atomically.
4. **Per-`(entry, locale)` lineage.** Version numbers are monotonic per `(entry, locale)`
   (`uniq_version_entry_locale_version`); pruning ranks and trims each lineage independently.
5. **Accepted tradeoff — pruning shrinks the rollback window.** `rollback()` can re-pin
   *any* historical version by UUID (`PublishService::rollback`). Pruning deletes
   non-pinned history, so a pruned version can no longer be a rollback target. This is the
   intended cost of retention; documented, not worked around. The export bundle is the
   recovery path for a version pruned past the point an operator later wants back.
6. **No new content event.** Pruning is not a content state change; the §5 event taxonomy
   is unchanged (consistent with the scheduled-publish decision). Pruning emits structured
   log output and returns a report; it does not emit `entry.published`/`entry.unpublished`.
7. **Accepted tradeoff — pruning can invalidate a historical preview token.** A preview
   token minted against a specific historical version (`PreviewController::mint` accepts an
   optional `version_uuid`; `PreviewReader` resolves it via
   `VersionRepository::findVersionByUuid`) will start returning **404** if that version is
   pruned while the token is still unexpired. This is graceful — the reader already fails
   closed to 404 on a missing version — and bounded: **draft** preview tokens (no
   `version_uuid`) are unaffected, only pinned-to-a-historical-version tokens are. Accepted
   as an inherent consequence of deleting history; documented because preview is a
   user-facing workflow. (Preview TTLs are short, so the exposure window is small.)

## Architecture

Three units. **This iteration ships the operator CLI only — there is no scheduled cron
job.** Scheduled pruning is deferred until an export-before-prune interlock exists, so the
only path that deletes is a deliberate, supervised operator command (see Out of scope).

- **`RetentionPolicy` (value object — also the validation barrier)** — `keep: ?int`,
  `maxAgeDays: ?int`, plus `isEnabled(): bool` (true when at least one dimension is set).
  Built via `RetentionPolicy::fromValues($keep, $maxAgeDays)` from config or CLI overrides.
  **Construction validates and fails loud** so a destructive command never runs on a
  nonsensical policy: each dimension must be **either absent (null/empty ⇒ that dimension
  off) or a positive integer (≥ 1)**. Anything present-but-invalid — `0`, a negative number,
  or a non-numeric string — throws `InvalidRetentionPolicyException` *before* any deletion.
  `keep=0` is explicitly rejected (it would mean "keep zero versions" → delete all
  non-pinned history), not silently treated as disabled. One home for "what counts as
  prunable" *and* "what counts as a valid policy," so the command can't bypass the check
  (and a future cron job would inherit the same barrier).
- **`VersionPruner` (service, the unit of logic and the direct test target)** —
  `prune(RetentionPolicy $policy, bool $dryRun = false): PruneReport`. Iterates each
  `(entry, locale)` lineage, computes the deletable set (see mechanism), and deletes in
  bounded batches. The deletion query is owned here.
- **One thin wrapper over the pruner:**
  - **`lemma:versions:prune` console command** — the operator/manual entry point (the only
    deletion path this iteration). Supports `--dry-run` (report only, delete nothing),
    `--keep=`, `--max-age-days=` (override config for an ad-hoc pass).

`PruneReport` is a small DTO: `lineagesScanned`, `versionsDeleted`, `versionsRetained`,
and `pinnedSkipped` counts (for the command output and the structured log line).

## Data model

**No new tables and no schema change.** Pruning is `DELETE FROM entry_versions WHERE …`.

- The only row that references `entry_versions.uuid` is `entry_publications.version_uuid`
  (the pin), and that row is always excluded **at delete time** (see mechanism step 3) — so
  pruning can never orphan a publication, even under a concurrent `rollback()`. The plan
  must assert (a test) that no other table FK-references `entry_versions.uuid` before relying
  on this; `entry_references` is rebuilt from the *pinned* version's fields and does not hold
  a per-historical-version FK.
- `entry_drafts` are the mutable working copy, not versions, and are never touched.

## Pruning mechanism — `VersionPruner::prune()`

Per pass:

1. **Enumerate lineages** — `SELECT DISTINCT entry_uuid, locale FROM entry_versions`.
   (Bounded sweep; for very large catalogs the plan may page this, but correctness doesn't
   depend on it.)
2. **For each lineage**, in one query, select the deletable version UUIDs:
   - rank rows `version DESC`; a row is a **keep-N survivor** if its rank ≤ `keep`
     (when `keep` is set);
   - a row is an **age survivor** if `created_at >= now() - maxAgeDays` (when set);
   - the **pinned** `version_uuid` for that `(entry, locale)` is always a survivor;
   - **delete** every row that is none of the above.
   Expressed as a single parameterized statement per lineage (or a set-based statement
   across lineages using a window function) — the exact SQL is the plan's call, but it is
   **Postgres**, consistent with the V1 requirement, and uses bound parameters.
3. **The DELETE re-checks the pin atomically.** The DELETE statement (whether it deletes a
   selected UUID set or is itself the set-based statement) **must** include
   `AND NOT EXISTS (SELECT 1 FROM entry_publications p WHERE p.version_uuid =
   entry_versions.uuid)` in its own WHERE clause. This closes the race where a row selected
   as deletable is pinned by a concurrent `rollback()` before the DELETE runs: the guard
   evaluates against the publications table *at delete time*, so a newly-pinned row is left
   untouched and `entry_publications.version_uuid` can never be orphaned. (The selection in
   step 2 is an optimization/report input; the delete-time guard is the correctness barrier.)
4. **Dry-run** computes the same sets and returns the report **without** issuing deletes.
5. **Accumulate** counts into the `PruneReport`.

**Behaviors:**
- **Idempotent** — running twice with the same policy deletes nothing the second time.
- **Disabled policy** (`isEnabled() === false`) → immediate no-op returning a zero report;
  the command short-circuits so an unconfigured policy never deletes.
- **Pinned-only lineage** (one version, and it's pinned) → nothing deleted.
- **No publication** (entry never published, or unpublished) → the lineage has no pin;
  keep-N/age rules apply normally to all its versions (there is no protected row). An
  unpublished entry's history is prunable down to the policy like any other.

## Configuration

`config/lemma.php` gains a `versions` block. **Config passes the raw env values through
unchanged** (empty string ⇒ "unset"); it does **not** cast — parsing + validation is
`RetentionPolicy`'s job, so an invalid value fails loud at policy construction rather than
silently casting (`(int) "" === 0`, `(int) "-1" === -1`) into a destructive policy:
```php
'versions' => [
    'retention' => [
        // Raw values; RetentionPolicy::fromValues() validates (null/'' ⇒ off; else int ≥ 1).
        'keep'         => env('LEMMA_VERSION_KEEP'),         // null, '', or a numeric string
        'max_age_days' => env('LEMMA_VERSION_MAX_AGE_DAYS'),
    ],
],
```
(No `pruning_enabled` flag — there is no scheduled job this iteration; the CLI command is the
only deletion path. A future cron job would add its own enable flag + an archive interlock.)
Both retention values absent (null/empty) ⇒ policy disabled ⇒ pruning is a no-op everywhere.
A present-but-invalid value (`0`, negative, non-numeric) ⇒ `InvalidRetentionPolicyException`
at construction ⇒ the command aborts with a clear error **before** any DELETE. The same
validation covers the `--keep=` / `--max-age-days=` CLI overrides.

## CLI / operator surface

- **`php glueful lemma:versions:prune`** — apply the configured policy.
  - `--dry-run` — report counts, delete nothing (the safe first step before enabling).
  - `--keep=N` / `--max-age-days=D` — override config for this run.
  - Prints the `PruneReport` (lineages scanned, deleted, retained, pinned-skipped).
- Operators are directed (in the command help + docs) to **export first**
  (`glueful/import-export`) if they want a recoverable archive, since deletion is
  permanent. This is documented, not enforced — the gate POST_V1 names is "an operator
  *can* archive before pruning," which export/import already satisfies.

There is **no admin HTTP API** for pruning in this feature — it is an operator/ops
concern, not an editor action. (A future admin "retention settings" UI can call the same
`VersionPruner`; out of scope here.)

## Events

No change to the §5 frozen taxonomy. Pruning emits no content event. It writes one
structured log line per **enabled** prune pass (counts + policy) for observability; a
disabled policy is a silent no-op (the command separately reports "pruning disabled" to
the operator).

## Testing (Postgres, `LemmaTestCase`)

- **keep-N:** lineage of 5 versions, `keep=2`, pinned = newest → 2 newest survive, 3
  oldest deleted; report counts match.
- **keep-N protects the pin:** `keep=2` but the **pinned** version is `v3` of 5 → survivors
  are `{v5, v4}` (keep-N) ∪ `{v3}` (pin) = 3 rows; `v1, v2` deleted.
- **age-based:** versions older than `max_age_days` deleted, newer retained, pinned always
  retained even if older than the cutoff.
- **combined:** `keep=2` + `max_age_days=30` → a 90-day-old version still survives if it's
  in the 2 newest (keep-N is a floor).
- **disabled:** both null → `prune()` deletes nothing and returns a zero report.
- **dry-run:** computes the report but `entry_versions` row count is unchanged afterward.
- **per-lineage isolation:** two entries / two locales pruned independently; one lineage's
  policy outcome doesn't bleed into another.
- **no-publication lineage:** an unpublished entry's versions prune down to policy (no
  protected pin).
- **FK-safety:** after a prune that deletes non-pinned versions, every `entry_publications`
  row still resolves to an existing `entry_versions` row (the pin invariant holds), and the
  delivery read path for a published entry is unaffected.
- **delete-time pin race (P1):** simulate a concurrent rollback — between candidate
  selection and the DELETE, pin a version that was selected as deletable (e.g. open a second
  connection, `pin()` `v3`, then run the pruner's DELETE). Assert the now-pinned `v3` is
  **not** deleted (the in-statement `NOT EXISTS` guard skips it) and `entry_publications`
  remains intact. The deterministic form: build the deletable set, pin one of its members,
  then issue the guarded DELETE and assert that row survives.
- **invalid policy fails loud (P2):** `keep=0`, `keep=-1`, `max_age_days=0`, a non-numeric
  string, and an empty-string override each → `InvalidRetentionPolicyException` at
  `RetentionPolicy` construction with **zero** rows deleted; an unset/empty env (both
  dimensions absent) → disabled no-op (not an error).
- **historical preview token (P2):** mint a preview token against a historical version, prune
  that version, then read the token → 404 (graceful fail-closed); a draft preview token is
  unaffected by the same prune.
- **idempotency:** a second identical pass deletes 0.

## Out of scope / follow-ups

- **Scheduled (cron) pruning + export-before-prune interlock** — deferred. Unsupervised
  scheduled deletion is too easy to misconfigure without an archive check, so this iteration
  ships the CLI command only. The follow-up adds a `PruneVersionsJob` (with its own enable
  flag) **gated on an export/archive precondition**, so scheduled deletion can never run
  without a recent recoverable bundle. (A report-only scheduled dry-run is a possible interim
  step.)
- **Admin retention-settings UI** — a frontend concern; this feature ships config + CLI only.
- **Pruning drafts or schedules** — only `entry_versions` history is in scope.
- **Per-content-type retention policies** — V1.x policy is global; a per-type override can
  layer on the same `RetentionPolicy` later.

## Success criteria

- With no policy configured, behavior is identical to today (unlimited history).
- With a policy, `lemma:versions:prune` deletes only out-of-policy, **non-pinned** versions;
  the pinned version and the delivery read path are never affected, even under a concurrent
  rollback (delete-time pin guard).
- An invalid policy value aborts the command before any deletion.
- `--dry-run` reports exactly what a real pass would delete, deleting nothing.
- Full suite green on Postgres CI; the keep-N, age, combined, pin-protection, delete-time
  race, invalid-policy, preview-token, dry-run, and FK-safety tests pass.
