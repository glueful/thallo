# Lemma Publishing Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Hang the downstream effects of content changes off the publish (and mutation) transactions via `afterCommit`: dispatch a **frozen domain-event taxonomy**, invalidate cache tags, and enqueue webhook deliveries / CDN purge / search reindex — all **re-drivable and idempotent**, with a `lemma:resync` command to re-drive.

**Architecture:** Each content mutation dispatches **one primary** PSR-14 domain event (and, for draft saves that change asset references, **zero or more** `asset.attached`/`asset.detached` delta events) from inside `db($context)->afterCommit(...)` (so effects fire only on the outermost commit, never on rollback). Listeners are registered at boot via **`EventService::addListener(EventClass::class, '@ListenerService')`** in `LemmaServiceProvider::boot()` (the lazy `@serviceId` form, resolved from the container) — **there is no config-driven listener map for the app event system**; `config/events.php` holds only toggles (`enabled`, etc.), so the plan does NOT register listeners there. The listeners: a cache-invalidation listener calls `CacheStore::invalidateTags`, a webhook listener calls the core `WebhookDispatcher` (which enqueues signed, retried deliveries), and capability-gated listeners enqueue CDN purge (`EdgeCacheInterface`, only if `glueful/cdn`) and search reindex (only if a provider binds Lemma's `ContentReindexerInterface`). Because `afterCommit` is in-process and cannot survive a crash between commit and callback (verified limitation, V1_DESIGN §5), every effect is idempotent and re-drivable, and `lemma:resync` re-emits them.

**Tech Stack:** PHP 8.3, Glueful ^1.56.0, `Glueful\Events\Contracts\BaseEvent` + `EventService::dispatch`, `Glueful\Database\Connection::afterCommit`, core `Glueful\Api\Webhooks\WebhookDispatcher` (signing/retries/delivery tracking), `Glueful\Cache\CacheStore::invalidateTags`, `Glueful\Cache\Contracts\EdgeCacheInterface` (CDN), Lemma's `ContentReindexerInterface` search-provider seam. Builds on the foundation + the **Delivery API** plan (which emits the `lemma:entry:{uuid}` / `lemma:type:{slug}` cache tags this plan invalidates).

**Source of truth:** [`../V1_DESIGN.md`](../V1_DESIGN.md) §5 (pipeline contract, the frozen event table, cache tags) and §8 (asset events). **Depends on the Delivery API plan** for the cache-tag scheme — sequence this after it.

**Scope boundary:** emit events + invalidate tags + enqueue webhook/CDN/search + `lemma:resync`. **Not** here: defining the cache tags / ETag (Delivery plan owns those), preview, SPA, export. Webhook *subscription management* uses core `Api\Webhooks` as-is — Lemma only defines the event names + payloads, it does not build webhook infra.

---

## Conventions
Inherit the foundation conventions (repositories, migrations with `hasTable` guard, `LemmaTestCase`, PSR-12 `composer phpcs` gate, `Glueful\Http\Response`). Events extend `Glueful\Events\Contracts\BaseEvent` and call `parent::__construct()` (per CLAUDE.md). Capability gates use `class_exists(...)` / container `has(...)` checks so a lean install (no cdn/content reindexer) is a clean no-op.

---

## File structure
```
config/
  lemma.php                               # MODIFY: + pipeline toggles (webhooks on, cdn/search auto)
app/Providers/LemmaServiceProvider.php    # MODIFY: register listener services + addListener() wiring in boot()
  # (config/events.php is NOT modified — it has no listener-map; registration is via EventService::addListener)
app/Content/
  Events/
    BaseContentEvent.php                  # shared payload shape (entry uuid, type, locale, version, actor, ts)
    EntryCreated.php EntryUpdated.php EntryPublished.php
    EntryUnpublished.php EntryDeleted.php
    ModelCreated.php ModelUpdated.php ModelDeleted.php
    AssetAttached.php AssetDetached.php
  Pipeline/
    PublishEventEmitter.php               # builds + dispatches the right event (via afterCommit) for each mutation
    Listeners/
      InvalidateCacheTagsListener.php     # CacheStore::invalidateTags(['lemma:entry:{uuid}','lemma:type:{slug}'])
      DispatchWebhookListener.php         # WebhookDispatcher::dispatch('entry.published', payload)
      PurgeCdnListener.php                # capability-gated: EdgeCacheInterface->purgeByTag(...)
      ReindexSearchListener.php           # capability-gated: enqueue a search-index job
  Services/
    PublishService.php                    # MODIFY: afterCommit-dispatch EntryPublished/Unpublished
    Repositories/EntryRepository.php      # MODIFY: afterCommit-dispatch EntryCreated/Updated/Deleted
  Http/Controllers/ContentTypeController.php  # MODIFY: dispatch ModelCreated/Updated/Deleted
  Console/ResyncCommand.php               # `lemma:resync [--type=slug] [--entry=uuid]`
tests/
  Unit/Content/EventTaxonomyTest.php      # event names/payload shape frozen as contract
  Integration/Pipeline/AfterCommitDispatchTest.php   # publish -> event fired once, on commit only, not on rollback
  Integration/Pipeline/CacheInvalidationTest.php
  Integration/Pipeline/WebhookDispatchTest.php
  Integration/Pipeline/CapabilityGatingTest.php      # no cdn/content reindexer -> clean no-op
  Integration/Console/ResyncCommandTest.php
```

---

### Task 1: The frozen event taxonomy (contract)

**Files:** Create `app/Content/Events/BaseContentEvent.php` + the 10 event classes; Test `tests/Unit/Content/EventTaxonomyTest.php`.

V1_DESIGN §5 freezes these event names as an API contract: `entry.created`, `entry.updated`, `entry.published`, `entry.unpublished`, `entry.deleted`, `model.created`, `model.updated`, `model.deleted`, `asset.attached`, `asset.detached`. Payload: entry uuid, type, locale, version, actor, timestamp — **never full field payloads by default** (receivers fetch via the delivery API with their own key).

- [ ] **Step 1: Write the failing unit test** — instantiate each event, assert its `name()` (the frozen string) and that `payload()` contains exactly `{entry|type, locale?, version?, actor, timestamp}` and **not** a `fields` key. Pin the 10 names in an array so a rename breaks the test.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement** `BaseContentEvent extends Glueful\Events\Contracts\BaseEvent` (calls `parent::__construct()`), holding the shared readonly payload + `abstract public function name(): string` + `public function payload(): array`. Each subclass sets its name. (Verify `BaseEvent`'s constructor/metadata API against `src/Events/Contracts/BaseEvent.php`.)
- [ ] **Step 4: Run → pass. Step 5: Commit** `Add frozen Lemma content-event taxonomy`.

---

### Task 2: `PublishEventEmitter` + afterCommit wiring (fire once, on commit only)

**Files:** Create `app/Content/Pipeline/PublishEventEmitter.php`; Modify `PublishService` + `EntryRepository` + `ContentTypeController` to emit via `afterCommit`; Test `tests/Integration/Pipeline/AfterCommitDispatchTest.php`.

- [ ] **Step 1: Write the failing test** — publish an entry; assert the **primary** `entry.published` event was dispatched **exactly once** and **after** commit (use a spy listener `addListener`ed on the test's `EventService`); then force a rollback (e.g. publish an invalid draft) and assert **no** event fired. Assert nested transactions only fire on the outermost commit. (Asset delta events are not exercised here — Task 2b.)
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.** `PublishEventEmitter` wraps `EventService` + `ApplicationContext`; `emitAfterCommit(BaseContentEvent $e): void` calls `db($this->context)->afterCommit(fn() => $this->events->dispatch($e))`. Then:
  - `PublishService::publish` → after the transaction returns, `emitAfterCommit(new EntryPublished(...))`; `unpublish` → `EntryUnpublished`; `rollback` → `EntryPublished` (re-publish, per §5 "includes re-publish/rollback").
  - `EntryRepository`: `createEntry` → `EntryCreated`; `saveDraft` → `EntryUpdated` (autosave-debounced is a later refinement — for now one per save); `softDelete` → `EntryDeleted`. (These need the emitter injected; keep it optional/null-safe so the foundation's existing repo tests that construct the repo without an emitter still pass — inject `?PublishEventEmitter`.)
  - `ContentTypeController`: `store`/`updateSchema`/(archive) → `ModelCreated`/`ModelUpdated`/`ModelDeleted`.
  - **Primary-event invariant:** each mutation emits exactly **one primary** event (`EntryCreated`/`EntryUpdated`/`EntryPublished`/`EntryUnpublished`/`EntryDeleted`/`Model*`). The afterCommit test below asserts the *primary* event fires exactly once — `asset.attached`/`asset.detached` are **separate, additional** delta events handled in **Task 2b**, deliberately out of this invariant (so the test enforces the right thing).
- [ ] **Step 4: Run → pass.** Confirm the foundation suite stays green (the `?PublishEventEmitter` optional injection mustn't break existing constructors).
- [ ] **Step 5: Commit** `Emit primary content events via afterCommit (commit-only, rollback-safe)`.

---

### Task 2b: Asset delta events (`asset.attached` / `asset.detached`)

**Files:** Modify `EntryRepository::saveDraft` (emit asset deltas); Test `tests/Integration/Pipeline/AssetEventsTest.php`.

Separate from the primary-event invariant. On a draft save, diff the entry's previous vs new `asset`-type field targets (the reference projection from the Delivery plan's Task 2 already computes the asset/reference rows) and emit `AssetAttached` for newly-referenced blob uuids and `AssetDetached` for removed ones — giving "where is this asset used" for free (V1_DESIGN §8).

- [ ] **Step 1:** failing test — save a draft adding asset `b1` → one `asset.attached`(b1); re-save replacing `b1` with `b2` → `asset.detached`(b1) + `asset.attached`(b2); a save that doesn't touch asset fields emits **no** asset event.
- [ ] **Step 2–4:** implement the old-vs-new asset diff inside `saveDraft` (after the primary `EntryUpdated` emit), each delta via `emitAfterCommit`. Pass; foundation suite still green.
- [ ] **Step 5: Commit** `Emit asset.attached/detached delta events on draft save`.

---

### Task 3: Cache-tag invalidation listener

**Files:** Create `app/Content/Pipeline/Listeners/InvalidateCacheTagsListener.php`; register it (service + `addListener` in `LemmaServiceProvider::boot()`); Test `tests/Integration/Pipeline/CacheInvalidationTest.php`.

§5 cache tags: `lemma:entry:{uuid}` (invalidated on publish/unpublish/delete of that entry); `lemma:type:{slug}` (invalidated on any publish within the type + on model changes).

- [ ] **Step 1: Write the failing test** — prime the cache with a tagged value (tag `lemma:entry:X`), publish entry X, assert the tag was invalidated (the value is gone / `CacheStore::invalidateTags` was called with the right tags). Use the real `CacheStore` from the container (array/file driver in tests).
- [ ] **Step 2–4:** implement the listener (maps each event → the tag set; calls `CacheStore::invalidateTags`); register it as a shared service and wire it in `LemmaServiceProvider::boot()` via `app($context, EventService::class)->addListener(EntryPublished::class, '@' . InvalidateCacheTagsListener::class)` (and for the other relevant events); pass. Idempotent by nature (invalidating an already-clear tag is a no-op).
- [ ] **Step 5: Commit** `Invalidate Lemma cache tags on content events`.

---

### Task 4: Webhook dispatch listener (core `WebhookDispatcher`)

**Files:** Create `app/Content/Pipeline/Listeners/DispatchWebhookListener.php`; register it (service + `addListener` in boot); Modify `config/lemma.php`; Test `tests/Integration/Pipeline/WebhookDispatchTest.php`.

Lemma does not build webhook infra — it calls `WebhookDispatcher::dispatch(string $event, array $data, array $options = []): array` (signing/retries/delivery tracking are the core's). Payload is the event's `payload()` (no full fields).

- [ ] **Step 1: Write the failing test** — with a webhook subscription registered for `entry.published` (set up via core `Api\Webhooks` — verify the subscription API against `src/Api/Webhooks/WebhookSubscription.php`), publish an entry and assert `WebhookDispatcher::dispatch` was invoked with `'entry.published'` and the payload (entry uuid/type/locale/version/actor/ts, **no** `fields`). Mock/spy the dispatcher at the container boundary.
- [ ] **Step 2–4:** implement (listener resolves `WebhookDispatcher` from the container, calls `dispatch($event->name(), $event->payload())`); gate on `lemma.pipeline.webhooks_enabled` (default true); register via `addListener` in `LemmaServiceProvider::boot()`; pass.
- [ ] **Step 5: Commit** `Dispatch Lemma content events to core webhooks`.

---

### Task 5: Capability-gated CDN purge + search reindex

**Files:** Create `app/Content/Pipeline/Listeners/PurgeCdnListener.php`, `ReindexSearchListener.php`; register both (services + `addListener` in boot); Test `tests/Integration/Pipeline/CapabilityGatingTest.php`.

- [ ] **Step 1: Write the failing test** — with **neither** `glueful/cdn` nor a content reindexer present (the default Lemma install per the foundation enables users/aegis/media/email — not cdn/search), publishing an entry must be a **clean no-op** for these listeners (no error, no exception), and the rest of the pipeline (cache/webhook) still runs. (If a test env has them, assert the purge/reindex is requested; otherwise assert graceful skip.)
- [ ] **Step 2–4:** implement.
  - `PurgeCdnListener`: if the container has `Glueful\Cache\Contracts\EdgeCacheInterface` bound to a real (non-null) edge cache, call `purgeByTag('lemma:entry:{uuid}')` / `purgeByTag('lemma:type:{slug}')`; else skip. (The core ships `NullEdgeCache` when cdn is absent — confirm and treat null/`getProvider()===null` as "skip".)
  - `ReindexSearchListener`: if Lemma's `ContentReindexerInterface` is bound, request a reindex for the entry's published version; else skip. Keep payload minimal (entry uuid + locale); the search extension owns queueing and document shape.
  - Register both via `addListener` in `LemmaServiceProvider::boot()`. Both idempotent + re-drivable.
- [ ] **Step 5: Commit** `Add capability-gated CDN purge + search reindex listeners`.

---

### Task 6: `lemma:resync` command (re-drive the pipeline)

**Files:** Create `app/Content/Console/ResyncCommand.php`; Test `tests/Integration/Console/ResyncCommandTest.php`.

§5: because effects are in-process and a crash between commit and callback can drop them, `lemma:resync` re-drives cache invalidation + search reindex + (optionally) re-dispatch for an entry, a type, or everything.

- [ ] **Step 1: Write the failing test** — publish an entry with the pipeline listeners disabled (simulating a dropped afterCommit), then run `lemma:resync --entry={uuid}` and assert the cache tags were invalidated + the search reindex enqueued (the re-drivable effects). `--type=slug` re-drives all published entries of a type; no args re-drives everything (bounded/batched).
- [ ] **Step 2–4:** implement as a `Glueful\Console\BaseCommand` (`#[AsCommand('lemma:resync')]`; discovered via the provider's `discoverCommands` or registered explicitly — confirm the app-command registration path against the skeleton/`LemmaServiceProvider`). It iterates published entries (via `DeliveryRepository`) and re-emits the idempotent effects (invalidate tags + enqueue reindex; webhook re-dispatch is opt-in behind `--webhooks` since re-firing webhooks may surprise receivers). Pass.
- [ ] **Step 5: Commit** `Add lemma:resync to re-drive the publishing pipeline`.

---

### Task 7: Wire + end-to-end pipeline test + full suite

**Files:** Modify `LemmaServiceProvider` (register the emitter + the four listener services in `services()`, and wire them in `boot()` via `EventService::addListener(EventClass::class, '@ListenerService')`); Test `tests/Integration/Pipeline/ListenerWiringTest.php` (+ aggregate).

- [ ] **Step 1: Write a wiring test** — boot the app (via `LemmaTestCase`) and assert `app($context, EventService::class)->hasListeners(EntryPublished::class)` (and `EntryUpdated`, `ModelUpdated`) returns `true`. This pins that the `boot()` `addListener` calls actually ran (catches the "listeners silently not registered" failure mode the original config-driven approach would have hit). `EventService::addListener`/`hasListeners` are confirmed present.
- [ ] **Step 2:** ensure listeners are registered and resolvable; run the FULL suite `composer test` (green, incl. the foundation + delivery suites) and `composer phpcs` (clean). Confirm a lean install (no cdn/content reindexer) publishes with the cache + webhook effects and no errors.
- [ ] **Step 2: Commit** `Wire Lemma publishing pipeline`.

---

## Self-review
- **Spec coverage:** §5 afterCommit contract + commit-only/rollback-safe → Task 2; frozen event taxonomy + payload-without-fields → Task 1; cache tags → Task 3; webhooks via core dispatcher → Task 4; CDN/search behind capability checks → Task 5; `lemma:resync` re-drivability → Task 6. §8 asset events → Task 2 (with an allowed deferral of the attach/detach diff).
- **Re-drivability/idempotency** is a first-class property: every listener is safe to run twice, and `lemma:resync` exists precisely because `afterCommit` is in-process (the §5 "outbox table" upgrade path is explicitly out of scope until webhook delivery becomes a hard guarantee).
- **Listener registration (P1 fix):** there is **no** config-driven listener map for the app event system — registration is `EventService::addListener(EventClass::class, '@ListenerService')` in `LemmaServiceProvider::boot()` (lazy `@serviceId` form, confirmed). A wiring test asserts `hasListeners()` after boot so a missed registration fails loudly (Task 7).
- **Primary-vs-asset events (P2 fix):** exactly one *primary* event per mutation (the afterCommit "fires once" test enforces this); `asset.attached`/`asset.detached` are separate additional deltas in Task 2b — the invariant and the test are aligned.
- **Verify-points:** `BaseEvent` constructor/metadata (T1); `WebhookDispatcher`/`WebhookSubscription` API (T4); `EdgeCacheInterface` null/Null-edge detection + content reindexer binding (T5); app console-command registration (T6); the `?PublishEventEmitter` optional injection not breaking foundation repo tests (T2).
- **Lean-install safety:** with only the foundation's extensions, cdn/search listeners no-op; webhooks fire only if a subscription exists; nothing in the pipeline is a hard dependency.
- **Sequencing:** build the **Delivery API** plan first (it defines the cache tags + the `DeliveryRepository` this plan's resync/search listeners read through).
