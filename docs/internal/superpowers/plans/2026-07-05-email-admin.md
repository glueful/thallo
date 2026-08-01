# Email Admin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild Settings → Email as a pure client of the email-notification extension's API: DB-backed transport settings, PocketBase-style Mail templates management, and test-sends — retiring Lemma's `.env`-writing controller.

**Architecture:** New `queries/email.ts` over the extension's root-mounted `/email/*` endpoints (authFetch — no OpenAPI attributes upstream); the page splits into a transport card (nested-shape hydrate, flat-key save) and a Mail templates accordion (row-scoped editing, chips from metadata, save/reset, inline 422 violations) plus the test modal; `email.templates.manage` granted to administrator via the folded seed migration.

**Tech Stack:** Vue 3 + Nuxt UI + CodeMirror (`htmlmixed` legacy mode), vitest; PHP only for route/controller removal + the grant fold.

**Spec:** `docs/superpowers/specs/2026-07-05-email-admin-design.md`

## Global Constraints

- Extension endpoints are ROOT-MOUNTED: literal `/email/...` paths via `authFetch` (the rbac.ts `/rbac/roles` precedent) — NOT `runtimeConfig.apiBase` and NOT `/v1/...` (extension route files load with no API prefix; verified against loadRoutesFrom + aegis/payvia).
- Transport GET is NESTED (`settings.mailers.smtp.host`, `settings.from.address`, `password_set`); PUT is FLAT (`host`, `from`, `from_name`, …; `password` only when non-empty).
- Mailer options = `['smtp', ...keys(settings.mailers)]` from the response — no hardcoded `sendmail`.
- Placeholder chips render from API metadata only; display `{{name}}` with the description as tooltip.
- Templates 403 hides the section silently; transport errors use the standard error state.
- Pre-launch fold rule: the grant joins `004_SeedLemmaRolesAndPermissions.php`; dev (`lemma`) + test (`lemma_test`) DBs synced manually via psql; `composer run test:migrate` must report nothing pending.
- Dev wiring: composer path repositories for `../extensions/contracts` + `../extensions/email-notification`; version pins restored at release (release-before-pinning).
- Session conventions: stage only; commit on "commit all"; CHANGELOG; no attribution.

---

### Task 1: Wiring, permission grant, queries module

