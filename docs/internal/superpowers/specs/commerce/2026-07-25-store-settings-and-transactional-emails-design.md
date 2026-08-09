# Store Settings & Commerce Transactional Emails — Design

Date: 2026-07-25. Status: approved direction (user: "proceed with the spec → plan → build flow"),
built on the session's infrastructure survey. Two parts, one theme: **manage commerce from the
admin by dogfooding thallo's existing tables and pipelines** — no new storage, no new
template-editing UI.

## 1. Problem

- Every store-level commerce setting (currency, tax rate, order-number format, cart TTL, order
  expiry) is env/config-only. Changing the store currency requires editing `.env` and a deploy —
  unacceptable for a merchant product, and invisible in the admin.
- Commerce sends **no transactional email at all**. `OrderPlaced` / `OrderPaid` /
  `OrderFulfilled` / `OrderCanceled` are dispatched with full order payloads (buyer email
  included), but nothing listens. Merchants coming from Woo/Shopify expect order emails as table
  stakes — and expect to edit their wording.

## 2. Verified contracts (what this design is built on)

Read before designing; every claim below was checked in source this session.

1. **`settings` table + `App\Settings\SettingsStore`** (`app/Settings/SettingsStore.php`): plain
   key/value; `get(key): ?string`, `putMany(array $pairs)`, `forget(key)` (DELETEs the row so the
   config/env fallback shows through — the homepage-setting spec §0 discipline), memoized per
   request. The table is **tenant-owned under tenancy enforcement** (retrofit widens it; system
   keys are reconciled to the unscoped SystemChannel) — so stored values are per-workspace, which
   is exactly right for a per-workspace store currency.
2. **`ApplicationContext::overrideConfig()` is boot-only** — it THROWS after `markBooted()`
   ("mid-request config changes would create split-brain services"). A runtime config overlay is
   therefore impossible by design; the alternative seam is §3.2.
3. **Commerce host-seam precedent**: `CommerceTenantResolution` — an interface commerce resolves
   from the container with a sentinel fallback, bound by thallo. The settings seam mirrors it.
4. **Email pipeline** (`glueful/email-notification`, installed): `EmailTemplateRegistry`
   (`register(EmailTemplateDefinition ...)`) where ANY package registers
   `EmailTemplateDefinition(key, label, description, defaultSubject, defaultBody, placeholders,
   owner)`; keys must match `[a-z0-9][a-z0-9._-]*`; re-registering a key under a DIFFERENT owner
   throws. Per-template overrides live in its own `email_templates` table; the existing
   **Settings › Email** admin page lists every registered definition with editing, placeholders,
   partials, and test-send. Registered definitions appear there with zero new UI.
5. **Send path** (`app/Signup/SignupMailSender.php` is the canonical consumer):
   `NotificationService::send($type, $recipient, $subject, ['template_name' => …, …placeholder
   data…], ['channels' => ['email'], 'idempotency_key' => …])`. `template_name` routes through
   the registry → saved override or default. `idempotency_key` gives at-most-once sends.
6. **Order events**: `OrderPlaced` (CheckoutService, guarded against double dispatch on payment
   retry), `OrderPaid` (OrderPaymentService), `OrderFulfilled` (AdminOrderController),
   `OrderCanceled`. Each carries the full order row array — `email` (buyer), `order_number`,
   totals, `currency`. Dispatch goes through `EventService` when bound.
7. **Pack listener precedent**: `CommerceIntegrationServiceProvider::registerLifecycleListeners`
   — guard on `interface_exists` + `EventService` bound, subscribe explicitly.
8. **Editable-key read sites** (all mid-request, i.e. AFTER tenant resolution): 9 in
   glueful/commerce (`commerce.currency` ×5, `commerce.tax.flat_rate_bps`,
   `commerce.cart.ttl_days`, `commerce.orders.number_format`,
   `commerce.orders.expiry_minutes`) and 6 in thallo-commerce (currency ×4,
   `commerce.cart.ttl_days`, `commerce.reports.low_stock_threshold`).
9. **Release state**: commerce 1.6.0 is cut but neither tagged nor pushed — additive commerce
   changes fold into it. The publish-1.6.0-before-pushing-thallo ordering already stands.

## 3. Part A — Store settings

### 3.1 The editable set (v1)

