# Storefront Customer Accounts — Design Overview

**Date:** 2026-07-28
**Status:** Approved. Three slices across five plans (§14); plan 1 shipped as framework 1.73.0.
**Supersedes:** the "account-backed wishlist" follow-up named in
`2026-07-28-storefront-v1-concept-a-design.md` §1/§7, which becomes slice 3 of this spec.

## §1 Goal and framing

Give Thallo storefront visitors an account: register, sign in, see their orders and
addresses, keep a cart across devices, and hold a wishlist that is not tied to one browser.

This is **customer-account integration, not a new authentication system**. The framework owns
login, logout, refresh and sessions; `glueful/users` owns verification, recovery, profiles and
`/me`; Commerce owns tenant-scoped commerce facts and already carries nullable `user_uuid` on
carts and orders, authenticated checkout attribution, cart merging, address books and customer
order reads. The missing work is composition, browser-session custody, and storefront UX.

The wishlist is the smallest part of this document. It is stated last, on purpose: an account
is justified by repeat purchasing and ownership of orders, not by a wishlist.

### Non-goals

Admin-side customer management UI; shopper 2FA enrolment UI; social login; saved payment
methods; server-persisted wishlists for anonymous visitors; gated content and subscriptions.
The last two are future consumers of `thallo-account`, which is why storefront identity is
**not** folded into `thallo-commerce`.

## §2 Verified landscape

Everything below was read in the working tree, not assumed.

**Framework** — `POST /auth/login`, `/auth/logout`, `/auth/refresh-token`,
`/auth/validate-token`, `GET /csrf-token`. `AuthMiddleware` supports `auth:optional` and sets
both the `'user'` array attribute and `auth.user` (`UserIdentity`). Token extraction is
`Authorization: Bearer` only — `TokenManager::extractTokenFromRequest()` with
`getallheaders()`/`apache_request_headers()` fallbacks. **No cookie path exists.**
`AuthController` splits login into `verifyCredentials()` (no session created) → 2FA branch
(`TwoFactorService::beginLogin()`) → session issuance; the 2FA gate lives in the controller,
not in `AuthenticationService`.

**glueful/users** — verify-email, verify-otp, resend-otp, forgot-password, reset-password,
`GET /me`, `GET /users`, 2FA routes. **No registration endpoint exists** in the framework or in
users. `AccountController::forgotPassword()` returns a neutral body for an unknown email only
when `security.auth.generic_error_responses` is enabled (default true), and declares a 404 in
its own OpenAPI attributes; mail-delivery failure follows a different path again.

**Thallo `app/Signup/`** — a complete signup pipeline: intent repository, throttle, verifier,
mail sender, telemetry, audit, role policy. `MemberSignupService::activate()` runs inside
`runAsTenant(... transaction(...))` and performs, in one transaction: existing-email handoff,
username-conflict check, user creation, profile write, `administration->addMember($context,
$tenantUuid, $userUuid, $role)`, `intents->setResults()`, `intents->consume()`, with the audit
record on `afterCommit`. The `addMember()` call is the line a shopper must never reach.

**Commerce** — storefront controllers for cart, checkout, orders, categories, products,
reviews, download links and `AccountAddressController`. `GET /commerce/orders` (mine) is behind
`auth`; `/commerce/account/*` is behind `auth` + tenant middleware. `CheckoutController::buyer()`
stamps `user_uuid` from `$request->attributes->get('user')`. `CartService::mergeIntoUser()`
merges a guest cart into a user's cart. `OrderRepository::linkGuestToUser()` stamps `user_uuid`
only `WHERE user_uuid IS NULL` (race-safe), and `paginatedFor()` scopes email-keyed guest
lookups to `user_uuid IS NULL` so a linked order never double-counts. `Customers/` is an address
book plus order aggregation — **not** an identity store. **No wishlist model exists.**

