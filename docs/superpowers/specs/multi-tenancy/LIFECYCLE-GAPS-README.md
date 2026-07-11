# Multi-Tenancy Lifecycle Gaps — Tracking Index

Post-SP3 completeness work for Thallo multi-tenancy. SP1 (foundation/enablement), SP2 (resolution,
tenant management, disable/diagnostics), and SP3 (membership×RBAC authorization + admin UI) shipped
the core arc. What remains is decomposed into two buckets.

**Legend:** ✅ shipped · 🔵 in progress · ⏳ next · ☐ planned (not started)

---

## Bucket 1 — Lifecycle gaps (closing the loop)

In-domain, bounded, mostly Thallo-only. These are the genuine multi-tenancy completeness items;
doing them truly closes the lifecycle loop. One slice at a time.

| # | Slice | Status | Spec | Plan |
|---|-------|--------|------|------|
| 1 | **Workspace-Manager role** — dedicated cross-workspace role split out of `administrator`; superuser lifecycle; assignment-policy hardening; authority-continuity protection; break-glass grant/transfer CLIs; server-derived role picker. | ✅ shipped (2026-07-11, commits `57fd32d`/`30c0b50`/`0359a9f` on `dev`, unpushed) | [spec](2026-07-11-operator-role-design.md) | [plan](../plans/multi-tenancy/2026-07-11-operator-role.md) |
| 2 | **Tenant deletion & host-retention** — two-phase trash→purge workspace deletion; host-cooldown ledger so a freed domain can't be squatted/reassigned. Release chain: contracts → tenancy engine → Thallo. | ✅ implemented (held, uncommitted) | [spec](2026-07-11-tenant-deletion-host-retention-design.md) | [plan](../../plans/multi-tenancy/2026-07-11-tenant-deletion-host-retention.md) |
| 3 | **Background domain re-verification** — periodic re-check of verified custom domains (DNS TXT drift / takeover detection); re-verification lifecycle + status surfacing. | ✅ implemented (held, uncommitted) | [spec](2026-07-11-domain-reverification-design.md) | [plan](../../plans/multi-tenancy/2026-07-11-domain-reverification.md) |

---

## Bucket 2 — In-domain extensions (opt-in follow-ups)

Real features, each sizeable and previously deferred by choice. Worth doing, but **not** part of
"closing the loop" — schedule independently.

| # | Feature | Status | Notes |
|---|---------|--------|-------|
| A | **Per-tenant custom roles / matrix overrides** | ☐ deferred | Per-tenant deviation from the global `role_matrix`; larger change to `RoleMatrix`. |
| B | **Public self-serve tenant signup** | ☐ deferred | Unauthenticated workspace creation flow (vs. today's operator-provisioned). |
| C | **Collections tenancy** | ☐ deferred | Unfence the deliberate `collections.*` fence so collections become tenant-scoped. |

---

## Working agreement

Each slice runs the full brainstorm → spec → plan → implement cycle with its own spec + plan under
`docs/superpowers/specs/multi-tenancy/` and `docs/superpowers/plans/multi-tenancy/`. Specs/plans are
user-reviewed before implementation. Thallo-only unless a slice needs a framework/extension seam (in
which case it follows the release chain: framework → contracts → extension → app). Commits held until
explicit go-ahead. Update this index's Status column as each slice moves.