| Settings key (`settings` table)        | Backs config key                        | Validation |
|----------------------------------------|-----------------------------------------|------------|
| `commerce.currency`                    | `commerce.currency`                     | 3-letter ISO code (uppercased); **locked once any variant exists** (§3.4) |
| `commerce.tax.flat_rate_bps`           | `commerce.tax.flat_rate_bps`            | int 0–10000 |
| `commerce.orders.number_format`        | `commerce.orders.number_format`         | non-empty, must contain `{seq}` |
| `commerce.orders.expiry_minutes`       | `commerce.orders.expiry_minutes`        | int 5–10080 |
| `commerce.cart.ttl_days`               | `commerce.cart.ttl_days`                | int 1–365 |
| `commerce.reports.low_stock_threshold` | `commerce.reports.low_stock_threshold`  | int 0–1000 |

Settings-table keys deliberately EQUAL the config keys — one vocabulary, no mapping table.
Values are stored as strings (the table's shape); the reader casts. Shipping methods (a config
array) stay config-only in v1 — zones/classes/rates already have DB-backed CRUD tabs.

### 3.2 The seam: `CommerceSettingsOverride` (glueful/commerce, folds into 1.6.0)

Because a runtime config overlay is impossible (§2.2), commerce gains its second host seam:

```php
namespace Glueful\Extensions\Commerce\Support;

interface CommerceSettingsOverride
{
    /** Raw override for a commerce config key ('commerce.currency', …) or null = no override.
     *  MUST return null (never throw) when it cannot answer — absent tenant context, missing
     *  table, any storage failure — so config()/env stays the always-working fallback. */
    public function value(ApplicationContext $context, string $key): ?string;
}

final class CommerceSettings
{
    public static function currency(ApplicationContext $context): string;          // ISO code
    public static function taxFlatRateBps(ApplicationContext $context): int;
    public static function orderNumberFormat(ApplicationContext $context): string;
    public static function orderExpiryMinutes(ApplicationContext $context): int;
    public static function cartTtlDays(ApplicationContext $context): int;
    public static function lowStockThreshold(ApplicationContext $context): int;
}
```

`CommerceSettings::x($context)` = container-bound `CommerceSettingsOverride` (if any) consulted
first, cast defensively (an unparseable override falls back too), then
`config($context, key, default)`. No binding → pure config passthrough, so commerce remains
fully app-agnostic and every existing install behaves identically. All 15 read sites (§2.8)
switch to `CommerceSettings::…`; the config file remains the single source of defaults.

**Timing safety**: every read site executes mid-request after tenant middleware, so a
tenant-scoped implementation is always called with tenant context available. Scheduled/CLI
contexts (order expiry sweep) may lack it — that is exactly the "return null, fall back to
config" branch, by contract.

### 3.3 Thallo's implementation (thallo-commerce pack)

`SettingsStoreCommerceOverride implements CommerceSettingsOverride`: whitelists the six §3.1
keys (anything else → null), reads through `SettingsStore::get(key)` inside a try/catch that
returns null on ANY throwable (tenancy query guard, absent table, no tenant context). Bound in
the pack's services **outside** the capability gate is wrong here — the binding IS user-facing
capability behavior, so it registers inside the `thallo.commerce` gate; with the capability
disabled, commerce (if mounted at all) reads pure config.

### 3.4 Admin API (pack controller, `CommerceMetaController` pattern)

- `GET /commerce/settings` (gate: `commerce.view`): for each §3.1 key →
  `{value: effective, default: config default, overridden: bool}` + `currency_locked: bool`.
- `PUT /commerce/settings` (gate: `commerce.manage`): full document of the six fields; each
  field either a value (validated per §3.1, then `putMany`) or `null`/empty (→ `forget`, i.e.
  return to default). Validation failures are per-field 422s.
- **Currency lock (REVISED 2026-07-25** — user: "I'm still setting my store up, why lock?"**)**:
  the lock's predicate is `OrderRepository::anyExistsForTenant` — recorded ORDERS, the durable
  money history — never mere catalog contents. A setup store full of draft products changes
  currency freely; while unlocked, an actual change ALSO rewrites every variant's currency code
  via `VariantRepository::reassignCurrencyForTenant` (amounts kept exactly as typed —
  reinterpretation, never conversion; required because checkout hard-rejects store↔variant
  currency mismatches). The GET response carries `has_priced_products` so the UI warns —
  "prices keep their numbers ($700.00 becomes GH₵700.00)" — without locking. Once any order
  exists, an actual change 422s ("Currency is locked once orders exist…"); idempotent saves
  never 422.