`CustomersLinkGuestsCommand` is an operator migration tool whose own docblock records that it
resolves through the *soft* `UserProviderInterface::findByLogin()` and cannot prove verified-email
status. It is not an ownership proof and this design never treats it as one.

**Thallo storefront** — `packages/thallo-commerce` renders the themed shop with cookie-only cart
identity (`CartCookie`), guest order credentials in `GuestOrderCookie`, and no authentication
anywhere. `ShopPageCache` "only honors the attribute — it never parses cookies": **cached shop
HTML is shared by every visitor.**

## §3 Ownership and layering

| Layer | Owns |
|---|---|
| `glueful/framework` | Browser-session transport and the shared login orchestrator. Knows nothing of shops, tenants or customers. |
| `glueful/users` + `app/Signup` | Identity: credentials, verification, recovery, profile, and the verified-account activation primitive. |
| `thallo-contracts` | The neutral contracts the packs meet on: `StorefrontAccountRegistration`, `AccountNavigationRegistry`. |
| `packages/thallo-account` (new) | Storefront identity UX: themed auth pages, the `/account` shell, the session header block, capability `thallo.accounts`. No commerce dependency. |
| `packages/thallo-commerce` | Commerce account sections, guest-order claim orchestration, cart merge, wishlist storefront surfaces. |
| `glueful/commerce` | Tenant-scoped commerce facts keyed by `user_uuid`: the guarded claim service and the wishlist model. Stays Thallo-agnostic. |

**Module boundary.** `thallo-account` must not import `App\Signup` classes. The app implements
`StorefrontAccountRegistration` over the activation primitive; the account pack consumes only the
contract. The account-navigation registry is contract-owned the same way, so `thallo-account` and
`thallo-commerce` remain sibling contributors rather than depending on each other.

**Capability.** `thallo.accounts` gates Thallo's themed account surfaces only. It never gates the
framework's `/auth/*` APIs — the capability controls product integration, not global identity
infrastructure. Commerce account sections require `thallo.accounts` **and** `thallo.commerce`;
disabling either removes the surfaces without deleting stored data, matching the commerce
capability boundary already shipped.

**No duplicate customer identity.** Commerce stores only tenant-scoped commerce facts keyed by
`user_uuid`. Credentials, profile and password lifecycle stay in `glueful/users`. Customers are
global Glueful users, **not** workspace members.

## §4 Session custody (framework seam)

Additive only. Bearer extraction is untouched and stays byte-compatible.

### 4.1 Components

- **`SessionCookieMiddleware`** (alias `session_cookie`, supports `:optional`). Reads the
  **access** cookie, injects `Authorization: Bearer <token>` for downstream `auth`, and sets
  `auth_transport=cookie` on the request. Identity attributes stay `AuthMiddleware`'s (`user`,
  `auth.user`) — a second identity source here could disagree with it, and in required mode this
  middleware deliberately does not validate. It does **not** refresh (see §4.3). Invalid or expired cookies fail normally on required routes and degrade to
  anonymous on optional routes.
- **`SessionCookieIssuer`** — `issue()` and `clear()` (rotation is `issue()` with the rotated
  session; a browser replaces a cookie by name and path). The only place cookie attributes
  are set: `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/`, no domain by default, host-configurable
  names, distinct access/refresh lifetimes, refresh cookie `Path`-scoped to the refresh route.
- **Login orchestrator** — extracted from `AuthController` so JSON login and cookie login share
  one path: credential verification → 2FA gate → session issuance. See §4.4.

Config lives under `auth.session_cookie.*`: `enabled`, `access_name`, `refresh_name`,
`access_ttl`, `refresh_ttl`, `secure`, `same_site`, `path`, `domain`. Host-configurable names
prevent collisions when several Glueful apps share a parent domain.

### 4.2 Mixed credentials

If both an explicit bearer header and a session cookie are present, the middleware resolves both.
Same identity → **the bearer is the effective transport** (`auth_transport=bearer`), so a request
that proved possession of an explicit bearer credential is not subjected to cookie CSRF rules.
Different identities, or either credential invalid → `401` with a non-revealing message. The
middleware never silently chooses.

