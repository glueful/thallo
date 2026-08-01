# Form Block — Design Spec

**Date:** 2026-07-09
**Status:** Approved for planning
**Target repo:** `/Users/michaeltawiahsowah/Sites/glueful/thallo`

## Goal

Add a **`form` block** to Thallo's page/block system whose flagship use case is a
contact form. Submissions are **stored** (system of record) and **best-effort
emailed** to a recipient, with invisible spam protection and an admin area to
triage and export them.

## Guiding decisions (settled during brainstorming)

- **One generic `form` block**, never a `contact_form` block. The backend is
  generic from day one (form identity, a normalized field model, validation,
  storage/email hooks). v1 ships a **contact preset**: the block exposes
  contact-friendly editor controls and *derives* the normalized fields — it does
  **not** yet ship a free-form field-builder UI.
- **Delivery = store + best-effort email.** Every submission is persisted; email
  is an add-on that can fail or be absent without ever losing data.
- **Spam = honeypot + time-trap + rate-limit**, behind a `FormSubmissionGuard`
  contract so CAPTCHA can be added later as another provider. No third-party
  scripts in v1.
- **Admin = triage list + CSV export** (filter by form, read/unread, unread
  badge, detail, delete, export). No bulk actions beyond export/delete; no
  scheduled email / CRM / webhooks / analytics.
- **Form identity = sealed descriptor** (Approach A): the exact normalized form
  the visitor saw is sealed into the rendered HTML and is the exact schema the
  server validates against. No forms-registry table, no block↔row sync.
- **v1 backend lives in the app** at `app/Content/Forms/`, alongside the existing
  block/region/content subsystems — not a separate `thallo-forms` pack.
- **v1 success behavior** = inline success message, with an optional (internal)
  redirect.

## Scope

**In v1:** the `form` block + contact preset, sealed descriptor seal/open, the
public submission endpoint, the guard chain, `form_submissions` storage,
best-effort email, the admin Submissions area with CSV export, and the render
template with a no-JS baseline + progressive enhancement.

**Out of v1 (future seams):** a full field-builder UI (add/remove/reorder
arbitrary fields — the biggest future seam), a first-class forms registry
(Approach B; for per-form dashboards/analytics), CAPTCHA, file-upload fields,
multi-step forms, scheduled digests, CRM sync, webhooks.

---

## 1. Architecture & ownership

The block spans the three layers the codebase already separates:

| Layer | Where | Responsibility |
|-------|-------|----------------|
| Block schema | `app/Content/Blocks/StarterBlockTypes.php` | Add a `form` block type (contact-oriented config). Seeds **active**. |
| Render | `packages/thallo-render/themes/default/templates/blocks/form.twig` + theme CSS | Render the `<form>`, no-JS baseline + PE. A render hook seals the descriptor into the markup. |
| Submission backend | `app/Content/Forms/` (new) | Descriptor sealer/opener, `POST /_forms/submit` endpoint, `FormSubmissionGuard` chain, `FormSubmissionRepository`, best-effort `FormNotifier`, admin Submissions controller. Owns the `form_submissions` migration. |

**Cross-package seam.** The render pack must seal a descriptor when it renders a
`form` block, but the sealing logic (encryption, field derivation, recipient
validation) lives in the app. Declare a **`FormSealerInterface`** in
`packages/thallo-contracts/` (implemented by the app, bound in the container) —
the same contract pattern the render pack already uses for site logo / custom
CSS. Expose it to Twig via a render extension function (e.g.
`form_descriptor(block)`). The block renders a **disabled notice** instead of a
broken form whenever a descriptor cannot be produced — either the binding is
absent (forms capability unavailable) or the form is un-routable (§4).

---

## 2. The `form` block — schema & contact-preset editor

The block's `data` (block-type schema in `StarterBlockTypes`) is
**contact-oriented config**, not a field builder:

- **Routing:** `recipient` (email) — where the notification goes. If empty, the
  sealer falls back to `forms.default_recipient`. If **neither** yields a valid
  address the form is **un-routable**: the sealer refuses (§4) and the block
  renders the disabled notice — it never seals a descriptor.
- **Success:** `success_message` (text), `redirect_url` (optional; **constrained
  to internal/relative URLs**, see §6).
- **Presentation:** `submit_label`, optional `heading`, optional `intro`.
- **Optional-field toggles:** `include_subject`, `include_phone`,
  `include_consent` (+ `consent_text`).
