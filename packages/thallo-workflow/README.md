# glueful/lemma-workflow

A single-stage **editorial approval workflow** for [Lemma](https://getlemma.dev) — submit →
review → approve/request-changes over the draft/publish lifecycle — packaged as a
**removable capability pack**. Core stays workflow-agnostic behind one tiny seam: the
`PublishGate` contract. With the pack absent or disabled, publishing behaves exactly as
stock Lemma.

## The state machine

Review state is per **(entry, locale)** — the same granularity as drafts. An absent row
means `draft`.

| Transition | From → To | Who |
|---|---|---|
| submit | draft, changes_requested → in_review | `content.edit` |
| approve | in_review → approved | `workflow.review`, reviewer ≠ submitter¹ |
| request changes | in_review → changes_requested (**note required**) | `workflow.review` |
| withdraw | in_review → draft | the submitter, or `workflow.review` |
| *(edit)* | in_review, approved → draft | automatic (`entry.updated`) |
| *(publish)* | any → draft (approval consumed) | automatic (`entry.published`) |

¹ Self-review is blocked by default; `lemma_workflow.allow_self_review`
(`WORKFLOW_ALLOW_SELF_REVIEW`, default `false`) is the tiny-team escape hatch.

**Edits invalidate active review or approval** — editing an `in_review` or `approved`
draft resets it to `draft` (what was approved must be what publishes). `changes_requested`
deliberately survives edits: it *means* "reviewer asked for revisions; author is revising".
Submit is the only transition that clears it.

## The publish gate

`PublishService` asks every container-tagged `lemma.publish_gate` service before any
write. This pack's gate allows a publish when the state is **`approved`**, or when the
actor holds **`workflow.bypass`** — otherwise it throws `PublishBlocked`, which the
publish endpoint maps to **409** with `details.workflow_state`. One rule, every path:
admin UI, API, **and scheduled publishes** (evaluated at run time with the schedule's
stored `created_by`; revoking a bypass before firing fails the schedule).

Every successful publish is recorded in the history: `published` when it consumed an
approval, **`published_with_bypass`** when the gate let it through on the permission —
emergency publishes never vanish from governance history.

## Storage, permissions, API

- **`workflow_review_states`** (state row per entry+locale) and **`workflow_transitions`**
  (append-only history with a `metadata` json forward seam). No cross-package FKs.
- The pack seeds `workflow.review` and `workflow.bypass` permission rows; the host app
  grants both to `administrator` in its own dependent migration. Submitting reuses
  `content.edit`.
- Routes (capability → `auth` → `lemma_permission`), under `/v1/admin/workflow`:
  `POST /entries/{uuid}/{locale}/submit|approve|request-changes|withdraw`,
  `GET /entries/{uuid}/{locale}` (state + history), `GET /queue` (paginated in-review
  list, enriched with draft title/type via the `DraftSummaryReader` contract).
- Events `ReviewSubmitted` / `ReviewApproved` / `ChangesRequested` are dispatched for
  future notification wiring; nothing consumes them in v1.

## The capability

`lemma.workflow`, **enabled by default** when installed. Disable via the app's
`config/lemma.php` switchboard (`'capabilities' => ['lemma.workflow' => false]`): routes
404, the lifecycle listener is not wired, and the gate short-circuits — publish behaves
exactly as current core. There is deliberately no `enabled` config key in the pack.

## Boundary

Depends on `glueful/lemma-contracts` + `glueful/framework` only. Permission checks use the
framework's `PermissionManager` (Aegis is the expected provider in Lemma but is never
imported). Lifecycle reactions subscribe to the `ContentLifecycleEvent` contract
(`name()`/`payload()`), never the engine's concrete event classes. The repo's
`composer boundaries` check enforces this.

## Install / remove

Bundled by default in the Lemma create-project template. To add to an existing app:
`composer require glueful/lemma-workflow`, `./lemma extensions:enable lemma-workflow`,
`./lemma migrate:run`. To remove: disable + `composer remove` — core boots and publishes
unchanged; the workflow tables remain on disk (drop manually if you want the data gone).

## Out of scope (v1)

Multi-stage/configurable pipelines, per-content-type reviewer policy, assignment,
notification delivery, and a workboard UI.
