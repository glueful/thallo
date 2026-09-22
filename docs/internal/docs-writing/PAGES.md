# The pages of Thallo's documentation

Every page the docs need, in the order the sidebar shows them. Read [GUIDE.md](GUIDE.md) first:
it is the contract; this file is the work list.

**How to read a row**

- **File** is where the page is written, under `docs/`. **Slug** is its URL, `/docs/{slug}`.
  Title, slug, section and order go into the front matter exactly as given. Slugs are unique
  across all of `docs/`: do not change one, other pages link to it.
- **Sources** are where the facts are. Read them all before writing. A path ending in `/` is a
  folder: read what is in it. They are a starting point, not a fence — follow the code wherever
  it leads — but a fact that is in none of them and nowhere you can find does not go in the page.
- **Must cover** is the least the page has to answer. It is a list of questions, not of
  headings: shape the page the way GUIDE.md §5 says its section is shaped.
- **Status** is `todo`, `draft` (file exists with `draft: true`) or `done`. Whoever coordinates
  the writing updates it; a writer does not edit this file.

Paths under `admin/src/` are the admin's screens: read them for the exact labels, fields and
order of what a reader sees. Paths under `tests/` show what the code is proven to do. The pack
READMEs (`packages/*/README.md`) predate the capability switchboard and the `glueful` binary:
several tell the reader to edit a `config/thallo.php` that a project does not ship, to run
`./thallo extensions:enable …`, or that a pack is removable. Use them for what a pack is, not for
how it is switched on.

---

## Getting started

For someone who has never used Thallo. One path, no choices.

### introduction — What Thallo is
- **File** `getting-started/01-introduction.md` · **Order** 1 · **Status** done
- **Summary** "What Thallo is, who it is for, and what a site built with it is made of."
- **Sources** `README.md`, `skeleton/README.md`, `docs/limitations.md`, `core/config/thallo.php`
  (the `capabilities` map), `core/src/Capabilities/`, `skeleton/config/extensions.php`,
  `core/composer.json`, `packages/*/README.md`
- **Must cover** What Thallo is in two sentences: a CMS installed with Composer, with an admin,
  a visual page builder, a theme layer that renders the site, and an API over the same content.
  What "hybrid" means here: the same entries are served as rendered pages and as JSON. What is
  on by default and what ships switched off. That it is a Developer Preview, with a link to
  limitations. Where to go next: install.

### install — Install Thallo
- **File** `getting-started/02-install.md` · **Order** 2 · **Status** done
- **Summary** "Create a project, set it up, and sign in to the admin."
- **Sources** `README.md` (Requirements, Quickstart), `skeleton/README.md`, `skeleton/.env.example`,
  `core/src/Setup/Console/ProvisionCommand.php`, `core/src/Setup/Console/CreateAdminCommand.php`,
  `core/src/Setup/Console/DoctorCommand.php`, `core/src/Setup/Doctor/Doctor.php`,
  `core/src/Setup/SetupService.php`, `core/src/Setup/PgsqlDatabaseConfigFactory.php`,
  `core/src/Http/Controllers/SetupController.php`, `core/src/Content/Starter/Kinds/ContentTypeKind.php`,
  `vendor/glueful/framework/src/Installer/Installer.php`, `skeleton/composer.json`, `admin/src/pages/setup.vue`
- **Must cover** Requirements, exactly as the README and the doctor check them. The
  create-project command. Creating the database. What `thallo:provision` does, step by step, and
  what it prints. Running `thallo:doctor` when something is wrong. The two ways to make the
  first admin: the setup link and `thallo:create-admin`. Switching `.env` to development for a
  local try-out. Starting PHP's built-in server. Signing in at `/admin`. Why `BASE_URL` matters.
  What the reader sees when it worked.

### first-page — Build your first page
- **File** `getting-started/03-first-page.md` · **Order** 3 · **Status** done
- **Summary** "Open the Design view, lay out a page from the library, and publish it."
- **Sources** `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`,
  `packages/thallo-render/assets/preview/preview-bridge.js` (what happens on the stage),
  `admin/src/pages/content/[type]/[uuid]/components/PublishPanel.vue`, `admin/src/utils/homepageButton.ts`,
  `admin/src/editor/palette/`, `admin/src/queries/patterns.ts`,
  `core/src/Content/Patterns/StarterPatterns.php`, `admin/e2e/tests/pattern-library.spec.ts`
- **Must cover** Where pages live (**Content › Pages**). Giving the entry a slug and saving its
  route before anything else: a page without a route can be published and renders nowhere.
  Creating an entry and opening the Design view. The two parts of the screen: the side panel
  with its tabs (the **Block** tab is the inspector, and shows only while a block is selected)
  and the stage. Inserting a starter page from the **Pages** view of the Blocks tab. Changing a
  block's text on the stage. Adding a section from the **Sections** view. Switching the stage
  between desktop, tablet and mobile, and what that changes about an edit. Saving, previewing,
  publishing. Setting the page as the homepage (**Settings › General**). Links on: the Design
  view concept, content types.

### first-content-type — Model your own content
- **File** `getting-started/04-first-content-type.md` · **Order** 4 · **Status** done
- **Summary** "Make a content type, add entries, and list them on the site."
- **Sources** `admin/src/pages/settings/content-types/`, `admin/src/components/ContentTypeFields.vue`
  (the field builder), `admin/src/queries/contentTypes.ts` (`FIELD_TYPES`: what the Type list offers),
  `core/src/Content/Schema/FieldDefinition.php`, `core/src/Content/Validation/FieldValidator.php`,
  `core/src/Settings/GeneralSettings.php` (listing types), `core/src/Content/Delivery/EnginePublicRouteResolver.php`
  (the URL grammar and the listing allowlist), `core/routes/content.php`, `core/src/Content/Delivery/DeliveryVisibility.php`,
  `packages/thallo-render/themes/default/templates/listing.twig`, `.../_listing_rows.twig`, `.../entry.twig`
- **Must cover** A worked example — Events (title, excerpt, starts_at, body); a blog already
  ships as the starter **Posts** type. Creating the type under
  **Settings › Content Types**. The field types that exist, by their real names. Adding two
  entries and publishing them. The URLs an entry and a listing get (`/{type}/{slug}` and
  `/{type}`), and the **listing types** setting that turns the listing on. That the same entries
  are in the API. Link on: the content model.

---

## Concepts

How Thallo thinks. Explanation, not steps.