- **Copy overrides:** label/placeholder for the core fields (Name, Email,
  Message) and the enabled optional fields.

The core fields are always present (Name, Email, Message). At render, this config
is **derived into the normalized field model** (§3); that derivation — not the
raw toggles — is what gets sealed and stored. So the descriptor and submissions
are already in the shape a future field-builder will edit directly.

Editor UI reuses the existing schema-driven block editor (`BlocksField` /
`BlockCard`); no new field-builder surface in v1.

---

## 3. Normalized field model

A **normalized field definition** (the shape a future builder will edit directly,
and the single source of truth for validation/rendering/storage):

```
{
  key:         string,   // stable machine key, e.g. "email", "message"
  label:       string,   // visitor-facing label
  type:        "text" | "email" | "tel" | "textarea" | "select" | "checkbox",
  required:    boolean,
  placeholder: string?,
  help:        string?,
  options:     string[]?  // for "select"
}
```

The contact preset derives this list from the block config, e.g. Name→`text`
required, Email→`email` required, Message→`textarea` required, plus
Subject/Phone/Consent when toggled on.

---

## 4. Sealed descriptor

**What it is.** An **encrypted + authenticated** token (framework
`EncryptionService`, AES-256-GCM — confidentiality *and* integrity in one) sealed
into the rendered form as a hidden `_form` input. Encryption keeps the
recipient/config out of page source; the GCM auth tag makes tampering fail
closed.

**Contents:**

```
{
  v:          int,            // descriptor version (schema evolution)
  form_key:   string,         // stable grouping key (see §5)
  form_name:  string,         // human label for admin/CSV
  fields:     FieldDef[],     // normalized fields (§3) — the ONLY validation source
  recipient:  string,         // email; validated at seal time and again post-decrypt
  success:    { message: string, redirect_url?: string },
  spam:       { honeypot_field: string, min_seconds: int, version: int },
  issued_at:  int             // unix seconds
}
```

**Refuse to seal an un-routable form.** Before sealing, resolve `recipient`
(block value, else `forms.default_recipient`) and validate it. If no valid
recipient exists, the sealer **returns nothing** — no descriptor is emitted, the
block renders the disabled notice (in preview/admin and on the live page), and
because there is no `_form` there is **no submit endpoint reachable** with an
un-routable descriptor. Routing is a precondition of a live form, not a
submit-time failure.

**Integrity, not secrecy (corrected framing).** The honeypot field name is
present in the visible markup — it is **not** unguessable. What sealing buys is
**integrity**: the server knows exactly which honeypot field name and which
minimum-elapsed-time rule *this rendered form* expected, and an attacker cannot
alter that. The anti-spam value comes from **bot behavior, elapsed time, and rate
limits** — not from secrecy of the field name.

**Expiry vs. page cache [P1].** The sealed `_form` sits inside cached HTML, so a
cached page could otherwise serve an expired descriptor and produce a dead form.
Rule: compute descriptor expiry at seal time as

```
expiry = issued_at + max(configured_max_age, render_page_cache_ttl + buffer)
```

with `configured_max_age` default **14 days** and `buffer` a small margin (e.g.
1 hour). The render page-cache TTL default is 3600s, so 14 days already dominates;
the `max(...)` makes it self-correct even if an operator raises the cache TTL.
On submit, an **expired or unopenable descriptor** returns a **reload-friendly**
result ("This form expired — reload the page and try again."), never a hard
error. (No per-submit cache purge — the render cache is tag-purged broadly, so
purging one page per stale submit is too blunt; the invariant above is the
primary guarantee, the friendly reload is the fallback.)

---

## 5. `form_key` derivation [P2]

`form_key` groups submissions without a registry. Derive a **stable,
source-scoped** key at seal time:

```
form_key = hash(source_identity, block_id)
```

- `block_id` = the block instance's persisted client-nanoid `id` (unique across
  the tree, stable across edits).
- `source_identity` = resolved by an **explicit fallback order**, first match
  wins, ending in a deterministic final fallback so a form is *never* ungrouped:
  1. `entry:{entry_uuid}` — the block is inside an entry/page.
  2. `region:{region_slug}` — the block is inside a global chrome region.
  3. `route:{route_path}` — a routed page without an entry UUID.
  4. `theme:path:{current_path}` — deterministic final fallback.

  The same `block_id` rendered in a region vs. a page therefore yields **distinct,
  predictable** `form_key`s (different `source_identity`), never an accidental
  cross-context merge.

