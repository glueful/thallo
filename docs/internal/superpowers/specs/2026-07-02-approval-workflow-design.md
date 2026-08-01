# Approval / Review Workflow — Design

**Date:** 2026-07-02
**Status:** Approved design, pre-implementation
**Deliverable:** `glueful/lemma-workflow` capability pack + one core seam (`PublishGate`)

A single-stage editorial review workflow layered on Lemma's draft/publish lifecycle
(the `docs/NEXT.md` "Approval / review workflow — a state machine layered on draft/publish"
item). Editors submit a draft for review; reviewers approve or request changes; publishing
requires an approval unless the publisher holds a bypass permission.

## 1. Placement and boundary

Workflow is important but not irreducible CMS core the way schemas, drafts, versions,
publish, and delivery are — some teams want direct publishing, others want editorial
approval. It ships as a **removable capability pack** with a deliberately tiny core seam:

- **Core** owns drafts, versions, publish, delivery — and asks one question:
  *"may I publish this draft?"*
- **`glueful/lemma-workflow`** owns review state, review actions, permissions, history,
  and admin-UI integration — and answers that question.

With the pack absent or the capability disabled, no gate is registered and publishing
behaves exactly as today.

**Pack dependencies:** `glueful/lemma-contracts` + `glueful/framework` only. Permission
checks use the framework's `Glueful\Permissions\PermissionManager` (the same class
`RequireLemmaPermission` uses); Aegis is the expected *provider* behind that contract in
Lemma but is never imported by the pack. No `App\` references (enforced by
`composer boundaries`).

## 2. The core seam: `PublishGate`

Two additions to `lemma-contracts` under `Authoring/`:

```php
interface PublishGate
{
    /** @throws PublishBlocked when the draft may not be published. */
    public function assertCanPublish(string $entryUuid, string $locale, ?string $actorUuid): void;
}