### 4.3 Refresh model

Transparent middleware refresh is **rejected** as incoherent with a path-scoped refresh cookie:
the browser would not send that cookie on `/account`, `/cart` or checkout requests at all.

The middleware authenticates **access cookies only**. `POST /auth/session/refresh` receives the
narrowly scoped refresh cookie, rotates both cookies and the CSRF state, and returns **no tokens**
in the body. It is protected by exact-origin/fetch-metadata checks. JavaScript may call it and
retry **safe reads**; unsafe requests are never automatically replayed. Logout revokes the
server-side session and expires both cookies.

### 4.4 2FA must not be bypassable

`AuthController` performs the 2FA check after credential verification and **before** session
issuance. A Thallo controller calling `AuthenticationService` directly would skip that
orchestration entirely.

The framework therefore extracts a shared login orchestrator used by **both** JSON login and
cookie-session login. It returns a **closed, transport-neutral result** — `authenticated(session)`
or `twoFactorRequired(challenge)` — and shapes no HTTP response of its own. `AuthController`'s JSON
login continues through `LoginResponseShaper::shape()` byte-identically, including its CSRF
generation. Cookie login passes the session's tokens **only** to `SessionCookieIssuer` and never
into a response body.

**Two event classes, two rules** — the split is already structural in the code and must stay that
way:

- `SessionCreatedEvent` (`SessionStore`) and `SessionCachedEvent` (`SessionCacheManager`) dispatch
  from inside session issuance, below the controller. They fire **exactly once for both
  transports** — neither duplicated by the extraction nor lost on the cookie path.
- `LoginResponseBuildingEvent` / `LoginResponseBuiltEvent` dispatch from `LoginResponseShaper` and
  exist to shape the **token response**. They stay **JSON-only**. Cookie login must not dispatch
  them: their payload is a session/token map that a cookie login never returns, so firing them
  would hand listeners a response that does not exist and invite a listener to mutate tokens into
  a body this transport is specifically designed never to produce. Shopper 2FA UI is out of scope for this spec, so cookie login **fails
closed** on `two_factor_required`: it issues no session, sets no cookie, and renders a themed
message telling the visitor their account requires two-factor authentication that the storefront
does not yet support. There is no code path in which cookie login reaches session issuance
without passing the same gate JSON login passes.

### 4.5 Secrecy

Cookie values and refresh credentials never reach logs, templates, JavaScript-readable storage,
or response bodies. Storefront tokens are never placed in `localStorage`.

## §5 CSRF policy matrix

The invariant is **"every cookie-authenticated unsafe route has an approved CSRF policy"**, not
"every route uses a session token". Token CSRF cannot be used on cart and checkout forms: that
markup is rendered into pages `ShopPageCache` shares across all visitors, so an embedded token
would be either shared or cache-defeating, and the no-JS PRG path cannot fetch one.

| Route class | Authentication | CSRF policy |
|---|---|---|
| Cacheable catalog `GET` | none | none; universal HTML only |
| Cart/checkout mutations | `session_cookie:optional` | existing strict Origin/Referer/Fetch-Metadata guard |
| Authenticated account `GET` | `session_cookie` | none |
| Account mutations, logout | `session_cookie` | session-bound token |
| Login/register/verify/recovery | anonymous | strict origin + rate limits (no session token exists yet) |
| Header/account hydration | `session_cookie:optional` | `GET`, `private, no-store` |
| Bearer-only API | existing bearer auth | CSRF-exempt |

`SameSite=Lax` is defence in depth. For cached-form mutations the exact-origin guard
(`ShopCsrfGuard`) is the primary protection.

**Test:** a route-manifest test asserts every unsafe route using cookie authentication carries
exactly one approved policy from this matrix. A new unsafe cookie route with no policy fails the
suite.

## §6 Identity: activation primitive and contracts

