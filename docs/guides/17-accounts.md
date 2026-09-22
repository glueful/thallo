---
title: "Let visitors sign up and sign in"
slug: accounts
section: guides
order: 17
summary: "Give the site's visitors accounts: registration, sign-in and account pages."
---

At the end of this page a visitor can create an account on your site, confirm their email with a
mailed code, sign in, see an account page and sign out — on pages your theme renders, reached
from a **Sign in** link in the header.

You need an account that can manage content, a mail service the site can send through, and a
site served over HTTPS: the session cookie that signs a visitor in is marked `Secure`, so a
browser on a plain-HTTP origin throws it away. `/_thallo/*` must reach PHP as well, because the
account pages load their stylesheet from there — see
[running Thallo in production](../production.md).

## Check that Accounts is on

The pages come from the **Accounts** capability (`thallo.accounts`), which is on by default.
Open **Extensions › Capabilities** and find the **Accounts** row; its badge should read **On**.
The capability is backed by the `glueful/users` extension, which a fresh install enables, so the
row turns on without anything else. [Capabilities and packs](../concepts/06-capabilities.md)
explains the switchboard.

While the capability is off, every `/account` URL is a 404 and the account blocks disappear from
the pickers. Nothing needs to be created for it: no entry, no route and no template of your own.

## The pages it adds

| Page | URL |
|---|---|
| Sign in | `/account/login` |
| Enter your sign-in code (accounts with two-factor on) | `/account/login/verify` |
| Register | `/account/register` |
| Verify email | `/account/verify` |
| Forgot password | `/account/forgot-password` |
| Enter your reset code | `/account/verify-reset` |
| Set a new password | `/account/reset-password` |
| Account dashboard | `/account` |
| Profile | `/account/profile` |

The seven anonymous pages render without the site's header and footer — a single card on a plain
background, with the site logo at the top of the card, or the site's name when no logo is set.
The signed-in pages, the dashboard at `/account` and the profile, keep the header and footer, so
a visitor can get back to the site from them.

The dashboard links to **Profile**, where a signed-in visitor changes their first and last name
and their password. A password change asks for the current one, applies the same eight-character
rule as registration, and signs the account out on every other device; the device that made the
change stays signed in. The email is shown but cannot be changed there.

Registration asks for first name, last name, email and a password of at least eight characters.
The email doubles as the username. A verified visitor becomes an ordinary user row — they appear
under **Users & Access › Users** — with no role and no permissions, so an account on the site is
never an account in the admin.

## Set up the mail that carries the code