final class PublishBlocked extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,       // human-readable
        public readonly ?string $state = null, // workflow state, when known
    ) { ... }
}
```

**Discovery and semantics (pinned):**

- Gates register under the container tag **`lemma.publish_gate`** (the framework's
  `TagCollector` supports per-tag priority; consumption is
  `$c->has('lemma.publish_gate') ? $c->get(...) : []`, the `import_export.importer` pattern).
- `PublishService::publish()` calls **all** tagged gates **before** any write, in
  deterministic tag-priority order.
- The **first thrown `PublishBlocked` stops the publish**; unexpected exceptions bubble
  (500) — a broken gate must not silently allow publishes.
- **No gates registered → publish behaves exactly as today**, byte for byte.

`PublicationController` maps `PublishBlocked` → **409 Conflict** (the request is valid;
the draft is in the wrong workflow state). **Response shape (pinned):**
`Response::error($e->reason, 409, ['workflow_state' => $e->state])` — the message is the
human-readable sentence the SPA shows (e.g. *"Publishing requires an approved review
(current state: in_review)."*), and `details.workflow_state` lets the editor render the
right badge/action without parsing the message. `ScheduleRunner` needs no change: its
existing try/catch marks a blocked scheduled publish `failed` with the exception message.

**Disabled-capability behavior (pinned):** container tags come from the compile-time
`services()` map, so the gate service is collected by `PublishService` even when the
capability is disabled — therefore **the gate itself checks
`CapabilityRegistry::isEnabled('lemma.workflow')` first and allows (returns) when
disabled**. Routes and lifecycle listeners are wired in `boot()` inside the usual
`isEnabled()` block (routes 404, listeners absent when disabled). Net effect when
disabled: publish behaves exactly as current core.

## 3. State machine

Review state is per **(entry, locale)** — the same granularity as drafts. `draft` is the
default state (no row required). States: `draft` → `in_review` → `approved` /
`changes_requested`.

| # | Transition | From → To | Actor requirement |
|---|---|---|---|
| 1 | submit | draft, changes_requested → in_review | `content.edit` |
| 2 | approve | in_review → approved | `workflow.review`, reviewer ≠ submitter¹ |
| 3 | request changes | in_review → changes_requested | `workflow.review`; **note required** |
| 4 | withdraw | in_review → draft | the submitter, or `workflow.review` |
| 5 | *(edit)* | in_review, approved → draft | automatic, via `entry.updated` |
| 6 | *(publish)* | any → draft (approval consumed) | automatic, via `entry.published` |

¹ Self-review is blocked by default; `lemma_workflow.allow_self_review` (config, default
`false`) is the escape hatch for tiny teams. There is deliberately **no** `enabled` config
key — the `lemma.capabilities` switchboard is the only gate.

**Rule name: “edits invalidate active review or approval”** (not “any edit resets to
draft”), a deliberate refinement:

- editing while `in_review` → `draft` (the reviewer must not approve a moving target)
- editing while `approved` → `draft` (what was approved is no longer what would publish)
- editing while `changes_requested` → **stays** `changes_requested` (the state *means*
  “reviewer asked for revisions; author is currently revising”; it cannot publish, so the
  approval guarantee is intact)
- **submit is the only transition that clears `changes_requested`** — “revisions are
  ready” → `in_review`

Illegal transitions (e.g. approve when not `in_review`) are **409** from the transition
endpoints.

**Publish rule (uniform, evaluated at publish time):** allow when state is `approved`
**or** the actor holds `workflow.bypass`; otherwise throw `PublishBlocked`. This one rule
covers the admin UI, the API, **and scheduled publishes**: `content_schedules.created_by`
is already stored and passed as the publish actor by `ScheduleRunner::fire()`, so a
schedule created by a bypass holder publishes at run time (an admin who could publish
directly can schedule one), and revoking the bypass before firing fails the schedule
(fail-safe). A schedule with no `created_by` has a null actor → approval required.

## 4. Automatic transitions: contract-only event subscription

The pack listens to **`Glueful\Lemma\Contracts\Events\ContentLifecycleEvent`** (interface
dispatch is proven — lemma-seo's `SitemapCacheInvalidator` subscribes the same way) and
switches on `name()`; it must **never** import `App\Content\Events\*`:

- `entry.updated` → apply rule 5 (reset `in_review`/`approved` to `draft`; record the
  transition with the editing actor from `payload()`).
- `entry.published` → inspect the state row **at publish time**: `approved` → record
  `published`; anything else → the gate necessarily allowed it via bypass → record
  **`published_with_bypass`** (actor = publisher). Then reset state to `draft`. This
  single listener is the only writer for publish-time history — emergency bypass
  publishes stay visible in the workflow history, which is the governance point.

## 5. Storage (pack-owned; flat `migrations/` at pack root, `DEPENDENT` priority)

- **`workflow_review_states`** — entry_uuid (12), locale (16), state (24), submitted_by
  (12, nullable), submitted_at, reviewed_by (12, nullable), reviewed_at, updated_at;
  `unique(entry_uuid, locale)`. No cross-package FKs. Absent row ≡ `draft`.
- **`workflow_transitions`** — append-only history: entry_uuid, locale, from_state,
  to_state, action (submit / approve / request_changes / withdraw / edit_invalidated /
  published / published_with_bypass), actor_uuid (nullable), note (nullable), metadata
  (json, nullable), created_at. Kept forever. **Known v1 limitation:** the
  `entry.published` payload carries no source/channel field, so history cannot cheaply
  distinguish a manual publish from a scheduled one — both record the stored actor. The
  nullable `metadata` column is the forward seam: if the publish event ever grows a
  source field, the listener records it there without a schema change.
- **Permissions seed migration** — declares `workflow.review` (“Review content
  submissions”) and `workflow.bypass` (“Publish without approval”), guarded/idempotent
  like the analytics/seo seeds; `down()` is a no-op. The **host app** grants both to
  `administrator` in its own dependent migration (the lemma-seo pattern). Submitting
  reuses `content.edit` — no new permission.

## 6. HTTP API (triple-gated: capability → `auth` → `lemma_permission`)

Under `/v1/admin/workflow`, loaded only when `lemma.workflow` is enabled:

| Route | Permission |
|---|---|
| `POST /entries/{uuid}/{locale}/submit` | `content.edit` |
| `POST /entries/{uuid}/{locale}/approve` | `workflow.review` |
| `POST /entries/{uuid}/{locale}/request-changes` | `workflow.review` |
| `POST /entries/{uuid}/{locale}/withdraw` | `content.view`² |
| `GET  /entries/{uuid}/{locale}` (state + history) | `content.view` |
| `GET  /queue?state=in_review` (paginated) | `workflow.review` |

² the route gate is deliberately just `content.view` (a reviewer may lack `content.edit`);
the real rule — actor = submitter **or** holder of `workflow.review` — is enforced in the
service and yields 403.

Transition bodies are validated by a DTO (note: string, required for request-changes,
max 2000). Responses return the updated state row. Illegal transitions → 409; unknown
entry/locale or no draft → 404. The queue joins entry title/type/locale/submitter/age;
v1 has no per-type reviewer policy — `workflow.review` sees all in-review items.

**Events (pack-owned, extend `BaseEvent`):** `ReviewSubmitted`, `ReviewApproved`,
`ChangesRequested` — the seam for future notification wiring; nothing consumes them in v1.

## 7. Admin SPA

- **Entry editor**: workflow state badge + capability/permission-gated actions — Submit
  for review, Withdraw, Approve, Request changes (note modal). Gating mirrors the
  analytics pattern (capabilities store) plus the session permission checks used
  elsewhere. Pinia setup-store style; query module follows `queries/seo.ts` conventions.
- **Review queue page**: lists in_review entries (title, type, locale, submitter, age)
  with links into the editor; visible only to `workflow.review` holders with the
  capability enabled.

## 8. Testing

Integration tests mirroring the other packs (`tests/Integration/Workflow/`):

- Full transition matrix, legal and illegal (409s), including note-required on
  request-changes and the self-review block + `allow_self_review` override.
- Gate: unapproved publish → 409 `PublishBlocked`; approved publishes; `workflow.bypass`
  publishes from any state and the history records `published_with_bypass`.
- Automatic rules through **real events**: draft save resets in_review/approved (and does
  not touch changes_requested); publish resets state and records the right action.
- Scheduled publish of unapproved content with a non-bypass `created_by` → schedule row
  marked `failed`; with a bypass holder → publishes.
- Capability disabled → routes 404, gate not registered (publish ungated), listeners not
  wired. Removability → core boots and publishes with the pack absent.
- Contract-coupling guards like `ContentImporterWritesViaContractTest` (no `App\` refs,
  constructor depends on contracts + `PermissionManager` only).
- SPA specs: badge/actions render per state + permissions; queue lists and links.

## 9. Out of scope (v1)

Multi-stage/configurable pipelines, per-content-type reviewer policy or approval opt-in,
assignment, notifications (events are emitted; wiring is future work), workboard UI, and
framework-level generalization to other “publishable” resources (no second consumer).
