# What writing the docs found

Each page's writer reported what the code did not do, what the prose got wrong, and what a
reader would miss. This is that list, kept so nothing is lost. `FOUND-REVIEW.md` checked every
entry against the code on 2026-09-22; this version applies its corrections, merges its
duplicates and moves fixed work to the end.

Every entry carries its evidence:

- **Test**: a regression test reproduces it, or pins the fix.
- **Code**: the mechanism is read in the code, and the review agreed.
- **Reported**: the writer's claim, not yet confirmed. Prove it before fixing it.

## Bugs in the framework (need a Glueful release)

- **Content webhooks never deliver.** Code, and the review ran the type probe.
  `WebhookDispatcher::queueDelivery()` and `Webhook::retry()` pass a job object to
  `QueueManager::push(string $job, …)` under `strict_types`, a certain `TypeError`. The delivery
  row is written first and stays `pending`. The event dispatcher logs the listener error, so the
  publish succeeds with nothing on screen. **Retry** 500s on a failed or retrying row; a pending
  row is refused before it. **Send test event** is synchronous and works, with no guard on the
  destination address. The `cleanup` config is read by nothing. Deleting a subscription may
  orphan its delivery rows (Reported); the dialog no longer claims they are removed. Until the
  framework is fixed, consider hiding the Content webhooks switch. (webhooks)
- **The scheduled database backup cannot back up a stock install.** Code. `DatabaseBackupTask`
  reads flat `driver`, `database`, `username` keys from a config shaped `engine` and
  `pgsql { db, user, pass }`, falls back to `mysqldump` with empty credentials, and logs
  "completed" with `Backup created: No`. Thallo now ships the job off. (backups)
- **`DatabaseQueue::pop()` takes no row lock.** Code. Two workers on one queue can select the
  same job. Expired reservations are released inside `pop()`, so a lone worker never releases its
  own running job; a second, overlapping worker reclaims one older than `retry_after`.
  (scheduler-and-queues)
- **`log_cleanup` ignores `LOG_RETENTION_DAYS`.** Code. The config passes `retentionDays`;
  `LogCleanupTask` reads `retention_days`. (troubleshooting)
- **No `queue:failed` or `queue:retry` command, and no admin screen for failed jobs.** Code, by
  inventory. (scheduler-and-queues)

## Bugs in Thallo

### Content, publishing and delivery

- **The delivery API's `published_at` is not ISO-8601.** Code, and the review ran the probe.
  `DeliveryItemShaper::item()` forwards the raw `timestamp without time zone`; `docs/openapi.json`
  declares `format: date-time`. (api)
- **`?expand=` narrows the response.** Code. `FieldSelector` merges `expand` into the field
  selection, so asking to expand one reference drops the other fields. (api)
- **A failed schedule is silent.** Code. The reason is stored on the row; the Publishing panel
  shows a status badge and no reason, and nothing at the entry warns that the scheduler cron is
  missing. (publishing)
- **`token` is offered as a field type and cannot be saved from the admin.** Code. The schema
  editor has no domain input and the server requires one. `box` is allowed by the backend and
  never offered. (first-content-type)
- **Three "default locale"s can disagree:** `i18n_locales.is_default`, Settings › General and
  `config/i18n.php`. Code. Disabling a language does not stop the delivery API serving it
  (Code). (languages)
- **Block-built page bodies are not indexed.** Code. `DocumentBuilder::INDEXABLE_TYPES` is
  `string` and `text`, so a Design-view page contributes only its title. Thallo search and the
  Meilisearch extension both declare `search:status`; which one wins is Reported. (search)

- **Analytics keeps recording content events after it is switched off in the admin.** Code.
  The content and collection event bridge in `CoreServiceProvider` reads only the
  `thallo.capabilities` config map, not the stored admin switch; only the auth listeners honour
  the switch. (found while fixing the analytics README)

### Admin

- **A single asset cannot be cleared.** Code. `AssetField.vue` has a remove control only in its
  `multiple` branch, so a chosen logo or favicon cannot be unset. (appearance)
- **API key scopes cannot be edited after creation from the detail pane**, though the endpoint
  exists. Code. (api)
- **Navigation: the editor allows six levels, the default theme draws three.** Code. Deeper items
  are stored and served and never shown. A 409 on save clears the unsaved tree and refetches.
  (navigation)
- **Two-factor sign-in is unsupported.** Code. Login answers with a `challenge_token`; the admin's
  session store rejects it as "Malformed login response", and the storefront refuses challenge
  outcomes because it has no second-factor screen. (users-and-roles, accounts)
