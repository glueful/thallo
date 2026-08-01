# Store Settings & Commerce Transactional Emails — Implementation Plan

> Spec: `docs/superpowers/specs/commerce/2026-07-25-store-settings-and-transactional-emails-design.md`
> Executed inline (this session's standing mode). Commits: commerce work amends the unpublished
> 1.6.0 release commit (verify untagged + not on remote FIRST, every time); thallo work lands as
> one commit at the end. Docs stay held. No AI attribution. Never push.

**Goal:** Store-level commerce settings editable from `/commerce/settings` (backed by thallo's
existing `settings` table), and four order-lifecycle emails managed from the existing
Settings › Email page.

## Global constraints (from spec, binding on every task)

- Settings keys EQUAL config keys: `commerce.currency`, `commerce.tax.flat_rate_bps`,
  `commerce.orders.number_format`, `commerce.orders.expiry_minutes`, `commerce.cart.ttl_days`,
  `commerce.reports.low_stock_threshold`.
- Validation: currency = 3-letter ISO, uppercased, LOCKED once any variant exists (422:
  "Currency is locked once priced products exist…"); bps 0–10000; number_format non-empty and
  contains `{seq}`; expiry 5–10080; ttl 1–365; threshold 0–1000.
- Clear = `SettingsStore::forget` (row DELETED — an empty-string row must never shadow config).
- `CommerceSettingsOverride::value()` returns null on ANY failure — never throws.
- Email template keys/owner: `commerce.order_confirmation|order_paid|order_fulfilled|
  order_canceled`, owner `thallo-commerce`. Placeholders: `order_number`, `customer_email`,
  `total`, `item_count`, `status`, `store_name`.
- Idempotency key: `commerce-email:{templateKey}:{orderUuid}`. Mail failure never propagates
  out of a listener. Missing buyer email → silent skip.
- Registrations (override binding, template definitions, email listeners) live INSIDE the
  `thallo.commerce` capability gate, each guarded by interface/container-existence checks so an
  install missing commerce or email-notification boots clean.

---

### Task 1 — commerce: the settings seam (`extensions/commerce`, fold into 1.6.0)

**Files:** create `src/Support/CommerceSettingsOverride.php`, `src/Support/CommerceSettings.php`;
create `tests/Unit/Support/CommerceSettingsTest.php`.

- [ ] `CommerceSettingsOverride` interface exactly as spec §3.2 (docblock carries the
      null-never-throw contract).
- [ ] `CommerceSettings`: static typed getters (spec list). Each: resolve override via
      `container($context)->has(CommerceSettingsOverride::class)`; non-null override → cast
      defensively (currency: trim+uppercase, reject non-`/^[A-Z]{3}$/` → fall back; ints:
      numeric string → int, else fall back); fallback `config($context, key, default)` with the
      config file's own defaults (`USD`, 0, `ORD-{seq}`, 60, 30, 2).
- [ ] Unit tests (CommerceTestCase bindings map): no binding → config default; bound override
      wins; override returning null → config; override returning garbage ("EURO", "abc") →
      config; override that THROWS is not caught here (contract says implementations never
      throw — assert doc'd behavior by NOT testing throw path in commerce; thallo owns that).
- [ ] Run: `vendor/bin/phpunit tests/Unit/Support/CommerceSettingsTest.php` → green.

### Task 2 — commerce: route read sites + variant existence check (fold into 1.6.0)

**Files:** modify `src/Catalog/CatalogService.php:1318`, `src/Tax/FlatRateTaxCalculator.php:16`,
`src/Http/Seller/SellerFinancialController.php:293`, `src/Http/Admin/AdminReportController.php:147`,
`src/Http/Admin/AdminMarketplaceFinancialController.php:145`, `src/Orders/CheckoutService.php:158`,
`src/Cart/CartService.php:57`, `src/Orders/OrderNumberGenerator.php:31`,
`src/Orders/ExpiryService.php:36`; modify `src/Catalog/VariantRepository.php`; extend
`tests/Integration/...` + CHANGELOG.

- [ ] Replace each `config($context, 'commerce.X', default)` with the matching
      `CommerceSettings::x($context)` (imports; no behavior change unbound — the existing suite
      is the regression net).
- [ ] `VariantRepository::anyExistsForTenant(ApplicationContext, string $tenant): bool` —
      `db()->table('commerce_variants')->where('tenant_uuid', '=', $tenant)->limit(1)->get()`
      non-empty. Tests: empty → false; seeded variant → true; other tenant's variant → false.
- [ ] One integration proof that a bound override reaches a real flow (bind a fixed-currency
      override in a checkout-adjacent test; assert the created variant/order carries it).
- [ ] CHANGELOG: extend the `[1.6.0]` section (settings seam + anyExistsForTenant bullets).
- [ ] Full commerce suite green → verify `git tag --list 'v1.6*'` empty AND
      `git branch -r --contains <release-sha>` empty → `git commit --amend` into the 1.6.0
      release commit (keep title, extend body with the settings-seam paragraph).

### Task 3 — thallo pack: override implementation + pack read sites

**Files:** create `packages/thallo-commerce/src/Settings/SettingsStoreCommerceOverride.php`;
modify `packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php`; modify the six
pack read sites (`Shop/ViewModels/CartViewModel.php:159`, `Http/CommerceMetaController.php:70+83`,
`Http/Shop/CartCookie.php:38`, `Http/Shop/ShopBlockDataController.php:160`,
`Http/Shop/ShopCatalogController.php:212`); create
`tests/Integration/Commerce/CommerceSettingsOverrideTest.php`.

- [ ] Confirm `SystemKeys::isSystem` does NOT match `commerce.*` (spec assumption; if it does,
      stop and re-scope).
- [ ] `SettingsStoreCommerceOverride`: whitelist the six keys (const list); anything else →
      null; `SettingsStore::get($key)` in try/catch(\Throwable) → null; '' → null.
- [ ] Bind `CommerceSettingsOverride::class` inside the capability gate in `boot()`, guarded by
      `interface_exists(CommerceSettingsOverride::class)`.
- [ ] Route the pack read sites through `CommerceSettings::…`.
- [ ] Tests: stored row wins over env default through a real request path (`/commerce/meta`
      currency); no row → config default; row deleted → default again; unscoped/failing read →
      null (no throw) via direct resolver construction.

### Task 4 — thallo pack: settings admin API + currency lock

**Files:** create `packages/thallo-commerce/src/Http/CommerceSettingsController.php`; modify the
pack's admin routes file (wherever `CommerceMetaController` registers — same group/middleware);
provider services entry; create `tests/Integration/Commerce/CommerceSettingsEndpointTest.php`.

- [ ] `GET /commerce/settings` (`commerce.view` gate, mirroring meta's middleware):
      `{settings: {key: {value, default, overridden}}, currency_locked}` — effective via
      `CommerceSettings`, default via `config()`, overridden via SettingsStore row presence.
- [ ] `PUT /commerce/settings` (`commerce.manage`): document of six fields; null/'' → forget;
      value → validate (constraints table) → putMany. Per-field 422s
      (`ValidationException::forField` style the pack already uses).
- [ ] Currency lock: on an attempted currency CHANGE, `VariantRepository::anyExistsForTenant`
      → 422 with the spec message; same-value writes pass (idempotent saves must not 422).
- [ ] Tests: GET shape; each validation bound (one accept + one reject per field); clear
      deletes the row; lock fires only with variants present AND only on actual change;
      `commerce.view` vs `commerce.manage` gating.

### Task 5 — thallo pack: email definitions + order-email listener

**Files:** create `packages/thallo-commerce/src/Email/CommerceEmailTemplates.php` (definitions)
and `packages/thallo-commerce/src/Email/SendOrderEmails.php` (listener); provider registration
(capability gate); create `tests/Integration/Commerce/CommerceOrderEmailsTest.php`.

- [ ] Four `EmailTemplateDefinition`s per global constraints; short neutral HTML defaults using
      `{{placeholder}}` (MustacheLite); every placeholder carries a sample
      (`EmailTemplatePlaceholder` contract — read its constructor first).
- [ ] Registration in boot inside the gate: `interface_exists(EmailTemplateRegistry)` +
      `container->has(EmailTemplateRegistry)` guards; register ONCE (owner conflict throws —
      per-request boot re-registration against a shared registry must be idempo-safe: same
      owner re-register is allowed per DefinitionRegistry, verify).
- [ ] `SendOrderEmails`: one class, four handlers (event → template key + subject line);
      placeholder data from the event's order array; `total` via commerce `Money::format` +
      currency code; `store_name` from site settings; missing/blank `email` → return;
      `NotificationService::send` with channels `['email']` + the idempotency key; try/catch
      \Throwable → log, never rethrow.
- [ ] Subscribe via `EventService` inside the gate, only when NotificationService + registry are
      bound (registerLifecycleListeners precedent).
- [ ] Tests: definitions registered with right keys/owner/placeholders; each event type →
      exactly one send with right template + idempotency key (fake NotificationService);
      transport throw swallowed; blank email skips; disabled capability registers nothing.

### Task 6 — admin SPA: Store tab + email pointer

**Files:** create `admin/src/queries/commerceSettings.ts`,
`admin/src/pages/commerce/settings/components/StorePanel.vue`; modify
`admin/src/pages/commerce/settings/index.vue` (tab list — Store first, new default); regen
`docs/openapi.json` + `admin/src/api/*` types if the generator covers pack routes (check how
`/commerce/meta` entered the schema; mirror); extend `admin/src/__tests__/` commerce settings
spec (or create `commerceSettings.spec.ts`).

- [ ] Query layer: `useCommerceStoreSettings` (GET) + save mutation (PUT), normalized
      `{value, default, overridden}` per key + `currency_locked`.
- [ ] StorePanel: six fields per spec §3.5 — currency input (uppercase, disabled+lock note when
      locked), tax as PERCENT (UI ÷/×100, one-decimal step), number format with live preview
      replacing `{seq}` with `1042`, three numeric fields; per-field default-help when not
      overridden + "Reset to default" (sends null); one Save; 422 field mapping; success toast.
- [ ] Pointer row: "Order emails are managed in Settings › Email" linking to
      `/settings/email`.
- [ ] Vitest: renders defaults vs overrides; lock disables currency + shows note; percent↔bps
      round-trip in the save payload; reset sends null; 422 maps to the field; tab order pin
      (Store default).

### Task 7 — verification, docs, commits

- [ ] Vendor overlay: copy the commerce-side changed files into
      `thallo/vendor/glueful/commerce/` (established local-only pattern).
- [ ] Gates: commerce full suite; thallo `tests/Integration/Commerce`; admin vitest full;
      vue-tsc; oxlint; phpcs on touched PHP.
- [ ] thallo CHANGELOG `[Unreleased]` (both parts, incl. the "no order links yet /
      both placed+paid emails send" honesty notes); ledger entry; spec/plan stay held.
- [ ] Commit thallo as one commit; commerce already amended in Task 2. Publish ordering note
      restated in the ledger.
