---
title: "Drafts, preview and publishing"
slug: publishing
section: concepts
order: 5
summary: "The life of an entry: draft, preview, review, publish, schedule, unpublish."
---

Nothing you type in the admin reaches the live site until you publish it. What the site serves is
a pinned version: a frozen copy of the draft, taken at the moment you published. This page is
about the two of them and everything in between. All of it is per entry **and locale** — one entry
can be published in English and still a draft in French.

## Saving writes a draft

**Save draft** — in the entry editor's top bar, and in the Design view — stores the entry's fields
as its draft, and the live site does not change. A draft save is permissive: you can leave a
required field empty, or point a reference at something that is not there, and it still saves.

The badge in the **Publishing** tab, and in the locale switcher, says where a locale stands:

| Badge | Means |
|---|---|
| Published | A version is pinned; the site serves it |
| Scheduled | Not published, a schedule is pending |
| Draft | A draft exists, never published |
| Not started | Nothing written in this locale |

Published wins over Scheduled and Scheduled over Draft, so a published entry with a pending
schedule shows Published.

## Preview shows the draft through the theme

Two buttons sit at the top of the **Publishing** tab. **Preview in theme** appears when rendered
delivery is on; **Preview draft** appears when **Settings › General › Site preview URL** is set,
for a separate frontend that reads Thallo's API. Both mint a signed token bound to one entry and
locale. The token is the credential: the preview URL needs no login, so anyone you send it to can
read the draft until it expires. Its lifetime is `PREVIEW_TTL` seconds in `.env`, 600 by default;
after that the link renders the site's 404 page.

A theme preview opens at `/_preview/{token}`: the real theme, rendering the draft. The response is
`no-store` and `noindex` and never enters the page cache, so a preview cannot leak into what
visitors are served. It also starts a session — a cookie carrying the same token — so links you
follow keep showing drafts and you can walk the site as it would be.

A bar runs across the top of the previewed page. Its first line is the entry's real publication
state: "Published — previewing the latest draft", "Published without a route — previewing the
latest draft", or "Draft — not published yet". Then the actions. **Edit** and **Design** return to
the entry in the admin: at this site's own admin, or wherever **Settings › General › Admin URL**
says it is hosted instead. **View live** appears only for a published entry with a route. **Exit
preview** ends the session; otherwise it dies with the token.

## Publishing pins a version

**Publish** in the top bar — **Update** once the locale is published. It saves the draft first,
and saves the slug if you have typed one and not saved it, so a page never goes live without a
URL. Then, in one transaction:

1. The draft is validated strictly. A required field left empty, or a reference to something that
   is gone, is refused here, where the draft save let it through.
2. The draft is copied into a new, numbered, immutable version.
3. That version is pinned as the published one.

The rendered site and the content API read the pin and nothing else, so every later edit changes
only the draft until you publish again.

### What a publish sets off

Once the transaction has committed, the entry's `entry.published` event runs these. None can fail
the publish: it is already durable.

| Effect | What it does |
|---|---|
| Reference projection | Rebuilds the published references behind term archives and facet counts |
| Cache | Invalidates the tags `thallo:entry:{uuid}` and `thallo:type:{slug}` |
| CDN | Purges the same tags at the edge, with a CDN integration installed |
| Search | Asks the search provider to reindex the entry, with one installed |
| Webhooks | Dispatches `entry.published` to subscriptions for that name |

The webhook payload carries identity only — entry, type, locale, version, actor, timestamp — never
the field values; a receiver re-reads the content through the API with its own key.
**Settings › General › Content webhooks** is the master switch. See
[notify other systems with webhooks](../guides/16-webhooks.md). The publish is recorded in the
audit log too, under **Users & Access › Audit Log**.

## Unpublishing removes the pin

**Unpublish**, in the **Publishing** tab, removes the pin. The versions are kept. The entry's URL
answers 404, it leaves the content API, `entry.unpublished` fires, and the same caches are
dropped. A redirect that points at the entry answers 410 while it is unpublished.

Publishing again does not restore the old pin: it makes a new version from the current draft.

## Scheduling a publish