### content-model — Content types, entries and fields
- **File** `concepts/01-content-model.md` · **Order** 1 · **Status** done
- **Summary** "How content is modelled: types, fields, entries, references, locales and versions."
- **Sources** `core/src/Content/Schema/`, `core/src/Content/Repositories/ContentTypeRepository.php`,
  `core/src/Content/Repositories/EntryRepository.php`, `core/src/Content/Repositories/VersionRepository.php`,
  `core/src/Content/Validation/FieldValidator.php`, `core/src/Content/Repositories/RouteRepository.php`,
  `core/src/Content/Seo/RedirectRepository.php`, `core/src/Content/Delivery/EnginePublicRouteResolver.php`,
  `core/src/Content/Services/MigrationService.php`, `core/src/Content/Backfill/BackfillRunner.php`,
  `core/src/Content/Schema/Migration/`, `core/src/Content/Console/PruneVersionsCommand.php`,
  `packages/thallo-collections/README.md`
- **Must cover** A content type is a schema; an entry follows it. Every field type and what it
  stores. References between entries. How an entry gets its slug and URL, and what happens to
  the old URL when the slug changes. One entry, many locales. Draft, published version, and the
  version history; how history is pruned. What changing a type's schema does to existing
  entries, and what a destructive change (rename, delete) requires. How collections differ from
  content types.

### blocks — Blocks and block types
- **File** `concepts/02-blocks.md` · **Order** 2 · **Status** done
- **Summary** "What a block is, how a page's body is stored, and how a block becomes HTML."
- **Sources** `core/src/Content/Blocks/StarterBlockTypes.php`, `core/src/Content/Blocks/`,
  `packages/thallo-render/docs/THEMING.md` §4, `packages/thallo-render/themes/default/templates/blocks/`,
  `admin/src/pages/settings/block-types/`, `core/src/Content/Console/SeedBlockTypesCommand.php`,
  `core/src/Content/Console/SyncBlockTypesCommand.php`, `core/src/Content/Validation/FieldValidator.php`
  (a block's shape and the depth cap), `core/src/Content/Starter/Kinds/ContentTypeKind.php`,
  `admin/src/editor/palette/order.ts`
- **Must cover** A page's body is a list of blocks, stored as data, never as HTML. A block has a
  type, its data and its settings. Blocks that hold blocks, and how deep they nest. A block type
  defines a block's fields; the starter set, counted from the source; making your own. One Twig
  template per block type, which a theme overrides. Switching a block type off, and what that
  does to pages that use it. Why storing structure instead of markup matters: the API returns
  it, and a theme change needs no content migration.

### design-view — The Design view
- **File** `concepts/03-design-view.md` · **Order** 3 · **Status** done
- **Summary** "How the visual builder works: the stage, the Container, breakpoints, and settings that are saved as data."
- **Sources** `packages/thallo-render/docs/THEMING.md` §12 (all of it),
  `packages/thallo-render/src/Style/StyleCompiler.php`, `packages/thallo-render/src/Style/BlockStyleEmitter.php`,
  `packages/thallo-render/assets/preview/preview-bridge.js` (what the stage itself does),
  `core/src/Content/Http/Controllers/PreviewController.php` (the stage is the real page; the render gate),
  `admin/src/editor/breakpoint.ts`, `admin/src/editor/inspector/tabMap.ts` (which property sits in which tab),
  `admin/src/editor/inspector/LayoutTab.vue`, `.../StyleTab.vue`, `.../layoutLabels.ts`,
  `admin/src/editor/inspector/controls/ResponsiveField.vue`, `admin/src/editor/structure/`,
  `admin/src/editor/ops/types.ts`, `admin/src/editor/ops/history.ts`,
  `admin/e2e/tests/stage-viewport-width.spec.ts`, `.../layout-mode-switch.spec.ts`, `.../grid-outline.spec.ts`, `.../grid-fill.spec.ts`
- **Must cover** The stage is the real page, rendered by the theme. Edits are operations on the
  page's data, each one undoable. The Container: one block that lays out its children as flex
  or grid. The three breakpoints, their widths, and the rule that catches everyone: **a setting
  is written at the breakpoint the stage is showing, and applies from that width up** — so set
  the base first. The Layout tab and the Style tab. Settings are chosen from the theme's
  vocabulary (tokens), not typed as CSS, and what that buys. Style classes. The section and page
  library. Motion. What the Design view deliberately does not do (free-form CSS, pixel values).

### themes — Themes
- **File** `concepts/04-themes.md` · **Order** 4 · **Status** done
- **Summary** "What a theme is, what it controls, and what it leaves to the site's settings."
- **Sources** `packages/thallo-render/docs/THEMING.md` §1–§3, §8–§11,
  `packages/thallo-render/themes/default/theme.json`, `packages/thallo-render/themes/default/templates/`,
  `packages/thallo-render/src/RenderThemeValidator.php` (what makes a theme selectable),
  `packages/thallo-render/src/ActiveThemeSource.php`, `packages/thallo-render/src/ThemeLocator.php`,
  `packages/thallo-render/src/Http/Controllers/RenderController.php` (the template hierarchy),
  `core/src/Content/Delivery/EnginePublicRouteResolver.php`, `packages/thallo-render/src/Theme/ThemeColors.php`,
  `packages/thallo-render/src/Theme/ThemeDesign.php`
- **Must cover** A theme is a folder: Twig templates, CSS, `theme.json`. The template hierarchy:
  which template renders which URL, and how a template named after a content type wins. Layout,
  regions and blocks. What the site's owner changes without touching the theme (**Site ›
  Appearance**: accent or brand colour, neutral, corners, typefaces, page ground, logos), and
  how those reach the CSS as tokens. Light and dark mode. Where a site's own theme lives and how
  it is chosen. Link on: make a theme.

### publishing — Drafts, preview and publishing
- **File** `concepts/05-publishing.md` · **Order** 5 · **Status** done
- **Summary** "The life of an entry: draft, preview, review, publish, schedule, unpublish."
- **Sources** `core/src/Content/Services/PublishService.php`, `core/src/Content/Preview/`,
  `core/src/Content/Http/Controllers/PublicationController.php`, `.../ScheduleController.php`, `.../PreviewController.php`,
  `packages/thallo-render/src/Http/Controllers/RenderController.php` (preview, previewBar),
  `packages/thallo-render/themes/default/templates/layout.twig` (the preview bar),
  `core/src/Content/Scheduling/`, `core/src/Content/Console/RunDueSchedulesCommand.php`,
  `core/src/Content/Retention/`, `core/src/Content/Console/PruneVersionsCommand.php`,
  `packages/thallo-workflow/src/`, `admin/src/pages/content/[type]/[uuid]/components/PublishPanel.vue`,
  `.../WorkflowPanel.vue`, `core/src/Content/Console/ResyncCommand.php`
