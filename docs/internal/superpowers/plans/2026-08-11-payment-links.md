# Payment Links for Admin Orders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Durable, revocable payment links for admin orders — ensure-live gateway sessions (Payvia 2.6.0), engine-native link custody with lease-governed expiry (Commerce 1.11.0), and Thallo's pay-only landing page, admin controls, and payment-request email.

**Architecture:** Payvia first: the `payment_intents` migration to reference-addressable session attempts, the ensure-live collector, and reference-aware confirmation. Then Commerce: the link table/custody, the two-phase token-initiation, and the session-exposure guard woven into every final-order cancellation path. One human publication sitting releases BOTH (2.6.0 then 1.11.0); Thallo repins both behind the gate, then builds the landing + signed returns, the admin send flow with its delivery ledger, the synchronous mailer, the SPA card, and the artifact pass.

**Tech Stack:** PHP 8.4 / Glueful (payvia repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/payvia`; engine repo `/Users/michaeltawiahsowah/Sites/glueful/extensions/commerce`; Thallo `/Users/michaeltawiahsowah/Sites/glueful/thallo`, pack `packages/thallo-commerce`), Vue 3 + pinia-colada (admin/, pnpm), vitest.

**Spec:** `docs/internal/superpowers/specs/commerce/2026-08-11-payment-links-design.md` — §1 rulings and §2 contracts govern verbatim; §3's matrix is distributed below. File/line citations were mapped against current sources; every implementer re-verifies before coding.

## Global Constraints

- Branches: work branches off each repo's `dev` (payvia dev; engine dev @ v1.10.0; Thallo dev @ 62e8e564). TWO publication gates, ONE human sitting: after Task 9, the HUMAN publishes Payvia 2.6.0 THEN Commerce 1.11.0 (push dev, PR dev→main, merge, tag, Packagist — each repo's own convention); Task 10 repins Thallo (`glueful/payvia: ^2.6.0`, `glueful/commerce: ^1.11.0`) only against published artifacts. If Composer cannot resolve either, STOP — no path-repo/vendor-edit workarounds.
- Baselines: before Task 1, capture same-checkout baselines in the SDD ledger for ALL THREE repos (payvia `composer test` + any lint scripts — first capture, record exactly; engine `composer test` at dev [expect ~3156/0F/0E, re-capture] + phpstan `--memory-limit=1G` [5 pre-existing errors] + phpcs [1 pre-existing error + 91 warnings]; Thallo full gates [last known: PHP 3118/0F/0E/71S; vitest 133/2108; type-check 0; build 0; harness 62/62; phpcs/boundaries clean]). Zero new failures anywhere, ever. pgsql-gated additions accounted exactly per the repos' `COMMERCE_TEST_DB_DRIVER=pgsql`-style conventions.
- Spec invariants binding every task: raw tokens have exactly TWO egress points (mint/regenerate one-time response; send-time email body) and NEVER touch application-side persistence (databases, queues, logs, audit rows) — a no-egress ratchet test guards this; ensure-live never force-fresh (unknown provider state fails closed); Paystack renewal unavailable — no timeout guessing anywhere; no provider/network I/O inside a database transaction or while row locks are held; order-before-link lock order in mint/revoke/initiate/expiry; the session-exposure guard governs EVERY final-order cancellation path; webhooks remain the settlement authority; exception messages never cross a response boundary.
- Gates per task, FOREGROUND, sequential, NEVER via run_in_background or monitors, with EXPLICIT Bash timeouts (backgrounded runs die when a subagent's turn ends; the 120s default auto-backgrounds long commands): payvia tasks — `composer test` (timeout 300000) + that repo's lint scripts; engine tasks — `composer test` (300000) + phpstan `--memory-limit=1G` (300000) + phpcs (120000); Thallo PHP tasks — `COMPOSER_PROCESS_TIMEOUT=0 composer test` (600000), `composer phpcs` (180000), `composer boundaries` (60000); SPA tasks add from `admin/` (pnpm, NOT npm): `npx vitest run` (300000), `pnpm run -s type-check` (180000), `pnpm run -s build` (300000). Never run different repos' suites (or a PHP suite + SPA gates) concurrently.
- Conventional commits, ONE per task in the task's repo; NO AI-attribution trailers; stage explicitly by path; never stage `.claude/`, `.superpowers/`, or composer files outside Task 10.
- TDD every task: the Step 1 matrix is written RED first (run, capture), then implement to GREEN.

---

### Task 1 (PAYVIA): `payment_intents` session-attempt migration + repository lifecycle

**Files:**
- Create: new migration (nullable `reference`; service-enforced closed status set `initializing|open|superseded|closed|failed`; portable UNIQUE `(tenant_uuid, gateway, reference)` — SQLite and PostgreSQL both admit multiple NULL references; existing globally-unique `uuid` IS the attempt UUID, no second column; existing `(tenant_uuid, idempotency_key)` unique retained with the active-port re-keying scheme)
- Modify: `src/Repositories/PaymentIntentRepository.php` to the attempt lifecycle: `claimAttempt()` (INSERT `initializing` with attempt UUID before provider I/O), `markOpen(reference, payload)`, `supersede()`, `close()`, `fail()`; active-port semantics — `initializing`/`open` share the payable's active idempotency key; terminal/superseded rows re-keyed by attempt UUID
- Test: migration fresh + REAL upgrade fixtures on SQLite AND PostgreSQL; multiple nullable references coexist; duplicate non-null `(tenant, gateway, reference)` rejected; initializing crash/retry reuses the SAME attempt UUID; deterministic provider rejection ⇒ `failed` frees the active port; existing rows remain valid post-upgrade

- [ ] **Step 1: Failing tests** per the §3 Payvia migration rows. **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + payvia gates. **Step 5:** Commit `feat(intents): reference-addressable session attempts with durable idempotency ports`.

### Task 2 (PAYVIA): ensure-live collector + provider renewal + URL trust boundary

**Files:**
- Modify: `config/payvia.php` (+`gateways.stripe.checkout_hosts`, default `['checkout.stripe.com']`; `gateways.paystack.checkout_hosts`, default `['checkout.paystack.com']`), `src/Services/PayviaPaymentCollector.php` (ensure-live per spec §2.1/Ruling 5: no intent ⇒ claim attempt + create; confirmed-live ⇒ same URL; confirmed-dead ⇒ supersede + new attempt; unknown ⇒ typed fail-closed), `src/Gateways/StripeGateway.php` (per-attempt idempotency key derived from attempt UUID replacing the fixed per-payable key at :148; renewal = status → expire → re-fetch, only `confirmed_dead` frees; exact provider-host validation on returned URLs), `src/Gateways/PaystackGateway.php` (reference from attempt UUID; exact provider-host validation on `authorization_url` — currently unchecked; liveness: create + confirmed-live reuse only, expired-without-proof/unknown ⇒ typed failure, NO renewal)
- Test: the full §2.1 provider matrix with fixtures; one-open-attempt serialization under concurrent renewal (parallel-process test per the repos' race conventions); per-attempt idempotency (transport-timeout retry returns the same provider session; a later confirmed renewal claims a new attempt); URL validation — case-normalized exact host succeeds, while missing/malformed, HTTP, userinfo, explicit port, trailing-dot, subdomain-lookalike, and untrusted-host URLs never enter intent payloads as usable checkout URLs

- [ ] **Step 1: Failing tests.** **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + gates. **Step 5:** Commit `feat(collector): ensure-live hosted sessions with provider-proven renewal`.

### Task 3 (PAYVIA): reference-aware confirmation + supersession settlement

**Files:**
- Modify: `src/Services/ConfirmationDispatcher.php` (:37 — resolve and close the exact `(tenant, gateway, provider reference)` intent row, never whichever-is-open)
- Test: webhook for a superseded attempt closes THAT row, the open attempt untouched; webhook for the open attempt closes it; unknown reference ⇒ existing unmatched-webhook behavior unchanged; Payvia's suite proves exact-reference dispatch with a recording confirmation handler. Commerce Task 8 separately fixture-proves that a late success for an old Stripe session is refused by the already-paid order CAS; no Payvia test conditionally reaches into the Commerce repo.

- [ ] **Step 1: Failing tests.** **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + gates. **Step 5:** Commit `fix(webhooks): reference-addressable intent confirmation`.

### Task 4 (PAYVIA): 2.6.0 release-prep (parked for the human)

- [ ] Land Tasks 1-3 on payvia `dev` per that repo's convention; changelog + version bump matching its prior release commits; final full suite on the release commit. STOP — do NOT push/tag. Record the exact publish steps in the ledger; the human publishes at the Task 9/10 sitting.

### Task 5 (ENGINE): `commerce_payment_links` migration + repository custody

**Files:**
- Create: migration per spec §2.2 (all columns incl. `initiation_window_started_at`, `initiation_count`, `provider_session_issued_at`; unique `(tenant_uuid, token_hash)`; index `(tenant_uuid, order_uuid, status)` + issued-session lookup), `src/Orders/PaymentLinkRepository.php` (row mechanics ONLY: hashed lookup, status transitions, counter window CAS under the link lock; no token generation here)
- Modify: tenant purge/adoption registration (DiagnosticsReport pattern from cycle 2)
- Test: migration fresh + v1.10-shape upgrade, both drivers; uniqueness; purge/adoption; counter-window atomicity (fixed UTC hour, reset under lock, deterministic clock)

- [ ] **Step 1: Failing tests.** **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + engine gates. **Step 5:** Commit `feat(orders): payment link storage with hashed token custody`.

### Task 6 (ENGINE): `PaymentLinkService` — mint/revoke/resolve + the return-URL seam

**Files:**
- Create: `src/Orders/PaymentLinkService.php` (`mint()` plus controller-safe `mintPublic()` sharing one private mint transaction; `mintPublic()` generates the token, composes + validates the public URL before persistence, then returns `{url,link}` with no separate token; revoke/resolveByToken plus `matchCurrentToken(context, tenant, orderUuid, rawToken): ?PaymentLinkAdminView` per spec §2.2 — shape-gate before lookup, host-resolved tenant, generic-null public indistinguishability, authenticated current-token check under order→link lock, transactional one-active per Ruling 7, TTL clamp 1-30 default `commerce.payment_links.ttl_days`=7, mint only for tenant-owned admin-origin pending_payment), `src/Contracts/PaymentLinkPublicUrlProvider.php` (`urlFor(context, rawToken): ?string`; in-memory composition only; Commerce requires absolute HTTPS, no userinfo/port/query/fragment, and the token exactly once as final path segment), `src/Contracts/PaymentLinkReturnUrlProvider.php` (`urlsFor(context, linkUuid): ?array{return:string,cancel:string}` — link UUID only, NEVER raw token; Commerce validates both as absolute HTTPS), `LinkView` value object (ONLY: order number, line names/quantities, totals, currency, payment/link state, expires_at, provider-session-exposure flag — engine-side EXCLUDES store identity, email, phone, addresses, user uuid, notes, internal ids, token, hash), closed `PaymentLinkAdminView` (`link_uuid`, state, expiry, exposure flag)
- Modify: config (`commerce.payment_links.*` keys), provider registration (bind an engine-owned unavailable `PaymentLinkPublicUrlProvider` by default so generic hosts compile and receive a typed unavailable result; Thallo overrides it after repin)
- Test: mint conflicts (non-admin-origin, non-pending, cross-tenant); concurrent mint ⇒ exactly one active (locked-order serialization, parallel-process); regenerate revokes prior; TTL clamp; shape-gate; resolve indistinguishability triple; current-token match returns the current link UUID but rejects stale/wrong/cross-tenant tokens without pack-side queries; `mintPublic()` rejects missing/invalid providers before persistence and accepts only the exact token-bearing URL shape; LinkView/AdminView exclusion sets; raw-token egress ratchet (token appears only in `mint()`'s one-time result or embedded once in `mintPublic()`'s URL — assert absent from events/logs tables and every other method's output)

- [ ] **Step 1: Failing tests.** **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + gates. **Step 5:** Commit `feat(orders): payment link service with one-active custody`.

### Task 7 (ENGINE): two-phase `initiateByToken`

**Files:**
- Modify: `src/Orders/PaymentLinkService.php` (+`initiateByToken(context, rawToken): {checkoutUrl}` per spec §2.2 verbatim: Phase A txn [lock order→link, revalidate, claim counter, capture identity, commit] → provider I/O OUTSIDE any txn/lock [ordinary `commerce_order` PayableReference + `PaymentLinkReturnUrlProvider` metadata → `PaymentCollector::initiate()`] → Phase B txn [relock, recheck every predicate, validate `status='ok'` + checkout URL, stamp `provider_session_issued_at`, commit]; typed unavailable states for manual/missing-URL/malformed/untrusted/renewal-unavailable/missing-return-provider — never empty redirects or exception leaks)
- Test: happy path; counter enforced before provider I/O (collector double counts calls); a BLOCKING fake collector proves revoke completes while provider I/O is in flight (no DB lock held) and Phase B then refuses the redirect (no URL exposed; the attempt stays server-side); every typed unavailable case; return-provider absent/insecure fails BEFORE provider I/O; PayableReference metadata carries signed-handle URLs never the raw token (sentinel assertion)

- [ ] **Step 1: Failing tests.** **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + gates. **Step 5:** Commit `feat(orders): two-phase payment link initiation`.

### Task 8 (ENGINE): session-exposure guard + expiry integration + catalog

**Files:**
- Create: `src/Orders/PaymentSessionExposureGuard.php` (THE authority for every non-draft cancellation path: given a locked order, decides allow / allow-with-`accept_late_payment_risk` / block; records `payment_session_risk_accepted` event with actor/time in the SAME cancellation transaction before stock release)
- Modify: `src/Orders/ExpiryService.php` (candidate prefilters: NOT-EXISTS active unexpired link AND NOT-EXISTS issued-session link for admin-origin; in-transaction lock/reload + guard invocation before stock release/transition), `src/Http/Admin/AdminOrderController.php` cancel path (same guard; refuses without acknowledgement, accepts with), `src/Http/Routing/AdminRouteCatalog.php` (+`orders.payment_link.store|destroy|show`, manage mode), `src/Orders/PaymentLinkService.php` (terminal transitions: OrderPaid ⇒ consumed eager+lazy; expiry lazy on resolve + swept), controller `src/Http/Admin/AdminOrderPaymentLinkController.php` (the ONLY mint/revoke/status HTTP owner; store calls `mintPublic()` and returns its validated one-time URL; show returns state/expiry/exposure only)
- Test: cancellation-guard inventory (pins the set of final-order cancellation authorities; a new one fails until wired); sweep races for mint AND initiation (mint/initiate inside the sweep window ⇒ order survives — transactional recheck proven, prefilter insufficient by construction); uninitiated expired/revoked link ⇒ order returns to ordinary sweep next tick; issued-session link blocks automatic cancellation regardless of link status; admin cancel refuses without `accept_late_payment_risk`, records the audit event atomically when accepted; storefront orders' 60-min behavior byte-unchanged; draft isolation untouched (drafts have no links — mint refuses); eager+lazy terminal transitions; a real Stripe supersession fixture's late second settlement is refused by the paid-order CAS; catalog mint with absent/invalid public-URL provider performs no mint; route catalog/parity ratchets updated per cycle-2 conventions

- [ ] **Step 1: Failing tests.** **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + gates. **Step 5:** Commit `feat(orders): session-exposure cancellation guard and lease-aware expiry`.

### Task 9 (ENGINE): 1.11.0 release-prep (parked) + THE PUBLICATION SITTING

- [ ] Land Tasks 5-8 on engine `dev`; changelog (1.10.0's shape) + composer.json version bump; final full suite. STOP — hand BOTH releases to the human: publish Payvia 2.6.0 first, then Commerce 1.11.0 (each: push dev, PR dev→main, merge, tag, Packagist). Resume only on their confirmation.

### Task 10 (THALLO + GATE): dual repin

**Files:** root `composer.json` (`glueful/payvia: ^2.6.0`, `glueful/commerce: ^1.11.0`), `packages/thallo-commerce/composer.json` (`glueful/commerce: ^1.11.0`), `composer.lock`; the mount-parity fixture + allowlist for the three new catalog keys (same-commit tree-green rule per cycle 2's precedent)

- [ ] `COMPOSER_PROCESS_TIMEOUT=0 composer update glueful/payvia glueful/commerce` (600000; BLOCKED if unresolvable). Verify vendor: payvia collector's ensure-live surface + intents migration; commerce `PaymentLinkService`/`PaymentSessionExposureGuard` + `orders.payment_link.*` catalog entries. Full Thallo gates; ONLY the mount-parity/allowlist failures for the new keys may appear — fix in-commit (allowlist + fixture regen). If `AdminOpenApiGateTest` trips on the new engine routes, extend `AWAITING_SPEC_REGENERATION` with them (the mechanically-enforced carve-out; Task 14 empties it). Commit `chore(commerce): repin to payvia 2.6.0 and commerce 1.11.0 with payment-link allowlist`.

### Task 11 (THALLO): landing page + signed returns + provider binding

**Files:**
- Create: `packages/thallo-commerce/src/Http/Shop/ShopPaymentLinkController.php` (GET landing + POST initiate + GET return/cancel receipts), `packages/thallo-commerce/src/Payments/PaymentLinkReturnSigner.php` (app.key-derived per `ResolvesPreviewKey`'s `base64:` fail-closed discipline; distinct `payment-link-return`/`payment-link-cancel` purposes; `hash_equals()` verify; no fallback key), `packages/thallo-commerce/src/Payments/ThalloPaymentLinkPublicUrlProvider.php` (binds the engine admin-mint seam; `ShopUrlGenerator::paymentLink(rawToken)` + `CanonicalPublicOriginResolver`, never request Host), `packages/thallo-commerce/src/Payments/ThalloPaymentLinkReturnUrlProvider.php` (binds the engine checkout-return seam; canonical-origin absolute URLs; linkUuid + signature paths — never tokens), `ShopUrlGenerator::paymentLink()` (the existing generator is at `packages/thallo-commerce/src/Shop/ShopUrlGenerator.php`; add the method there)
- Modify: `packages/thallo-commerce/routes/shop-routes.php` (GET `/checkout/pay/{token}`; POST `/checkout/pay/{token}/initiate` with `ShopCsrfGuard` + IP rate limit; GET `/checkout/pay/return/{linkUuid}/{signature}` + `/checkout/pay/cancel/{linkUuid}/{signature}` — all reserved-path guarded, never page-cached), provider registration, theme templates for the landing/receipt pages (follow the shop page-rendering conventions — read how `/checkout/confirmation` renders)
- Test (spec §3 Thallo rows): landing state matrix (active/paid/revoked/expired/canceled) + headers (`Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow, noarchive`) on GET AND POST + generic 404 triple (unknown/malformed/cross-tenant identical); CSRF guard enforced; 303 ONLY to an independently revalidated absolute-HTTPS URL; unavailable/manual ⇒ no `Location`; signed receipts: purpose separation (return signature rejected on cancel route and vice versa), hostile signature/shape ⇒ generic 404, receipts render the generic copy with NO order/link fields, the guest-cookie confirmation route provably never selected; log-redaction guidance documented in the pack README/docs

- [ ] **Step 1: Failing tests.** **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full Thallo gates. **Step 5:** Commit `feat(commerce): payment link landing with signed non-authorizing returns`.

### Task 12 (THALLO): admin endpoints + delivery ledger + mailer + template

**Files:**
- Create: pack-owned send controller ONLY (`POST /orders/{uuid}/payment-link/send`; mint/revoke/status stay owned by the mounted Commerce catalog controller), required opaque 16–128-character `Idempotency-Key`; `mode=current` submits the visible raw token and delegates to engine `matchCurrentToken()` (missing/cross-tenant order ⇒ 404; stale/wrong token on an owned order ⇒ 409 `payment_link_changed`; never logged/persisted); `mode=regenerate` accepts TTL/no token, claims delivery idempotency FIRST, then calls engine `mintPublic()`; delivery failure keeps the link active + returns the one-time URL on the ORIGINAL response. Add migration + repository for `thallo_commerce_payment_link_deliveries` (spec §2.4 shape verbatim; same-key+fingerprint replays recorded outcome WITHOUT raw URL or resend; different fingerprint ⇒ 409; `processing` older than `thallo-commerce.payment_links.delivery_processing_stale_seconds` becomes `indeterminate`, replay instructs new-key/regenerate), shared `RichEmailAvailability` authority, `packages/thallo-commerce/src/Email/PaymentRequestMailer.php` (resolves `email` through that authority requiring `RichNotificationChannel`; calls `sendNotification()` directly — NEVER `NotificationService::send()`; typed safe result; missing channel ⇒ send refusal, never boot failure)
- Modify: `packages/thallo-commerce/config/thallo-commerce.php` (+`email.payment_request.enabled=false`; `payment_links.delivery_processing_stale_seconds` default 300 clamped 60–3600), `packages/thallo-commerce/src/Http/EmailSettingsController.php` (+`payment_request` to TEMPLATES), `packages/thallo-commerce/src/Email/CommerceEmailTemplates.php` (+definition; `action_url` placeholder + expiry chip), routes/admin-routes.php (send route before catalog mount; no duplicate mint/revoke/status routes), `CommerceMetaController` (MANDATORY `email_available` from `RichEmailAvailability`), DI registration, tenant purge/adoption for the deliveries table
- Test (spec §3): migration fresh + real upgrade on SQLite/PostgreSQL; tenant purge/adoption; send matrix (engine-authoritative current-mode match; exact 404/409 split; regenerate-mode confirmation semantics; same-key replay; different-fingerprint 409; deterministic-clock 299s processing remains processing / 300s becomes indeterminate + instructed recovery; delivery-failure keeps link + returns URL); receipts shape exact (never token/email/rendered body/exception text); PaymentRequestMailer persistence audit (notification + queue tables provably token-free after a send — sentinel sweep); mandatory `email_available` true/false cases; template toggle defaults false from config; `action_url` substitution at send time only (stored template token-free); route uniqueness proves the engine catalog owns mint/revoke/status exactly once; authority matrix on all new endpoints

- [ ] **Step 1: Failing tests.** **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN + full Thallo gates. **Step 5:** Commit `feat(commerce): payment link admin flow with custody-safe delivery ledger`.

### Task 13 (THALLO SPA): the payment-link card

**Files:**
- Create: `admin/src/pages/commerce/orders/components/OrderPaymentLinkCard.vue`, `admin/src/queries/commercePaymentLinks.ts` (raw authFetch idiom until Task 14's schema regen)
- Modify: `admin/src/pages/commerce/orders/[uuid]/index.vue` (card for `origin='admin'` + `pending_payment`), `admin/src/queries/commerceMeta.ts` (required `email_available` field)
- Test: card gating matrix (origin/status; hidden for storefront + non-pending); Create with TTL clamp UI; the ONE-TIME copy surface (raw URL rendered exactly once, never re-fetchable — assert status reads contain no token); "Stock reserved until …" and the post-exposure warning copy variant (Ruling 3); Regenerate + Revoke flows with confirmation; Send visible only when (email present + toggle on + email_available), disabled-with-reason otherwise; "Send this link" parses the visible URL with the platform URL API and submits only its shape-validated final path token (never ad-hoc splitting), while "Regenerate and send" works after the URL is gone with the invalidation confirmation; malformed visible URL disables current-send; delivery-failure rendering (link stays active, URL still copyable from the response); idempotency double-click (one send); Paystack honesty copy (no revive/invalidate claims; mark-paid or risk-acknowledged cancel as recovery)

- [ ] **Step 1: Failing vitest specs.** **Step 2:** RED. **Step 3:** Implement. **Step 4:** GREEN (vitest/type-check/build via pnpm) + PHP gates. **Step 5:** Commit `feat(admin): payment link card with one-time custody`.

### Task 14 (THALLO): artifacts + docs

**Files:** regenerate `docs/openapi.json` (cache-deletion first per the known generator bug; two-run byte-identity) + `AdminOpenApiGateTest::PACK_OWNED_ROUTES`/carve-out EMPTIED + `admin/src/api/schema.d.ts` (`pnpm gen:api`) + typed-client migration where covered (payment-link admin calls; the shop-side public routes stay raw — no auth client); `docs/internal/OUTSTANDING.md` (shipped entry; §4 follow-ups verbatim: Paystack renewal pending provider death-signal; provider-session invalidation seam; guest custody unchanged; SMS/WhatsApp channels; link analytics excluded)

- [ ] Artifacts + docs; failing-first where testable (gate-test pins RED→regen GREEN); FULL gates both stacks + `cd tools/runtime-browser && npm test` (420000). Commit `feat(commerce): payment link artifacts and shipped docs`.

### Task 15: Final whole-program review + finish

- [ ] Cross-repo final review (fable) over all four ranges (payvia, engine, Thallo, + the released artifacts' coherence); one fix wave if needed; scoped re-review; then superpowers:finishing-a-development-branch.

---

## Self-Review

- **Spec coverage:** §1.1 → T6/T11; §1.2 → T5/T6/T12 (ratchet rows in each); §1.3 → T8 (+T13 UI copy); §1.4 → T12/T13; §1.5 → T2; §1.6 → T2/T13; §1.7 → T5/T6; §1.8 → T12/T13; §1.9 → T11 (signer/generator/canonical origin); §1.10 → task-repo assignment throughout; §2.1 → T1/T2/T3; §2.2 → T5/T6/T7/T8; §2.3 → T11; §2.4 → T12/T13; §2.5 → T14; §3 Payvia rows → T1/T2/T3; §3 Commerce rows → T5/T6/T7/T8; §3 Thallo rows → T11/T12/T13/T14; §4 → T14 docs. No gaps.
- **Placeholder scan:** no locate/decide/TBD branches remain; every security- or persistence-relevant choice is pinned.
- **Type consistency:** `PaymentLinkService.{mint,mintPublic,revoke,resolveByToken,matchCurrentToken,initiateByToken}` is consistent across T6/T7/T8/T11/T12; `PaymentLinkPublicUrlProvider::urlFor(context, rawToken)` T6/T8/T11/T12; `PaymentLinkReturnUrlProvider::urlsFor(context, linkUuid)` T6/T7/T11; `LinkView`/`PaymentLinkAdminView` exclusions T6/T11/T12; guard name + `accept_late_payment_risk` + `payment_session_risk_accepted` T8/T13; deliveries-table shape T12; `action_url` T12/T13; attempt lifecycle vocabulary T1/T2/T3.
- **Sequencing:** 1 → 2 → 3 → 4(prep) → 5 → 6 → 7 (consumes 6; provider behavior via collector doubles — engine tests never require published Payvia) → 8 → 9(prep + HUMAN SITTING) → 10(GATE) → 11 → 12 → 13 → 14 → 15. Engine work uses the PaymentCollector CONTRACT, so it needs no Payvia gate; only Thallo's repin does — both releases land in the one sitting.