**Duplicated forms.** Duplicating a block yields a **new** `block_id`, hence a
**new** `form_key` — duplicates get their own submission grouping, never shared.

---

## 6. Rendering (`form.twig`)

**No-JS baseline (must work):** a native
`<form method="post" action="/_forms/submit">` containing:
- the hidden `_form` (sealed descriptor),
- the honeypot input (name from `spam.honeypot_field`),
- a hidden render-timestamp (for the time-trap),
- `_return` = current page path,
- the derived visible fields with proper `<label for>` / `aria-describedby`.

On success the server does **POST-redirect-GET** back to
`_return?form_ok=<form_key>`; the block renders its success state when `form_ok`
matches its own `form_key`.

**redirect_url constraint [P1].** `redirect_url` is treated like a safe-url:
**internal root-relative only** — a single leading slash (e.g. `/thanks`), never
protocol-relative (`//host`), a scheme/host, a bare relative (`contact/thanks`),
or query/fragment-only. Validated at seal time and re-checked post-decrypt.
External URLs are rejected so a form block can never become an open redirect
after submit.

**Progressive enhancement:** a small script in the theme's existing `blocks.js`
intercepts submit → `fetch` → renders inline success message / per-field errors,
no navigation. `aria-live` region announces the result.

**Styling:** theme tokens (`--accent`, etc.), consistent with the rest of the
default theme.

---

## 7. Submission endpoint & flow — `POST /_forms/submit`

Registered as a public route (reserved `/_forms/` prefix, consistent with
`/_preview/`). No page cache (POST); rate-limit applies.

1. **Open descriptor:** decrypt/authenticate `_form`. Fail/expired → reload-
   friendly message (§4).
2. **Guard chain (§8):** honeypot, time-trap, rate-limit.
3. **Validate** submitted values **against sealed `fields[]` only** — never
   against visible field names (visible names can be spoofed). Enforces required,
   email/tel format, select-option membership, max lengths.
4. **Re-validate `recipient`** after decrypt.
5. **Normalize & store** a snapshot (§9, §9a).
6. **Best-effort email** (§10) — never blocks the stored record.
7. **Respond** (unified semantics, §7a).

### 7a. Unified response semantics [P2]

Both the AJAX and no-JS (PRG) paths share the **same** security posture:

| Outcome | AJAX (fetch) | No-JS (PRG) |
|---------|--------------|-------------|
| **Spam reject** | Generic success (`{ ok: true }`) | Redirect to `_return?form_ok=<form_key>` (looks identical to a real success) |
| **Validation error** | `{ ok: false, errors: {field: msg} }` | Redirect back with a **generic failure** flag (e.g. `?form_err=<form_key>`); the block re-renders with a generic "please check your entries" message |
| **Success** | `{ ok: true }` | Redirect to `_return?form_ok=<form_key>` |

No-JS **must not** leak guard rejection reasons or become the weaker path. Guard
reasons are recorded server-side only (§8).

---

## 8. Spam — `FormSubmissionGuard` contract

```
interface FormSubmissionGuard {
    public function check(Request $request, Descriptor $descriptor): GuardResult;
}
```

- **Built-in `DefaultFormGuard`** = honeypot (filled → reject) + min-elapsed-time
  (now − render-timestamp < `spam.min_seconds` → reject) + **per-`form_key`+IP
  rate limit** (config defaults).
- **Silent rejects** return the **same generic success** a real submit shows
  (bots learn nothing) — in **both** AJAX and PRG paths (§7a). The **rejection
  reason** (honeypot / time-trap / rate-limit) is recorded server-side for an
  audit counter / logs, **not** stored as a submission row.
- **CAPTCHA later** = an additional guard provider implementing the same
  contract — no rewrite.

---

## 9. Storage — `form_submissions` table

Owned by `app/Content/Forms/` (migration).

| Column | Notes |
|--------|-------|
| `uuid` | PK |
| `form_key` | grouping (§5) |
| `form_name` | snapshot for admin/CSV |
| `source_url` | page/source if present |
| `fields_snapshot` | JSON: labels + types + keys as sealed at submit time |
| `values` | JSON: **normalized** values (§9a) |
| `descriptor_version` | the `v` at submit time |
| `status` | `unread` \| `read` |
| `ip` | requester IP |
| `user_agent` | requester UA |
| `submitted_at` | timestamp |

