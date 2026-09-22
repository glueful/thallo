---
title: "Security"
slug: security
section: operations
order: 6
summary: "What Thallo protects by default, the secrets a site holds, and what is yours to do."
---

Thallo generates its own keys, locks its first-run setup screen, hashes every credential it
stores, and refuses a template or an upload that could run code. It does not terminate TLS, send
the transport security headers or choose your policy. This page covers both halves.

## The three keys provision generates

`php glueful thallo:provision` writes three random values into `.env` where they are empty, and
never overwrites one that is already there.

| Key | Length | What it protects |
|---|---|---|
| `APP_KEY` | 32 characters | AES-256-GCM encryption of the secrets kept in the database — the gateway keys saved under **Settings › Payments** — and the HMAC behind signed preview tokens, sealed form descriptors and persisted queue and scheduler payloads. |
| `TOKEN_SALT` | 32 characters | Salts the SHA-256 fingerprint a session token is stored under, so the sessions table is not a list of usable tokens. |
| `JWT_KEY` | 64 characters | Signs the access and refresh tokens a signed-in session carries. `JWT_ALGORITHM` chooses the algorithm; only `HS256` (the default), `HS384` and `HS512` are accepted. |

`php glueful thallo:doctor` reports all three under its `keys` check and warns when one is
missing. Anyone who can read `.env` holds all three: it sits beside `public/`, never inside it,
and it is the file a backup must carry and an attacker must not reach. See
[back up and restore](04-backups.md).

## Rotating a key

Changing `APP_KEY` makes every value encrypted under the old one unreadable. Keep the old value:
`APP_PREVIOUS_KEYS` in `.env` takes a comma-separated list, decryption tries the current key
first and then each previous key in turn, and every new write uses the current key. A stored
secret moves onto the new key when it is written again — re-enter the gateway keys under
**Settings › Payments** and save, and they are re-encrypted with the current key.

`php glueful encryption:rotate --table= --columns=` re-encrypts named columns in place, but it
binds each value to the AAD `{table}.{column}`. Nothing Thallo stores is encrypted under that
convention, so the command is for columns your own code wrote that way, not for Thallo's.

Signatures are not stored data, so nothing has to be migrated for them. A preview token minted
under the old key stops verifying, which matters for at most `PREVIEW_TTL` seconds — 600 by
default. A queued job signed under the old key is refused outright, so drain the queue before
you rotate.

Changing `JWT_KEY` or `TOKEN_SALT` signs everybody out. To end sessions without touching a key:

```bash
$ php glueful security:revoke-tokens --all --reason="key rotation"
```

`--user` limits it to one account, `--type` to `access` or `refresh`, `--older-than` to tokens
past an age, and `--force` skips the confirmation. `php glueful generate:key` mints a fresh
`APP_KEY` and `JWT_KEY` (`--app-only`, `--jwt-only`, `--force`); it does not touch `TOKEN_SALT`.

## The setup token

First-run setup is a `POST /admin/setup` nobody is signed in for — there is no admin yet — so
`SETUP_TOKEN` is what stops the first stranger who finds a fresh deployment from claiming it.
Provision mints a 40-character token when `.env` has none and prints the setup link with the
token in its query string. The admin reads it once, drops it out of the address bar and sends it
back as an `X-Setup-Token` header, compared in constant time.

- With a token set, a request that does not carry it is refused with 403.
- With no token set, setup is refused in production and stays open outside it, which is what
  keeps a local install zero-configuration.
- The endpoint locks itself the moment the first admin exists. Every later call answers 409, and
  `SETUP_TOKEN` is blanked from `.env`.

Run provision again to print the link again. `php glueful thallo:create-admin` creates the first
admin from the terminal instead, with no HTTP door opened at all. See
[install Thallo](../getting-started/02-install.md).

## HTTPS and the production settings

The shipped `.env.example` is a production file: `APP_ENV=production`, `APP_DEBUG=false`,
`CORS_ALLOWED_ORIGINS` empty and `CORS_SUPPORTS_CREDENTIALS=false`. Keep it that way, and set
`BASE_URL` to the canonical HTTPS origin — every absolute URL Thallo writes comes from it and
never from the request's `Host` header.

Thallo does not redirect HTTP to HTTPS itself. `FORCE_HTTPS` only feeds the advice
`php glueful security:check` prints; no middleware acts on it. Terminate TLS in front of PHP and
redirect there.

Behind a reverse proxy or a load balancer, list its addresses in `TRUSTED_PROXIES`
(comma-separated, CIDR allowed). Empty trusts none, and the forwarded headers are ignored, so
the client IP a rate limit counts and the audit log records is the proxy's.

