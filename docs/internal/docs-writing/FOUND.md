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

- **Form notification email has never worked.** `FormMailSender` is an interface with no
  implementation anywhere (`core/`, `packages/`, `skeleton/`, `vendor/`); `FormNotifier` no-ops
  and logs nothing, the visitor sees success, the block type's own description promises the
  email. `delivery: email_only` therefore loses the submission without a trace; `recipient` is
  required and unused; **success message** is dead because no theme ships the script that shows
  it. Verified by the coordinator. (forms)
- **Editor-only state leaks through an expanded reference.** `DeliveryItemShaper::shape()`
  strips `_`-prefixed keys from root rows only, after `ReferenceResolver::expand()` has spliced
  target rows in, so a target's `fields._presentation` is served at
  `data.fields.{ref}[n].fields._presentation`. Read, not yet reproduced; write the test first.
  (api)
- **The delivery API's `published_at` is not ISO-8601.** `Timestamps` is never called on the
  delivery path; the raw `timestamp without time zone` is emitted, while `docs/openapi.json`
  declares `format: date-time`. `?expand=` is merged into `?fields=` and narrows the response.
  (api)
- **Saving a logo or favicon does not purge the rendered page cache.** `ThemeAppearanceChanged`
  fires only for theme, accent, neutral, radius, font and background, and the cache key omits
  the logo, so visitors keep the old logo for up to `render.cache_ttl`. (appearance)
- **A single asset cannot be cleared from the admin.** `AssetField.vue` has a remove control only
  in its `multiple` branch, so a chosen logo or favicon cannot be unset. (appearance)
- **The API key create modal hints scopes the delivery API does not check** (`read:*`,
  `write:posts`; it checks `read:content` / `read:content:{type}`); an empty Scopes field is full
  access; scopes cannot be edited after creation from the detail pane though the endpoint
  exists. (api)
- **Navigation: the editor allows six levels, the default theme draws three**; deeper items are
  stored and served and never shown. A 409 on save discards the unsaved tree. (navigation)
- **The tenancy enablement screen's remedy for the capability is a command that is refused**
  (see above); enabling is refused outright with any data collection defined, and on a cache
  driver without pattern purge, with no warning before the button. (workspaces)

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

- **The section and page library cannot be extended** (no config, directory or event); nothing
  saves a hand-built section back; the header and footer have no palette. (sections-and-pages)
- **A style class job's id is shown nowhere in the admin**, though the CLI needs it; a failed
  everywhere-job cannot be retried; a class can only be archived; style classes are in no
  import or export. (style-classes)
- **Regions are not per locale, have no draft, preview, versions or undo**; only two exist; a
  region cannot carry a style class; the preview frame runs no scripts. (header-and-footer)
- **Menus are unversioned and unpreviewable**; nothing shows where a menu is used, and deleting
  one does not warn; the tree editor's buttons have no accessible names. (navigation)
- **No faithful motion preview on the stage**; Ken Burns has no timing control; the CSP hash is
  surfaced nowhere; no repeat cap. (animation)
- **Form submissions have no retention, bulk delete or per-form view**; no field builder, file
  uploads or CAPTCHA, and none of it is in `docs/limitations.md`. (forms)
- **Workspaces need three restarts to enable**, and cannot be disabled with more than one
  workspace; `docs/limitations.md` is silent on all of it. (workspaces)
- **API keys can only be minted in the admin.** (api)
- **`TENANCY_TRASH_RETENTION_DAYS`, `TENANCY_HOST_COOLDOWN_DAYS` are in no `.env.example`.**
  (workspaces)

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
- `packages/thallo-render/docs/THEMING.md` §9.6 and the `ThemeDesign` docblock: "every pairing
  but custom costs a visitor nothing" — Editorial and Slab still download the theme's face. §3's
  `region_settings` omits `style`. §12.6 matched the code on every value.
- `packages/thallo-navigation/README.md`: "up/down/indent/outdent", "drag-drop out of scope",
  "no icons or badges" — all shipped. `packages/thallo-tenancy/README.md`: "switched on through
  the capabilities" — it is protected and refused there. `docs/internal/OUTSTANDING.md`: form
  submissions "best-effort-emailed" (never); public origin "config-only" (admin-settable now).
- `config/documentation.php` says admin routes enforce `thallo.*` permissions; they use
  `content.*`, `users.*`, `styles.manage`, `system.access`, `tenancy.*`. `config/api.php`'s
  operator list is not what the delivery API supports, and nothing in delivery reads it.
  `core/src/Content/Delivery/Timestamps.php` documents a normalisation the delivery path does
  not perform.
- Code comments: `admin/src/editor/palette/order.ts` ("flat list, no category headings");
  `BlockTypeRepository` ("no nesting in v1"); `ContentTypeRepository::updateSchema()` ("backfill
  planned"); `ScheduledTasksController` ("QueueManager isn't container-registered");
  `PublishPanel.vue` ("always shown" on a `v-if`).
- `getting-started/01-introduction.md` says the capabilities page explains what each
  capability "costs to run"; that page does not. Reconcile.

## Where a picture is missing

Every writer named where the page suffers without one. When images travel with the import,
these are the first to add: the theme gallery and the brand-colour report; the header and footer
editor and its Block settings; the Sections view (thumbnails); the Advanced tab's style class
row and a cascade diagram; the menu tree editor; the Motion group; the form block's settings
and the Submissions screen; Developers › API Keys and the `/api-docs` reference; Workspaces ›
Domains and the enablement timeline; the Design view's layout and its breakpoint rule; the Sections and
Pages views (they are thumbnails); the block toolbar; the setup form and the admin Home; the
Settings › Content Types field editor and the entry's Publishing panel; Settings › Block Types;
Site › Appearance and the theme gallery; Extensions › Capabilities; the preview bar; Utilities
› Health and Scheduled Tasks.