Storing the labels/types **snapshot** + `form_name` means submissions stay
readable and exportable **even after the page/form changes**. Silent-reject
counts are recorded separately (audit counter), never as rows.

### 9a. Normalize values, not raw request bags [P2]

Store values **after validation + normalization**: checkbox → boolean, select →
canonical option value, tel/email trimmed/normalized, unknown/extra request keys
dropped. The submission record is clean export data; raw request may be logged
separately if useful but is never the stored record.

---

## 10. Email delivery (soft, best-effort)

A **`FormNotifier`** seam over the framework mailer/notification channel:

- If an email sender is bound, send a notification to `recipient` (subject +
  formatted field values + source URL).
- If **not** bound, **skip silently** — the submission is already stored.
- Send failures are caught and logged; **never** surfaced to the visitor, never
  block storage.
- `recipient` validated at **seal time** and again **post-decrypt**.

---

## 11. Admin Submissions area

**Backend** (`app/Content/Forms/` + admin routes):
- `FormSubmissionsController`: list (filter by `form_key`/form name; read/unread),
  detail, mark-read, delete, **CSV export** (current filter or a selected form).
- `FormSubmissionRepository`: queries + status updates + delete + export cursor.
- Gated by the appropriate content-manage permission.

**Frontend** (Vue admin SPA):
- A **Submissions** page: filterable list, read/unread state with an
  **unread-count badge in the nav**, a detail view (fields + metadata:
  `submitted_at`, form name, source URL, IP/UA), delete, and **CSV export**.
- **CSV** includes submitted field values **plus** metadata: `submitted_at`,
  form name, source URL, IP/UA.
- No bulk actions beyond export/delete.

---

## 12. Configuration keys

- `forms.descriptor_max_age` (default `1209600` = 14 days)
- `forms.descriptor_buffer` (default `3600` = 1 hour margin over cache TTL)
- `forms.min_seconds` (time-trap floor, default e.g. `2`)
- `forms.rate_limit` (per `form_key`+IP: count + window, sane defaults)
- `forms.default_recipient` (fallback when a block leaves `recipient` empty; if
  this is also empty/invalid the form is un-routable and the sealer refuses — §4)

(Descriptor expiry uses `max(descriptor_max_age, render_cache_ttl + buffer)`.)

---

## 13. Testing

**Backend (phpunit):**
- Descriptor **seal/open round-trip**; **tamper** rejection (GCM auth fails);
  **expiry** → reload-friendly result; expiry `max(...)` invariant vs cache TTL.
- Validation **only against sealed fields** — a spoofed visible field name /
  extra key is ignored; required/format/option/length rules enforced.
- Each guard (honeypot, time-trap, rate-limit) rejects and returns **generic
  success** in **both** AJAX and PRG paths; rejection reason recorded, no row
  written.
- **redirect_url** external URL rejected at seal and post-decrypt.
- **Normalized** value storage (checkbox/select/tel/email); snapshot preserved
  after config change.
- Best-effort email: sends when bound, **skips silently** when unbound, never
  blocks storage.
- CSV export contents (values + metadata); endpoint PRG + JSON response shapes.
- `form_key` derivation: duplicated block → distinct key; **source-identity
  fallback order** (same `block_id` in a region vs. a page → distinct
  `form_key`); deterministic `theme:path:` final fallback.
- **Un-routable refusal:** empty block recipient + empty/invalid
  `forms.default_recipient` → sealer returns no descriptor (no `_form` emitted);
  a submit with a manufactured/absent descriptor is rejected.

**Admin (vitest):** Submissions list/filter/read-state/detail/delete/export;
unread badge.

**Render:** `form.twig` emits sealed `_form` + honeypot + no-JS `action`;
disabled notice when the `FormSealerInterface` binding is absent **or** the form
is un-routable (no valid recipient).

---

## 14. Future seams (explicitly deferred)

- **Field-builder UI** — the biggest future seam: an editor to add/remove/reorder
  arbitrary fields of any type, editing the **same** normalized model the sealed
  descriptor and submissions already use. Additive, not a rewrite.
- **Forms registry (Approach B)** — a first-class `forms` table + projector, only
  when forms need per-form dashboards/analytics. Submissions already carry
  `form_key` + `form_name`, so adopting B later doesn't strand data.
- **CAPTCHA guard provider**, **file-upload fields**, **multi-step forms**,
  **scheduled digests / CRM / webhooks**.
