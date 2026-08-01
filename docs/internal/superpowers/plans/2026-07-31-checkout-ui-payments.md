# Checkout UI + payment initiation seam (payvia / commerce / thallo)

> **For agentic workers:** implement task-by-task with red-green TDD. Steps use `- [ ]` tracking.
> Tasks 1–2 live in FRAMEWORK-SIDE repos (`extensions/payvia`, `extensions/commerce`), Task 3 in
> thallo. Respect each repo's own suite + conventions.

**Goal:** A real checkout page (quote-driven shipping + totals, honest payment posture, proper
fields) on top of a working hosted-payment leg: Paystack AND Stripe redirect flows via one
payable-type-agnostic initiation seam.

**Architecture:** payvia documents well-known `PayableReference::metadata` keys (`email`,
`callback_url`, `cancel_url`) and its collector lifts them into gateway options ONCE -- any payable
builder (orders today; subscriptions/invoices later) supplies its own values, so nothing is
order-specific. Commerce feeds the convention for orders through an optional, host-bound
`OrderPaymentReturnUrlProvider`: email always; trusted return/cancel URLs when a host supplies the
provider. The provider receives the completed order, so it serves initial placement AND the public
payment-retry path without placeholder substitution or request-host trust. Stripe gains
`InitiationCapableGateway` via Checkout Sessions — its `verify()` already normalizes `cs_`
sessions, so only creation is new. Thallo's checkout page then renders the flow it already
receives: quote → place → `redirect | manual | reference | unavailable`.

**Tech stack:** PHP 8.3+, payvia (extensions/payvia, PHPUnit + mocked HttpClient), commerce
(extensions/commerce), thallo (Twig + ThalloRuntime + node harness).

## Global Constraints (binding on every task)

- **The metadata convention is the seam.** Keys `email`, `callback_url`, `cancel_url` on
  `PayableReference::metadata` (contract UNCHANGED — the array already exists). The collector
  lifts them; gateways stay dumb consumers of their existing options. Never thread per-consumer
  parameters into payvia; never special-case `payable_type` anywhere in payvia.
- **Webhooks stay the settlement authority.** Return/cancel URLs are UX navigation ONLY — no
  payment state may ever be mutated on a browser return (thallo's return routes already only
  redirect to confirmation; keep it that way). Both gateways' webhook verify paths are untouched.
- **Graceful degradation is preserved.** Keyless/disabled gateway → manual instructions;
  non-initiation-capable gateway → manual "confirm via reference" — the existing collector
  fallbacks must survive unchanged (tests pin them). `CheckoutPresentation` needs NO change: it
  already accepts `checkout_url` and hard-requires absolute https.
- **Secrets:** test-mode keys only (project is pre-live), via env — never in code, fixtures,
  or plan docs. Payvia tests mock `HttpClient`; no live API calls in any suite.
- **Cross-repo discipline:** vendor-first dogfood (rsync the patched packages into thallo's
  `vendor/` to test live; vendor/ is untracked so thallo commits stay clean) → port to the real
  repos → user publishes payvia then commerce → thallo repins. Framework-side commits happen in
  their OWN repos; no tags; thallo's version pins bump only after publication.
- **Checkout page rules:** it renders the visitor's cart server-side, so it is already per-visitor
  (`no-store`) -- keep it uncached; assert it in tests. No-JS floor first (a non-mutating POST that
  directly renders the quote; NOT PRG, because there is no session/flash authority that could
  preserve address/errors/idempotency across a 303), JS enhancement second (ThalloRuntime module),
  mirroring the storefront discipline. Before placement the posture line is deliberately generic:
  "After placing your order, payment instructions or a secure payment step will follow." The
  concrete provider/manual/reference/unavailable result is rendered only after initiation. A
  config preflight can never guarantee that the later provider request will succeed.
- **Trusted public URLs:** Thallo composes absolute callback URLs only from
  `CanonicalPublicOriginResolver::currentOrigin()` plus `ShopUrlGenerator`; never from `Host`,
  `Request::getSchemeAndHttpHost()`, or another request-derived base. Commerce validates returned
  URLs as absolute HTTPS before adding them to payable metadata.