### 3.5 Admin UI

`/commerce/settings` gains a **Store** tab (first tab, new default): a small form of the six
fields — currency (text, uppercased, disabled + lock explanation when `currency_locked`), tax
rate (entered as percent, stored bps: UI divides/multiplies by 100), order number format with a
live preview (`ORD-{seq}` → "ORD-1042"), expiry/TTL/threshold numeric fields. Each field shows
its default as help when not overridden ("Default: USD — from server config") and a per-field
"Reset to default" affordance. One Save; success toast; server field errors map inline.
The tab also carries a pointer row: "Order emails are managed in **Settings › Email**" (link) —
discoverability for Part B without duplicating the template UI.

### 3.6 Settings-surface roadmap (survey 2026-07-25 — user: "does this cover ALL commerce settings?")

The FULL commerce config surface, classified. "Store" = the current tab; each group names its
natural build-in.

| Group | Keys | Disposition |
|---|---|---|
| Store basics | currency, tax bps, number format, expiry, cart TTL, low-stock | **SHIPPED** (Store tab) |
| Store identity | `commerce.seller.name/address/tax_id` (invoice header — the "store location" data) | **SHIPPED** (Store tab, same whitelist mechanics; ConfigSellerIdentityProvider reads through the seam) |
| Downloads | `downloads.url_ttl` | Easy Store-tab addition |
| Fallback shipping | `shipping.methods` (config array) | Skip — zones/classes/rates tabs are the real system; a JSON-array editor is a footgun |
| Order emails | `commerce.email.enabled` + per-template toggles | Commerce's own dormant mailer; thallo's sender STANDS DOWN when it's on (double-send guard). Per-template on/off in the UI = follow-up |
| Payments (payvia) | gateway enablement, keys, webhooks | **SHIPPED** as a full Payments TAB (user decision 2026-07-25, reversing the env-only posture): default gateway, enable toggles, AND secret/webhook keys editable in-admin — encrypted at rest (AES-256-GCM, AAD = settings key), write-only on the wire ({set, source} booleans out; blank=keep, null=clear). Flows through payvia 2.2.0's PayviaSettingsOverride seam; SettingsStorePayviaOverride decrypts on read. Payvia degrades to manual collection while the default gateway is keyless. CONSTRAINT: credentials are installation-global (webhook verification precedes tenant context) — pin to global scope when tenancy enforcement lands. **Status update (2026-08-08): this enforcement-time obligation is SATISFIED** — the [platform-payments-settings program](../2026-08-05-platform-payments-settings-design.md) moved gateway credentials OUT of Commerce entirely: `SettingsStorePayviaOverride` and this Payments tab are RETIRED, replaced by an app-owned `App\Settings\PlatformPayviaSettingsOverride` over an unscoped system channel and a neutral `Settings → Payments` page, resolved independently of ambient tenant context and of the `thallo.commerce`/`thallo.subscriptions` capabilities (proven end to end in `tests/Integration/Settings/PlatformPaymentsRegressionTest.php`). This row's disposition is historical from here. |
| Marketplace master | `marketplace.enabled` | BOOT-TIME wiring (route groups/services register conditionally) — cannot be a runtime setting; env-only, documented |
| Marketplace per-workspace | `commerce_marketplace_settings` row (MarketplaceMode) + commission/reserve policy | Already DB-backed with its own admin surface — a future "Marketplace" tab fronting those endpoints, NOT the settings store |
| Ops tuning | rate limits, payout batching/backoff, webhook retry/retention, seller API-key retention | Deliberately env-only: infra knobs, wrong blast radius for an admin UI |
| Tenancy | `commerce.tenancy.enabled` | Boot-time + owned by thallo's own enablement flow — never this page |

Also revised in this pass: currency is a curated DROPDOWN (env value outside the list still
renders as an extra option), and the Commerce nav places Settings LAST.

## 4. Part B — Commerce transactional emails

### 4.1 Template definitions (thallo-commerce registers; owner `thallo-commerce`)