### 6.1 `VerifiedAccountActivator`

```
activate(
    string $intentUuid,
    string $continuationToken,
    string $purpose,
    callable $afterIdentityCreated,
): array
```

`$continuationToken` is the existing pass-through credential, carried into the result exactly as
today. Extracted from `MemberSignupService::activate()`, preserving today's atomicity. Inside one
`runAsTenant(... transaction(...))`, in order:

1. `lockForUpdate($intentUuid)`, then — **under that row lock** — assert
   `$intent['kind'] === $purpose`, failing with the same non-revealing 404 the current code raises
   for a non-`member` intent. This is the single guard that keeps a customer intent from ever
   reaching the member continuation (and vice versa), so it must hold **before either continuation
   is invoked**, not as an optimistic pre-check. Today's equivalent check sits outside the
   transaction; moving it under the lock is deliberately stricter.
2. Consume-guard (duplicate clicks are idempotent — a consumed intent returns its recorded
   outcome, it does not create a second identity).
3. Existing-email handoff: a secure sign-in/recovery handoff, never a second account.
4. Username conflict check.
5. Identity creation (`status: active`, `email_verified_at` set) and profile write.
6. **`$afterIdentityCreated($userUuid, $intent)`** — the purpose-specific continuation.
7. `intents->setResults()`, `intents->consume()`.
8. Commit; audit on `afterCommit`.

`MemberSignupService` passes a continuation that validates workspace policy and calls
`addMember()`. `CustomerSignupService` passes a no-op. A membership failure rolls the identity
back with it — no consumed intent with an orphaned identity. The boundary is structural: customer
signup has no code path that reaches `addMember()`, rather than relying on an implementer
remembering to omit one call.

The intent records the originating tenant and a safe return path; the created identity is
**global**. No session is issued before verification. Activation may, afterwards, issue the
storefront session and then run cart merge, guest-order claiming and wishlist import (§8, §9).

### 6.2 Contracts (`thallo-contracts`)

- `StorefrontAccountRegistration` — begin registration, resend, verify, activate, plus the neutral
  result shapes. Implemented by the app over `VerifiedAccountActivator`; consumed by
  `thallo-account`.
- `StorefrontAccountRecovery` — begin recovery and complete recovery, with a **closed neutral
  result** that cannot express "unknown email" or "delivery failed" to a caller. `thallo-account`
  needs unconditional neutrality (§7), which `glueful/users`' `AccountController::forgotPassword()`
  does not provide: its neutral branch is conditional on `security.auth.generic_error_responses`,
  it declares a 404 in its own OpenAPI attributes, and mail-delivery failure follows a third path.
  App glue implements this contract over `glueful/users`, collapsing all three outcomes and logging
  operational failures internally. Without this contract, `thallo-account` would have to reach past
  the boundary into the users extension and re-derive neutrality itself.
- `AccountNavigationRegistry` — account-area sections contributed by packs (label, route, order,
  capability requirement). `thallo-account` renders it; `thallo-commerce` contributes to it.

## §7 Registration, verification and recovery semantics

- **Enumeration neutrality is unconditional on the storefront.** Thallo's registration and
  recovery surfaces return the **same status and body** for known email, unknown email, and
  mail-delivery failure. This must not depend on `security.auth.generic_error_responses`, which is
  a global toggle the storefront cannot assume. Operational failures are logged internally.
  All three paths are tested. Neutrality is delivered by the `StorefrontAccountRecovery` contract
  (§6.2) — whose result type cannot express the difference — rather than by `thallo-account`
  calling the users controller and re-deriving neutrality itself.
- Rate limiting and throttling reuse the existing signup throttle.
- Verification creates the user exactly once; duplicate link clicks are idempotent.
- Registration collects email, password and display name — three fields, no username. The
  pipeline's `username` requirement is satisfied by a derived, collision-resolved value the shopper
  never sees or supplies; its uniqueness conflict is retried internally rather than surfaced as a
  form error. The display name is written through the same profile write the pipeline already
  performs (`first_name`; `last_name` empty). Shoppers are identified by email.
