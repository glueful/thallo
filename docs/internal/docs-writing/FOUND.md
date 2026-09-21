# What writing the docs found

Each page's writer reports what the code did not do, what the prose got wrong, and what a
reader would miss. This is that list, kept so nothing is lost. Each entry is one item: fix it,
or decide not to, and take it off. Entries are as the writer reported them; none has been
reproduced beyond what the report says.

## Bugs in the product

- **`QUEUE_CONNECTION=sync` resolves no driver.** `config/queue.php` lists `sync` and `null`;
  only the `database` and `redis` drivers exist. Fixed in the docs; the config still lists it.
- **Content webhook delivery may never run.** `WebhookDispatcher::queueDelivery()`
  (`vendor/glueful/framework/src/Api/Webhooks/`) passes a job object to
  `QueueManager::push(string $job, …)`. No test covers a delivery. Establish before the webhooks
  guide is written. (scheduler-and-queues)
- **The Design view tells the reader to set `RENDER_ENABLED`**, an env var nothing reads
  (`design/[locale].vue`). The gate is the `thallo.render` capability. (design-view)
- **The tenancy capability's on-screen remedy is a command that is always refused.**
  `ExtensionCapabilityAvailabilityResolver` says to run `extensions:enable glueful/tenancy`;
  `ProtectedProviders` refuses it. (capabilities)
- **Restore says it rewrote the draft; it did not.** `VersionsPanel.vue` toasts "The draft now
  carries this version's content"; `PublishService::rollback()` re-pins the version and leaves
  the draft alone, and the test asserts only the pin. (publishing)
- **Scheduled unpublish has no UI.** The API and the status code accept it; `PublishPanel.vue`
  only ever posts `publish`. (publishing)
- **A failed schedule is silent.** The reason is stored on the row; the admin shows a badge and
  no reason. Nothing at the entry warns that the scheduler cron is missing. (publishing)
- **The Design view can publish a page with no route.** It has no slug field; the form editor's
  Publish saves a dirty slug first, the Design view's does not. (first-page)
- **`thallo:doctor`'s failure message names `thallo setup`**, which is not a console command
  (it is a verb of the `./thallo` launcher). (install)
- **`token` is offered as a field type and cannot be saved from the admin** (no domain input);
  `box` is allowed by the backend and never offered. (first-content-type)
- **`extensions.install.auto_enable`** (`config/extensions.php`) is read by nothing that
  ships. (capabilities)
- **`config/schedule.php` has a dead `settings` block** (`SCHEDULER_ENABLED`,
  `MAX_CONCURRENT_JOBS`, `USE_QUEUE_FOR_SCHEDULED_JOBS`, the `queue_mapping` array …) read by
  nothing; each job's `queue`, `timeout` and `retry_attempts` keys are ignored on the inline
  scheduler path. `queue_mapping` names a job that does not exist. (scheduler-and-queues)
- **`DatabaseQueue::pop()` takes no row lock**, so two workers on one queue can take the same
  job; `retry_after` (90s) releases a long job under a single worker. (scheduler-and-queues)
- **No `queue:failed` / `queue:retry` command and no admin screen** for failed jobs.
  (scheduler-and-queues)

## Things a reader cannot do, or is not told

- **Nothing creates the PostgreSQL database.** Provision fails on the connection test if it is
  absent. (install)
- **`SETUP_TOKEN` is never blanked after `thallo:create-admin`**, only after web setup. (install)
- **The CLI accepts an 8-character admin password; the web form enforces seven rules.** (install)
- **Provision prints the setup link from `BASE_URL`**, which is usually still `localhost` at
  that point. (install)
- **A block type made in the admin cannot ship its template from the admin**, and gets exactly
  one style target. Nothing warns before a block type is deactivated. (blocks)
- **Content-type schema migrations have no admin UI**; the field editor will not remove a field.
  A field can never be retyped. (content-model)
- **Version pruning is CLI-only and unscheduled**; `entry_versions` grows for ever. (content-model,
  publishing)
