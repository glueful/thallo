# Storefront Customer Accounts

Storefront accounts give a shopping visitor a **global Glueful identity and nothing else** — no
workspace membership, no role, no permission. A shopper can register, verify their email with a
mailed one-time code, sign in over an HttpOnly session cookie, see their `/account` page, recover a
forgotten password, and sign out. That is the whole surface. The zero-authority guarantee is
structural, not a rule to remember: the activation path a customer travels has no branch that could
reach `addMember()`, and a test asserts the authority tables stay empty after a shopper activates.

The feature ships as the removable `glueful/thallo-account` capability pack, built on neutral
contracts in `glueful/thallo-contracts` and app-side glue over the existing signup pipeline.

## Requirements

- **`glueful/framework` ^1.74.0.** The customer's username defaults to their email, which is only
  possible because framework 1.74.0 widened username validation to accept full email addresses.
- **The HttpOnly session-cookie transport**, enabled by default. Thallo's `config/auth.php` sets
  `auth.session_cookie.enabled` to `env('SESSION_COOKIE_ENABLED', true)` — the account pages sign
  visitors in over this cookie, so it must be on for login to work. Enabling the transport does not
  cookie-authenticate any other route: the `session_cookie` middleware is opt-in per route, bearer
  API authentication is unchanged, and an operator can turn the transport off with
  `SESSION_COOKIE_ENABLED=false`. The cookie attributes — `Secure`, `HttpOnly`, `SameSite=Lax`, and
  a host-only domain — are the framework's defaults; Thallo does not restate them.
- **An email channel.** Registration and recovery mail a one-time code. Without a working channel
  no code arrives, but — by design — the visitor's experience is identical either way (see
  *Neutrality* below).

## Enabling the capability

`thallo.accounts` is a Thallo capability and is **on by default** — a registered capability with no
`false` entry in the `thallo.capabilities` switchboard is enabled. To turn the account surface off,
set it to `false` in that config map; the pack's routes and templates then do not register, while
the framework's own `/auth/*` identity infrastructure is untouched (the capability gates Thallo's
product surface, never global identity infrastructure).

## What registration collects

A shopper provides **first name, last name, email, and password**. The **username defaults to the
email** — there is no separate username field, no derivation, and no collision handling, because the
email is already unique. A shopper who later wants a different username cannot change it yet:
username/profile editing is a named follow-up, not a shipped feature. Treat "username equals email"
as today's default, not a permanent constraint.

## The routes

All routes live under `/account`. The prefix is deliberately **not** an auth boundary — gating it
wholesale would lock a signed-out visitor out of the very page they sign in on. Each route carries
exactly the protection its role requires.

| Route | Auth | Purpose |
|-------|------|---------|
| `GET /account/login` | anonymous | Sign-in form |
| `POST /account/login` | anonymous | Sign in; issues the session cookie |
| `GET /account/register` | anonymous | Registration form |
| `POST /account/register` | anonymous | Begin registration |
| `GET /account/verify` | anonymous | One-time-code entry |
| `POST /account/verify/{intentUuid}` | anonymous | Verify the code, create the identity |
| `POST /account/resend` | anonymous | Resend the code |
| `GET /account/forgot-password` | anonymous | Request a reset |
| `POST /account/forgot-password` | anonymous | Mail a reset code |
| `GET /account/verify-reset` | anonymous | Reset-code entry |
| `POST /account/verify-reset` | anonymous | Exchange the code for a reset token |
| `GET /account/reset-password` | anonymous | New-password form |
| `POST /account/reset-password` | anonymous | Set the new password |
| `GET /account` | cookie | The signed-in account page |
| `POST /account/logout` | cookie | Sign out; clears the session cookie |

Every route is classified `tenant_system`: a storefront account is a global identity with no tenant
scope.

### How the anonymous routes are protected (the CSRF matrix)

There are two rows, and a route-inventory test enforces them as a gate — a new unsafe route added
without a policy fails that test rather than shipping unprotected:

- **Anonymous unsafe POSTs** have no session yet, so there is no token to bind. Their control is
  **same-origin provenance** (`Sec-Fetch-Site`, else an exact `Origin` match) plus a **rate limit**.
- **Cookie-authenticated mutations** (`POST /account/logout`, and any future account write) use the
  framework's **session-bound CSRF token** (`csrf`) — a hidden `_token` the page renders.

## The registration flow

1. **`POST /account/register`** records a pending intent and mails a one-time code. It always
   redirects to a fixed `/account/verify` — never a URL that carries the intent id — so the redirect
   cannot distinguish a registered address. The pending intent travels in a short-lived HttpOnly
   cookie the verify page reads.
2. **`POST /account/verify/{intentUuid}`** checks the code and, on success, **activates** the intent
   into a real Glueful identity: a user row and a profile, and nothing else. The shopper is then sent
   to sign in.
3. **`POST /account/login`** verifies credentials through the framework's `LoginOrchestrator` and
   issues the session cookie. Login always runs through the orchestrator, so the two-factor gate is
   un-bypassable: if an account requires a second factor, login **fails closed** — no session, no
   cookie — because the storefront has no second-factor step yet.

Password recovery mirrors this: `forgot-password` mails a code, `verify-reset` exchanges the code for
a single-use reset token (carried in an HttpOnly cookie, never a URL), and `reset-password` sets the
new password and revokes the visitor's other sessions.

## Neutrality: a storefront is never an account oracle

Registration and recovery are built so their responses **cannot reveal whether an address is
registered**. The contracts make this structural: a recovery request returns only `accepted`, a
verification returns only `verified` plus an optional token — neither type can express "unknown
email" or "delivery failed". The app glue collapses every outcome — success, an already-registered
address, a throttle limit, even a mail-delivery failure — to the same neutral result. The operator
sees the real cause in the log; the visitor always sees "check your email".

## Contributing an account-navigation item

The `/account` page renders its navigation from the `AccountNavigationRegistry` rather than a
hardcoded menu. The pack ships with no items of its own; another pack adds a section by registering
an `AccountNavigationItem` during boot:

```php
use Thallo\Contracts\Account\AccountNavigationItem;
use Thallo\Contracts\Account\AccountNavigationRegistry;

$registry = app($context, AccountNavigationRegistry::class);
$registry->register(new AccountNavigationItem(
    id: 'orders',
    label: 'Orders',
    path: '/account/orders',
    order: 10,
    capability: 'thallo.commerce', // omit (null) for an always-visible item
));
```

Items render in ascending `order`. An item whose `capability` is disabled simply disappears — the
dashboard filters each item by its capability, so a section vanishes without its registration being
deleted.

## Account chrome is not server-rendered

The one piece of account UI that appears on **cacheable** storefront pages — a header block showing
"Sign in" versus the visitor's name — is **not** rendered server-side. A cacheable page must render
identically for every visitor, so a server-rendered header would either poison the cache with one
visitor's identity or force the page out of the cache entirely. Instead that header ships as a
universal shell hydrated client-side from a private, no-store endpoint (`/_account/session`).

The `/account` page itself is different: it is uncached and cookie-authenticated, so it renders the
signed-in visitor's own name directly and embeds a per-session CSRF token safely.

**The header block and the `/_account/session` endpoint are not part of this pack** — they ship in
the companion account-chrome work. This pack builds the server-rendered `/account/*` pages; the
cache-safe chrome is layered on top separately.
