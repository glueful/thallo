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

**Status 2026-09-22: framework 1.87.0 is released and Thallo requires it**, with meilisearch
2.0.0, subscriptions 2.4.0, payvia 2.9.0, users 2.5.0 and import-export 1.2.1. Every fix that
waited on a release has shipped, and Thallo's follow-ups are done (see Fixed).

## Bugs in Thallo

- **Collections' `filter[field][like]` is case-sensitive on PostgreSQL and treats `%` and `_` as
  wildcards.** It is part of the public collections API, so switching it to `whereContains()`
  changes a contract: decide, then document. Code.

## Things a reader cannot do, or is not told

A missing feature is not a regression. These are product decisions to make, or to document in
`docs/limitations.md`. The boundaries decided on 2026-09-22 are documented there now (see Fixed);
what follows is still open or unchecked.

### Design and themes

- **A theme cannot inherit the default theme's CSS**, only its templates. The Appearance preview
  needs a homepage entry, and `theme_neutral` offers five families.
- **Whether anything warns before an admin-made block type is deactivated** is unchecked.
- **Regions have block palettes but no Sections or Pages view.** The region preview frame runs no
  scripts.
- **A style class can only be archived, and style classes are in no export.**
- **Motion: the stage restates the site's transition rules.** Audited 2026-09-22. The stage reuses
  the compiled classes and variables but writes its transition and animation rules a second time
  in `packages/thallo-render/assets/preview/preview.css`, so easing and fallback durations can
  drift from `StyleCompiler::motionRules()`. The CSP hash is computed and tested; timings match
  apart from Play's documented 8-second Ken Burns. An "always" entrance replays without a cap, on
  scroll only.
- **`is_preview()` and `is_canvas()` read one flag.** Disk templates are parsed and their theme
  config validated, but get no admin template lint.
- **The `links` block's items are raw JSON.**
- **No `config:cache`.**

### Site features

- **Forms: no field builder, file uploads or CAPTCHA.**
- **Languages:** whether `direction` does anything, and whether a language can be removed, is
  unchecked.
- **SEO:** whether a redirect can be edited, target an entry or pick its locale is unchecked.
- **`SECURITY.md` has no dedicated address, PGP key or disclosure window.** Needs the maintainer's
  contact address.

## Where a picture is missing

Captured 2026-09-22 from the live site (1.0.0-beta.51, so older than `dev`), in
`docs/images/screenshots/`, with the signed-in account's email masked: Appearance (theme gallery),
the header and footer editor, the content-type field editor, Block Types, Extensions (installed
and Capabilities), Utilities › Health and Scheduled Tasks, Developers › API Keys, the `/api-docs`
reference, Submissions, the entry's Publishing panel, and in the Design view its layout, the
Blocks palette, the Sections and Pages views, the block toolbar, the Style tab, the Motion group,
the Advanced tab's style class row, and the preview bar (the strip above the stage).

Still missing, because the live site has nothing to show or showing it would change it: the menu
tree editor (no menus), the brand-colour report (needs a colour typed in), the form block's
settings (no form on a page), Workspaces › Domains and the enablement timeline (tenancy off), the
setup form (installed), the admin Home (shows the account email), the breakpoint rule and the
cascade diagram (drawn, not captured). The docs import still needs image support before any of
these appear in the published docs. Screens changed on `dev` since beta.51 (field labels, the
listing switch, scheduled unpublish, Settings › Accounts › Emails, the Submissions filters) need
recapturing after the next deploy.

## Fixed

Kept for the record; each is in the CHANGELOG.

- **Released and required (2026-09-22):** `search:status` is Thallo's alone (meilisearch 2.0
  moved its commands to `meilisearch:*`); the APIs and `/api-docs` send `nosniff` and a referrer
  policy (framework 1.87, security page updated); `import-export:cleanup` deletes finished jobs'
  files through their disk (1.2.1, backups page updated); customer reset mail uses its own
  template (users 2.5); plan prices and Stripe plan changes (subscriptions 2.4, payvia 2.9);
  unconfigured mail reports itself (framework 1.87).
- **Media, all four:** search folds case and matches literally (`whereContains()`; API key search
  too); deleted files are purged after 30 days (`blob_purge` scheduled); optimized images'
  variants follow the file; `UPLOADS_STRIP_EXIF` is read and strips metadata by default. Docs
  updated. Tests.
- **A rich-text body rendered escaped** (confirmed 2026-09-22). It renders through `safe_html`
  when the field's format is rich; entry templates get `rich_fields`. Test.
- **Boundaries documented in `docs/limitations.md`** (decided 2026-09-22): no database creation,
  workspace restarts, pack config files, manual secret re-keying, no field retyping, the default
  theme's two fields, create-only format imports, one homepage, one style target, a fixed section
  and page library, regions and menus without history, site-wide SEO fallbacks, no per-language
  grant screen, the account dashboard in code. The marketplace switch is in the commerce guide.
- **`skeleton/themes/` shipped empty.** A README with the clone command as the starter, and what
  falls back.
- **A style class job's id was nowhere in the admin, and a failed job could not be retried.** The
  card shows the id and offers Run again. Test.
- **Deleting a menu gave no warning, and tree buttons had no names.** The confirmation lists where
  the menu is shown; the five icon-only row buttons are named. Tests.
- **Form submissions had no retention, bulk delete or per-form view.** All three. Tests.
- **`site.locales` was always empty.** It lists the enabled languages. Test.
- **Scheduled unpublish had no UI.** The schedule picks Publish or Unpublish. Test.
- **Content-type fields had no labels.** A Label per field, and readable names otherwise. Tests.
- **Listings: the switch was far from the type, and the heading printed the slug.** A Listing page
  switch on the content type; the heading prints the name. Tests.
- **Ken Burns looped forever with no way to stop it.** One drift there and back, then rest; paused
  under hover and focus. Test.
- **Starter pages duplicated the page heading; Design showed on types without blocks** (verified
  2026-09-22). Inserting a starter page hides the theme title; the button needs a blocks field.
  Tests.
- **No CLI listed block types or ran the capability switchboard.** `thallo:blocks:list` and
  `thallo:capabilities`. Test.
- **The tenancy status commands printed raw JSON.** A table, with `--json` for scripts. Test.
- **Maintenance commands were unscheduled** (version pruning, `import-export:cleanup`,
  `analytics:prune`, commerce sweeps). `config/schedule.php` runs them. Test.
- **Setup: the CLI's weaker admin password, the kept `SETUP_TOKEN`, and the silent localhost
  link.** The form's password rules hold on the server and in `thallo:create-admin`, which also
  blanks the token; provision warns on a local `BASE_URL`. Tests.
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