Four templates, keyed `commerce.order_confirmation`, `commerce.order_paid`,
`commerce.order_fulfilled`, `commerce.order_canceled` — labels "Order confirmation", "Payment
received", "Order fulfilled", "Order canceled". Shared placeholder set (samples included per
the `EmailTemplatePlaceholder` contract):

- `order_number` — e.g. "ORD-1042"
- `customer_email` — the buyer address
- `total` — formatted grand total with currency (built via commerce `Money::format` + code)
- `item_count` — number of lines
- `status` — the order's status after the event
- `store_name` — thallo site name (`SettingsStore`/site config)

Default bodies are short, neutral HTML consistent with the email extension's built-ins. No
order links in v1: the storefront confirmation page authenticates by guest-order cookie, so an
emailed link would not open for the buyer — an honest omission, noted as the follow-up
(tokenized order-status links). Registration happens once in the pack's boot, inside the
`thallo.commerce` capability gate, guarded by `interface_exists(EmailTemplateRegistry)` +
container-bound check so an install without the email extension boots clean (soft dependency —
the forgot-password precedent).

### 4.2 Sending (listeners in thallo-commerce)

One listener class, `SendOrderEmails`, subscribed to the four commerce order events —
registered inside the capability gate, only when `EventService` AND `NotificationService` AND
the registry are all bound. Per event:

- Build placeholder data from the event's order array (it carries `email`, `order_number`,
  totals, `currency` — §2.6).
- `NotificationService::send('commerce_order_email', recipient(email), subject, ['template_name'
  => key, …placeholders], ['channels' => ['email'], 'idempotency_key' =>
  "commerce-email:{key}:{order_uuid}"])` — the idempotency key makes payment-retry double
  dispatch and admin double-clicks at-most-once per template×order.
- **A mail failure never fails commerce**: the listener wraps the send in try/catch and logs —
  checkout/fulfillment must succeed even with a broken transport (the notification system's own
  retry queue is the recovery channel).
- Skip silently when the order has no usable email address.

`OrderPlaced` AND `OrderPaid` firing near-simultaneously for card checkouts is accepted v1
behavior (Woo sends two as well); merchants can blank a template's body… no — an emptied
override still sends. v1 accepts both emails; per-template on/off switches are the noted
follow-up if it annoys.

### 4.3 Management

Nothing to build: the four definitions appear automatically in **Settings › Email** with
editing, placeholder chips, and test-send (per-workspace overrides where that page is
tenant-scoped, matching its existing behavior).

## 5. Non-goals (v1)

- No per-template enable/disable switches; no order-status links in emails (both noted
  follow-ups above).
- No shipping-methods editing (config array; zones/classes/rates tabs already exist).
- No admin toggle for the `thallo.commerce` capability (stays `config/thallo.php`).
- No currency MIGRATION — the lock exists precisely because stored minor units are
  currency-relative.
- No download-delivery email (digital orders ride the confirmation).
- No glueful/commerce email sending — commerce stays email-free; thallo owns delivery.

## 6. Testing

- **commerce (folds into 1.6.0's suite)**: `CommerceSettings` unit tests (no binding → config
  passthrough; binding wins; unparseable/throwing override falls back; casts);
  `VariantRepository::anyExistsForTenant` (empty/present/cross-tenant); one read-site
  integration proof (e.g. checkout uses the bound override's currency).
- **thallo integration**: settings endpoint GET shape (effective/default/overridden,
  currency_locked); PUT validation per field; clear-restores-default (row DELETED, not '');
  currency lock 422 once a variant exists; override resolver returns null without tenant
  context (no throw). Email: definitions registered exactly once with owner
  `thallo-commerce`; OrderPlaced/Paid/Fulfilled/Canceled each produce one
  `NotificationService::send` with the right template key + idempotency key; transport failure
  doesn't propagate; no-email order skips.
- **admin vitest**: Store tab rendering (defaults vs overrides, lock state, percent↔bps
  conversion, number-format preview), save payload shape, reset-to-default, 422 mapping;
  settings-page tab pins updated.

## 7. Rollout order

1. glueful/commerce additions (seam + read-site routing + `anyExistsForTenant`) → fold into the
   unpublished 1.6.0 release commit (verified untagged/unpushed before amending, as before).
2. thallo pack + app + admin work, with the vendor overlay for live verification.
3. Standing constraint unchanged: **publish commerce 1.6.0 before pushing thallo dev**.