Of the `headers` block in `config/security.php`, only `CSP_HEADER` reaches a response.
`HSTS_HEADER`, `X_FRAME_OPTIONS`, `X_CONTENT_TYPE_OPTIONS` and `X_XSS_PROTECTION` are read by
nothing and are not sent: set `Strict-Transport-Security`, `X-Frame-Options`,
`X-Content-Type-Options` and `Referrer-Policy` in the web server. The admin at `/admin` is a
separate case — it is a mounted single-page app and its document carries its own policy plus
`X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` and
`X-Frame-Options: SAMEORIGIN`.

The API reference at `/api-docs` is served in every environment, including production. Set
`API_DOCS_ENABLED=false` to stop serving it.

Once the install is complete and `APP_ENV=production`, every boot writes a `[security] WARNING:`
line to the PHP error log for an `APP_KEY` or `JWT_KEY` that is missing or shorter than 32
characters and for `APP_DEBUG` left on. Advisory recommendations — a wildcard
`CORS_ALLOWED_ORIGINS`, an empty `CSP_HEADER`, `LOG_LEVEL=debug` — go to the same log as
`[security] RECOMMENDATION:` lines, written once and then only when the set of them changes.

## The content security policy the theme needs

`CSP_HEADER` is sent verbatim as `Content-Security-Policy` on every response that does not
already carry a policy; the admin's document policy and a capability's own header keep
precedence. `CSP_REPORT_ONLY=true` sends it as `Content-Security-Policy-Report-Only` instead, so
the browser logs what the policy would block and blocks nothing. `.env.example` ships a policy
that way:

```ini
CSP_HEADER="default-src 'self'; img-src 'self' data: blob: https:; media-src 'self' https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'"
CSP_REPORT_ONLY=true
```

Once the console is quiet for your theme, set `CSP_REPORT_ONLY=false` to enforce it.

To tighten `script-src` past `'unsafe-inline'`, you need two hashes. The theme emits exactly two
inline scripts, both byte-stable:

| Inline script | Add to `script-src` | Published as |
|---|---|---|
| The colour-mode resolver, in the head of every page while colour mode is on | `'sha256-LPPpGD9ammrw92nJUwoMRPu1xnHk26P8c3tFKYUe8OE='` | `Thallo\Render\ColorMode::RESOLVER_SHA256` |
| The motion flag, on a page holding a block with an entrance | `'sha256-oITHwt56P1Z4Ld9JC9e+G5nYf5B76v5qE0R8X48Xz9E='` | `Thallo\Render\Motion::FLAG_SHA256` |

A test fails the build if either script's bytes drift from its hash, so the two values stay
correct. Neither is load-bearing: blocked, the resolver leaves the page in light mode and the
flag hides nothing, so every block is simply shown. See
[animate a page](../guides/06-animation.md).

`style-src` has to keep `'unsafe-inline'`. Three inline `<style>` elements vary with the site's
own settings — the theme colours and design settings, a Style block's scoped tokens, and the
theme's `@font-face` block — so no fixed hash covers them, and a page that is cached cannot
carry a per-request nonce. What varies is generated from closed lists of choices, not from free
CSS.

Everything else the theme loads is same-origin: the stylesheets under `/theme-assets/`, the
runtime and the site's custom stylesheet under `/_thallo/`. Add a CDN's origin to `img-src` if
media is served from one.

## What a saved template may do

A template saved from **Site › Theme editor** is stored in the database, and it is checked
against a fixed allowlist twice: when it is saved, which answers a refusal line by line, and
again before it is compiled, so a row written straight to the database is caught too. That check
is the whole sandbox. There is no second one at render time, and what it allows is what runs.

The allowlist is default-deny and not configurable. Tags are `if`, `for`, `set`, `block`,
`extends`, `include`, `verbatim`, and `macro` with `import` in the one shape
`{% import _self as x %}`. Filters, functions and tests are each an enumerated list. `raw` is not
on it. Neither is `matches`, because a regular expression written in a template is a
denial-of-service waiting to happen; the two places the shipped templates needed one are now
bounded PHP helpers. Arrow functions are denied, which is how `map`, `filter` and `reduce` stay
out, and `extends` and `include` take a constant string only.

Two shipped templates use vocabulary the check will never accept, so the admin marks them
read-only with the reason rather than offering a Save that always fails: `blocks/html.twig`, the
raw-HTML escape hatch, and `blocks/shortcode.twig`, which dispatches through a non-constant
include. Templates edited on disk are not checked at all.