- **Optional shopper identity:** checkout routes run `session_cookie:optional` then `auth:optional`.
  The JWT identity supplies the user UUID only; a new `StorefrontAccountIdentityReader` contract in
  `thallo-contracts`, implemented by the app over `UserProviderInterface`, resolves the email. The pack
  never reaches into `App\\` or assumes email exists in JWT claims. Authenticated placement passes
  the UUID to Commerce; anonymous placement passes null.
- **Commit cadence:** thallo work commits on thallo `dev` (never push, no AI attribution, plan doc
  held uncommitted); payvia/commerce work commits in their repos on their working branches with
  their own changelogs. Gates per commit: each repo's full PHPUnit + phpcs.

---

## Task 1: payvia — metadata lift + Stripe Checkout Sessions (repo: `extensions/payvia`)

**Files:** modify `src/Services/PayviaPaymentCollector.php`, `src/Gateways/StripeGateway.php`
(add `InitiationCapableGateway`), `README`/docs section for the metadata convention, `CHANGELOG.md`
[Unreleased]; tests in the repo's existing Unit/Integration suites beside the current collector
and gateway tests (extend the files that already cover them; create siblings if none do).

- [ ] **Collector lift** (TDD first): in `initiate()`, build the options once --
  `array_filter(['email' => $payable->metadata['email'] ?? null, 'callback_url' => …, 'cancel_url' => …])`
  — and pass to `$gateway->initialize($payable, $options)`. Absent keys are simply omitted
  (Paystack: no email means its API error propagates; Commerce catches initiation exceptions and
  maps them to `init_failed`/`unavailable`). `PayviaPaymentCollector` has no catch and this task must
  not claim otherwise. Tests: options reach the gateway; empty metadata passes `[]`; initialization
  exceptions propagate; the keyless/disabled/manual fallbacks are byte-identical to today.