- **The workspaces enablement screen gives no warning before its refusals.** Code. Enabling is
  refused with any data collection defined, and on a cache driver without pattern purge.
  (workspaces)
- **`csv.users` shows on the Import page past the capability gate, with no mapping UI there.**
  Code. A gated publish is counted as a failed record (Reported). (import-content)
- **A block type's template cannot be created from the admin.** Code. The copy now says to put
  it in the theme's templates folder. A block-type migration locks the entries until a worker runs
  and the dialog does not say so (Reported). (make-a-block-type)
- **Media.** Split, each needs its own check:
  - The panel's "File URL" shows the raw storage path, not `display_url`. Code.
  - Search uses `LIKE`; case sensitivity depends on the collation. Code.
  - Deleting a file soft-deletes it; the bytes are never reclaimed. Code.
  - Alt text, caption and tags reach the admin API but no theme, delivery field or block.
    Reported.
  - "Used in" ignores blocks; Optimize leaves stale variants. Reported.
  - `UPLOADS_STRIP_EXIF` is read by nothing. Code, by search.
  (media)

### Setup, operations and security

- **`QUEUE_CONNECTION=sync` resolves no driver.** Code. `config/queue.php` lists `sync` and
  `null`; only `database` and `redis` exist. Fixed in the docs; the config still lists them.
- **Security headers cover the rendered site only.** Code. The rendered site now sends nosniff,
  a referrer policy, X-Frame-Options and HSTS; the SPA documents send their own. The framework's
  `SecurityHeadersMiddleware` is applied to no route, so admin and delivery JSON get none of it,
  and `config/security.php`'s `headers` block is read by nothing. There is no HTTPS redirect:
  the docs now say that is the web server's job. (security)
- **`thallo:doctor` checks the theme in `RENDER_THEME`**, not the one chosen in Appearance.
  Code. A theme's stylesheets do not fall back to the default's (templates do); a theme without
  a valid manifest can fail to load rather than render unstyled. (make-a-theme)
- **Provision re-grants the install roles' permissions on every run.** Code. It reapplies grants
  to superuser and administrator, so a permission revoked from those two comes back on upgrade.
  Other roles are untouched. (users-and-roles)
- **Commerce permissions reach the install roles only on re-provision.** Code. Which non-superuser
  roles lack them after enablement is Reported. The cart cookie is `Secure` unconditionally.
  Code. (commerce)
- **The account pages: no profile surface, no auto-login after verification, no admin editor for
  the mails.** Code. A mail transport failure may be invisible because the mail channel reports
  available on an unconfigured install (Reported). (accounts)
- **`permissions:diff` may report every Thallo permission as unenforced**, blind to the
  `content_permission:` middleware (Reported). `workflow.bypass` is not in `CapabilityCatalog`
  (Code). (permissions)
- **Self-serve checkout sends a public visitor into the admin.** Code. Pricing deep-links to the
  admin's `/billing`; there is no public subscribe flow and **Change plan** is disabled. The
  Thallo plan picker has no price, currency or interval. A plan is purchasable through its
  provider mappings, not the scalar `provider_price_id`. (subscriptions)
- **Pack blocks (five shop, four account) declare no `layout.item`.** Code. They lack the
  Container's basis, span, grow, shrink and align-self controls; the browser still sizes them,
  and some declare a width. (block-library)
- **The scheduler check cannot change the Health page's overall status.** Code. It is appended
  after the framework's overall status is taken. (troubleshooting)
- **`import-export:cleanup` leaves completed exports on disk.** Code. It unlinks only temporary
  files, then deletes every file and job row, so finished exports become unreachable from the
  admin and are never removed. (backups)
- **Enabling workspaces from a terminal has no way out of `failed`.** Code. Retry exists in the
  service and the admin, not the command. No shipped command prints a user uuid for `--owner`.
  (multi-site)
- **No path to re-key stored secrets.** Code. `encryption:rotate` binds `{table}.{column}` as the
  AAD; the payment settings store binds the settings key. `security:check`'s score is mostly
  stubs (Reported). (security)
- **Dead config.** Code, by search. `extensions.install.auto_enable` in `config/extensions.php`;
  the `settings` block of `config/schedule.php` (`SCHEDULER_ENABLED`, `MAX_CONCURRENT_JOBS`,
  `USE_QUEUE_FOR_SCHEDULED_JOBS`, `queue_mapping`, which names a job that does not exist); each
  job's `queue`, `timeout` and `retry_attempts` on the inline scheduler path; `allowed_operators`
  in `config/api.php` (its comment now says so); `MAIL_BCC` and `MAIL_LOGO_URL`.

## Things a reader cannot do, or is not told

A missing feature is not a regression. These are product decisions to make, or to document in
`docs/limitations.md`.

