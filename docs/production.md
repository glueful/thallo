# Running Thallo in Production

Everything on this page is stated once, per the capability that creates the obligation.
A tier-1 install (the fresh default) needs only the **Core** rows.

## Core (every install)

| Obligation | Required? | Detail |
|---|---|---|
| `BASE_URL` set to the canonical public origin | **Required** | Every absolute URL derives from it, never the Host header. HTTPS with no non-default port if you will ever mint payment links. |
| Scheduled-publishing cron | **Required** | `* * * * * php /path/to/site/glueful thallo:schedules:run` — fires due scheduled publish/unpublish actions. |
| `APP_ENV=production` (+ real `APP_KEY`/`JWT_KEY`) | **Required** | The shipped `.env.example` is already production (debug off, API docs off, HTTPS enforced); keep it that way and let `thallo:provision` generate the keys. `thallo:doctor` warns if a public `BASE_URL` runs in development mode. |
| Clear compiled containers on every deploy/update | **Required** | `php glueful` cache clears — a stale compiled container can construct services with outdated signatures (this failure class is designed to fail loud, not silently). See [upgrading](upgrading.md). |
| Queue workers | Recommended | Background jobs (mail, maintenance) degrade gracefully without them, but production should run the queue (`php glueful queue:work`; presets in `.env`). |
| Backups (database + `storage/`) | **Required** | Media, uploads, caches and the database carry all state. |
| HTTPS + proxy configuration | **Required** | Terminate TLS in front of PHP; forward proto/host headers correctly. |
| `zend.exception_ignore_args=On` (php.ini) | Recommended | Keeps sensitive values out of logged stack-trace arguments. |
| `logging.sensitive_paths` | Recommended | Committed defaults cover the payment-link paths; if you mount the app under a base path, register prefixed templates too. Reverse-proxy/CDN access logs are outside the app — see the redaction recipes in `packages/thallo-commerce/README.md`. |
| Signup cleanup / domain reverification | Automatic | Queue/background-driven once workspaces/signup features are in use — no extra cron entries. |

## Email (any capability that sends mail)

| Obligation | Required? | Detail |
|---|---|---|
| SMTP / mail transport configured | **Required** for mail features | Forgot-password, notifications, payment-request email. |
| Rich notification channel present | Required for payment-request email | Ships via the bundled EmailNotification extension; the "send payment link" email refuses cleanly (never crashes) without it. |

## Commerce (after `extensions:enable Commerce`)

| Obligation | Required? | Detail |
|---|---|---|
| Orders expiry sweep | **Required** | Cron `php glueful commerce:orders:expire` (e.g. every 15 minutes). Cancels stale storefront orders and stale drafts, and **hard-deletes canceled draft artifacts** older than `commerce.orders.draft_purge_days` (default 30, clamp 1–365 — no disable value; raise the window if you have retention requirements). |
| Stock / catalog reindex jobs | Automatic | Ride the queue when workers run. |

## Payments (after `extensions:enable Payvia`)

| Obligation | Required? | Detail |
|---|---|---|
| Gateway credentials | Required to charge | Settings → Payments (runtime-editable, encrypted, write-only) or `.env`. Keyless installs degrade to manual collection — nothing breaks. |
| Stale-intent sweep | **Required** | Cron `php glueful payvia:intents:sweep-stale` (daily is fine; on multi-workspace installs loop `--tenant` or drive it via your tenancy scheduler). Frees abandoned payment attempts after `payvia.intents.stale_after_days` (default 30). |
| Webhook endpoint reachable over HTTPS | **Required** | Provider webhooks settle payment-link orders; without them, orders await manual confirmation. |
| Paystack: integration `payment_session_timeout` = 0 | **Required if using Paystack** | A non-zero value silently dead-ends resumed checkouts. Set it in your Paystack dashboard. |
| Payment-link minting origin | — | Requires `BASE_URL` to be canonical HTTPS with no non-default port (see Core). |

## Workspaces / multi-tenancy (after enabling via Settings → Workspaces)

| Obligation | Required? | Detail |
|---|---|---|
| Enforcement lifecycle via Settings → Workspaces only | **Required** | Never toggle the tenancy enforcement provider by hand — the enablement flow owns it (begin → widen → confirm → finalize). |
| Tenant-scoped sweeps | **Required** | The payvia sweep (above) needs the per-tenant loop; core sweeps handle tenancy automatically. |

## Monitoring worth having (any install taking money)

Alert on: failed/undelivered provider webhooks, `payment_late_rejected` audit entries
(Admin → Audit, category *security* — a refused late or duplicate payment may need a refund),
mail failures, queue backlog, and 5xx rates.
