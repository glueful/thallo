# Account form blocks + Store pages inventory

> **For agentic workers:** implement task-by-task with red-green TDD. Steps use `- [ ]` tracking.

**Goal:** Let a dev/operator build **custom versions of the account pages** by composing form blocks
(`login-form`, `register-form`, `forgot-password-form`) onto CMS pages — with a modern, cache-safe
inline error experience — and adopt the account-pages inventory concept for **commerce's default
pages** (a read-only "Store pages" card in Commerce → Settings).

**Architecture:** The anonymous account forms carry **no per-visitor state** (same-origin provenance
+ rate limit, no session CSRF), so form blocks are byte-identical in the shared page cache. Errors
return to the custom page via a JS-injected `return_to` (PRG, 303) carrying only an allowlisted
code; all messages ship hidden in the cached HTML and a script reveals the matching one. No-JS
submits carry no `return_to` and flow through today's themed pages, where errors render normally.

**Tech stack:** PHP 8.3+, glueful/framework ^1.74.1, Twig, ThalloRuntime, PHPUnit 10, Vue 3 +
Nuxt UI (admin SPA), node (runtime harness).

## Global Constraints (binding on every task)

- **Cache safety.** Every form block renders byte-identical for every visitor: no session CSRF
  token, no per-visitor text, no server-rendered error state. Anonymous POSTs keep their existing
  policy (`account_same_origin` + rate limit); a route-inventory test already enforces that matrix.
- **Never blocks:** logout (session-bound CSRF — the "logout stays on `/account`" pin) and the
  transitional pages (verify / verify-reset / reset-password — mid-flow, reached by redirect with a
  cookie). v1 blocks are exactly `login-form`, `register-form`, `forgot-password-form`.
- **Error codes are an allowlist.** The PRG-back redirect carries ONLY a code from a closed set —
  v1: `credentials`. Never email, provider text, exception messages, or validation payloads in the
  URL. `rate_limited` is designed-for but DEFERRED (the rate-limit middleware 429s before the
  controller; making it PRG-aware is out of scope). Unknown codes reveal nothing.
- **2FA is navigation, not an error code.** A two-factor-challenged login keeps the themed-page 422
  re-render (the storefront's fail-closed message) even when `return_to` is present — there is no
  challenge flow to return to; when one exists, that branch navigates there. No `two_factor` code.
- **Enumeration neutrality holds.** `credentials` covers unknown-email and wrong-password
  identically. Register/forgot keep their neutral fixed redirects — their flows intentionally LEAVE
  the custom page into the themed verify pages; only login has the PRG-back contract.
- **No-JS fallback via JS-injected, path-only `return_to`.** Static block HTML carries NO
  `return_to` field — the login-form enhance script injects it from `location.pathname` at
  hydration. No-JS submits therefore flow through the themed pages where errors render normally.
  `AccountReturnPath::validatePagePath()` is the one posted-return authority: it applies the
  existing safe-relative-path rules and additionally rejects `?` / `#`, so the controller can
  append its one allowlisted query parameter without fragment ambiguity or duplicate-key
  behavior. The richer existing `validate()` contract remains unchanged for post-login `next`
  destinations, which may legitimately carry a query or fragment. Absent/unsafe `return_to`
  falls back to the themed 422.
- **A11y + URL hygiene.** The revealed error node uses `role="alert"` and receives focus; after
  consuming the code the script strips it with `history.replaceState()` (no replay on
  refresh/back). Toasts are reserved for transient confirmations — none in v1.
- **sessionStorage: login email only, page-bound, consume-once + TTL.** On login submit the script
  stashes ONLY the email (never passwords/OTPs/tokens) under
  `thallo:account:refill:{location.host}:{location.pathname}:login` with a timestamp; on
  error-return it refills and DELETES the entry; entries older than 5 minutes are ignored/purged.
  Including the pathname prevents a manually constructed error URL on a different custom login
  page from consuming/refilling the first page's email. Host remains the tenant boundary
  client-side (accepted simplification of the tenant-namespace pin: non-secret value,
  short-lived, no new render seam). Register and forgot-password never write sessionStorage: their
  neutral flows leave the custom page and have no PRG-back consumer.
- **Styling separability.** Blocks use their own `thallo-block-<slug>__*` classes and a dedicated
  `account-blocks.css` (served via the existing `AccountAssetMap` auto-scan + fingerprinted asset
  route) — never `account.css` (page styling stays separable, per the earlier review pin). Mirror
  the mini-cart pattern (stylesheet link + intrinsic sizes).
- **No region palette entries.** Forms are page content, not chrome — `RegionDefinitions::PALETTES`
  is untouched. The three slugs ARE added to `auth-state`'s slot allowlist (its slots enforce via
  `enforce_block_types`), so a dev can show a login form only to signed-out visitors.
- **Store pages inventory is server-computed and allowlisted.** Every path comes from the
  corresponding `ShopUrlGenerator` method (`shopIndex()`, `wishlist()`, `cart()`, `checkout()`) —
  never from `->prefix`, duplicate concatenation, a config read, or the router. Fixed pages only:
  Shop, Wishlist, Cart, Checkout. Parameterized pages (product/category) and per-order
  transitional hops (confirmation/return/cancel) are excluded — same rule as the auth-action
  exclusions.