### Install and setup

- **Nothing creates the PostgreSQL database.** Provision needs an existing one. The install page
  and both READMEs now say so.
- **`SETUP_TOKEN` is kept after `thallo:create-admin`**, and blanked only after web setup.
- **The CLI accepts an 8-character admin password**; the web form applies the shared policy.
  Compare the two against the policy's tests before quoting a rule count.
- **Provision prints the setup link from `BASE_URL`**, which is often still `localhost` then.
- **No CLI for the capability switchboard.** Some flips need a reload; which ones depends on the
  capability.

### Content

- **Content-type schema migrations have no admin UI.** The field editor shows Remove and type
  changes; the save refuses both and now names the migration route. A field can never be retyped.
- **Scheduled unpublish has no UI.** The API and the runner accept it; the Publishing panel only
  posts `publish`.
- **Version pruning is CLI-only and unscheduled**, so `entry_versions` grows unless an operator
  prunes.
- **The default theme renders only `title` and `body` of a custom type.** Every other field needs a
  template, and a rich-text `body` renders escaped.
- **A listing is a 404 until the type is in `listing_types`**, a setting far from the type. The
  listing's `h1` prints the slug, not the Name.
- **Content-type fields have no labels.** The starter form reads "title" and "body".
- **The Landing page pattern may duplicate the page heading** (theme title plus hero title), if
  `show_title` stays on. Verify the whole pattern application first.
- **The Design button may show on entries of a type with no `blocks` field.** Not traced.
- **Content imports.** CSV and the other format adapters only create; the bundle importer
  upserts. The bundle carries the blob manifest, not the files. Nothing schedules
  `import-export:cleanup`.

### Design and themes

- **`skeleton/themes/` ships empty**, with no README or starter. There is no way to inherit the
  default theme's CSS. The Appearance preview needs a homepage entry, and `theme_neutral` offers
  five families.
- **A block type made in the admin gets exactly one style target** (by design). Whether anything
  warns before deactivation is unchecked.
- **The section and page library cannot be extended** (no config, directory or event), and
  nothing saves a hand-built section back. Regions have block palettes; they have no Sections or
  Pages view.
- **A style class job's id is shown nowhere in the admin**, though the CLI needs it. A failed
  everywhere-job cannot be retried from the admin; a class can only be archived; style classes are
  in no export.
- **Regions are not per locale and have no draft, versions or undo.** Only header and footer
  exist. The region preview frame exists and runs no scripts. A region's root cannot carry a style
  class; its child blocks can.
- **Menus are unversioned and unpreviewable**; nothing shows where a menu is used, and deleting
  one does not warn. Some tree-editor buttons lack accessible names; audit them one by one.
- **Motion:** stage fidelity, Ken Burns timing, the CSP hash and a repeat cap. Not audited.
- **A theme cannot see `site.locales`** (always empty). `is_preview()` and `is_canvas()` read one
  flag. Disk templates are parsed and their theme config validated, but get no admin template
  lint.
- **No CLI lists block types; the `links` block's items are raw JSON.**
- **Pack config is overridable only by creating a file that does not ship** (`config/render.php`,
  `search.php`, `seo.php` …). No `config:cache`, and no effective-config view.

### Site features

- **Form submissions have no retention, bulk delete or per-form view** (the API filters by
  `form_key`; the admin does not). No field builder, file uploads or CAPTCHA.
- **Workspaces need several restarts to enable**, and cannot be disabled with more than one
  workspace. The exact count depends on the deployment.
- **Languages:** a single homepage, not one per language; `site.locales` is empty. Whether
  `direction` does anything, and whether a language can be removed, is unchecked.
- **SEO:** no per-type fallbacks or robots groups from the admin, and `config/seo.php` does not
  ship. Whether a redirect can be edited, target an entry or pick its locale is unchecked.
- **Commerce sweeps are not in `config/schedule.php`.** Read-only Customers and the marketplace tab
  are unchecked.
- **Accounts:** dashboard items come from a code registry. Name the exact missing no-code
  controls before writing this up; templates and account settings do exist.
- **No admin UI for a resource-scoped (per-language) grant.**
- **The tenancy status commands print raw JSON; `analytics:prune` is unscheduled.**
- **`SECURITY.md` has no dedicated address, PGP key or disclosure window.**

### Missing from `.env.example`

`PREVIEW_TTL`, `VERSION_KEEP`, `VERSION_MAX_AGE_DAYS`, `WORKFLOW_ALLOW_SELF_REVIEW`,
`CONTENT_SCHEDULER_ENABLED`, `TENANCY_TRASH_RETENTION_DAYS`, `TENANCY_HOST_COOLDOWN_DAYS`,
`PUBLIC_URL_BASE`. `MEILISEARCH_HOST` appears only in a comment.

