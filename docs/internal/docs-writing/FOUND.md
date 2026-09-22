# What writing the docs found

Each page's writer reported what the code did not do, what the prose got wrong, and what a
reader would miss. This is that list, kept so nothing is lost. A review checked every entry
against the code on 2026-09-22; this version applies its corrections, merges its duplicates and
moves fixed work to **Fixed** at the end. Anything still listed above that section is open.

Every entry carries its evidence:

- **Test**: a regression test reproduces it, or pins the fix.
- **Code**: the mechanism is read in the code, and the review agreed.
- **Reported**: the writer's claim, not yet confirmed. Prove it before fixing it.

## Bugs in the framework (need a Glueful release)

**Status 2026-09-22: framework 1.86.2 is released and Thallo requires it.** Every fix below marked
"framework" shipped in 1.86.0–1.86.2. Each is generic: tested in the framework, working on SQLite,
MySQL and
PostgreSQL, with the failed-job commands serving the Redis driver too; the API skeleton carries
the same config corrections. Thallo's follow-ups are done: the webhook delete dialog, the
`webhook_cleanup` job in the schedule, the `webhooks` queue in the documented worker line, and
the docs for the failed-job commands, the backup and `security:check`.


## Bugs in Thallo

### Content, publishing and delivery

- **`search:status` is declared twice** (fixed on `glueful/meilisearch`'s `dev` branch, awaiting
  its 2.0.0 release). Thallo's won, as the later registration, and nothing said so. The extension's
  commands are now `meilisearch:*`, and the framework's console (`dev`) logs any name taken twice.
  When 2.0.0 ships, require it. (search)

### Admin

- **Media.** Split, each needs its own check:
  - Search uses `LIKE`; case sensitivity depends on the collation. Code.
  - Deleting a file soft-deletes it; the bytes are never reclaimed. Code.
  - Optimize leaves stale resized images (fixed on the framework's `dev` branch: the variant cache
    and its ETag are versioned by the blob's size and update time).
  - `UPLOADS_STRIP_EXIF` is read by nothing. Code, by search.
  (media)

### Setup, operations and security

- **The APIs and `/api-docs` send no security headers** (fixed on the framework's `dev` branch,
  awaiting the next framework patch). The rendered site and the admin page send their own. The
  framework's response chokepoint now adds `nosniff` and a referrer policy to every response that
  has none; when that release ships, require it and update `docs/operations/06-security.md`.
  Framing on the APIs and an HTTPS redirect stay deliberately out: JSON is not framed, and TLS is
  the web server's job. (security)
- **Customer reset mail waits on glueful/users 2.5.0.** Its reset mail takes a template name;
  until it ships, Thallo's customers get the built-in reset template. When it ships, require
  `^2.5` in `core/composer.json`. The unconfigured-mail report is fixed on the framework's `dev`
  branch (no default host or sender). (accounts)
- **Self-serve checkout needs releases to finish.** Signup from the pricing page, plan prices and
  **Change plan** are built. Plan prices need glueful/subscriptions 2.4.0 and Stripe plan changes
  need glueful/payvia 2.9.0; when those ship, require them in `core/composer.json`.
  (subscriptions)
- **`import-export:cleanup` leaves completed exports on disk** (fixed on `glueful/import-export`'s
  `dev` branch, awaiting its next release). It unlinked only `tmp`-role files, which nothing
  records, then deleted every row. It now deletes a finished job's result and tmp files through
  their disk and keeps the rows when a file cannot be deleted. When that release ships, require it
  and update the cleanup paragraph in `docs/operations/04-backups.md`. (backups)

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
- **`UPLOADS_STRIP_EXIF` is read by nothing** (see Media above).

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

### Security

- **No automated re-key of stored secrets.** Verified 2026-09-22. `encryption:rotate` binds
  `{table}.{column}` as the encryption context and the payment settings store binds the settings
  key, so the command cannot re-encrypt them. A path exists and is documented in the security page:
  keep the old key in `APP_PREVIOUS_KEYS`, then re-save the gateway secrets under Settings ›
  Payments.

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

- **The account pages had no profile surface and no editor for the customers' mails** (decided
  2026-09-22). `/account/profile` changes name and password; Settings › Accounts › Emails edits
  the customer verification and reset mails. Tests.
- **Signup and form mail counted as undelivered after it was sent.** Found while testing the
  account mails: both senders read a result shape the framework dropped in 1.42. Test.
- **Self-serve checkout sent a public visitor into the admin, with no price and no plan change**
  (decided 2026-09-22). A pricing card leads to a public signup page that creates the workspace,
  signs the visitor in and opens billing with the plan chosen. Plans carry a display price.
  **Change plan** switches a Stripe subscription, prorated; on Paystack it offers cancelling at
  period end. Tests.
- **A headless front end could not read an asset's alt text or caption** (decided 2026-09-22).
  `?expand=` naming an asset field returns `{uuid, url, alt, caption, mime_type}`, for public
  files; the bare uuid stays the default. Test.