Registration and password recovery both send a one-time code by email, and nothing works without
it. Set the transport in `.env`:

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=my-site
MAIL_PASSWORD=SMTP_PASSWORD
MAIL_ENCRYPTION=tls
MAIL_FROM=no-reply@example.com
MAIL_FROM_NAME=My site
```

The password above is a placeholder; put your own in. Thallo sends the code while the request
runs, so no queue worker has to be running for it to arrive.

The wording is yours to change. **Settings › Accounts › Emails** holds the two mails a visitor
gets, **Customer email verification** and **Customer password reset**, apart from the admin's own
verification and reset mails in **Settings › Email**. Open one to edit its subject and body, send
a test, or go back to the default. `{{otp}}` is the code and `{{expiry_minutes}}` how long it
lasts; the reset mail also has `{{name}}`, the customer's first name.

These values in `.env` set how long a code lives and how often one can be asked for:

| Key | Default | What it sets |
|---|---|---|
| `SIGNUP_OTP_TTL_SECONDS` | `900` | How long a code stays valid. |
| `SIGNUP_OTP_ATTEMPTS` | `5` | Wrong guesses before the code is dead. |
| `SIGNUP_RATE_WINDOW_SECONDS` | `3600` | The window the two counters below run in. |
| `SIGNUP_RATE_PER_IP` | `10` | Registrations one address may start per window. |
| `SIGNUP_RATE_PER_EMAIL` | `5` | Registrations one email may start per window. |
| `SIGNUP_RESEND_PER_INTENT` | `3` | Times one visitor may ask for the code again. |
| `SIGNUP_MEMBER_DAILY_CAP` | `500` | Registrations the whole site accepts in a day. |

The registration and recovery pages never say whether an address is already registered, whether a
limit was hit, or whether the mail went out: every outcome looks the same to the visitor, so the
site cannot be used to test which addresses have accounts. That also means a broken mail
transport is invisible from the browser — the visitor reaches the code-entry page and no code
arrives. The reason is written to `storage/logs/`, which is the only place to look.

## Register one visitor to test it

1. Open `/account/register` on the site and fill the form in with an address you can read.
2. Press **Create account**. The browser lands on **Enter your code**.
3. Read the code from your inbox and enter it, then press **Verify**. If nothing arrived, press
   **Resend code**, and check `storage/logs/` before trying a third time.
4. You are signed in and taken to your account (or to **After sign in** under Settings › Accounts,
   when set).
5. Confirm the dashboard says **Signed in as** your email. Open **Profile**, change your name and
   press **Save name**, then go back and press **Sign out**.
6. Open **Users & Access › Users** in the admin: the new visitor is in the list.

## Choose where visitors land after signing in and out

Go to **Settings › Accounts**. The panel has three parts; the third, **Emails**, is described
under [the mail that carries the code](#set-up-the-mail-that-carries-the-code).

**Account pages** lists the six pages an operator normally links to — Sign in, Register, Verify
email, Forgot password, Account dashboard, Profile — each one a link you can open in a new tab.
It is a list, not a setting: the URLs are fixed.

**Redirects** holds the two settings:

- **After sign in** — where a visitor goes after signing in. Blank uses `/account`.
- **After sign out** — where a visitor goes after signing out. Blank uses `/account/login`.

Each box suggests destinations as you type, including your published pages. A value must be a
site-relative path with a single leading `/`; anything else is refused with "Enter a site-relative
path beginning with a single /." Press **Save**.

A sign-in form can override **After sign in** for its own visitors with a `next` value, described
below. Sign-out always uses the setting.

## Put sign-in on the site

The capability adds four block types, all under **Account** in the Blocks tab.

**Account state** shows one set of blocks to signed-out visitors and another to signed-in ones.
It has two block slots, **signed out** and **signed in**, and each takes only vetted children:
**Button**, **Links**, **Rich text**, **Logo**, **Navigation**, **Sign-in form**, **Registration
form** and **Password reset request**. This is the block for the header: put a **Button** to
`/account/login` in **signed out** and one to `/account` in **signed in**. It is allowed in both
regions — see [edit the header and footer](02-header-and-footer.md).

Both branches are in the HTML of every cached page and a small script hides the wrong one, so
**Account state** is presentation only. Never put anything private in **signed in**; a visitor
who reads the page source sees both.

The other three put a form straight onto a page:

- **Sign-in form** — fields **heading**, **next** and **show links**. **next** is the path the
  visitor goes to after signing in, which beats the **After sign in** setting. A wrong password
  brings the visitor back to the page the block is on, with the message "We could not sign you
  in. Check your email and password." above the form; with JavaScript off the same message
  appears on `/account/login` instead. **show links** prints the "Forgot password?" and
  "Create an account" links under the button.
- **Registration form** — field **heading**. It continues to the code-entry page.
- **Password reset request** — field **heading**. It continues to the reset-code page.

## Restyle the account pages

The pages extend your theme's `layout.twig`, so they already use its fonts, colours and chrome.
Three ways to change the rest, in increasing order of effort:

- **Write CSS.** Open **Site › Theme editor** and pick `custom.css` under **Site**. It loads
  after every other stylesheet, so its rules win. The card is `.account__card` inside `.account`,
  the title is `.account__heading`, each labelled input is `.account__field`, the button is
  `.account__submit`, and `.account-bg` is the full-viewport background behind the anonymous
  pages. The blocks use `.thallo-block-login-form`, `.thallo-block-register-form` and
  `.thallo-block-forgot-password-form`, each with `__heading`, `__field` and `__submit` beneath
  it.
- **Edit a template in the admin.** The same screen lists `account/layout.twig` and one file per
  page — `account/login.twig`, `account/register.twig` and the rest — marked as package
  templates. Saving one stores your version in the database; the package file is untouched, and
  **Delete override** brings the original back.
- **Ship a template in your theme.** Put a file at `templates/account/login.twig` inside your
  theme's folder and it replaces the packaged one for that theme. Fallback is per file, so a
  theme overrides only the pages it cares about. See
  [how themes work](../concepts/04-themes.md) and
  [make your own theme](13-make-a-theme.md).

## What the account pages cannot do yet

- A visitor cannot change their email address. The profile page changes the name and the
  password only.

## Check it worked

Sign out, then load the site's home page as a stranger: the header offers **Sign in**. Register a
second address, verify it, and confirm you land on the path you set in **After sign in**. Sign
out and confirm you land on the path you set in **After sign out**. If a code never arrives, the
answer is in `storage/logs/` — the pages themselves will not tell you.