## Stale prose still in the repository

- `packages/thallo-render/themes/default/theme.json`'s `menus` key: nothing reads it.
- `config/payvia.php`'s header explains that a cached boot skips extension `register()`.
  Unchecked against the current framework.

## Where a picture is missing

When images travel with the import, these are the first to add: the theme gallery and the
brand-colour report; the header and footer editor and its Block settings; the Sections and Pages
views; the Advanced tab's style class row and a cascade diagram; the menu tree editor; the Motion
group; the form block's settings and the Submissions screen; Developers › API Keys and the
`/api-docs` reference; Workspaces › Domains and the enablement timeline; the Design view's layout
and its breakpoint rule; the block toolbar; the setup form and the admin Home; the Settings ›
Content Types field editor and the entry's Publishing panel; Settings › Block Types; Site ›
Appearance; Extensions › Capabilities; the preview bar; Utilities › Health and Scheduled Tasks.

## Fixed

Kept for the record; each is in the CHANGELOG.

- **Request logs were written into the web root.** A relative `LOG_FILE_PATH` resolved against
  `public/` under FPM. Relative paths now resolve against the project root, and the doctor warns
  about any `.log` under `public/`. Test.
- **Every admin-started import failed on a real install.** The upload root lived in the core
  package's config under `vendor/`; it now defaults to the uploads disk's root. Test.
- **The rendered site sent no security headers.** It now sends nosniff, a referrer policy,
  X-Frame-Options and HSTS over HTTPS. Test.
- **The nightly database backup was on by default and could not back up.** It ships off. Test.
- **Contact form email never sent, and "email only" lost the submission.** Forms now mail through
  Settings › Email, and store the submission when the mail fails. Test.
- **The sitemap and robots.txt answered 409 on a stock install.** They use `BASE_URL`, or the
  workspace's origin, with `PUBLIC_URL_BASE` as an override. Test.
- **Three site names.** Settings › General › Site name is what visitors see, in templates,
  `og:site_name` and the title template. `RENDER_SITE_NAME` and `SEO_SITE_NAME` are gone. Test.
- **A new logo, favicon or site name was served stale.** Saving one now clears the rendered
  pages. Test.
- **The Design view published pages with no URL.** It refuses and says where to set the slug.
  Test.
- **Editor-only `_presentation` leaked through expanded references.** Stripped at every depth.
  Test.
- **Admin text that said something false:** the restore toast (Test), the tenancy remedy (Test),
  the doctor's `thallo setup` line, the `RENDER_ENABLED` hint, the bulk-locale error, the
  shared-fields banner, the role delete dialog, the webhook delete dialog, the block-type
  template hint, the Markdown import hint, and the API key scope example.
- **The schema save promised migrations in "a later release".** It names the migration route.
  Test.
- **The API reference default.** It is on in every environment by a deliberate config choice;
  the env files no longer say otherwise.
- **Prose:** the README and skeleton README (database, first-run sequence, capability defaults);
  both `.env.example` files (keys comment, `DB_USERNAME`, `users.view`, HTTPS, API docs,
  `LOG_FILE_PATH`, `HSTS_HEADER`); `config/documentation.php`'s permission names;
  `config/storage.php`'s driver packages; `core/config/thallo.php`'s site name note;
  `Timestamps`; the code comments in `order.ts`, `BlockTypeRepository`,
  `ContentTypeRepository::updateSchema()`, `ScheduledTasksController` and `PublishPanel.vue`;
  the introduction's "costs to run"; `THEMING.md` (the removed `blocks.js`, the full function
  list, inline-children blocks, colour mode, CSP, the Preview card, font downloads, section order)
  and the "resolved at boot" notes in `render.php`, `ThemeLocator` and `ThemeCloneCommand`;
  every pack README (removable, `config/thallo.php`, `./thallo extensions:enable`, the navigation,
  SEO, search and commerce claims, and full READMEs for account, subscriptions and tenancy);
  `OUTSTANDING.md` on form mail; `STOREFRONT_ACCOUNTS.md`; `PER_LOCALE_RBAC.md`'s permission
  names; `operations/tenancy.md`; the pack config comments on where the switch lives; `docs/production.md`, `docs/upgrading.md` and
  `skeleton/README.md` on `public/storage/`; the tenancy maintenance queue in the worker docs.
- **Docs claims the review disproved:** `read:*` does read every type; keys can be minted from
  the CLI; the bundle importer upserts; a lone database-queue worker keeps its own job.
