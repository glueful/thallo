# glueful/thallo-account

Storefront customer accounts for [Thallo](https://thallo.dev): themed registration, email
verification, sign-in, password recovery and an account dashboard for the site's visitors, as a
capability pack. A visitor account is an ordinary `glueful/users` user row with no role and no
permissions, so it never grants access to the admin.

## What it provides

- **Account pages**, rendered through the active theme's `layout.twig` from the pack's
  `templates/account/` (a theme or an admin template override can replace any of them):

  | Page | Route |
  |---|---|
  | Sign in | `GET`/`POST /account/login` |
  | Register | `GET`/`POST /account/register` |
  | Verify email | `GET /account/verify`, `POST /account/verify/{intentUuid}`, `POST /account/resend` |
  | Forgot password | `GET`/`POST /account/forgot-password` |
  | Enter the reset code | `GET`/`POST /account/verify-reset` |
  | Set a new password | `GET`/`POST /account/reset-password` |
  | Account dashboard | `GET /account` (signed in) |
  | Sign out | `POST /account/logout` (signed in, CSRF token) |

  Anonymous form posts are same-origin checked and rate limited; signed-in mutations carry the
  framework's session-bound CSRF token.
- **Four block types** under **Account** in the Blocks tab: `auth-state` (Account state: separate
  signed-out and signed-in slots), `login-form`, `register-form` and `forgot-password-form`.
- **Chrome endpoints**: `GET /_account/session` (private, no-store sign-in state the blocks hydrate
  from) and `GET /_thallo/account/{file}` (the pack's fingerprinted scripts and stylesheets
  from `assets/`).
- **Settings › Accounts** in the admin (`/settings/accounts`, backed by
  `GET`/`PUT /v1/admin/settings/accounts`): the list of account page URLs and the two redirect
  settings, **After sign in** and **After sign out**.

## Turning it on

The pack ships with Thallo: `glueful/thallo-core` requires it at the same version and the project's
`config/serviceproviders.php` loads its provider. It registers the `thallo.accounts` capability,
whose owning package is `glueful/users`. A new project enables that extension, so the capability
is **on by default**. An operator turns it off or on in the admin under **Extensions ›
Capabilities** (stored system-wide; it overrides the deploy-time `thallo.capabilities` config map).
While it is off, every `/account` URL is a 404 and the account blocks leave the pickers.

Registration and password recovery send a one-time code by email, so the site needs a working
mail transport.

## Documentation

The user guide is [`docs/guides/17-accounts.md`](../../docs/guides/17-accounts.md). The internal
design notes are in `docs/internal/STOREFRONT_ACCOUNTS.md`.

## Contributing

This repository is a read-only mirror, published from
[glueful/thallo](https://github.com/glueful/thallo) on every release; its `main` is overwritten
by the next split, so nothing can land here. Issues and pull requests belong in glueful/thallo,
where this code lives at `packages/thallo-account/`.
