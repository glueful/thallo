# Thallo Post‑V1 Backend Backlog

The V1 headless backend is complete (see [V1_DESIGN.md](V1_DESIGN.md), §§1–10).
This document consolidates the backend features V1 **deliberately deferred** — each
is already captured as an explicit deferral in V1_DESIGN; this is the single tracked
list so they aren't scattered. It is **not** an umbrella design doc: each item gets
its own focused spec (brainstorm → spec → plan) when it is actually scheduled.

Ordering here is rough priority, not a committed sequence. The Admin SPA
(V1_DESIGN §11 step 7) is a separate frontend deliverable and is tracked elsewhere.

**Status (2026-06-17): ✅ COMPLETE — all six backlog items have shipped.** Each went the
full distance: brainstorm → spec → spec review → plan → plan review → implementation →
review, grounded against the real framework/Thallo/Aegis code. The per-item entries below are
retained for history (with their settled specs + implementation plans); this backlog is
closed. New post-V1 work should start a fresh document.

---

## 1. Scheduled publish / unpublish

- **Status:** shipped.
- **Reference:** V1_DESIGN §2 ("Scheduled publish/unpublish is implemented").
- **Spec:** [`superpowers/specs/2026-06-16-scheduled-publish-design.md`](superpowers/specs/2026-06-16-scheduled-publish-design.md)
  + [implementation plan](superpowers/plans/2026-06-16-scheduled-publish.md).
- **Shipped shape:** `entry_schedules` records pending/history rows; the admin API
  creates/lists/cancels schedules; `RunDueSchedulesJob` fires due rows through the
  normal `PublishService::publish()` / `unpublish()` path with no new publication
  states and no delivery read-path changes.

## 2. Destructive schema‑change backfill

- **Status:** ✅ shipped (2026-06-17).
- **V1 behavior:** destructive field changes (delete / retype; rename surfaces as
  delete+add) are rejected with a `422`. Models are field‑append‑only.
- **Reference:** V1_DESIGN §1 ("Backfill is a V1.x/V2 feature, not V1", line ~130).
- **Spec:** [`superpowers/specs/2026-06-16-destructive-schema-backfill-design.md`](superpowers/specs/2026-06-16-destructive-schema-backfill-design.md) — shipped (delete + rename; retype deferred).
- **Scope sketch:** an explicit model‑migration step in the admin that captures
  rename/retype/delete intent and enqueues a backfill job over **current published
  versions only**, with a draft/version‑history policy, reference/index rebuild, and
  failure reporting. History stays as written.
- **Hard parts:** every sub‑part touches immutable published content — this is why it
  must not ship half‑built. Failure reporting and partial‑backfill recovery are the
  core design risk.
- **Depends on:** the queue (present); the filterable‑index reconciliation job
  (present) for index rebuild.

## 3. Version retention / pruning

- **Status:** ✅ shipped (2026-06-17).
- **V1 behavior:** unlimited published version history.
- **Reference:** V1_DESIGN "Resolved V1 decisions → Version retention" (line ~528).
- **Spec:** [`superpowers/specs/2026-06-16-version-pruning-design.md`](superpowers/specs/2026-06-16-version-pruning-design.md) — shipped (CLI-only this iteration; scheduled pruning deferred).
- **Scope sketch:** configurable retention (keep‑N and/or age‑based) that prunes
  `entry_versions` rows below the policy, never touching the currently‑pinned version.
- **Hard parts / gate:** pruning **must not** run until the export/import bundle can
  preserve full history as the safety net — i.e. an operator must be able to archive
  before pruning. (Export/import is already built, so this gate is satisfiable.)
- **Depends on:** the `glueful/import-export` adapters (present) as the backup path.

## 4. SEO / redirects

- **Status:** ✅ shipped (2026-06-17).
- **V1 behavior:** `entry_routes` carries current route rows only.
- **Reference:** V1_DESIGN "Resolved V1 decisions → Redirects" (line ~532).
- **Spec:** [`superpowers/specs/2026-06-16-seo-routing-module-design.md`](superpowers/specs/2026-06-16-seo-routing-module-design.md) — shipped (full SEO/routing module: redirects + 301/302/308 + canonical/hreflang).
- **Scope sketch:** redirect rows (in `entry_routes` or a dedicated `entry_redirects`
  table) with status codes (301/302/308), redirect chains, and canonical‑URL handling.
- **Hard parts:** deliberately **bundled with the SEO/routing module** so status
  codes, chains, canonical URLs, and admin UX land together — not as a half‑feature in
  core content routing. This is a module, not a column add.
- **Depends on:** the (future) SEO/routing module.

## 5. Field‑level localization automation

- **Status:** ✅ shipped (2026-06-17).
- **V1 behavior:** the `localized: true` field‑schema flag is representable but inert;
  the persisted unit is the whole‑entry locale variant.
- **Reference:** V1_DESIGN §3 ("Field‑level localization … already representable", line ~229).
- **Spec:** [`superpowers/specs/2026-06-16-field-localization-design.md`](superpowers/specs/2026-06-16-field-localization-design.md) — shipped (flag-aware copy-on-create; copy-on-change deferred).
- **Scope sketch:** use the existing `localized` flag to automate copy behavior — when
  a locale variant is created or saved, copy **non‑localized** field values from the
  source/default locale so editors only translate what's marked localized.
- **Hard parts:** copy‑on‑create vs copy‑on‑change semantics, and not clobbering an
  intentional per‑locale override of a non‑localized field.
- **Depends on:** `glueful/i18n` (now Thallo core) and the locale‑variant endpoints
  (present).

## 6. Per‑locale RBAC

- **Status:** ✅ shipped (2026-06-17).
- **V1 behavior:** coarse, namespaced permissions checked with **no resource argument**
  (`thallo.entries.publish`, etc.); no per‑locale or per‑content‑type rules.
- **Reference:** V1_DESIGN §3 ("does not add per‑locale RBAC", line ~225) + §7.
- **Spec:** [`superpowers/specs/2026-06-16-per-locale-rbac-design.md`](superpowers/specs/2026-06-16-per-locale-rbac-design.md) — shipped (per-locale via Aegis resource filters; per-content-type deferred).
- **Scope sketch:** per‑locale (and, on the same mechanism, per‑content‑type)
  permission checks — e.g. an editor may publish `fr` but not `de` — via Aegis's
  native resource‑level filters (`can($user, 'thallo.entries.publish', 'locale:fr')`),
  so the V1 permission names never have to be renamed.
- **Hard parts:** the resource‑argument convention and the admin UX for assigning
  per‑locale grants; deciding the resource taxonomy (locale, content‑type, both).
- **Depends on:** `glueful/aegis` resource‑level filters (the V1 permission model was
  chosen specifically to make this additive).

---

## How to pick one up

1. Confirm the **trigger/gate** above is met (e.g. for pruning, export/import exists).
2. Brainstorm → write a focused spec under `docs/superpowers/specs/` → plan → implement.
3. Update the matching V1_DESIGN deferral note to point at the spec (or mark it shipped),
   and move the item out of this backlog.