- [ ] **`StripeGateway::initialize()`** implementing `InitiationCapableGateway` (TDD, mocked
  HttpClient): POST `{$baseUrl}/v1/checkout/sessions` (form-encoded, matching the gateway's
  existing request idiom — mirror `requestOptions()`), with `mode=payment`, one line item
  (`price_data`: `currency`, `unit_amount` = `$payable->amount`, `product_data.name` =
  `$payable->description ?? 'Payment'`), `client_reference_id` = `$payable->id` (the Stripe session
  id remains Payvia's provider reference), `metadata[payable_type|payable_id]` (mirror Paystack),
  `customer_email` from
  `$options['email']` when present, `success_url` from `$options['callback_url']` and
  `cancel_url` from `$options['cancel_url'] ?? $options['callback_url']`. Callback is REQUIRED;
  cancel alone may be absent and falls back to callback. Add a deterministic Stripe
  `Idempotency-Key` derived from payable type + id so concurrent collector calls cannot create two
  hosted sessions for one payable. Validate the response BEFORE the collector persists an open
  intent: non-empty `cs_` id and an absolute HTTPS checkout URL, otherwise throw. Return
  `['reference' => session id (cs_…), 'checkout_url' => validated URL, 'raw' => …]` -- the session
  id is exactly what the existing `verify()` session branch expects.
- [ ] Tests: session created with the right form fields and stable idempotency header; missing secret
  throws; missing callback throws; missing cancel reuses callback; malformed/missing session id,
  missing URL, and non-HTTPS URL all throw before persistence; the returned reference round-trips
  into `verify()`'s checkout-session branch (existing normalizer, now covered end-to-end).
- [ ] Document the metadata convention (the three keys, who supplies them, webhook-is-authority
  note) in the package docs; CHANGELOG [Unreleased]; full payvia suite + phpcs; commit in the
  payvia repo.

---

## Task 2: commerce — feed the convention for orders and retries (repo: `extensions/commerce`)

**Files:** add `src/Contracts/OrderPaymentReturnUrlProvider.php`; modify
`src/Orders/CheckoutService.php` and its service-provider wiring (including the existing optional
`LoggerInterface` soft-resolution pattern); tests beside the existing checkout/placement/retry
suites; `CHANGELOG.md` [Unreleased].

- [ ] Add `OrderPaymentReturnUrlProvider` with one method receiving `(ApplicationContext, order)`
  and returning `?array{return: string, cancel: string}`. Append it as a nullable collaborator on
  `CheckoutService`, preserving all direct-construction call sites and making zero calls when
  absent. Append a `LoggerInterface` collaborator defaulting to `NullLogger`, also preserving all
  direct-construction call sites. This is Commerce-owned and order-specific; Payvia remains
  payable-type-agnostic.
- [ ] `initiatePayment()` builds the payable metadata: always `email` from `$order['email']`; when
  the optional provider returns URLs, validate each as absolute HTTPS and add `callback_url` and
  `cancel_url`. Resolve and validate the provider INSIDE the existing payment-initiation try/catch,
  after the order number exists, so bound-provider exceptions or invalid output are logged and map
  to the existing `init_failed` result without rolling back the already-placed order. An ABSENT
  provider simply adds no URL metadata. The same path is used by initial placement, durable replay,
  and `retryPayment()`.
- [ ] Tests: payable metadata always carries email; provider URLs reach the collector on initial
  placement, replay, and the public retry path; absent provider adds no URL metadata; malformed or
  non-HTTPS bound-provider output and provider exceptions map through the existing
  `payment_init_failed`/`init_failed` path; replay/idempotency behavior remains unchanged. Full
  commerce suite + phpcs; commit in the commerce repo.

---

## Task 3: thallo — the checkout page (repo: thallo)

**Files:** add `packages/thallo-contracts/src/Account/StorefrontAccountIdentityReader.php`,
`app/Account/AppStorefrontAccountIdentityReader.php`, and
`packages/thallo-commerce/src/Payments/ThalloOrderPaymentReturnUrlProvider.php`; modify
`packages/thallo-commerce/src/CommerceIntegrationServiceProvider.php`,
`packages/thallo-commerce/routes/shop-routes.php`,
`packages/thallo-commerce/templates/shop/checkout.twig`,
`packages/thallo-commerce/src/Http/Shop/ShopCheckoutController.php`,
`packages/thallo-commerce/assets/shop.css`, and `packages/thallo-commerce/assets/shop.js`; tests in
`tests/Integration/Commerce/` beside `NoJsAddToCartTest` (no-JS POST-render legs) and the
storefront render/harness suites. Prereq: rsync the patched payvia+commerce into `vendor/`
(untracked) until the releases land.

**Page structure (top to bottom):** order summary (lines + quote-driven totals: subtotal,
discount, shipping, tax, grand) · contact (email — prefilled server-side for a signed-in customer
by resolving the authenticated user UUID through `StorefrontAccountIdentityReader`; JWT attributes
do not contain email; the page is uncached so that is safe) · shipping address (name, line1,
line2 optional, city, state, postcode, country — country/state/postcode drive zone matching) ·
shipping method (radio list from `shipping_options`, selected id posted) · payment posture line ·
place button.

- [ ] **Routes + optional identity:** add POST `/checkout` with `ShopCsrfGuard` for the no-JS quote
  render. Apply `session_cookie:optional` then `auth:optional` to GET/POST `/checkout`, JSON quote,
  and placement routes without changing the existing same-origin CSRF policy. Resolve email
  through the optional account identity contract and pass authenticated `user.uuid` as
  `buyer.user_uuid`; anonymous behavior stays byte-identical. Tests pin middleware order,
  signed-in prefill + ownership stamping, missing-reader fail-soft, and anonymous null ownership.
- [ ] **No-JS quote leg (floor):** the address form POSTs to `/checkout`; a dedicated controller
  method directly renders the response (200 valid, 422 invalid) because quote is non-mutating and
  PRG cannot preserve request state here. It calls the same `CheckoutService::quote()` and renders
  submitted address, totals, shipping-method radios, and field errors inline. The submitted hidden
  idempotency key is reused verbatim. Use one form: its normal action is `/checkout`; after a valid
  quote the Place order submitter uses `formaction="/_shop/checkout/place"`, while Update quote
  continues to POST `/checkout`. With `Accept: application/json`, that same method returns the
  existing quote JSON shape through one shared quote-result projector; do not fork validation or
  totals projection from `/_shop/checkout/quote`. Also replace `placeError()`'s lossy 303/query-flag
  path with the same state-preserving checkout render for 422/409; successful placement keeps its
  existing redirect/inline presentation. Tests assert HTML/JSON parity, values/errors/key survive,
  button actions are honest, and every response is `no-store`.
- [ ] **Payment posture (server-side):** render the fixed provider-neutral line from the Global
  Constraints before placement. Do not inspect Payvia config or name a gateway. After placement,
  the existing `CheckoutPresentation` remains the sole authority for redirect/manual/reference/
  unavailable UI. Test the generic line plus each post-placement presentation mode.
- [ ] **Trusted return URLs:** bind Commerce's optional `OrderPaymentReturnUrlProvider` to
  `ThalloOrderPaymentReturnUrlProvider`. It composes
  `CanonicalPublicOriginResolver::currentOrigin($context)` with
  `ShopUrlGenerator::paymentReturn($orderNumber)` / `paymentCancel($orderNumber)`; it never reads
  request host data and needs no `{ref}` template. Tests cover a custom trusted origin, an order
  number containing URL-significant characters, retry parity, and hostile Host irrelevance.
- [ ] **JS enhancement (`checkout` runtime module):** debounced live re-quote on address/method
  change via `POST /_shop/checkout/quote` (JSON), patching totals + method list; disable the
  place button while a quote is in flight; keep the no-JS form fully functional untouched.
  The existing `shop-form` module remains the ONLY submit owner and its existing
  `updateQuoteRegions()` path is extended/reused. Add `/checkout` to `FORM_SELECTOR`, and make the
  shared submitter honor `SubmitEvent.submitter.formAction` before falling back to the form action,
  so Update quote and Place order still have one interception authority. The checkout module adds
  change listeners only, never a second submit listener. Exactly-once guard + catch-up enhance per
  the established module idiom. Node-harness tests: one submit binding, both submitter actions,
  coalesced quote call shape, totals/method patch, method re-selection, and fail-open (fetch error
  leaves the server-rendered state usable).
- [ ] Styling in shop.css (`shop-checkout__*`, mirroring the existing checkout classes); manual
  smoke on dev against Paystack test keys (redirect out + return + confirmation) and manual mode.
- [ ] CHANGELOG [Unreleased]; full thallo suite + phpcs; commit on thallo `dev`.

---

## Publish order (user-driven, after all three tasks green)

payvia release → commerce release → thallo repins both (`composer update glueful/payvia
glueful/commerce`) → push when ready. Until then thallo runs on the vendored overlay.

## Not in scope (deferred, agreed)

- Subscriptions/invoices UI — the seam serves them by construction; their flows build their own
  payables with their own callback URLs when they arrive.
- **Embedded official payment UI (stay-on-page)** — Stripe Elements / Paystack Popup(InlineJS),
  the gateways' official iframe components (the ONLY sanctioned way to put card fields on our own
  page; raw card inputs are never an option). A distinct future slice with a different
  architecture: payvia grows a client-side mode (publishable-key config slots, PaymentIntent
  client-secret exposure — today's settings are secret-key/webhook-only, i.e. hosted-shaped) and
  the checkout module mounts the official SDK components. v1 stays hosted-redirect (SAQ A, no
  payment UI on our side by design).
- Paystack `channels` selection and saved cards (both belong to that embedded slice or later).
- Billing address separate from shipping (single address v1); order notes; coupons UI on the
  checkout page (cart owns discounts today).
- A payvia-owned generic browser-return route (per-consumer return URLs cover today's needs).