- **Commit cadence:** commit on `dev`, never push, no AI attribution, hold this plan doc
  uncommitted. Gates per commit: `vendor/bin/phpunit` + `vendor/bin/phpcs` (+ `pnpm run
  type-check`, `pnpm run lint`, `npx vitest run` when the SPA changes). Update CHANGELOG
  [Unreleased] with the feature when the work completes.

---

## Task 1: Store pages inventory (Commerce → Settings)

**Files:** modify `packages/thallo-commerce/src/Http/CommerceSettingsController.php` (extend
`show()`; route is `GET /v1/admin/commerce/settings`), `admin/src/queries/commerceSettings.ts`
(type + normalize `pages`), `admin/src/pages/commerce/settings/index.vue` (render the card); add
`admin/src/pages/commerce/settings/components/StorePagesCard.vue`; tests in
`tests/Integration/Commerce/CommerceSettingsEndpointTest.php` and
`admin/src/__tests__/commerceSettings.spec.ts`.

- [ ] Backend: inject `ShopUrlGenerator` (confirm its container binding in
  `CommerceIntegrationServiceProvider`; it is the same instance the route file resolves) and have
  `show()` additionally return
  `'pages' => [{label: 'Shop', path: $this->urls->shopIndex()}, {label: 'Wishlist', path: $this->urls->wishlist()}, {label: 'Cart', path: $this->urls->cart()}, {label: 'Checkout', path: $this->urls->checkout()}]`.
  There is no response DTO to update: this controller returns an inline `Response::success()`
  payload and its generated OpenAPI 200 response is intentionally untyped today. The endpoint test
  is the backend payload authority; the SPA's hand-written boundary type/normalizer below owns its
  consumer shape. Do not invent a DTO or unrelated OpenAPI schema in this task.
- [ ] Test: `CommerceSettingsEndpointTest` asserts the four pages with the LIVE prefix (change the
  configured prefix in one case and assert the paths follow), and that no parameterized or
  confirmation path ever appears.
- [ ] SPA: `StorePagesCard.vue` mirrors the account page's read-only inventory (label left, linked
  path right, `data-testid="store-page-link"`); mount it on the settings page above/beside
  `StorePanel`. Extend the `commerceSettings.ts` type + normalizer (`pages: []` fallback when the
  server omits it, so the SPA tolerates an older backend).
- [ ] Vitest: the card renders the inventory from the query payload; omitted `pages` renders an
  empty-state without crashing.
- [ ] Commit (gates green).

---

## Task 2: The three account form blocks

**Files:** modify `packages/thallo-account/src/Blocks/AccountBlockTypesContributor.php` (3 new
definitions + add the three slugs to `ALLOWED_CHILD_TYPES`); add
`packages/thallo-account/templates/blocks/login-form.twig`, `register-form.twig`,
`forgot-password-form.twig`, `packages/thallo-account/assets/account-blocks.css`; tests in
`tests/Integration/Account/AccountFormBlocksTest.php` (new) + an `AccountCacheIsolationTest`
addition.

**Block schemas** (minimal, YAGNI):
- `login-form`: `heading` (string, optional), `next` (string, optional — the post-login target,
  emitted as the form's hidden `next`; the SERVER validates it on POST via the existing
  `safeNext()`, so a hostile stored value is simply ignored), `show_links` (boolean, default true —
  the forgot-password/register links).
- `register-form`: `heading` (string, optional).
- `forgot-password-form`: `heading` (string, optional).

**Templates:** each renders the same fields as its themed counterpart (login: email + password;
register: first/last name + email + password; forgot: email), posting to the standard endpoints
(`/account/login`, `/account/register`, `/account/forgot-password`) with block-scoped classes and a
`<link>` to the fingerprinted `account-blocks.css`. **Only `login-form` is enhanced in v1:** its
root carries `data-account-form="login"`, it ships the hidden
`data-account-error="credentials"` node, and it emits
`<script src="/_account/assets/account-forms.js" defer>`. Register and forgot-password are plain
server forms: no error node, script, `data-account-form`, `return_to`, or sessionStorage behavior.
No template contains `{{ csrf }}` or any per-visitor state.

- [ ] Write `AccountFormBlocksTest` first: each block renders its form with the right action +
  fields; the rendered HTML is byte-identical across an anonymous and a cookie-bearing render (the
  cache-safety pin); `login-form` emits the stored `next` as a hidden field; static markup contains
  NO `return_to` and NO csrf token; only login emits the credentials node and
  `account-forms.js`; register/forgot emit neither; the capability-off boot falls back to the
  missing-template path (mirror the auth-state test).
- [ ] Add the three definitions + templates + CSS; register nothing new in the provider (the
  contributor and asset map already auto-cover them).
