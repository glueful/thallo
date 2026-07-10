# Thallo Multi-Tenancy SP1 — Implementation Plan Set

**Spec:** [../../specs/multi-tenancy/2026-07-09-sp1-foundation-enablement-design.md](../../specs/multi-tenancy/2026-07-09-sp1-foundation-enablement-design.md)

SP1 spans four repos and eight subsystems, so it is delivered as a **sequenced set of phase plans**. Each phase produces working, independently-testable software and is gated for review before the next begins. Execute them in order — later phases consume interfaces produced by earlier ones.

| Phase | Plan file | Deliverable | Repos touched |
|---|---|---|---|
| **A** | `2026-07-09-sp1-phase-a-foundations.md` | `TenantContextRunner` contract, framework insert-hook primitive, tenancy binding + insert-stamper | contracts, framework, tenancy |
| **B1** | `2026-07-09-sp1-phase-b1-pack-foundation.md` | `thallo-tenancy` pack scaffold + capability, system-global channel, `ThalloTenantTables` registry, table registration + compound boot gate | Thallo |
| **B2a** | `2026-07-09-sp1-phase-b2a-scoping-fixes.md` | widened-unique metadata fix, `TenantScope` seam (in `extension-contracts`), early two-tenant oracle harness, then per-repo raw-PDO `tenant_uuid` fixes (seo/navigation/analytics/workflow/app-content) — each shipping its own isolation test | Thallo, contracts |
| **B2b** | `2026-07-09-sp1-phase-b2b-system-workers-lint.md` | system-path workers (schedule runner carry-tenant + fail-closed; retention pruner), raw-PDO regression lint (smoke + targeted), capstone cross-repo cross-tenant sweep | Thallo |
| **C** | `2026-07-09-sp1-phase-c-retrofit-settings.md` | Retrofit engine (column add + backfill + NOT NULL + widened uniques), rebuild path (`regions`/`settings`/`entry_redirects`), uniqueness preflight, default-tenant creation, settings system/site split | Thallo |
| **D** | `2026-07-09-sp1-phase-d-seed-sync.md` | `SetupService` core refactor, `starter_provenance`, `TenantSeeder`, sync engine (add/update/skip/orphan/rename), `thallo:tenant:*` CLI | Thallo |
| **E** | `2026-07-09-sp1-phase-e-enable-machine.md` | `TenancyEnablement` service (state pair, CAS lock, install→enable→migrate→confirm→retrofit→on), collections-block preflight, disable + divergence check, `thallo:tenancy:enable` CLI | Thallo |
| **F** | `2026-07-09-sp1-phase-f-cache-diagnostics.md` | `tenantCacheSegment()` helper (fail-closed) threaded through all cache surfaces + tags + purge patterns, transition purge, `thallo:tenancy:diagnose` | Thallo |

**Global posture (every phase):** work on `dev` directly; no AI/Anthropic attribution anywhere; hold all commits until explicit go-ahead; `declare(strict_types=1)` + `final class` + constructor DI + `use`-imports (no inline FQCNs); `composer phpcs` clean (warnings are failures) before a backend task is done; dev-DB/test-DB migration + seed are local-only (never committed).

**Release pinning:** the framework insert-hook (Phase A, task A2) lands in a new `glueful/framework` release; the contracts (`TenantContextRunner`) and tenancy (binding + stamper) versions carrying Phase A pin that framework release. Thallo pins all three at SP1 release. Do not bump dependents' pins to unreleased versions before the framework/extension releases cut.