- **`PREVIEW_TTL`, `VERSION_KEEP`, `VERSION_MAX_AGE_DAYS`, `WORKFLOW_ALLOW_SELF_REVIEW`,
  `CONTENT_SCHEDULER_ENABLED` are in no `.env.example`.** (publishing)
- **The default theme renders only `title` and `body` of a custom type**; every other field needs
  a template. A rich-text `text` field named `body` renders as escaped markup. (first-content-type)
- **A listing is a 404 until the type is in `listing_types`**, a setting nowhere near the type.
  The listing's `h1` and the "New …" button print the slug, not the Name. (first-content-type)
- **Content-type fields have no labels**; the starter form reads "title" and "body". (first-page)
- **The Landing page pattern duplicates the page heading** (theme title + hero title). (first-page)
- **`themes/` ships empty**, with no README or starter. The Appearance preview needs a homepage
  entry. `theme_neutral` accepts only five families. (themes)
- **No CLI for the capability switchboard**; a flip needs a reload and nothing says so.
  (capabilities)
- **The Design button shows on every entry**, even of a type with no `blocks` field.
  (design-view)
- **`search:reindex` exists only while the search capability is on.** (capabilities)

## Stale prose in the repository (the pages follow the code)

- `README.md`: "default `thallo`" database and `createdb thallo` (no such default; provision
  prompts); "Settings → Extensions" (it is **Extensions › Capabilities**); the "on by default"
  list omits workflow, accounts, analytics and says nothing of tenancy.
- `.env.example`: keys comment names `php glueful install --skip-keys/--unattended`; keys come
  from `thallo:provision`, which has neither, and `TOKEN_SALT` is generated too.
- `skeleton/README.md` and `README.md` give different first-run sequences (doctor first / no
  doctor).
- `packages/*/README.md`: "disable in `config/thallo.php`" (a project ships no such file;
  and an admin flip overrides it), "removable" (core requires every pack), `./thallo
  extensions:enable …` (navigation has no extension; the binary is `glueful`). The
  thallo-account, -subscriptions and -tenancy READMEs are four-line stubs.
- `packages/thallo-render/docs/THEMING.md`: §2's `layout.twig` script tag (`asset('blocks.js')`
  is gone; `runtime_script()` and several head helpers are missing); §2's `entry.twig` example
  names a `blog` type that does not exist; §4.3 names navigation and social links as
  inline-children blocks (it is accordion, stepper, tabs, carousel, gallery, pricing_table);
  §8.3 "in the header palette" (color_mode is in Content); §9.1 on `--accent-ink` contradicts
  the `ThemeColors` docblock; §9.3 describes a "Preview on site" button that is now the Preview
  card; §12.3a's wording against `invalidChoiceAt`.
- `packages/thallo-render/config/render.php`: "resolved at boot" is true of `RENDER_THEME` only;
  the admin's override is per request.
- `packages/thallo-render/themes/default/theme.json`'s `menus` key: nothing found that reads it.
- `docs/production.md` (fixed): `sync`; "dispatches to `default` and `maintenance`"; mail as a
  background job.
- Code comments: `admin/src/editor/palette/order.ts` ("flat list, no category headings");
  `BlockTypeRepository` ("no nesting in v1"); `ContentTypeRepository::updateSchema()` ("backfill
  planned"); `ScheduledTasksController` ("QueueManager isn't container-registered");
  `PublishPanel.vue` ("always shown" on a `v-if`).
- `getting-started/01-introduction.md` says the capabilities page explains what each
  capability "costs to run"; that page does not. Reconcile.

## Where a picture is missing

Every writer named where the page suffers without one. When images travel with the import,
these are the first to add: the Design view's layout and its breakpoint rule; the Sections and
Pages views (they are thumbnails); the block toolbar; the setup form and the admin Home; the
Settings › Content Types field editor and the entry's Publishing panel; Settings › Block Types;
Site › Appearance and the theme gallery; Extensions › Capabilities; the preview bar; Utilities
› Health and Scheduled Tasks.