**Files:**
- Modify: `composer.json` (path repos + `glueful/email-notification: *@dev`), run `composer update glueful/email-notification glueful/extension-contracts`
- Modify: `database/dependent-migrations/004_SeedLemmaRolesAndPermissions.php` — **create-if-missing THEN grant** (hard requirement, not a verify-point: the Aegis catalog sync is CLI-only, so a grant-only fold silently no-ops when the `email.templates.manage` row doesn't exist). Follow the file's own permission-INSERT + grant shape (the SeedSeoPermissions pattern lives in this migration family — read it first); the insert is idempotent by slug so a later `permissions:sync` reconciles cleanly
- Manual: psql grants into dev + test DBs (mirror whatever rows the migration writes — inspect `aegis` role/permission tables first; the established single-row INSERT pattern)
- Create: `admin/src/queries/email.ts`; Delete: `admin/src/queries/emailSettings.ts`
- Test: harness check — if `tests/run-test-migrations`/RBAC re-grant helpers enumerate grants, add the new one

**Interfaces (produced — the whole client surface):**

```ts
export interface EmailTransportSettings {
  default: string
  from: { address: string; name: string }
  bcc: string
  logo_url: string
  mailers: Record<string, { host?: string; port?: number; username?: string; encryption?: string }>
}
export interface EmailSettingsPayload {
  settings: EmailTransportSettings
  password_set: boolean
}
export interface EmailTemplatePlaceholder { name: string; description: string; sample: string }
export interface EmailTemplateRow {
  key: string; label: string; description: string; owner: string
  placeholders: EmailTemplatePlaceholder[]
  subject: string; body: string; overridden: boolean
}
export type EmailSettingsInput = Partial<{
  mailer: string; host: string; port: string; username: string; password: string
  encryption: string; from: string; from_name: string; bcc: string; logo_url: string
}>

const base = '/email'
export async function fetchEmailSettings(): Promise<EmailSettingsPayload>
export async function saveEmailSettings(input: EmailSettingsInput): Promise<EmailSettingsPayload>
export async function testEmailSettings(to: string): Promise<void>   // POST {to} — a REAL send
export async function fetchEmailTemplates(): Promise<EmailTemplateRow[]>
export async function saveEmailTemplate(key: string, input: { subject: string; body: string }): Promise<void>
export async function resetEmailTemplate(key: string): Promise<void>
export async function testEmailTemplate(key: string, to: string): Promise<void>
```

(All `authFetch(base + …)`; unwrap the `{data}` envelope like queries/templates.ts. Save/violation 422s surface through the thrown `ApiError.body.errors`/message — reuse `violationsFrom`-style extraction if the extension's 422 shape matches; verify one save response shape empirically first.)

- [ ] **Step 1:** Path repos + composer update; confirm `vendor/glueful/email-notification` is the symlinked dev copy and `php glueful migrate:run --pending`-equivalent shows the two new extension migrations; run them on dev + test DBs; verify `composer run test:migrate` clean.
- [ ] **Step 2:** Grant fold + manual DB grants; verify with a psql SELECT that administrator carries `email.templates.manage` in BOTH DBs.
- [ ] **Step 3:** Write `queries/email.ts`; delete the old module (its only importer is the page, rebuilt in Task 2 — do the delete in the same change set as the page rewrite if type-check must stay green between tasks).
- [ ] **Step 4:** `pnpm type-check` green (with the page still importing the old module, defer the delete to Task 2 — the plan's gate point is end of Task 2).

---

### Task 2: Page rebuild — transport card + Mail templates accordion

**Files:**
- Modify: `admin/src/pages/templates/components/TemplateEditor.vue` (+`'html'` language via `import { htmlmixed } from '@codemirror/legacy-modes/mode/htmlmixed'` — add to the `modes` map; NO new dependency)
- Rebuild: `admin/src/pages/settings/email/index.vue`
- Create: `admin/src/pages/settings/email/components/TemplateRow.vue`
- Delete: `admin/src/queries/emailSettings.ts` (now unreferenced)
- Test: `admin/src/__tests__/emailSettingsPage.spec.ts` (rewrite)

**Interfaces:**
- Page structure: `UDashboardPanel id="settings-email"` → navbar (title "Email", Save w/ dirty chip for the TRANSPORT form only) → body:
  - **Transport card** — form state hydrates from the NESTED payload:

```ts
const form = reactive<Required<EmailSettingsInput>>({ mailer: 'smtp', host: '', port: '', username: '', password: '', encryption: '', from: '', from_name: '', bcc: '', logo_url: '' })
function hydrate(p: EmailSettingsPayload) {
  const smtp = p.settings.mailers[p.settings.default] ?? p.settings.mailers.smtp ?? {}
  Object.assign(form, {
    mailer: p.settings.default, host: smtp.host ?? '', port: String(smtp.port ?? ''),
    username: smtp.username ?? '', encryption: smtp.encryption ?? '',
    from: p.settings.from.address, from_name: p.settings.from.name,
    bcc: p.settings.bcc, logo_url: p.settings.logo_url, password: '',
  })
}
```

    Mailer `USelect` items = `[...new Set(['smtp', ...Object.keys(p.settings.mailers)])]`; `password_set` hint preserved; dirty/syncing guard copied from Settings → General; save posts only non-empty password; "Send test email" (transport) button opens the TestEmailModal preselected on the transport option (Task 3).
  - **Mail templates card** — `fetchEmailTemplates()` on mount; a 403 (`ApiError.status === 403`) → `templatesVisible = false`, no toast; each row renders `<TemplateRow :template="t" @saved="reload" @reset="reload" />` inside a `UCollapsible` (collapsed by default, `:unmount-on-hide="false"`).
- `TemplateRow.vue` props `{ template: EmailTemplateRow }`, emits `saved`/`reset`:
  - Local buffers `subject`/`body` seeded from props (re-seed on prop change while clean — the dirty-guard idiom).
  - Header content lives in the page's collapsible trigger (label + `UBadge` `custom`/`default` + muted owner when ≠ `glueful/email-notification`).
  - Body: Subject `UInput` + chip strip; `TemplateEditor :language="'html'"` + chip strip; chips = `UBadge` per placeholder, text `{{name}}`, `:title="description"`, `data-test="placeholder-chip-{name}"`.
  - Save → `saveEmailTemplate` (on `ApiError` 422: render `body.errors` list inline `data-test="template-violations"`, no toast); Reset (only `template.overridden`) → confirm → `resetEmailTemplate`.
- [ ] **Step 1: Failing vitest** (rewrite the spec file; mock `@/queries/email`):

```ts
it('hydrates the nested transport shape and saves flat keys (password only when typed)')
it('derives mailer options from settings.mailers')            // ['smtp','log'] fixture → both offered
it('renders template rows with chips and overridden badges')  // chip text {{app_name}}, title = description
it('saves one row and shows 422 violations inline')
it('reset is visible only when overridden')
it('a 403 from templates hides the section without a toast')
```

- [ ] **Step 2–4: fail → implement → pass** + `pnpm type-check && pnpm lint`.

---

### Task 3: Test modal + retirement

**Files:**
- Create: `admin/src/pages/settings/email/components/TestEmailModal.vue`
- Modify: `admin/src/pages/settings/email/index.vue` (launch button on the templates card header)
- Delete: `app/Http/Controllers/EmailSettingsController.php`; Modify: `routes/lemma_admin.php` (drop the three `/settings/email*` routes + the controller import)
- Test: vitest modal case; `vendor/bin/phpunit` (nothing may reference the deleted controller — grep first)

**Interfaces:**
- `TestEmailModal` props `{ templates: EmailTemplateRow[] }`, `v-model:open`: URadioGroup with a LEADING "Transport test (no template)" option (value `''`) plus one option per template (label; value key) + To-address `UInput` (required, email) + Send → `''` ? `testEmailSettings(to)` : `testEmailTemplate(key, to)`; success toast / error toast with the server message (422 = invalid address or transport unconfigured; 502 = transport failure). Launched from BOTH the templates card header and the transport card's test button (which preselects the transport option). `data-test="test-email-modal|test-email-template|test-email-to|test-email-send"`.
- vitest: open modal (teleport — document.querySelector + attachTo), pick the second template, submit → `testEmailTemplate` called with `(key, to)`.
- [ ] **Steps: failing test → implement → pass → retire controller/routes → full `vendor/bin/phpunit` + `composer run phpcs` green.**

---

### Task 4: Gates + CHANGELOG + stage

- [ ] Full gates: `vendor/bin/phpunit && composer run phpcs`; `pnpm vitest run && pnpm type-check && pnpm lint`. (`docs:openapi && pnpm gen:api` — the deleted admin routes leave the schema; regenerate.)
- [ ] CHANGELOG `[Unreleased]`: email admin (DB-backed transport via the extension API — EnvWriter controller retired; Mail templates management with placeholder chips, save/reset, inline lint 422s; template + transport test-sends; `email.templates.manage` granted to administrator).
- [ ] Stage everything. NO commit — wait for "commit all".

---

## Self-Review Notes (completed)

- Spec §1 retirement → Task 3; §2 client + nested/flat + mailer options → Tasks 1–2; §3 grant fold + 403-hides → Tasks 1–2; §4 UI (cards, rows, chips, violations, modal, dirty guards, row-scoped state) → Tasks 2–3; §5 tests all named in Tasks 2–3. Out-of-scope respected.
- Verify-points: the 004 seed's grant-list shape + whether Aegis catalog sync precedes it for EXTENSION permissions (Task 1 — fall back to harness-level grant if ordering bites); the extension 422 body shape for violations (Task 1 empirical check); `htmlmixed` export name in legacy-modes (Task 2); whether anything else imports the deleted controller (Task 3 grep).
- Type consistency: `EmailTemplateRow`/`EmailSettingsPayload` defined once in Task 1 and consumed by Tasks 2–3; `TemplateEditor` language union gains `'html'`.
