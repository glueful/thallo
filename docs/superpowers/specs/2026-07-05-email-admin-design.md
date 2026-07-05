# Email Admin (Settings → Email rebuild) — Design

**Date:** 2026-07-05
**Status:** Draft for review
**Depends on:** `glueful/email-notification` managed templates & settings
(implemented; spec in that repo, `2026-07-05-email-templates-design.md`) +
`glueful/extension-contracts` `Email/` namespace. **Release order rule:**
contracts releases first, then email-notification 1.11 pins it, then Lemma
bumps its `^1.10.0` requirement — dev wiring uses composer path repositories
until then (the commerce-plan pattern).

## Goal

Settings → Email becomes the full email admin, PocketBase-style: DB-backed
transport settings (no more `.env` writes), a **Mail templates** section
(accordion per registered template — subject, HTML body, placeholder chips,
save/reset-to-default), and test-sends for both the transport and individual
templates. Lemma writes NO email logic — the page is a pure client of the
extension's API.

## Contract

### 1. What retires (breaking, sanctioned — the page is the only consumer)

- `App\Http\Controllers\EmailSettingsController` (the `EnvWriter`-based one),
  its three `/settings/email*` routes in `routes/lemma_admin.php`, and the
  old `queries/emailSettings.ts` module. Nothing else in Lemma reads them.
- With it dies the "test-send builds a fresh transport from the just-written
  .env" workaround — stored settings ARE the live settings now.

### 2. Client plumbing

- New `admin/src/queries/email.ts` against the EXTENSION endpoints — which
  are ROOT-MOUNTED at `/email/...` (P1 review pin, resolved by evidence:
  `ServiceProvider::loadRoutesFrom()` includes extension route files raw with
  no API prefix, and that is the ECOSYSTEM contract — aegis mounts `/rbac/*`,
  payvia `/payvia/*`, and the SPA's own `rbac.ts` already calls `/rbac/roles`
  verbatim. Hardcoding `/v1` into the extension would bake an app-owned
  configurable (`API_PREFIX`) into a package that must work everywhere, so
  the fix lands HERE: the client calls `/email/...`). `authFetch` with
  literal paths (no OpenAPI attributes upstream — the rbac.ts raw-fetch
  pattern; a typed-client migration can follow if the extension gains
  attributes).
  - `fetchEmailSettings(): {settings, password_set}` — settings is the
    extension's redacted `effectiveConfig()` shape (nested: `default`,
    `from.address/name`, `bcc`, `logo_url`, `mailers.smtp.{host,port,username,encryption}`;
    secrets stripped server-side).
  - `saveEmailSettings(partial)` — FLAT keys per the extension's PUT contract
    (`mailer`, `host`, `port`, `username`, `password?`, `encryption`, `from`,
    `from_name`, `bcc`, `logo_url`); password sent only when non-empty.
  - `testEmailSettings(to)` — BOTH test endpoints now require and validate a
    `to` address and perform a REAL send (P1 review pin, fixed in the
    extension: the endpoints were render/read stubs); `fetchEmailTemplates()`,
    `saveEmailTemplate(key, {subject, body})`, `resetEmailTemplate(key)`,
    `testEmailTemplate(key, to)`.
- **Mailer options** come from the response: the names under
  `settings.mailers` (plus `'smtp'`), matching the extension's save-time
  validation set — the old hardcoded `smtp|sendmail` select dies (sendmail
  was never actually supported).

### 3. Permission

- The extension declares `email.templates.manage` via `permissions()` —
  but the Aegis catalog sync is CLI-driven (`permissions:sync` /
  `aegis:bootstrap-admin`), NOT part of migrations (P2 review pin, verified).
  A grant-only fold would silently no-op when the permission row doesn't
  exist yet. So the fold follows Lemma's OWN established pattern
  (`SeedSeoPermissions` et al. INSERT permission rows in migrations):
  `004_SeedLemmaRolesAndPermissions.php` gains a create-if-missing for the
  `email.templates.manage` permission row (idempotent — a later catalog sync
  reconciles by slug) AND the administrator grant. Dev + test databases
  synced manually (the established psql step); the per-test RBAC re-grant
  harness updated if it enumerates grants.