- **Must cover** Saving writes a draft; the live site does not change. Preview: a signed,
  time-limited session that shows drafts through the real theme, and the bar across its top.
  Publishing makes a version live and what else it sets off (cache, search index, webhooks).
  Unpublishing. Scheduling either, and that it needs the scheduler cron. The review workflow
  when it is on: who may publish, the review queue. Version history and restoring. What
  `thallo:resync` is for.

### capabilities — Capabilities and packs
- **File** `concepts/06-capabilities.md` · **Order** 6 · **Status** done
- **Summary** "How Thallo's features are packaged, and what switching one on or off does."
- **Sources** `core/config/thallo.php` (the `capabilities` map), `core/src/Capabilities/`,
  `core/src/Providers/CoreServiceProvider.php` (`makeCapabilityRegistry`), `core/src/Http/Controllers/CapabilityAdminController.php`,
  `packages/*/src/*ServiceProvider.php` (where each `new Capability(...)` lives), `admin/src/pages/extensions/`,
  `admin/src/queries/capabilityManagement.ts`, `skeleton/config/extensions.php`
- **Must cover** A capability is a feature with a switch; a pack is the Composer package it
  ships in. The list of capabilities, from the config, each in a line, with its default. Off
  means inactive, not uninstalled: the code and the tables stay, the routes, menus and blocks go.
  Where to switch one (**Extensions › Capabilities**) and what a capability may ask for when it
  is switched on (a migration, a cron line, a config value). Extensions from the Glueful
  framework (Commerce, Payvia, Meilisearch) and how they differ from Thallo's own packs.

### api — The content API
- **File** `concepts/07-api.md` · **Order** 7 · **Status** done
- **Summary** "Read your content as JSON: the delivery API, API keys, and the reference every install serves."
- **Sources** `core/routes/content.php`, `core/src/Content/Delivery/` (`FilterCompiler.php`, `SortCompiler.php`,
  `DeliveryVisibility.php`, `DeliveryItemShaper.php`, `DeliveryEtag.php`), `core/src/Content/Http/DeliveryAccessMiddleware.php`,
  `core/src/Content/Http/OptionalApiKeyAuthMiddleware.php`, `core/src/Content/Http/Controllers/`,
  `core/src/Settings/GeneralSettings.php` (page size, cache TTL), `admin/src/pages/developers/api-keys/`,
  `docs/openapi.json` (generated, and incomplete: it omits `fields`; for shapes, not prose),
  `core/src/Setup/ApiReferencePublisher.php`. Nothing in delivery reads `config/api.php`. A
  request and response composed from the source is acceptable; there is no server to run one.
- **Must cover** Two APIs: delivery (public, read-only, published content) and admin
  (`/v1/admin`, authenticated, what the admin itself uses). The delivery routes that exist.
  Listing, filtering, sorting and paging as the code implements them. Expanding references.
  Locales. API keys and their scopes. What is never delivered (drafts, types with public
  delivery off). The reference at `/api-docs`, generated per install. One complete request and
  response.

### workspaces — Workspaces
- **File** `concepts/08-workspaces.md` · **Order** 8 · **Status** done
- **Summary** "Running several sites on one install: what a workspace is and what it separates."
- **Sources** `packages/thallo-tenancy/src/ThalloTenantTables.php` (what is per workspace), `core/src/Settings/SystemKeys.php`
  (what stays install-wide), `packages/thallo-tenancy/src/`, `config/tenancy.php`, `vendor/glueful/tenancy/config/tenancy.php`,
  `vendor/glueful/tenancy/src/Resolution/`, `admin/src/pages/workspaces/`, `admin/src/pages/settings/workspaces/`,
  `admin/src/components/tenancy/`, `admin/src/navigation/shapeTenancyNav.ts`, `core/src/Content/Authorization/`, `docs/limitations.md`
- **Must cover** A single-site install needs none of this, and says so first. What a workspace
  is and what is separated per workspace (content, settings, media, roles) and what is shared.
  How a request is matched to a workspace (domains). Members and per-workspace roles. That it is
  enabled in stages, resumable, from **Settings › Workspaces** or the command line, never by the
  generic extension toggle — with a link to the operations page.

---

## Guides

One job each, start to finish.

### appearance — Set your colours, fonts and logo
- **File** `guides/01-appearance.md` · **Order** 1 · **Status** done
- **Summary** "Give the site your brand colour, your own fonts and your logo, and see it before you save."
- **Sources** `admin/src/pages/appearance/`, `admin/src/registry/appearanceModule.ts`, `admin/src/style/contrast.ts`,
  `packages/thallo-render/docs/THEMING.md` §9, `packages/thallo-render/src/Theme/ThemeColors.php`,
  `packages/thallo-render/src/Theme/ThemeDesign.php`, `core/src/Http/Controllers/GeneralSettingsController.php`,
  `config/uploads.php`, `vendor/glueful/framework/src/Uploader/FileUploader.php`, `admin/e2e/tests/appearance-page.spec.ts`
- **Must cover** The theme gallery. Accent: a colour family or **Brand colour…** with a hex;
  what Thallo does with it in light and in dark mode, and the warning about light colours as
  link text. Neutral, corners, page ground. Typefaces: the pairings that download nothing, and
  **Custom** with `.woff2` uploads (and the `font/woff2` upload setting an older install needs).
  Editorial and Slab still download the theme's own face for the body; do not say every pairing
  downloads nothing.
  Logos and the favicon. The preview pane and its device sizes. Nothing changes until **Save**.