- Guest checkout remains fully supported. Accounts improve order history, addresses, cart
  continuity and wishlists; they are never required to purchase.

## §8 Cache invariants

`ShopPageCache` and `RenderPageCache` never parse cookies, so per-visitor state can never be
server-rendered into a cacheable page.

- Cacheable catalog routes never run `session_cookie` and never emit `Set-Cookie`.
- Account-dependent chrome renders a **universal shell** and hydrates from a `private, no-store`
  endpoint (`GET /_account/session`), exactly like the mini-cart.
- Account routes are uncached and require cookie authentication.
- Cart/checkout mutations may use optional cookie authentication but remain private and uncached.
- **Anonymous-parity definition:** enabling `thallo.accounts` alone changes no existing page
  unless the account header block is actually placed. Where starter provisioning places it
  automatically, the requirement becomes: **cached HTML is identical for authenticated and
  anonymous visitors**, with identity hydrated privately.

**Poison-identity test.** An authenticated request to a cacheable catalog route must produce the
same body and the same cache identity as the anonymous request, contain no identity string, emit
no `Set-Cookie`, and prove `session_cookie` never ran.

## §9 Slice 1 — Account foundation

**Depends on:** the framework release only. **Splits across plans 1 and 2** (§14): item 1 below is
the framework deliverable, released before items 2–5 are built against it.

1. Framework: `SessionCookieMiddleware`, `SessionCookieIssuer`, extracted login orchestrator with
   the 2FA gate, `POST /auth/session/refresh`, `auth.session_cookie.*` config.
2. `VerifiedAccountActivator` extraction; `MemberSignupService` refactored onto it with no
   behavioural change; `CustomerSignupService` added.
3. `StorefrontAccountRegistration` and `AccountNavigationRegistry` contracts; app implementation.
4. `packages/thallo-account`: capability `thallo.accounts`, themed pages under `/account/*`
   (`login`, `register`, `verify/{intentUuid}`, `forgot-password`, `reset-password`, `logout`) and the
   `/account` shell rendering the navigation registry. Pages live under `/account/*` rather than
   top-level `/login` because render's `GET /{path}` catch-all serves content slugs. **The prefix
   is not the auth boundary:** `login`, `register`, `verify/{intentUuid}`, `forgot-password` and
   `reset-password` are anonymous routes (§5 row 5); the shell and every other `/account/*` route
   require cookie authentication. Gating the whole prefix with `session_cookie` would lock a
   signed-out visitor out of signing in. **Verification is OTP entry, not a magic link:**
   `SignupMailSender::sendVerification()` mails an OTP and `SignupCoordinator::verify()` accepts
   `(intentUuid, otp)`, so `/account/verify/{intentUuid}` renders an OTP-entry form. Converting the
   pipeline to emailed magic links is explicitly not in scope here; reusing it as built is the
   whole point of the extraction.
5. Session header block + `GET /_account/session` hydration endpoint.

**Proves:** a visitor registers, verifies, signs in over cookies, sees `/account`, signs out —
and **zero** tenant memberships, roles, permissions, or any other scoped authorization
assignments exist for that identity afterwards.

## §10 Slice 2 — Commerce account area

**Depends on:** slice 1 and the Commerce 1.8.0 release.

**Commerce 1.8.0 seam — `GuestOrderClaimService`.** A customer-safe operation over
`linkGuestToUser()` that verifies, before stamping: the guest credential, the tenant, the
email/identity relationship, and that the order is currently unowned. Every failure — unknown
order, wrong tenant, owned by another user, bad token — returns the same non-revealing 404.
Re-claiming an order you already own is a success no-op. Commerce stays Thallo-agnostic: it
exposes the service and does **not** listen for Thallo account events.

**Claiming policy.**