The site's `custom.css` is capped at `CUSTOM_CSS_MAX_BYTES` bytes — 262144 by default — and
checked for encoding only. CSS is never parsed, so a broken rule loses in the browser instead of
breaking the page. Editing any of this needs the **Manage templates** permission; see
[make a theme](../guides/13-make-a-theme.md). `RENDER_DB_TEMPLATES=false` in `.env` turns
database templates off altogether: the site renders from the filesystem and the theme editor's
routes are not registered.

## What an upload has to satisfy

`config/uploads.php` holds the limits, and every one of them is enforced on the bytes rather
than on what the browser claims.

- `allowed_types` ships as `image/*`, `video/*`, `audio/*`, `application/pdf` and `font/woff2`.
  The type is detected from the file's own contents.
- `max_size` caps one file at 10 MB; `UPLOADS_MAX_SIZE` changes it, in bytes.
- The first 64 KB of every upload is scanned, and a file containing `<?php`, `<?=` or `<script`
  is refused — which is what keeps an SVG carrying script out.
- Each file is stored under a generated name on the `uploads` disk, `storage/uploads/` by
  default. That is outside the document root: the bytes are served by the application at
  `/v1/blobs/{uuid}`, never as a file the web server might execute.
- `UPLOADS_ACCESS` ships as `upload_only`: uploading and deleting need an admin session,
  retrieval is decided per file. The media library stores what it uploads as public, so treat
  anything put there as readable by anyone holding the URL.
- Uploads are rate limited to 30 a minute and retrieval to 200, per
  `uploads.rate_limits`.

See [manage images and files](../guides/08-media.md).

## API keys

An API key is a bearer credential for the content API. Make one under **Developers › API Keys**,
or with `php glueful apikey:create`. Only a SHA-256 hash of the key is stored, so a key that is
lost can be rotated or revoked but never read back; the plaintext is shown once, when it is
created and when it is rotated.

Give every key the narrowest scope that works. A key created with no scopes at all has full
access; `read:content` reads every content type and `read:content:{type}` reads one. A key can
also carry an allowlist of IP addresses or CIDR ranges and an expiry date. **Rotate key** issues
a successor and keeps the old one working for a grace period, so a deploy can overlap;
**Revoke key** stops it at once. From the terminal: `php glueful apikey:list`, `apikey:rotate`
and `apikey:revoke`.

Generated keys read `{prefix}_live_…` in production and `{prefix}_test_…` elsewhere;
`API_KEY_PREFIX` sets the prefix, `gf` by default. [The content API](../concepts/07-api.md)
covers what each scope reads.

## The other credentials a site holds

- Account passwords are hashed with bcrypt at cost 12. Nothing stores or logs a password.
- Preview URLs carry a signed token that is itself the permission: anyone you send one to reads
  the draft until it expires. See [drafts, preview and publishing](../concepts/05-publishing.md).
- Payment-link tokens are bearer credentials in a URL. `logging.sensitive_paths` replaces them
  with `[REDACTED]` in every log sink Thallo writes to. Reverse-proxy and CDN access logs are
  outside the application and remain yours to redact.
- The audit log redacts passwords, secrets, API keys and anything ending in `_token` before a
  row is written. See [users, roles and permissions](../guides/15-users-and-roles.md).
- Public form submissions are protected by a honeypot, a time trap and a per-form, per-IP rate
  limit; see [add a form](../guides/07-forms.md).

## Check the posture

```bash
$ php glueful thallo:doctor
$ php glueful security:check --production
```

Doctor prints one row per check; `keys` must be OK. `security:check` prints seven groups and a
summary table with a readiness score; the first group is the one that carries signal, and it
lists the same production warnings boot writes to the error log — outside production it reads
"not applicable". Then confirm from outside that the policy arrives:

```bash
$ curl -sI https://example.com/ | grep -i content-security-policy
```

A report-only policy arrives as `content-security-policy-report-only`. No line at all means
`CSP_HEADER` is empty or something in front of PHP is stripping it.

## Reporting a vulnerability

Do not open a public issue for a security report. Email the maintainer — `michael@glueful.dev`,
the address in the package's `composer.json` — with a description and, where possible, a
reproduction. You will get an acknowledgement, and a fix lands as an immutable release with the
issue credited if you wish.

During the Developer Preview, only the latest release receives fixes. From the first stable
release, the latest minor receives security fixes.

Thallo's security engineering assumes the posture this page and
[running Thallo in production](../production.md) describe: `APP_ENV=production`, real generated
keys, HTTPS with a canonical `BASE_URL`, caches cleared on upgrade, and the redaction above.
What Thallo cannot do yet is in [known limitations](../limitations.md).