- **Three default locales could disagree** (decided 2026-09-22: the default language is the one
  truth). Settings › General shows and sets it, and the config value is copied from it at boot.
  Test.
- **No auto-login after account verification.** Verifying signs the customer in. Test.
- **A disabled language was still served by the delivery API.** It answers 404. Test.
- **Media alt text and caption reached no page.** The Image block falls back on them, and
  `media_text()` reads them. Test.
- **Media "Used in" ignored images inside blocks.** It counts them, nested too, and
  `thallo:media:rebuild-usage` fills in older content. Test.
- **Media: the panel's File URL showed the storage path.** It shows `display_url`. Test.
- **The workspaces enablement screen gave no warning before its refusals.** The status lists
  `blockers`, the admin shows them and keeps Enable off, and a collection refuses the first
  stage. Test.
- **Provision re-granted the install roles' permissions on every run.** A ledger records what each
  role was offered; only new permissions are granted. Test.
- **A block type's template could not be created from the admin, and the migration dialog did not
  mention the worker.** The block type page opens the template, a missing one starts from a
  generated starter that saves clean, and the card names the worker. Test.
- **Navigation depth and the 409 that lost edits.** The editor stops at six levels, marks items
  past the theme's three, and keeps the tree on a conflict. Test.
- **API key scopes could not be edited after creation.** The detail pane edits them. Test.
- **Enabling workspaces from a terminal had no way out of `failed`.** `--retry` and `--cancel`;
  `--owner` takes an email; `thallo:create-admin` prints the uuid. Test.
- **Commerce permissions and the always-Secure cart cookie.** The permissions were never the
  problem: they are in Thallo's catalogue and install grants them; the commerce guide and README
  said otherwise and are corrected. The cart and guest-order cookies follow
  `SESSION_COOKIE_SECURE`. Test.
- **`csv.users` showed on the Import page, and a publish held for review counted as a failed
  record.** The users import is gone from that page; a held publish is a warning. Test.
- **Pack blocks declared no `layout.item`.** All nine do now. Test.
- **The scheduler check could not change the Health page's overall status.** The status now
  counts every check. Test.
- **No workspace role could hold `workflow.bypass`** (not intended, decided 2026-09-22). It is in
  the catalogue, and the built-in `owner` and `admin` hold it. Test.
- **`token` could not be saved from the field builder, and `box` was never offered.** The builder
  asks for the token's domain and offers `box`. Test.
- **The database backup stays off by default** (decided 2026-09-22). It works, but it needs
  `pg_dump` on the scheduler host and writes to the same machine as the database.
- **`.env.example` left out settings the config reads.** `PREVIEW_TTL`, `VERSION_KEEP`,
  `VERSION_MAX_AGE_DAYS`, `WORKFLOW_ALLOW_SELF_REVIEW`, `CONTENT_SCHEDULER_ENABLED`, the two tenancy
  retention days, `PUBLIC_URL_BASE` and `MEILISEARCH_HOST` each have a line now. Test.
- **Stale prose:** `config/payvia.php` explained a cached-boot gap the framework has closed; the
  file is gone, and the cached-boot test proves payvia's own defaults reach that boot. The default
  theme's unread `menus` key is gone. Test.
- **Downloading an export failed on every stock install.** Results were recorded on a `local`
  disk no storage config defined. Core supplies it now, and the exporter writes through
  `import_export.result_disk`. Test.
- **`thallo:doctor` checked the theme in `RENDER_THEME`**, not the one chosen in Appearance. With
  the database reachable it now checks the stored choice, says which source it checked, and says
  the site serves `RENDER_THEME` while a stored choice is broken. Test.
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
- **Content webhooks never delivered** (framework 1.86.0). Both enqueue paths passed a job
  object to `QueueManager::push(string)`, a `TypeError`; they now push the class and delivery id.
  "Send test event" now applies the delivery's destination guard. Test.
- **The scheduled database backup could not back up a stock install** (framework 1.86.0).
  The task now reads the stock nested config, passes the password through the dump tool's
  environment, and logs "failed" when no backup is made; the job fails when no backup exists.
  Test, plus a real `pg_dump` run. Thallo keeps it off until the release is required.
- **Deleting a webhook subscription left its deliveries behind** (framework 1.86.0). They are
  now deleted with it. Test.
- **The webhook `cleanup` config was read by nothing** (framework 1.86.0). `Webhook::cleanup()`
  applies it; a daily job and `webhook:cleanup` run it. Test.
- **No way to see or retry failed queue jobs** (framework 1.86.0). `queue:failed`,
  `queue:retry`, `queue:forget` and `queue:flush`; a retry verifies the stored signature first.
  Test.
- **`permissions:diff` reported every Thallo permission as unenforced** (framework 1.86.0).
  It read only controller attributes. It now also counts the parameters of middleware named in
  `permissions.enforcing_middleware`, and Thallo declares `content_permission` there. Test.