- *Credential-backed linking is automatic.* After activation or login, `thallo-commerce` reads the
  current tenant's `GuestOrderCookie` entries and calls `GuestOrderClaimService` for each. **Both**
  proofs are required: a valid guest token **and** a normalized verified-email match.
- *Historical email linking is explicit.* An "Import past orders for `email@example.com`" action
  inside the account area requires a fresh authenticated session and confirmation, and is audited.
  It may reuse the CLI's exact-match semantics, but as a deliberate customer operation — never a
  silent activation side effect. Email verification proves current mailbox control, not historical
  checkout ownership; recycled, shared or mistyped addresses would otherwise expose shipping
  addresses, downloads and full purchase history.
- *Linking never blocks authentication.* It runs after successful activation/login. A guest
  credential is cleared only after its order links successfully; failed entries are retained for
  retry.

**Thallo surfaces.** `/account` dashboard; `/account/orders` list and detail; `/account/addresses`
CRUD; authenticated address selection at checkout. All consume the existing Commerce REST.
Cart and checkout routes gain `session_cookie:optional`, which is all `user_uuid` stamping needs.

**Guest-cart merge** runs on successful login and on activation via `CartService::mergeIntoUser()`
with the cart cookie's token. Two pinned details: the **surviving cart's token is written back to
`CartCookie`** (the merge may return a different cart than the guest one), and a merge failure
never blocks login — log, continue, leave the guest cart addressable.

## §11 Slice 3 — Wishlist synchronization

**Depends on:** slice 1 and the Commerce 1.8.0 release. (Slice 2 is not a prerequisite.)

**Commerce 1.8.0 model.** `commerce_wishlist_items`: `uuid`, `tenant_uuid`, `user_uuid`,
`product_uuid`, `created_at`; unique on `(tenant_uuid, user_uuid, product_uuid)`; listing index on
`(tenant_uuid, user_uuid, created_at DESC)`. Service operations: list, add, remove, import.
REST: `GET/POST/DELETE /commerce/account/wishlist`, `POST /commerce/account/wishlist/import`.

**Storefront.** `shop.js` gains a single authority switch — signed in → account store, anonymous →
today's device store, **same interface** — so hearts, badges and the wishlist page do not branch.
Browser calls continue to go to `/_shop/*` endpoints owned by `thallo-commerce`, which call
Commerce services server-side, keeping one origin and CSRF story.

**Cache.** `/shop/wishlist` stays **one universal cached shell for everyone**; the client probes
`GET /_account/session`, selects its store, and loads data from private, no-store endpoints only.
`/account/wishlist` is an uncached account route or a redirect to the canonical universal page.
Anonymous rendering is byte-identical to today.

**Import rules** (inherited from the storefront-v1 spec, plus the cap):

1. Dedupe by product uuid.
2. Existing account chronology is preserved first; local-only items are appended in device-list
   order. Device v1 stores UUIDs only — no timestamps — so no timestamp ordering may be claimed
   or reconstructed.
3. Availability is re-validated; unavailable items are dropped.
4. The account list keeps the same **100** bound. `add` at the cap returns an explicit "wishlist is
   full" rather than silently evicting. Import fills the remaining headroom, leaves the overflow in
   local storage, and reports what did not fit. **No silent eviction of deliberately saved items.**
5. Only successfully imported uuids are cleared from `localStorage`.

Import runs after activation and after login, alongside cart merge, and never blocks either.

## §12 Tenancy

One global identity may shop across multiple Thallo workspaces. Everything shown under `/account`
— orders, addresses, carts, wishlist — is always scoped to the **current** workspace.

Cross-tenant tests prove a user with orders, addresses and wishlist items in tenant A sees none of
them in tenant B, and that a claim attempt across tenants returns the same non-revealing 404 as an
unknown order.

## §13 Testing strategy

**Authorization boundary.** After a customer activation, assert zero rows in tenant memberships,
roles, permissions, and any other scoped authorization assignment table for that identity.