- The page treats a 403 from the templates endpoints as "section hidden"
  (an operator without the grant still sees nothing broken), and any
  transport-settings 403 as the standard error state.

### 4. UI (one page, `settings/email/index.vue`, UDashboardPanel layout)

- **Transport card** (reworked, same fields): mailer select (from §2
  options), host/port/username/encryption/from/from_name/bcc/logo_url,
  password field with the existing `password_set` affordance (blank keeps
  the current one). Save → PUT; the navbar keeps Save with the dirty chip;
  the dirty/syncing watch guard pattern from Settings → General applies.
  A "Send test email" button on this card fires `POST /email/settings/test`
  (plain transport test).
- **Mail templates card** (new, the PocketBase reference): one
  `UCollapsible` row per template from `GET /email/templates` — header:
  label + `overridden` badge (`custom` primary / `default` neutral) +
  owner as a muted hint when not the email extension's own. Body:
  - **Subject** — `UInput`, placeholder chips underneath.
  - **Body (HTML)** — the templates-page `TemplateEditor` (CodeMirror) with
    an `html` language mode (add `htmlmixed` from the already-installed
    `@codemirror/legacy-modes` — the same additive pattern as css/json/js);
    chips repeated under the editor.
  - **Placeholder chips** — display-only `{{name}}` badges with the
    DESCRIPTION as tooltip (metadata from the API, never parsed from the
    body — matching the extension's contract).
  - Per-template **Save** (PUT; a 422 renders the engine violations —
    unbalanced `{{#if}}` — inline, the templates-page violations pattern)
    and **Reset to default** (DELETE, shown only when `overridden`, with a
    confirm).
- **Send test email modal** (the PocketBase modal): launched from the Mail
  templates card header AND from the transport card's test button — radio
  list with a leading "Transport test (no template)" option plus one entry
  per template, and a "To email address" input. Transport option →
  `POST /email/settings/test {to}`; template option →
  `POST /email/templates/{key}/test {to}` (the server renders with
  placeholder SAMPLES and REALLY sends — the domain policy applies).
  Success/failure toasts; 422 (invalid address / transport unconfigured)
  surfaces its message; 502 = transport failure.
- Per-template state is local to each accordion row (row-scoped
  editing buffers seeded from the API values; a row's Save only submits that
  row). The transport form keeps the page-level Save.

### 5. Testing

- vitest (`emailSettingsPage.spec.ts` rewrite): transport form hydrates from
  the nested settings shape and saves FLAT keys (password only when typed);
  mailer options derive from `settings.mailers`; templates accordion renders
  from a mocked list with chips + overridden badges; per-template save
  payload; reset visible only when overridden; violations render inline on a
  422; test modal picks a template and posts the address; templates-403
  hides the section without an error toast.
- PHP: no new Lemma endpoints — only the grant fold; the existing RBAC
  test-harness re-grant list gains `email.templates.manage` if applicable.

## Out of scope

- Extension-side anything (done); WYSIWYG editing; template creation from
  the admin (registry-declared only); per-locale templates; typed-client
  migration for the email endpoints (needs OpenAPI attributes upstream).

## Files touched

`admin/src/queries/email.ts` (new), `admin/src/queries/emailSettings.ts`
(deleted), `admin/src/pages/settings/email/index.vue` (rebuilt, + local
components `TemplateRow.vue` / `TestEmailModal.vue`),
`admin/src/pages/templates/components/TemplateEditor.vue` (+`html` mode),
`app/Http/Controllers/EmailSettingsController.php` (deleted),
`routes/lemma_admin.php` (routes removed),
`database/dependent-migrations/004_SeedLemmaRolesAndPermissions.php`
(+grant, folded) + manual dev/test DB grant sync, composer path-repo dev
wiring (reverted to version pins at release), vitest, CHANGELOG.