- **`security:check` reported checks it never ran** (framework 1.86.0). Five steps were
  hard-coded passes; they now check the database, `.env` and `storage/` permissions, the signing
  secrets, token lifetimes and CORS credentials. The production validation stopped recommending
  `FORCE_HTTPS` and `HSTS_HEADER` (read by nothing) and judges the active engine's database
  password. Test.
- **The database queue's health check always failed** (framework 1.86.0; found while fixing
  `security:check`). It probed with a query the builder refuses. Test.
- **Dead config** (framework and Thallo). Removed from both: the `sync` and `null` queue
  connections, the schedule's `settings` block, `queue_mapping` and per-job `queue`/`timeout`/
  `retry_attempts`; from the framework, `app.force_https` and `security.headers`; from Thallo,
  `extensions.install.auto_enable`, `allowed_operators` and `MAIL_BCC`. `MAIL_LOGO_URL` was wrongly
  listed: it is a mail template variable. The notification retry job's limit and the framework's
  own `log_cleanup` retention now reach the key their job reads. Test.
- **An app's config lists merged into the framework's by position** (framework 1.86.0;
  found checking the fixes against other Glueful apps). An app's Nth scheduled job took every key
  it lacked from the framework's Nth job: Thallo's `log_cleanup` carried both retention keys and
  the `queue`/`timeout` keys Thallo removed leaked back in. Lists now replace; maps still merge.
  Test.
- **The failed-job commands worked only on the database driver** (framework 1.86.0). A
  `FailedJobStore` contract, implemented by the database and Redis drivers, backs them. Test.
- **The ORM id fix would have broken creates on tables without a sequence** (framework 1.86.0; found in review). PostgreSQL has no `lastval` there; the id read now returns null and
  leaves the key as the database set it. Test, plus a PostgreSQL probe on a temp table.
- **The query validator refused ordinary text** (framework 1.86.0). A value reading like
  "; delete …" was refused (an import failed on "would be deleted; delete nothing"), and a value
  over 64 KB raised a warning the error handler turns into an exception. Values are bound, so the
  check protected nothing; it is gone. Test.
- **The framework's failed-job helper did not work** (framework 1.86.0). `FailedJobProvider`
  read and wrote columns `queue_failed_jobs` never had, its requeue was a stub, and its trend
  query was MySQL-only. It now works over the real table on every engine and is the one
  implementation behind the database driver and the failed-job commands. Test.
- **Every ORM-created auto-increment model came back with id 1** (framework 1.86.0; found
  while fixing the webhooks). The id is now read from the connection that ran the insert. Test.
- **A failed schedule was silent.** The reason shows under a failed schedule, and the Publishing
  tab warns while a schedule is pending and the scheduler is not ticking. Test.
- **A single asset could not be cleared.** Single asset fields have a **Remove** button; settings
  hosts save the removal as `''`. Test.
- **The delivery API's `published_at` was not ISO-8601, and `?expand=` narrowed the response.**
  Items now carry ISO-8601; an expand-only request keeps every field. Test.
- **Block-built page bodies were not indexed.** A `blocks` field now contributes the text of each
  block through the engine's `BlockTextExtractor`. Test.
- **Two-factor sign-in was unsupported.** The admin read the challenge as a malformed session and
  the storefront refused it. The admin asks for the emailed code; the storefront has a code page
  (`/account/login/verify`) over a new `StorefrontTwoFactor` contract. Test.
- **Automatic webhook retries never ran** (framework 1.86.1). The worker runs a driverless copy
  of a job, so a job's own `release($delay)` requeued nothing and the delivery sat at
  **Retrying**. The queue wrapper now carries out the release. Test.
- **An empty array in config wiped the value below it** (framework 1.86.2; a regression in
  1.86.0, caught by Thallo's suite). A package's `'source_roots' => []` removed Thallo's uploads
  root, so admin imports could not find their file. `[]` adds nothing again. Test.
- **Two database-queue workers could run the same job** (framework 1.86.0). The reservation
  is a conditional claim; the loser takes the next job. Test.
- **`LOG_RETENTION_DAYS` changed nothing.** Filed here as a framework bug, it was Thallo's: the
  shipped schedule passed `retentionDays`, and the framework's job reads `options.retention_days`.
  The schedule now passes the right key. Test.
- **Analytics kept recording after an admin switch-off.** The content and collection bridge read
  only the config map at boot. It now checks the live switch on every event. Test.
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
  names; `operations/tenancy.md`; the pack config comments on where the switch lives;
  `docs/production.md`, `docs/upgrading.md` and
  `skeleton/README.md` on `public/storage/`; the tenancy maintenance queue in the worker docs.
- **Docs claims the review disproved:** `read:*` does read every type; keys can be minted from
  the CLI; the bundle importer upserts; a lone database-queue worker keeps its own job.
