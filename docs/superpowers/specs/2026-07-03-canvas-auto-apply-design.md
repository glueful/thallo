# Canvas v5: Auto-Apply — Design

**Date:** 2026-07-03
**Status:** Approved design, pending implementation
**Builds on:** loop C (ephemeral apply), v3/v4 edit sessions, the v2 mirror loop

## 0. Summary

The canvas applies the working tree automatically on a trailing debounce after
tree changes, reloading the stage without the user pressing Apply — with an
Auto toggle (on by default), hard suppression during in-place edit sessions,
one-banner-then-suspend failure behavior, and scroll preservation across
stage reloads (which also fixes manual Apply's scroll snap).

Decision pins from brainstorm review:

- **One write path (hard pin):** auto-apply is a SCHEDULER over the same
  `applyWorking()` core — never a second apply implementation. Token retry,
  reset-on-failure, validation surfacing, and stash semantics can never
  drift because there is exactly one code path that talks to the endpoint.
- **Default:** on, controlled by a navbar Auto toggle, persisted per browser
  (`localStorage` key `lemma.canvas.auto_apply`).
- **Scroll preservation is in scope** — bridge-reported positions restored
  after every stage reload (auto, manual, failure reset).
- **Failure:** stage reset + ONE banner + suspend; suspension is
  session-local and re-arms on a successful MANUAL apply (or by clicking the
  toggle). **Suspend after the FINAL retry only (review pin):** a dead-token
  first attempt whose re-mint retry succeeds is normal TTL churn and must
  not trip suspension.
- **Coalesced follow-up (review pin):** changes landing during an in-flight
  apply set ONE boolean; when the request settles, at most one follow-up run
  applies the LATEST tree. Never a counter, never a queue.

## 1. Scheduler

State (all page-local): `autoEnabled` (persisted), `autoSuspended`
(session-local), `editSessionActive`, `applyInFlight`, `applyQueued`
(the coalescing boolean), plus the existing `stageStale`.

- A watcher on the tree (the `stageStale` inputs) schedules a run on an
  **800ms trailing debounce** — each change restarts the timer.
- At fire time the run is SKIPPED when any of: `!autoEnabled`,
  `autoSuspended`, `editSessionActive`, `renderDisabled`, `mintFailed`, no
  preview token, or `!stageStale` (the change was already applied manually).
- **No concurrent applies (review pin):** if the debounce fires while
  `applyInFlight`, set `applyQueued = true` and RETURN — the settle-time
  follow-up (below) is the only way a queued change runs. Overlapping
  `applyPreview` requests must be impossible by construction.
- `editSessionActive` is parent-tracked from the bridge's NEW
  `lemma:edit-start {id}` message (posted only when a session actually
  starts) to `edit-end`. Not from the grant: a grant whose region-matching
  fails bridge-side never starts a session, and keying off the grant would
  wedge suppression forever in that case. **Edit-end re-arms the debounce**
  when `stageStale` — in-place typing patches the tree mid-session, and the
  post-session reload re-syncs the stage to server truth (visually identical
  to what was typed, scroll preserved).
- If a change lands while `applyInFlight`, set `applyQueued = true`; when the
  in-flight run settles successfully, run once more iff `applyQueued && stageStale`
  (clearing the flag first). A failed run clears the flag — suspension owns
  what happens next.

## 2. One apply core

`applyWorking()` splits into `runApply(options)` + two thin callers:

- **Manual** (`applyWorking`, the button): `editFlush()` first, then the
  core; keeps today's banner behavior verbatim; on SUCCESS clears
  `autoSuspended` (the documented re-arm path).
- **Auto** (the scheduler): no flush (never runs during a session), same
  core. The core itself keeps: the 410/403 re-mint-once retry, the
  stage-reset-on-final-failure, `lastApplied` bookkeeping, and the same-URL
  reload on success.
- **Snapshot honesty (review pin):** the core captures an immutable payload
  snapshot BEFORE the first request, sends that same snapshot to the retry,
  and stamps `lastApplied` from the snapshot only. Reading live fields after
  the await would record edits the server never saw — `stageStale` would
  read false and the coalesced follow-up would silently skip.
- **Failure policy in the core, parameterized by caller:** on FINAL failure
  (after the retry — a successful retry is not a failure, review pin), auto
  shows one banner (same copy as manual's branches) and sets
  `autoSuspended = true`; manual behaves exactly as today. Save draft
  (`saveDraftOnly`) is untouched.

## 3. Scroll preservation

Two nonce-enveloped messages:

- Bridge → parent: `lemma:scroll {y}` — posted on scroll, trailing-throttled
  at 250ms.
- Parent → bridge: `lemma:restore-scroll {y}` — sent after every stage
  reload's `hello` when the remembered `y > 0`; the bridge jumps instantly
  (`window.scrollTo(0, y)` — never smooth; a reload restore must not visibly
  travel).

The parent remembers the last reported position and clears it when the
ENTRY/locale changes (route params) — not per reload. This applies to auto
reloads, manual Apply, and the save/apply failure resets alike: it
retroactively fixes manual Apply's scroll snap.

## 4. Toggle + suspension UI

- Navbar toggle beside Apply: `data-test="canvas-auto-toggle"`, icon-style
  button with pressed state; ON by default; writes
  `localStorage['lemma.canvas.auto_apply']` (`'1'`/`'0'`; absent = on).
- Suspended state renders as a warning tint on the same toggle (not a third
  control); tooltip/aria-label says auto-apply is paused after an error.
  Clicking while suspended clears suspension AND leaves auto enabled.
  Suspension never persists.
- The Apply button + stale chip are unchanged — the escape hatch and the
  post-failure recovery affordance are the control users already know.

## 5. Testing

- **Canvas-page (fake timers):** one apply after the debounce; a burst of
  changes → one apply; a change during an in-flight apply → exactly one
  follow-up with the latest tree (the coalescing boolean); grant suppresses
  and edit-end re-arms; final failure suspends (one banner; subsequent
  changes apply nothing) and a successful manual Apply re-arms; a dead-token
  first attempt with a successful retry does NOT suspend; toggle off →
  nothing schedules; localStorage read at mount + write on toggle.
- **Bridge direct suite:** throttled `lemma:scroll` posts (fake timers);
  `lemma:restore-scroll` calls `window.scrollTo(0, y)` (spied); nonce
  discipline on both.
- **Composable:** `onScroll(cb)` dispatch + `restoreScroll(y)` post shape.
- **Manual acceptance (recorded):** typing rhythm vs the 800ms debounce on a
  real theme; scroll restore below the fold; suspension UX under a forced
  409 (block migration); toggle persistence across reloads.

## 6. Out of scope (recorded follow-ups)

- Per-user server-side preference for the toggle.
- Adaptive debounce (faster on light pages, slower on heavy themes).
- Partial DOM patching instead of full reloads (the "no flicker" endgame).
- Skipping redundant reloads after in-place-only edit sessions.