- [ ] Extend `ALLOWED_CHILD_TYPES` and update `AccountBlockTypesContributor`'s class/constant
  docblocks: the allowlist is no longer “passive / never self-hydrating”; it is an explicit set of
  vetted, cache-safe blocks, including the account-owned login enhancement, and remains an
  authorisation-independent presentation boundary. Assert in the existing enforcement test that
  `login-form` now validates inside an `auth-state` `signed_out` slot (and a disallowed sibling
  still 422s).
- [ ] **Existing installs need a block-type re-sync.** Runtime enforcement reads the STORED
  `block_types` row's schema (`BlockTypeRepository::schemasBySlug()`), not the contributor — so an
  already-synced `auth-state` row keeps its old 5-slug allowlist and would hard-reject `login-form`
  until `php glueful thallo:tenant:blocks:sync --all` re-applies the definition (fingerprint-based;
  it never force-overwrites customized rows). After landing: pg_dump `block_types` and
  `starter_provenance` first (standing practice), run the sync (which also creates the three new
  block-type rows), and inspect its report. `skipped_customized` for `thallo-account:auth-state`
  means the allowlist was NOT updated: preserve the operator's custom fields but explicitly merge
  `login-form`, `register-form`, and `forgot-password-form` into BOTH stored slot allowlists before
  proceeding. Verify from fresh `BlockTypeRepository::schemasBySlug()` state that all three new
  block types exist and both `auth-state` slots permit all three — report success alone is not the
  gate. Then run `php glueful render:cache:clear` and reload/restart long-lived web/worker
  processes: `schemasBySlug()` is memoized per repository instance, so an already-running process
  can otherwise retain the pre-sync schema even though the database is correct.
- [ ] Commit (gates green).

---

## Task 3: PRG-back inline errors (`return_to` contract + runtime)

**Files:** modify `packages/thallo-account/src/AccountReturnPath.php` (add the path-only return
validator) and `packages/thallo-account/src/Http/AccountAuthController.php` (`login()`); add
`packages/thallo-account/assets/account-forms.js`; tests in
`tests/Unit/Account/AccountReturnPathTest.php`,
`tests/Integration/Account/AccountFlowTest.php` (controller contract), and
`tests/Integration/Account/AccountFormBlocksTest.php` (node harness, mirroring
`AccountCacheIsolationTest`'s executable-JS pattern).

**Controller contract (`login()` only):**
- [ ] Add `AccountReturnPath::validatePagePath(string): ?string`: delegate first to `validate()`,
  then reject an otherwise-safe value containing `?` or `#`. Existing `validate()` / `resolve()`
  behavior and tests remain byte-compatible for richer `next` destinations. Unit-test a plain
  custom path as accepted and query/fragment return paths as rejected.
- [ ] Read + validate posted `return_to` via `validatePagePath()`. On
  `AuthenticationException` (invalid credentials) with a VALID path: `303` to
  `$returnTo . '?account_error=credentials'`. There is no append/merge branch because
  `return_to` is path-only by contract. Absent/unsafe `return_to`, or the 2FA branch: today's
  themed 422 re-render, unchanged. Success is unchanged (`next` precedence → 303).
- [ ] Tests: safe path-only `return_to` + wrong password → 303 back with exactly
  `account_error=credentials`; hostile (`//evil.example`), query-bearing, and fragment-bearing
  `return_to` values → themed 422 (never echoed); 2FA challenge + valid `return_to` → themed 422
  with NO code; register/forgot behavior unchanged.

**`account-forms.js`** (login-only ThalloRuntime module `account-forms`, selector
`[data-account-form="login"]`, exactly-once `window.thalloAccountForms` guard + catch-up enhance,
mirroring `account.js`):
- [ ] On enhance: inject `<input type="hidden" name="return_to" value="{location.pathname}">` into
  the login form; on submit, stash `{email, t}` under
  `thallo:account:refill:{location.host}:{location.pathname}:login` (email only).
- [ ] On load with `?account_error=<code>`: if the code is in the allowlist and a matching hidden
  `[data-account-error]` node exists, reveal it (`role="alert"`), move focus to it, refill the
  email from the stash (consume-once: delete after read; ignore entries older than 5 min), then
  strip the param with `history.replaceState()`. Unknown code or no matching node → reveal
  nothing, still strip.
- [ ] Node harness tests: return_to injection; reveal+focus+replaceState for a known code; unknown
  code reveals nothing; stash → refill → consumed (second load refills nothing); expired stash
  ignored/purged; two custom login paths on the same host cannot read each other's stash; register
  and forgot-password forms receive no enhancement; the exactly-once guard.
- [ ] Commit (gates green) + CHANGELOG [Unreleased] entry covering Tasks 1–3.

---

## Not in scope (deferred, agreed)

- `rate_limited` PRG-back (needs a PRG-aware rate-limit response — middleware-level).
- A 2FA challenge flow (the fail-closed themed branch stands; navigation upgrades when it exists).
- Custom-page overrides replacing the themed `/account/*` routes themselves (the themed pages stay
  canonical; blocks compose ADDITIONAL pages).
- Toast presentation (reserved for transient confirmations later).
- Commerce form/checkout blocks (commerce already ships its own block set).