### header-and-footer — Edit the header and footer
- **File** `guides/02-header-and-footer.md` · **Order** 2 · **Status** done
- **Summary** "Change what is in the site's header and footer, and how they look."
- **Sources** `admin/src/pages/regions/`, `core/src/Content/Regions/`, `packages/thallo-contracts/src/Style/RegionStyle.php`
  (what a region's Style tab offers), `admin/src/editor/inspector/StyleTab.vue`, `core/src/Content/Blocks/StarterBlockTypes.php`
  (the Navigation block's `menu` field), `admin/src/fields/components/blocks/BlockFields.vue`,
  `packages/thallo-render/docs/THEMING.md` §3, `admin/e2e/tests/regions-style.spec.ts`
- **Must cover** **Site › Header & footer**. The Content tab: the blocks a region holds. The
  Style tab: what can be styled. The options a region has (sticky, width). The live preview. How
  a region relates to a navigation menu.

### navigation — Build the site's menus
- **File** `guides/03-navigation.md` · **Order** 3 · **Status** done
- **Summary** "Create a menu, order its links, and show it in the header."
- **Sources** `packages/thallo-navigation/src/`, `admin/src/pages/navigation/`, `admin/src/__tests__/navigationPage.spec.ts`,
  `core/src/Content/Starter/Kinds/NavigationMenuKind.php` (the seeded menu), `core/src/Content/Starter/Kinds/RegionKind.php`,
  `core/src/Content/Regions/RegionDefinitions.php`, `core/src/Content/Blocks/StarterBlockTypes.php` (the Navigation block's fields),
  `admin/src/fields/components/blocks/BlockFields.vue`, `admin/src/registry/navigationModule.ts`,
  `packages/thallo-render/themes/default/templates/blocks/navigation.twig` (the README is stale: drag-and-drop, icons and descriptions exist)
- **Must cover** Creating a menu. Adding links to entries and to URLs. Nesting and ordering.
  Placing the menu with the Navigation block. Per-locale labels, and that an entry item resolves
  per locale (one tree per menu).

### sections-and-pages — Use the section and page library
- **File** `guides/04-sections-and-pages.md` · **Order** 4 · **Status** done
- **Summary** "Start a page from ready-made sections and pages instead of an empty stage."
- **Sources** `core/src/Content/Patterns/StarterPatterns.php`, `core/src/Content/Patterns/PatternLibrary.php`,
  `admin/src/pages/content/[type]/[uuid]/design/[locale].vue` (`insertFromPalette`, `insertPage`, `armInsertTarget`),
  `admin/src/queries/patterns.ts`, `tests/Integration/Content/PatternLibraryTest.php`,
  `admin/src/__tests__/blocks-palette-library.spec.ts`, `admin/e2e/tests/pattern-library.spec.ts`
- **Must cover** The Blocks tab's three views. Dragging or clicking a section in. Inserting a
  whole page, and that it is one step in the history. The list of sections and pages that ship,
  from the source. Why a pattern can be missing (it uses a block type that is switched off).

### style-classes — Reuse styling with style classes
- **File** `guides/05-style-classes.md` · **Order** 5 · **Status** done
- **Summary** "Save a block's styling as a named class, apply it elsewhere, and change it once for all."
- **Sources** `packages/thallo-render/docs/THEMING.md` §12.4, `admin/src/pages/settings/style-classes/`,
  `admin/src/editor/inspector/SaveAsStyleClassDialog.vue`, `core/src/Content/Style/Classes/`,
  `core/src/Content/Console/RunStyleClassJobCommand.php`, `admin/e2e/tests/style-class-picker.spec.ts`
- **Must cover** Saving a class from a block. Applying it to another. Editing the class and what
  follows. A block's own settings against its class's: which wins. Detaching and removing a
  class everywhere, that this is a background job, and the command that runs it without a queue
  worker.

### animation — Animate blocks as they scroll into view
- **File** `guides/06-animation.md` · **Order** 6 · **Status** done
- **Summary** "Add entrances, stagger a group, and set a slow zoom on a picture."
- **Sources** `packages/thallo-render/docs/THEMING.md` §12.6, `packages/thallo-render/src/Style/StyleCompiler.php`
  (motion), `core/src/Content/Blocks/StarterBlockTypes.php` (which blocks offer what), `admin/src/editor/inspector/StyleTab.vue`,
  `admin/src/editor/inspector/choiceLabels.ts`, `packages/thallo-render/runtime/block-motion.js`, `packages/thallo-render/src/Motion.php`,
  `admin/e2e/tests/motion-play.spec.ts`, `tests/Integration/Render/MotionOnAServedPageTest.php`
- **Must cover** The Motion group in the Style tab. Entrance presets, **Duration** and **Delay**. Stagger
  on a Container. Ken Burns, and the two places it works. **Play** on the stage. What a visitor
  who asked for reduced motion sees, and what happens without JavaScript.

### forms — Add a form and receive submissions
- **File** `guides/07-forms.md` · **Order** 7 · **Status** done
- **Summary** "Put a contact form on a page and read its submissions in the admin."
- **Sources** `core/src/Content/Forms/`, `core/config/forms.php`, `core/routes/forms.php`, `core/routes/admin.php`,
  `core/src/Content/Blocks/StarterBlockTypes.php` (the form block's settings), `core/src/Http/Controllers/FormSubmitController.php`,
  `core/src/Http/Controllers/FormSubmissionsController.php`, `admin/src/pages/submissions/`,
  `packages/thallo-render/src/RenderContextExtension.php` (`form_render`), `packages/thallo-render/themes/default/templates/blocks/form.twig`,
  `packages/thallo-render/themes/default/assets/blocks.css`
- **Must cover** The form block and its fields. Where submissions arrive. The spam protection the
  code has. **No notification email is sent**: `FormMailSender` has no implementation anywhere,
  so say so and do not describe mail settings.

### media — Manage images and files
- **File** `guides/08-media.md` · **Order** 8 · **Status** done
- **Summary** "Upload, find and reuse images, documents and fonts."
- **Sources** `admin/src/pages/media/`, `admin/src/queries/media.ts`, `core/src/Http/Controllers/MediaAdminController.php`,
  `core/src/Content/Delivery/EngineMediaUrlResolver.php`, `.../EngineMediaVariantUrlResolver.php`,
  `core/src/Content/Pipeline/Listeners/MediaUsageProjector.php`, `core/src/Content/Repositories/EntryRepository.php` (`assetTargets`),
  `packages/thallo-render/src/RenderContextExtension.php` (`mediaImage`), `packages/thallo-render/themes/default/templates/blocks/image.twig`,
  `vendor/glueful/framework/src/Controllers/UploadController.php`, `vendor/glueful/framework/src/Uploader/FileUploader.php`,
  `vendor/glueful/framework/routes/blobs.php`, `config/uploads.php`, `config/storage.php`
- **Must cover** Uploading and the types and size allowed, and where that is configured. Filters
  by type. Alt text. How images are resized and served. Where files are stored and the choice of
  disk. What deleting a file that is in use does.

### languages — Publish in more than one language
- **File** `guides/09-languages.md` · **Order** 9 · **Status** done
- **Summary** "Add a language, translate entries, and give each language its URLs."
- **Sources** `admin/src/pages/settings/languages/`, the locale toolbar in `admin/src/pages/content/[type]/[uuid]/index.vue`,
  `core/src/Content/Localization/`, `core/src/Content/Delivery/EnginePublicRouteResolver.php`, `core/src/Content/Seo/CanonicalPathBuilder.php`,
  `packages/thallo-render/src/SiteContext.php` (`site.locale`; `site.locales` is empty), `packages/thallo-render/src/RenderContextExtension.php`
  (`seoHead`: `seo.alternates`), `core/src/Content/Seo/EngineSeoHeadProvider.php`, `config/i18n.php`
- **Must cover** Adding and enabling a locale. The default locale. Translating an entry: each
  locale is its own draft and its own published version. The URLs of a translated page. What a
  visitor gets when a translation does not exist. A language switcher in the theme.

### seo — Titles, descriptions, sitemaps and redirects
- **File** `guides/10-seo.md` · **Order** 10 · **Status** done
- **Summary** "Control how pages appear in search results and what happens to old URLs."
- **Sources** `packages/thallo-seo/src/`, `core/src/Content/Seo/`, `packages/thallo-render/src/RenderContextExtension.php` (`seoHead`),
  `packages/thallo-render/themes/default/templates/layout.twig`, `admin/src/pages/settings/redirects/`,
  `admin/src/pages/content/[type]/redirects.vue` (the second redirects screen),
  `admin/src/pages/content/[type]/[uuid]/components/SeoPanel.vue` (mounted in the form editor and the Design view).
  The pack README describes a headless-only pack; the code renders the head.
- **Must cover** The SEO tab of an entry: what each field becomes in the page's head. Defaults
  when a field is empty. The sitemap and robots URLs. Redirects: the ones Thallo makes when a
  slug changes, and making your own.

### search — Add search to the site
- **File** `guides/11-search.md` · **Order** 11 · **Status** done
- **Summary** "Turn on content search, build the index, and choose between PostgreSQL and Meilisearch."
- **Sources** `packages/thallo-search/src/Engine/`, `packages/thallo-search/src/Index/DocumentBuilder.php`,
  `packages/thallo-search/src/Http/SearchController.php`, `packages/thallo-search/routes/public-routes.php`,
  `packages/thallo-search/config/search.php`, `core/src/Content/Delivery/EngineIndexableContentReader.php`,
  `vendor/glueful/meilisearch/config/meilisearch.php` (`MEILISEARCH_HOST`; no `.env.example` names it),
  `packages/thallo-render/themes/default/templates/_docs_search.twig`, `packages/thallo-render/runtime/block-docs-search.js`
- **Must cover** Switching it on (**Settings › General**). `search:reindex` once. What is
  indexed and what is left out. The search API route and one request. `SEARCH_ENGINE` and when
  Meilisearch is used. Per-type configuration, if the config has it. Showing a search box in a
  theme.

### documentation-sites — A documentation section on your site
- **File** `documentation-sites.md` (exists, stays at the top of `docs/`) · **Order** 60 · **Status** done

### import-content — Import content from CSV, WordPress or Markdown
- **File** `guides/12-import-content.md` · **Order** 12 · **Status** done
- **Summary** "Bring existing content in: map a CSV, import a WordPress export, or move a Thallo site."
- **Sources** `packages/thallo-importers/src/`, `core/src/Content/ImportExport/`, `core/src/Http/Controllers/ImportExportController.php`
  (the upload: accepted kinds and the size cap), `admin/src/pages/settings/import-export/`,
  `admin/src/pages/users/components/UserBulkImportModal.vue` (where `csv.users` lives), `vendor/glueful/import-export/`,
  `core/config/import_export.php` and `CoreServiceProvider::withUploadsRoot()`
- **Must cover** **Settings › Import / Export** and the importers capability. Each adapter: what
  file it takes, how fields are mapped, what it does with HTML. Dry run, then commit. The jobs
  list, errors and reports. Exporting and re-importing NDJSON to move a site. That imports are
  queue jobs on the `import-export` queue. Link: documentation sites for the Markdown folder.

### make-a-theme — Make your own theme
- **File** `guides/13-make-a-theme.md` · **Order** 13 · **Status** done
- **Summary** "Start a theme from the default one, override a template, and ship your own CSS."
- **Sources** `packages/thallo-render/docs/THEMING.md` (all), `packages/thallo-render/src/Console/ThemeCloneCommand.php`,
  `packages/thallo-render/src/ThemeLocator.php`, `packages/thallo-render/config/render.php`,
  `packages/thallo-render/src/Style/ThemeVocabulary.php`, `packages/thallo-contracts/src/Style/Vocabulary.php` (the required tokens),
  `packages/thallo-render/src/Http/Controllers/TemplatesAdminController.php`, `packages/thallo-render/src/Templates/TemplateCatalog.php`,
  `admin/src/pages/templates/`, `packages/thallo-render/themes/default/`, `packages/thallo-render/src/Style/ThemeCssLint.php`.
  Templates fall back to the default theme file by file; stylesheets do not — a theme serves only
  the CSS its own `theme.json` names.
- **Must cover** Where a site's themes live in an install. Cloning the default theme, by
  whatever means the code provides. `theme.json` and its keys, the screenshot. Overriding one
  template and leaving the rest to the default, if the code supports inheritance — find out.
  The tokens a theme must define, and what the theme lint refuses. The hooks a template must
  keep for the Design view to work. Making the theme live. The **Theme editor** in the admin.

### make-a-block-type — Make your own block type
- **File** `guides/14-make-a-block-type.md` · **Order** 14 · **Status** done
- **Summary** "Define a block's fields in the admin, write its template, and give it style settings."
- **Sources** `admin/src/pages/settings/block-types/`, `core/src/Content/Blocks/`, `core/src/Content/Http/Controllers/BlockTypeController.php`
  (the style refusal), `packages/thallo-contracts/src/Style/StyleTargets.php`, `packages/thallo-render/src/RenderContextExtension.php` (`blocks`:
  the missing-template behaviour), `packages/thallo-render/src/Templates/TargetLint.php`, `.../DatabaseTemplateLoader.php`,
  `admin/src/editor/inspector/tabMap.ts`, `core/src/Content/Schema/FieldDefinition.php`,
  `packages/thallo-render/docs/THEMING.md` §4 and §12.3 (§4.2's `media(uuid, variant)` is wrong: one argument; `media_image` exists)
- **Must cover** A worked example (a testimonial). Creating the type and its fields. The Twig
  template: its file name and where it goes (on disk, in the site's own theme: the admin cannot
  create one), the data it receives. Style settings, and the order that trips a reader: the
  template must carry the helpers before a group can be ticked. What happens on a site with no
  template for the type. Changing the type later.

### users-and-roles — Users, roles and permissions
- **File** `guides/15-users-and-roles.md` · **Order** 15 · **Status** done
- **Summary** "Create accounts, decide what each may do, and read the trail of who changed what."
- **Sources** `admin/src/pages/users/`, `admin/src/pages/roles-permissions/`, `admin/src/pages/audit-log/`,
  `core/src/Support/UserRoleAssignmentPolicy.php`, `.../RoleAuthority.php`, `.../AuthorityContinuityGuard.php`,
  `core/src/Http/Controllers/UserAdminController.php`, `.../AssignableRolesController.php`, `core/routes/admin.php`,
  `core/src/Setup/InstallRoleGrants.php`, `core/database/dependent-migrations/004_SeedRolesAndPermissions.php`,
  `vendor/glueful/aegis/migrations/003_SeedDefaultRoles.php`, `core/src/Setup/Console/SuperuserGrantCommand.php`,
  `config/users.php`, `config/auth.php`. (`core/src/Content/Authorization/` is the workspace role layer, not this.)
  There is no invitation flow: an administrator sets the password.
- **Must cover** Creating a user. The roles that ship and what each may do, from the source.
  Changing a role's permissions. The superuser and the two commands about it. Sign-in options
  the config offers. The audit log.

### webhooks — Notify other systems with webhooks
- **File** `guides/16-webhooks.md` · **Order** 16 · **Status** done
- **Summary** "Register an endpoint, verify the signature Thallo signs its requests with, and read the delivery log."
- **Sources** `admin/src/pages/developers/webhooks/`, `admin/src/queries/webhooks.ts` (the event list the admin offers),
  `core/src/Content/Events/`, `core/src/Providers/CoreServiceProvider.php` (`registerEventListeners`),
  `core/src/Content/Pipeline/Listeners/DispatchWebhookListener.php`, `core/routes/admin.php`,
  `core/database/dependent-migrations/007_CreateWebhookTables.php`, `config/api.php` (the `webhooks` block),
  `vendor/glueful/framework/src/Api/Webhooks/` (`Webhook.php`, `WebhookDispatcher.php`, `WebhookSignature.php`),
  `core/src/Settings/GeneralSettings.php` (`webhooks_enabled`)
- **Must cover** Switching webhooks on. Creating one. The events that exist and the payload of
  each. Signing and how a receiver checks it. Retries and the delivery log. Delivery is queued on
  the `webhooks` queue (`config/api.php`). **First establish that delivery works at all:** the
  pilot read `WebhookDispatcher::queueDelivery()` in `vendor/glueful/framework/src/Api/Webhooks/`
  as passing a job OBJECT to `QueueManager::push(string $job, …)`, which would throw. Find what
  Thallo's own content webhooks actually call, find a test that proves a delivery, and if there
  is none, say so in your report and do not describe delivery as working.

### accounts — Let visitors sign up and sign in
- **File** `guides/17-accounts.md` · **Order** 17 · **Status** done
- **Summary** "Give the site's visitors accounts: registration, sign-in and account pages."
- **Sources** `packages/thallo-account/src/`, `packages/thallo-account/routes.php`, `core/src/Account/`, `core/src/Signup/`,
  `core/config/signup.php`, `admin/src/pages/settings/accounts/`, `core/src/Content/Blocks/ContributedBlockTypeReconciler.php`,
  `packages/thallo-render/src/ThemeLocator.php`, `packages/thallo-render/src/Templates/TemplateCatalog.php`, `admin/src/pages/templates/index.vue`,
  `vendor/glueful/email-notification/src/EmailChannel.php`, `vendor/glueful/framework/config/services.php` (the mail defaults).
  (`core/routes/signup.php` and Settings › Signup are member and workspace signup, not this;
  `docs/internal/STOREFRONT_ACCOUNTS.md` is stale in three places.)
- **Must cover** The accounts capability. What pages it adds to the site and their URLs. The
  settings. Email verification and the mail it needs. How a theme styles the account pages.

### commerce — Sell products
- **File** `guides/18-commerce.md` · **Order** 18 · **Status** done
- **Summary** "Switch on the store, add a product, take a payment, and see the order."
- **Sources** `packages/thallo-commerce/routes/shop-routes.php` (the route list), `packages/thallo-commerce/src/` (`Http/Shop/`,
  `Starter/`, `Payments/`, `Console/`, `Diagnostics/`), `packages/thallo-commerce/src/Starter/ShopBlockTypesContributor.php`,
  `packages/thallo-commerce/config/thallo-commerce.php`, `vendor/glueful/commerce/config/commerce.php`, `admin/src/pages/commerce/`,
  `admin/src/pages/settings/payments.vue`, `core/src/Settings/PlatformPaymentSettingsStore.php` (the admin's values win over `config/payvia.php`),
  `core/src/Setup/InstallRoleGrants.php`, `docs/production.md` (commerce obligations), `docs/limitations.md`.
  (The pack README is stale: four blocks not five, "enabled by default", `./thallo`.)
- **Must cover** Enabling Commerce and Payvia, as the code has it. Payment settings, and
  why payment links need a canonical HTTPS `BASE_URL`. A product and its variants. The shop
  blocks and pages. Cart and checkout. Orders in the admin. The cron lines commerce adds. Be
  exact about what the preview does not do yet.

### subscriptions — Charge for plans
- **File** `guides/19-subscriptions.md` · **Order** 19 · **Status** done
- **Summary** "Define plans, show pricing on a page, and let a workspace subscribe."
- **Sources** `packages/thallo-subscriptions/src/`, `admin/src/pages/subscriptions/`, `admin/src/pages/billing/`,
  `admin/src/registry/subscriptionsModule.ts`, `admin/src/utils/shapeTenancyNav.ts`, `packages/thallo-contracts/src/Billing/PlanCheckoutUrlResolver.php`,
  `packages/thallo-render/src/RenderContextExtension.php` (`plan_checkout_url`), `packages/thallo-render/themes/default/templates/blocks/pricing_plan.twig`,
  `core/src/Content/Blocks/StarterBlockTypes.php` (the pricing blocks are core starters), `core/src/Content/Authorization/CapabilityCatalog.php`,
  `config/tenancy.php`, `vendor/glueful/subscriptions/src/Plans/PlanPurchasability.php`, `vendor/glueful/subscriptions/config/subscriptions.php`,
  `core/src/Settings/EngineAdminUrlProvider.php`
- **Must cover** What the subscriptions engine is for and who subscribes: it bills a workspace
  for its use of the install, never a site visitor. Plans. The pricing
  blocks and how a plan's button reaches checkout. The billing page and the return from the
  payment provider. What it needs from the install (`BASE_URL`, the admin's address).

---

## Reference

Things you look up. Complete, in a predictable order.

### cli — Command line reference
- **File** `reference/01-cli.md` · **Order** 1 · **Status** done
- **Summary** "Every command Thallo adds to the console, with its arguments and options."
- **Sources** `php glueful list` and `php glueful <command> --help`; the command classes under
  `core/src/**/Console/` and `packages/*/src/Console/`; `packages/*/src/*ServiceProvider.php` (`$this->commands`:
  a command inside a capability gate is missing from `list` while the capability is off). The `search:*`
  commands `list` prints are the Meilisearch extension's; Thallo's are `search:reindex` and `search:status`.
  Whether a command writes is read from its class, not from `--help`.
- **Must cover** Every command Thallo adds — `thallo:*`, `render:*`, `analytics:prune`, `search:reindex`, `search:status` — grouped (setup, content, blocks, docs and imports,
  search, tenancy, commerce, maintenance). For each: one line on what it does, its arguments and
  options exactly as `--help` prints them, whether it writes, and an example. The framework's
  own commands a site owner uses (`migrate:*`, `queue:*`, `cache:*`, `extensions:*`,
  `generate:*`) in one table with a line each. Leave out commands that are leftovers of
  development; list the ones you left out in your report.

### configuration — Configuration reference
- **File** `reference/02-configuration.md` · **Order** 2 · **Status** done
- **Summary** "The environment keys and config files a site owner sets, with their defaults."
- **Sources** `skeleton/.env.example`, `skeleton/config/`, `core/config/`, `config/` (its `development/` and
  `testing/` folders prove the environment overlay), `vendor/glueful/framework/src/Bootstrap/ConfigurationLoader.php`
  and `ApplicationContext::mergeConfigDefaults()` (the layering), `core/src/Settings/GeneralSettings.php`,
  `admin/src/pages/settings/general/index.vue` (the labels), `vendor/glueful/commerce/config/commerce.php`
- **Must cover** How configuration is layered: `.env`, `config/*.php`, and settings saved in the
  admin, and which wins. The keys a site owner actually touches, grouped (site and URLs,
  database, mail, queue and scheduler, storage and uploads, search, security, rendering and
  cache, tenancy, commerce). For each: what it does, its default, an example. Do not list every
  framework key: link to the file.

### template-functions — Template functions
- **File** `reference/03-template-functions.md` · **Order** 3 · **Status** done
- **Summary** "Every function, filter and variable a Thallo template can use."
- **Sources** `packages/thallo-render/src/RenderContextExtension.php` (`getFunctions`/`getFilters`: the only complete list;
  THEMING §4.2 is a stale subset), `packages/thallo-render/src/Http/Controllers/RenderController.php` and
  `packages/thallo-render/src/SiteContext.php` (what each template receives), `core/src/Http/Controllers/RegionAdminController.php`,
  `packages/thallo-render/src/Templates/TemplatePolicy.php` (the allowlist — enforced for database templates only),
  `admin/src/pages/templates/components/twigCompletions.ts` (must agree with the policy)
- **Must cover** Every function with its signature, what it returns and a one-line example,
  grouped by purpose (content, media, navigation, regions, localisation, assets, search, docs,
  commerce, accounts). The variables each kind of template receives. What the template sandbox
  refuses. Anything in the code that is not in THEMING.md, and the reverse, goes in your report.

### block-library — The block library
- **File** `reference/04-block-library.md` · **Order** 4 · **Status** done
- **Summary** "Every block that ships: what it is for, its fields, and its style settings."
- **Sources** `core/src/Content/Blocks/StarterBlockTypes.php`, `packages/thallo-render/docs/THEMING.md` §4.4,
  `packages/thallo-render/themes/default/templates/blocks/`, the two packs that add blocks:
  `packages/thallo-account/src/Blocks/AccountBlockTypesContributor.php` and
  `packages/thallo-commerce/src/Starter/ShopBlockTypesContributor.php` (subscriptions and navigation add none;
  the pricing and navigation blocks are core starters), `admin/src/pages/settings/block-types/components/BlockTypeStyleSettings.vue`
  (the labels for style capability groups)
- **Must cover** Every block type, grouped as the Blocks tab groups them. For each: what it is
  for, its fields with their types, whether it holds other blocks, which style settings it
  offers (by label; what each does is the style settings page's), and the capability it needs if
  it is not always there. The template is always `blocks/{slug}.twig`: say it once. Count them from the source: do not
  repeat a number from elsewhere.

### style-settings — Style settings reference
- **File** `reference/05-style-settings.md` · **Order** 5 · **Status** done
- **Summary** "Every setting in the Style and Layout tabs, its choices, and the CSS it becomes."
- **Sources** `packages/thallo-render/docs/THEMING.md` §12.1–§12.3a, `packages/thallo-contracts/src/Style/StyleSchema.php`,
  `.../Vocabulary.php`, `.../StyleTargets.php`, `.../CascadeResolver.php`, `packages/thallo-render/src/Style/StyleCompiler.php`,
  `packages/thallo-render/src/Style/ClassNames.php`, `admin/src/editor/inspector/StyleTab.vue` (`LABELS`),
  `.../layoutLabels.ts`, `.../choiceLabels.ts`, `.../tabMap.ts`, `core/src/Content/Blocks/CustomBlockStyle.php`,
  `admin/src/editor/breakpoint.ts`. A long reference: 51 properties, about 3,500 words.
- **Must cover** The vocabulary: each token family and its steps. Each property: its path, its
  choices, whether it is responsive, the class it emits and the declaration behind it. The
  breakpoints and their widths. How a reset works. Which properties a given block offers
  (targets). This page is for theme authors and the curious: say so at the top.

### permissions — Permissions reference
- **File** `reference/06-permissions.md` · **Order** 6 · **Status** done
- **Summary** "Every permission, what it allows, and which roles hold it on a new install."
- **Sources** `php glueful permissions:list` (the whole declared catalogue, with owners),
  `vendor/glueful/aegis/migrations/003_SeedDefaultRoles.php`, `core/database/dependent-migrations/` (004–015 seed
  Thallo's permissions and roles), `core/src/Providers/CoreServiceProvider.php` (`permissions()`),
  `core/src/Setup/InstallRoleGrants.php`, `core/routes/admin.php` and `packages/*/routes/` (which route asks for which),
  `config/tenancy.php` (`role_matrix`), `core/src/Content/Authorization/CapabilityCatalog.php`
- **Must cover** Every permission string with a line on what it opens, grouped. The roles a new
  install has and the permissions each is granted. Permissions that imply others.

### limitations — Known limitations
- **File** `limitations.md` (exists, stays at the top of `docs/`) · **Order** 90 · **Status** done

---

## Operations

Running a live site.

### production — Running Thallo in production
- **File** `production.md` (exists, stays at the top of `docs/`) · **Order** 1 · **Status** done

### upgrading — Upgrading Thallo
- **File** `upgrading.md` (exists, stays at the top of `docs/`) · **Order** 2 · **Status** done

### scheduler-and-queues — The scheduler and the queue
- **File** `operations/03-scheduler-and-queues.md` · **Order** 3 · **Status** done
- **Summary** "The one cron line every site needs, what runs on it, and three ways to run background jobs."
- **Sources** `config/schedule.php`, `config/queue.php`, `core/config/import_export.php`,
  `vendor/glueful/framework/src/Scheduler/JobScheduler.php`, `vendor/glueful/framework/src/Queue/QueueWorker.php`,
  `vendor/glueful/framework/src/Queue/Drivers/`, `core/src/Content/Scheduling/SchedulerHeartbeat.php`,
  `core/src/Http/Controllers/HealthAdminController.php`, `core/src/Http/Controllers/ScheduledTasksController.php`,
  `skeleton/.env.example` (the queue block), `php glueful queue:work --help`, `php glueful queue:scheduler --help`
- **Must cover** The scheduler cron line and every job it runs, from `config/schedule.php`, with
  its schedule; that they run inside the tick, not on the queue. How to tell it is running
  (**Utilities › Health**). What is a queue job, and every queue name the code dispatches to.
  The ways to get queued work done: a worker under a supervisor; a cron line with
  `--stop-when-empty`, with the limits the worker's code implies; one job by hand. There is no
  inline (`sync`) connection, and mail is sent inline, not queued: the pilot established both.
  A control-panel cron form, filled in.

### backups — Back up and restore
- **File** `operations/04-backups.md` · **Order** 4 · **Status** done
- **Summary** "What to back up, the backup job that ships, and how to restore a site."
- **Sources** `config/schedule.php` (`database_backup` and its `DB_BACKUP_*` keys — no `.env.example` names them),
  `vendor/glueful/framework/src/Tasks/DatabaseBackupTask.php` (where the behaviour is; the job is a wrapper),
  `config/app.php` (`paths.backups`), `config/storage.php`, `core/src/Content/ImportExport/`,
  `php glueful thallo:provision --help` (`--force`), `thallo:resync`
- **Must cover** The three things that make a site: the database, `storage/` (uploads), and
  `.env` with its keys. What the scheduled backup does, where it writes, how long it keeps.
  Restoring, step by step, and the keys that must match for encrypted values and signed previews
  to survive. The NDJSON export as a content-only copy, and what it leaves out.

### troubleshooting — Health checks and troubleshooting
- **File** `operations/05-troubleshooting.md` · **Order** 5 · **Status** done
- **Summary** "Find out what is wrong: the doctor, the Health page, the logs, and the usual causes."
- **Sources** `core/src/Setup/Doctor/Doctor.php`, `core/src/Setup/Console/DoctorCommand.php`,
  `vendor/glueful/framework/src/Services/HealthService.php`, `core/src/Content/Scheduling/SchedulerHeartbeat.php`,
  `core/src/Http/Controllers/CacheAdminController.php`, `core/src/Setup/AdminBundlePublisher.php`,
  `packages/thallo-render/src/Console/ClearRenderCacheCommand.php`, `packages/thallo-render/src/Http/Middleware/RenderCachePurge.php`,
  `vendor/glueful/framework/src/Logging/LogManager.php` (the real file names), `config/logging.php`,
  `docs/production.md` (PHP-served asset paths), `tests/Integration/Render/PreviewAssetsUnderProxiedPrefixTest.php`
- **Must cover** `thallo:doctor` and each check it makes. The Health page and each check. Where
  logs are. Clearing caches and when it is the answer. A table of symptoms to causes, only for
  problems the code or the tests show are real: unstyled preview, `/admin` assets 404 when the
  bundle was never published, scheduled publishing not happening, imports stuck at queued,
  preview bar links to the wrong place, canonical and OG URLs missing at a localhost `BASE_URL`.

### security — Security
- **File** `operations/06-security.md` · **Order** 6 · **Status** done
- **Summary** "What Thallo protects by default, the secrets a site holds, and what is yours to do."
- **Sources** `SECURITY.md`, `config/security.php` (its `headers` and `password` blocks are read by nothing),
  `config/cors.php` (the live CORS config), `config/session.php`, `config/auth.php`, `skeleton/.env.example`,
  `core/src/Http/Controllers/SetupController.php` and `core/src/Setup/Console/ProvisionCommand.php` (the setup token),
  `packages/thallo-render/src/Templates/TemplatePolicy.php` (database templates only), `vendor/glueful/framework/src/Application.php`
  (`ContentSecurityPolicy::fromEnv`), `packages/thallo-render/docs/THEMING.md` §8.5 and §9.5 (say no CSP ships; one does, report-only)
- **Must cover** The keys provision generates and what each protects; rotating them. The setup
  token. HTTPS and the production defaults of `.env`. The content security policy the theme
  needs. The template sandbox. Upload restrictions. API keys and their scopes. How to report a
  vulnerability, word for word from `SECURITY.md`.

### multi-site — Turn on workspaces
- **File** `operations/07-multi-site.md` · **Order** 7 · **Status** done
- **Summary** "Enable multi-tenancy in stages, give each workspace its domain, and turn it off again."
- **Sources** `packages/thallo-tenancy/src/Enablement/`, `packages/thallo-tenancy/src/Resolution/`,
  `packages/thallo-tenancy/src/Console/`, `config/tenancy.php`, `skeleton/.env.example` (the tenancy block),
  `docs/internal/operations/tenancy.md` (purge recovery and the cooldown sweep; its `extensions:enable tenancy` is wrong),
  the starter-sync commands `thallo:tenant:sync`, `thallo:tenant:blocks:sync`, `thallo:tenant:seed` (the disable gate demands them)
- **Must cover** That this is a one-way-looking door with a way back: read the whole page first.
  The stages of `thallo:tenancy:enable` and what each changes. Activating full resolution.
  Tenants, domains and members by command. Diagnosing. Disabling. What a single-store install
  must not run.

---

## After the pages

- Update the README's **Documentation** list to the sections.
- The homepage's links (the blueprint) point at `/docs/concepts/blocks`,
  `/docs/concepts/publishing` and `/docs/upgrading#knowing-when-to-upgrade`. Docs URLs are
  flat: they become `/docs/blocks`, `/docs/publishing` and `/docs/upgrading#…`.