The clock button in the **Publishing** tab opens **Publish at**. Give a date and time — read in
your browser's time zone, stored in UTC — and press **Schedule**. It must be in the future. The
badge becomes Scheduled, and the schedule is listed under the field with its action, time and
status; the cross beside it cancels it while it is still pending. A second publish scheduled for
the same locale replaces the pending one rather than queueing another.

A schedule fires only if the site's scheduler cron line is installed, and nothing at the entry
says when it is not. The `schedules_run` job fires due rows every minute — see
[the scheduler and the queue](../operations/03-scheduler-and-queues.md).
**Settings › General › Publish scheduler** stops the firing while the cron tick keeps running.

A due schedule takes the publish path the button takes, with the same validation and review gate,
as the user who created it. Its status ends at `done`, at `failed` with the reason stored, or at
`canceled` when the entry has been deleted. Unpublishing on a schedule is in the admin API, but
the admin has no control for it.

## Version history and restoring

The **Versions** tab lists the locale's versions, newest first, with the time each was made.
**Restore** re-pins one: the site serves it again, and it counts as a publish — the same events,
the same cache drops. It does not change the draft, so the editor still holds whatever was last
saved, and the next publish supersedes what you restored.

History grows without limit unless you set a retention policy in `.env` — `VERSION_KEEP`, the
newest N per entry and locale, and `VERSION_MAX_AGE_DAYS` — and neither deletes anything by
itself. The deleting is a command:

```bash
$ php glueful thallo:versions:prune --dry-run
```

`--dry-run` reports and deletes nothing; without it the deletion is permanent. `--keep` and
`--max-age-days` override `.env` for one run. A pinned version is never deleted, and with no
policy at all the command does nothing.

## Review before publishing

The **Approval workflow** capability (`thallo.workflow`) puts a single review stage in front of
publishing; with it disabled or removed, publishing behaves as above. See
[capabilities and packs](06-capabilities.md). With it on, each entry and locale carries a review
state:

| Transition | From, to | Who |
|---|---|---|
| Submit for review | Draft or Changes requested, to In review | `content.edit` |
| Approve | In review, to Approved | `workflow.review`, and not the submitter |
| Request changes | In review, to Changes requested — a note is required | `workflow.review` |
| Withdraw | In review, to Draft | The submitter, or `workflow.review` |

The controls are a **Review** section inside the **Publishing** tab. Requesting changes opens a box
for the note and a **Send feedback** button; the note is shown to the author while they revise.

Editing an entry that is In review or Approved returns it to Draft: what was approved has to be
what publishes. Changes requested survives an edit, because it means the author is working;
submitting again is what clears it.

Publishing is allowed when the state is Approved, or when you hold `workflow.bypass`. Otherwise it
is refused and the admin says the locale needs a review. The same gate applies to a scheduled
publish, checked as it fires against the person who scheduled it: take their bypass away
beforehand and the schedule fails. A successful publish returns the state to Draft and records
`published`, or `published_with_bypass`, in the entry's history — an emergency publish stays
visible. Approving your own submission is refused unless you hold bypass, or
`WORKFLOW_ALLOW_SELF_REVIEW=true` is set in `.env` for a team too small to have two people.

**Review queue** in the sidebar lists what is waiting, with its type, locale, submitter and age;
a row opens the entry. The item is gone from the sidebar when the capability is off. For granting
`workflow.review` and `workflow.bypass`, see
[users, roles and permissions](../guides/15-users-and-roles.md).

## Re-driving the effects after a crash

Those effects run in the same process, after the commit. If it dies in between, the entry is
published but its caches were never dropped and its search document never rebuilt.
`thallo:resync` re-drives them over content that is already published:

```bash
$ php glueful thallo:resync --type=post
```

`--entry=UUID` does one entry; no option at all does every published entry of every content type.
It rebuilds the reference projection, drops the cache tags, purges the CDN and asks for a reindex,
but does not re-fire webhooks unless you pass `--webhooks`, because receivers would see the
delivery twice. Every effect is idempotent, so a second run is harmless, and it reads only
published content — a draft is never touched.

You tell any of this worked the same way: load the page on the live site, in a browser with no
preview session, and see the change.