**Session seam.** Cookie attributes exactly as specified; mixed-credential matrix (same identity →
bearer effective; mismatch → 401; either invalid → 401); expiry degrades to anonymous on optional
routes and 401s on required routes; refresh rotates both cookies and CSRF state and returns no
tokens; logout revokes server-side session and expires both cookies; bearer requests are
byte-compatible with today and remain CSRF-exempt.

**2FA and orchestration.** A 2FA-enabled account attempting cookie login receives no session and
no cookie. JSON login responses are byte-identical to pre-extraction output. No cookie-login path
reaches session issuance without the 2FA gate. Events assert per class: `SessionCreatedEvent` and
`SessionCachedEvent` fire exactly once on **both** transports; `LoginResponseBuildingEvent` and
`LoginResponseBuiltEvent` fire exactly once on JSON login and **never** on cookie login.

**Intent binding.** A customer intent passed to member activation, and a member intent passed to
customer activation, each fail with the non-revealing 404 **before any continuation runs** and
leave no identity, no membership and an unconsumed intent. Asserted under concurrent activation of
the same intent, since the guard's value is that it holds under the row lock.

**Neutrality.** Registration and recovery return identical status and body for known email,
unknown email, and mail-delivery failure, with `security.auth.generic_error_responses` toggled
**both ways** — the storefront's neutrality must not depend on it.

**Cache.** Route-manifest CSRF policy test; poison-identity test (§8); anonymous parity per §8.

**Claiming.** Credential-backed automatic claim links only with both proofs; missing or mismatched
proof leaves the order unlinked; cross-tenant and already-owned attempts return the same 404;
re-claim by the owner is a no-op success; failed credentials are retained for retry.

**Merge determinism.** Cart merge writes back the surviving token and never blocks login. Wishlist
import: chronology, device order, dedupe, availability, cap with overflow preserved and reported,
and clearing only imported uuids.

## §14 Dependency graph and sequencing

Five plans, not three. The two upstream releases are independently reviewable deliverables with
their own review and release gates — not implementation tasks buried inside a Thallo plan.

```
Plan 1  Framework session + login seams ──────► RELEASED (1.73.0 — Algedi)
                                                  │
Plan 2  Thallo account foundation ◄───────────────┘
        (activator, contracts, thallo-account)

Plan 3  Commerce 1.8.0: claim + wishlist seams ─► release
        (folded into the UNPUBLISHED 1.8.0            │
         alongside its batched catalog reads)         │
                        ┌─────────────────────────────┤
Plan 4  Thallo commerce account area ◄────────────────┤
Plan 5  Thallo wishlist synchronization ◄─────────────┘
```

Plan 1 blocks plan 2 and nothing else. **Commerce ships ONE release, not two:** 1.8.0 was never
published — its batched catalog reads still sit under `[Unreleased]` on commerce `dev` (last tag
`v1.7.0`) — so the claim service and wishlist model fold into that same unreleased version rather
than stacking a 1.9.0 on top. Plan 3 therefore ends in the single 1.8.0 release, and blocks plans 4 and 5. Plans 4 and 5 are independent of each other — the wishlist does not need the
account area. Plan 2 must land before either, since both need a signed-in visitor.

**Slices map to plans:** slice 1 (§9) splits across plans 1 and 2 — the framework seams, then the
Thallo account foundation built against the published framework. Slice 2 (§10) is plan 4 and slice
3 (§11) is plan 5, both preceded by plan 3's Commerce release.

**Plan 1 is gated on its own merits.** It refactors the framework's most security-sensitive path —
credential verification, the 2FA gate, session issuance — in service of a storefront feature, and
it ships to every Glueful app, not just Thallo. It is planned, reviewed, security-tested and
released as its own unit before any Thallo pack consumes it.

Thallo pins are bumped only after each upstream release is actually published.

## §15 Open items for planning

None. The work becomes five implementation plans in the dependency order of §14 — two of which
(plans 1 and 3) end in a published release that the plans after them build against.
