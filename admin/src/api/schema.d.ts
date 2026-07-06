export interface paths {
    "/analytics/series": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Analytics time-series for one metric */
        get: operations["getV1AdminAnalyticsSeries"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/analytics/summary": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Analytics summary (KPIs incl. active users) */
        get: operations["getV1AdminAnalyticsSummary"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/analytics/breakdown": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Analytics breakdown: top subjects for one event */
        get: operations["getV1AdminAnalyticsBreakdown"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/collections": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** List collections */
        get: operations["getV1AdminCollections"];
        put?: never;
        /** Create a collection */
        post: operations["postV1AdminCollections"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/navigation/menus": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** List navigation menus */
        get: operations["getV1AdminNavigationMenus"];
        put?: never;
        /** Create a navigation menu */
        post: operations["postV1AdminNavigationMenus"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/render/themes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** List selectable themes (validator-accepted) and the active one */
        get: operations["getV1AdminRenderThemes"];
        put?: never;
        /** Clone a theme into a new app theme directory (themes/{name}) */
        post: operations["postV1AdminRenderThemes"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/render/templates": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** List resolvable templates (filesystem + DB) for a theme */
        get: operations["getV1AdminRenderTemplates"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workflow/queue": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Review queue (in_review submissions) */
        get: operations["getV1AdminWorkflowQueue"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/content-types": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List content types
         * @description Each item includes its full field schema.
         */
        get: operations["getV1AdminContenttypes"];
        put?: never;
        /**
         * Create a content type
         * @description `slug` must be a unique lowercase identifier. Filterable-field indexes are built out-of-band after commit.
         */
        post: operations["postV1AdminContenttypes"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/block-types": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** List block types */
        get: operations["getV1AdminBlocktypes"];
        put?: never;
        /**
         * Create a block type
         * @description `slug` is a unique lowercase identifier and IMMUTABLE after creation — it is the blocks/{slug}.twig template contract.
         */
        post: operations["postV1AdminBlocktypes"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List entries of a content type (draft-inclusive)
         * @description Returns a page of entries for the content type named by `type` (slug), INCLUDING drafts/scheduled/unpublished entries (this is the admin authoring list, not the published delivery feed). Each row has a derived `display_title`, editorial `status` (draft|scheduled|published), the `locales` present, and `updated_at`. Offset paged via `page`/`perPage`; `q` filters on the display title. Requires the `content.view` permission.
         */
        get: operations["getV1AdminEntries"];
        put?: never;
        /**
         * Create an entry
         * @description Seeds an empty draft in the given `locale` (defaults to the i18n default).
         */
        post: operations["postV1AdminEntries"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/icons": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List vendored icons
         * @description Bare icon names from the render pack's vendored set (lucide|brands), sorted. The icon picker's source of truth — parity with icon() by construction. Requires `content.view`.
         */
        get: operations["getV1AdminIcons"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/regions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List chrome regions
         * @description Every global region (header, footer) with its saved blocks, settings, allowed block palette and settings keys. Absent rows surface as empty lists so the editor always round-trips. Requires `content.view`.
         */
        get: operations["getV1AdminRegions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/regions/preview": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Preview chrome regions
         * @description Renders the POSTED (unsaved) header/footer block lists through the real theme pipeline and returns a self-contained HTML document for an iframe. Validates exactly like a save (palette, schemas, settings) so errors surface BEFORE anything goes live. Never writes. Requires `content.view`.
         */
        post: operations["postV1AdminRegionsPreview"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/settings/general": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get general settings
         * @description Effective instance settings (site identity, default locale, delivery defaults, feature toggles): a settings override, else the config/.env default. Requires `content.manage`.
         */
        get: operations["getV1AdminSettingsGeneral"];
        /**
         * Update general settings
         * @description Persists the submitted settings to settings (only supplied fields change). Applies on the next request — no restart. Requires `content.manage`.
         */
        put: operations["putV1AdminSettingsGeneral"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/users": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Create a user
         * @description Creates an active account with an admin-set password and marks the email verified (admin-created accounts are trusted, so the user can sign in immediately). `username` and `email` must be unique. Optionally assign roles by passing `role_slugs` (applied via the RBAC layer after the account is created). Requires the `users.create` permission.
         */
        post: operations["postV1AdminUsers"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/extensions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List installed extensions
         * @description Installed glueful-extension packages with version, provider, dependencies and enabled state. Requires the `system.access` permission.
         */
        get: operations["getV1AdminExtensions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/extensions/registry": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Browse the extension catalog
         * @description Searches Packagist for `type=glueful-extension` packages (optional `q` filter) and flags those already installed. Requires the `system.access` permission.
         */
        get: operations["getV1AdminExtensionsRegistry"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/extensions/enable": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Enable an installed extension
         * @description Adds the extension to config/extensions.php and recompiles the cache. Dev only. Requires the `system.access` permission.
         */
        post: operations["postV1AdminExtensionsEnable"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/extensions/disable": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Disable an installed extension
         * @description Removes the extension from config/extensions.php and recompiles the cache. Dev only. Requires the `system.access` permission.
         */
        post: operations["postV1AdminExtensionsDisable"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/media": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List media
         * @description Paginated blob library. Optional `type` (image|video|audio|doc), `q` (name search), `page`, `per_page`. Requires the `content.view` permission.
         */
        get: operations["getV1AdminMedia"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api-keys": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List API keys
         * @description System-wide, paginated list of API keys with their owner and status. Optional `status` (active|expired|revoked), `q` (name search), `page`, `per_page`. Requires the `system.access` permission.
         */
        get: operations["getV1AdminApikeys"];
        put?: never;
        /**
         * Create an API key
         * @description Mints a new key owned by the authenticated admin. The plaintext key is returned once as `plain` and never stored. Requires `system.access`.
         */
        post: operations["postV1AdminApikeys"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/webhooks/subscriptions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List webhook subscriptions
         * @description Paginated list of webhook subscriptions. Optional `active=true` to return only active ones, plus `page` and `per_page` (max 100).
         */
        get: operations["getV1AdminWebhooksSubscriptions"];
        put?: never;
        /**
         * Create a webhook subscription
         * @description Subscribe an endpoint to events. Body: `url` (required, must be a valid URL — HTTPS when require_https is on), `events` (required, non-empty array; supports `*` and `prefix.*` wildcards), optional `metadata`. The signing `secret` is returned once.
         */
        post: operations["postV1AdminWebhooksSubscriptions"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/webhooks/deliveries": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List webhook deliveries
         * @description Paginated delivery log. Optional `status` (pending|delivered|failed|retrying), `subscription` (UUID) filters, plus `page` and `per_page` (max 100).
         */
        get: operations["getV1AdminWebhooksDeliveries"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/capabilities": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List enabled capabilities
         * @description Capabilities provided by installed packs and not disabled by the thallo.capabilities switchboard. Requires the `system.access` permission.
         */
        get: operations["getV1AdminCapabilities"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/health": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * System health
         * @description Overall health (database, cache, extensions, config) plus runtime info (version, PHP, memory, disk). Read-only. Requires `system.access`.
         */
        get: operations["getV1AdminHealth"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/cache": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Cache status
         * @description Cache driver, prefix, tag support, key count and driver stats. Requires `system.access`.
         */
        get: operations["getV1AdminCache"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/cache/clear": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Clear cache
         * @description Clears the cache. With `content_type`, only that type's delivery cache (the `thallo:type:<slug>` tag) is invalidated; otherwise the whole cache is flushed. Requires `system.access`.
         */
        post: operations["postV1AdminCacheClear"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/scheduled-tasks": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List scheduled tasks
         * @description The recurring jobs from config/schedule.php — name, cron schedule, computed next run, configured enabled state, handler, and queue. Requires `system.access`.
         */
        get: operations["getV1AdminScheduledtasks"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/import-export/upload": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Upload an import file
         * @description Stores an NDJSON import source file on the uploads disk and returns its {disk, path} for POST /import-export/imports. Requires `content.manage`.
         */
        post: operations["postV1AdminImportexportUpload"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/collections/{name}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Get a collection */
        get: operations["getV1AdminCollectionsByName"];
        put?: never;
        post?: never;
        /** Drop a collection (guarded) */
        delete: operations["deleteV1AdminCollectionsByName"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/collections/{name}/rows": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** List rows in a collection */
        get: operations["getV1AdminCollectionsByNameRows"];
        put?: never;
        /** Create a row */
        post: operations["postV1AdminCollectionsByNameRows"];
        /** Delete all rows in a collection (guarded) */
        delete: operations["deleteV1AdminCollectionsByNameRows"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/collections/{name}/rows/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Get a row by UUID */
        get: operations["getV1AdminCollectionsByNameRowsByUuid"];
        put?: never;
        post?: never;
        /** Delete a row */
        delete: operations["deleteV1AdminCollectionsByNameRowsByUuid"];
        options?: never;
        head?: never;
        /** Update a row */
        patch: operations["patchV1AdminCollectionsByNameRowsByUuid"];
        trace?: never;
    };
    "/navigation/menus/{slug}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Menu editor payload: full unfiltered tree for a locale
         * @description Per entry item: target_status (published|unpublished|deleted|missing|routeless), target_title (the localized page title an empty label inherits) and target_url resolved FOR ?locale= (status is locale-sensitive). Includes lock_version.
         */
        get: operations["getV1AdminNavigationMenusBySlug"];
        /** Rename a navigation menu */
        put: operations["putV1AdminNavigationMenusBySlug"];
        post?: never;
        /** Delete a navigation menu (and its items) */
        delete: operations["deleteV1AdminNavigationMenusBySlug"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/render/templates/{path}/versions/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** One version's source */
        get: operations["getV1AdminRenderTemplatesByPathVersionsByUuid"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/render/templates/{path}/versions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Version history (newest first; survives delete) */
        get: operations["getV1AdminRenderTemplatesByPathVersions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/render/templates/{path}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Current template source (DB override or filesystem) */
        get: operations["getV1AdminRenderTemplatesByPath"];
        /** Save a template override (create or update; DB-only paths allowed) */
        put: operations["putV1AdminRenderTemplatesByPath"];
        post?: never;
        /** Delete the override (deactivate — history preserved), fall back to filesystem */
        delete: operations["deleteV1AdminRenderTemplatesByPath"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/seo/meta/{entryUuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Read SEO meta overrides for an entry */
        get: operations["getV1AdminSeoMetaByEntryuuid"];
        /** Upsert SEO meta overrides for an entry */
        put: operations["putV1AdminSeoMetaByEntryuuid"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workflow/entries/{uuid}/{locale}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Review state + history for an entry/locale */
        get: operations["getV1AdminWorkflowEntriesByUuidByLocale"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/content-types/{slug}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get a content type by slug
         * @description Includes the full field schema.
         */
        get: operations["getV1AdminContenttypesBySlug"];
        put?: never;
        post?: never;
        /**
         * Delete a content type
         * @description Soft-delete: existing entries stay in storage but the model is hidden from listing and delivery.
         */
        delete: operations["deleteV1AdminContenttypesBySlug"];
        options?: never;
        head?: never;
        /**
         * Update content-type metadata
         * @description Non-schema metadata only (slug immutable; schema has its own endpoint). Changing public_delivery or mount_at_root purges the render page cache.
         */
        patch: operations["patchV1AdminContenttypesBySlug"];
        trace?: never;
    };
    "/content-types/{slug}/migrations": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** List schema migrations for a content type */
        get: operations["getV1AdminContenttypesBySlugMigrations"];
        put?: never;
        /**
         * Start a destructive schema migration
         * @description Runs asynchronously. `ops` is a list of `{op:"rename",from,to}` / `{op:"delete",name}`; only one migration per type may run at a time (409).
         */
        post: operations["postV1AdminContenttypesBySlugMigrations"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/content-types/{slug}/migrations/{migrationUuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Get one schema migration */
        get: operations["getV1AdminContenttypesBySlugMigrationsByMigrationuuid"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/block-types/{slug}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** One block type */
        get: operations["getV1AdminBlocktypesBySlug"];
        put?: never;
        post?: never;
        /**
         * Hard-delete an UNUSED block type
         * @description Destructive cleanup for unused/mistaken types only (block-migrations spec §6): refuses while any current draft/publication uses the type (the usage scan re-runs server-side) or while a migration is active. No force flag — deactivate is the editorial path.
         */
        delete: operations["deleteV1AdminBlocktypesBySlug"];
        options?: never;
        head?: never;
        /** Update a block type (slug is immutable) */
        patch: operations["patchV1AdminBlocktypesBySlug"];
        trace?: never;
    };
    "/block-types/{slug}/migrations": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** List schema migrations for a block type */
        get: operations["getV1AdminBlocktypesBySlugMigrations"];
        put?: never;
        /**
         * Start a block-type schema migration
         * @description Runs asynchronously. `ops` is a list of `{op:"rename",from,to}` / `{op:"delete",name}`; one active migration per block type (409 — failed migrations stay active until re-driven to completion).
         */
        post: operations["postV1AdminBlocktypesBySlugMigrations"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/block-types/{slug}/migrations/{migrationUuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Get one block-type schema migration */
        get: operations["getV1AdminBlocktypesBySlugMigrationsByMigrationuuid"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/block-types/{slug}/usage": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Block type usage across current content
         * @description On-demand scan of current drafts + pinned publications (non-deleted entries, archived included, nested blocks counted). Historical versions never count. Content-type blockTypes picker allowlists are reported but never gate deletion.
         */
        get: operations["getV1AdminBlocktypesBySlugUsage"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get an entry
         * @description Identity and status only, not field content — use the draft endpoint for values.
         */
        get: operations["getV1AdminEntriesByUuid"];
        put?: never;
        post?: never;
        /**
         * Delete an entry
         * @description Soft-delete; refused (409) while published content still references the entry.
         */
        delete: operations["deleteV1AdminEntriesByUuid"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/draft/{locale}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get an entry's draft for a locale
         * @description Returns field values plus the `lock_version` to echo back on save.
         */
        get: operations["getV1AdminEntriesByUuidDraftByLocale"];
        /**
         * Save an entry's draft (optimistic-locked)
         * @description Optimistic-locked: pass the `lock_version` from the last read; a stale value yields 409 carrying the current draft so the client can rebase.
         */
        put: operations["putV1AdminEntriesByUuidDraftByLocale"];
        post?: never;
        /**
         * Discard an entry draft
         * @description Drops the working draft only; published content is untouched.
         */
        delete: operations["deleteV1AdminEntriesByUuidDraftByLocale"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/locales": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List entry locale variants
         * @description Per-locale draft, publication, and route state — the entry's translation status.
         */
        get: operations["getV1AdminEntriesByUuidLocales"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/locales/{locale}/usage": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get locale content usage counts
         * @description Returns the number of published and draft entries that exist in the given locale. Use this before disabling a locale to warn when published content would be hidden. Requires the `content.manage` permission.
         */
        get: operations["getV1AdminLocalesByLocaleUsage"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/versions/{locale}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List entry versions
         * @description Immutable published versions, newest first.
         */
        get: operations["getV1AdminEntriesByUuidVersionsByLocale"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/routes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List entry routes
         * @description Route slugs assigned across all the entry's locales.
         */
        get: operations["getV1AdminEntriesByUuidRoutes"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/content-types/{slug}/redirects": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List redirects for a content type
         * @description Returns the manual redirects defined for the content type named by `slug` (optionally filtered by `?locale=`), each with its resolved target state (live/broken).
         */
        get: operations["getV1AdminContenttypesBySlugRedirects"];
        put?: never;
        /**
         * Create a redirect for a content type
         * @description Adds a manual SEO redirect (301/302/308) from a source slug to a target URL or entry, scoped to the content type named by `slug`.
         */
        post: operations["postV1AdminContenttypesBySlugRedirects"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/schedules": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** List an entry's schedules */
        get: operations["getV1AdminEntriesByUuidSchedules"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/extensions/{vendor}/{name}/readme": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Render an installed extension README
         * @description Renders the README of an installed glueful-extension package to safe HTML (CommonMark, raw HTML escaped, unsafe links blocked, images stripped). The package path is resolved through the installed-extension registry, never from the request. Cacheable via ETag. Requires the `system.access` permission.
         */
        get: operations["getV1AdminExtensionsByVendorByNameReadme"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/media/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Get a media item */
        get: operations["getV1AdminMediaByUuid"];
        put?: never;
        post?: never;
        /**
         * Delete a media item
         * @description Soft-deletes the blob (status=deleted). Requires the `content.manage` permission.
         */
        delete: operations["deleteV1AdminMediaByUuid"];
        options?: never;
        head?: never;
        /**
         * Update media metadata
         * @description Updates the title (blob name) and the CMS sidecar (alt text, caption, tags). Requires the `content.manage` permission.
         */
        patch: operations["patchV1AdminMediaByUuid"];
        trace?: never;
    };
    "/media/{uuid}/usage": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Where a media item is used */
        get: operations["getV1AdminMediaByUuidUsage"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api-keys/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Get an API key */
        get: operations["getV1AdminApikeysByUuid"];
        put?: never;
        post?: never;
        /**
         * Revoke an API key
         * @description Marks the key revoked so it stops authenticating immediately. The row is kept (revoked_at is set) for audit. Requires `system.access`.
         */
        delete: operations["deleteV1AdminApikeysByUuid"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/webhooks/subscriptions/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Get a webhook subscription */
        get: operations["getV1AdminWebhooksSubscriptionsById"];
        put?: never;
        post?: never;
        /** Delete a webhook subscription */
        delete: operations["deleteV1AdminWebhooksSubscriptionsById"];
        options?: never;
        head?: never;
        /**
         * Update a webhook subscription
         * @description Partial update. Body may include `url`, `events` (non-empty array), `is_active`, `metadata`; only supplied fields change.
         */
        patch: operations["patchV1AdminWebhooksSubscriptionsById"];
        trace?: never;
    };
    "/webhooks/subscriptions/{id}/stats": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get subscription delivery stats
         * @description Delivery counts and success rate over a window. Optional `days` query (default 30).
         */
        get: operations["getV1AdminWebhooksSubscriptionsByIdStats"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/webhooks/deliveries/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get a webhook delivery
         * @description A single delivery including its request payload and the endpoint response body.
         */
        get: operations["getV1AdminWebhooksDeliveriesById"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/import-export/jobs/{uuid}/download": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Download an export result
         * @description Streams the NDJSON result of a completed export job (concatenating its result files). Requires `content.manage`.
         */
        get: operations["getV1AdminImportexportJobsByUuidDownload"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/navigation/menus/{slug}/items": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /**
         * Replace a menu tree atomically
         * @description Whole-tree PUT guarded by lock_version (the GET payload carries it); a stale version is a 409 — reload and retry.
         */
        put: operations["putV1AdminNavigationMenusBySlugItems"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/routes/{locale}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /**
         * Assign an entry route
         * @description Replaces any existing route slug for the entry+locale.
         */
        put: operations["putV1AdminEntriesByUuidRoutesByLocale"];
        post?: never;
        /**
         * Remove an entry route
         * @description Idempotent — succeeds even when no route is assigned.
         */
        delete: operations["deleteV1AdminEntriesByUuidRoutesByLocale"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/regions/{slug}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /**
         * Save a chrome region
         * @description Replaces the region's block list and settings. Blocks are validated against their block-type schemas AND the region's server-enforced palette (out-of-palette types 422 with dot paths); settings are a fixed vocabulary. Applies immediately and purges the render page cache. Requires `content.manage`.
         */
        put: operations["putV1AdminRegionsBySlug"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/collections/{name}/fields/{field}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /** Drop a field (guarded) */
        delete: operations["deleteV1AdminCollectionsByNameFieldsByField"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/collections/{name}/indexes/{field}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /** Remove an index */
        delete: operations["deleteV1AdminCollectionsByNameIndexesByField"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/redirects/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /**
         * Delete a redirect
         * @description Removes the manual redirect identified by `uuid`.
         */
        delete: operations["deleteV1AdminRedirectsByUuid"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/schedules/{scheduleUuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /** Cancel a pending schedule */
        delete: operations["deleteV1AdminEntriesByUuidSchedulesByScheduleuuid"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/users/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        /**
         * Delete a user
         * @description Soft-deletes the user (sets `deleted_at`), so the account drops out of the user list/reads and loses access while the row is preserved for restore/audit. You cannot delete your own account. Requires the `users.delete` permission.
         */
        delete: operations["deleteV1AdminUsersByUuid"];
        options?: never;
        head?: never;
        /**
         * Update a user
         * @description Partial update — only the supplied fields change (`username`, `email`, `status`, `first_name`, `last_name`, `role_slugs`). `username`/`email` must remain unique. `role_slugs` is optional: omit it to leave roles untouched, or send the full desired set (even `[]`) to replace them. Password is not editable here (it has its own reset flow). Requires the `users.edit` permission.
         */
        patch: operations["patchV1AdminUsersByUuid"];
        trace?: never;
    };
    "/collections/{name}/fields": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Add a field */
        post: operations["postV1AdminCollectionsByNameFields"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/collections/{name}/indexes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Add an index */
        post: operations["postV1AdminCollectionsByNameIndexes"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/render/templates/{path}/versions/{uuid}/restore": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Restore a version (append-as-new-current; reactivates a deleted override) */
        post: operations["postV1AdminRenderTemplatesByPathVersionsByUuidRestore"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workflow/entries/{uuid}/{locale}/submit": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Submit a draft for review */
        post: operations["postV1AdminWorkflowEntriesByUuidByLocaleSubmit"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workflow/entries/{uuid}/{locale}/approve": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Approve a submission */
        post: operations["postV1AdminWorkflowEntriesByUuidByLocaleApprove"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workflow/entries/{uuid}/{locale}/request-changes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Request changes on a submission */
        post: operations["postV1AdminWorkflowEntriesByUuidByLocaleRequestchanges"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/workflow/entries/{uuid}/{locale}/withdraw": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Withdraw a submission */
        post: operations["postV1AdminWorkflowEntriesByUuidByLocaleWithdraw"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/block-types/{slug}/activate": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Reactivate a block type */
        post: operations["postV1AdminBlocktypesBySlugActivate"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/block-types/{slug}/deactivate": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Deactivate a block type (existing content keeps rendering/editing) */
        post: operations["postV1AdminBlocktypesBySlugDeactivate"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/locales/{locale}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Create an entry locale draft
         * @description Optionally seeds the new draft by copying the current draft from `source_locale`.
         */
        post: operations["postV1AdminEntriesByUuidLocalesByLocale"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/preview/{locale}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Mint a short-lived preview token
         * @description The returned token is the bearer capability for the unauthenticated `GET /v1/preview/{token}`. An optional `version_uuid` pins a historical version instead of the current draft.
         */
        post: operations["postV1AdminEntriesByUuidPreviewByLocale"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/preview/{locale}/apply": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Apply working fields as an ephemeral preview
         * @description Validates the submitted fields with the same guards as a draft save and stashes the cleaned result so the preview session renders unsaved work. Nothing is persisted; a successful draft save clears the stash.
         */
        post: operations["postV1AdminEntriesByUuidPreviewByLocaleApply"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/schedules/{locale}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Schedule a publish/unpublish */
        post: operations["postV1AdminEntriesByUuidSchedulesByLocale"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/publish/{locale}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Publish an entry's draft
         * @description Snapshots the current draft into an immutable version, pins it, and makes it visible to the delivery API.
         */
        post: operations["postV1AdminEntriesByUuidPublishByLocale"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/unpublish/{locale}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Unpublish an entry
         * @description Removes the publication pin (versions are retained); idempotent — succeeds even when nothing is published.
         */
        post: operations["postV1AdminEntriesByUuidUnpublishByLocale"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/entries/{uuid}/rollback/{locale}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Roll back to a previous version
         * @description Re-pins an existing `version_uuid` as the published version; no new version is created.
         */
        post: operations["postV1AdminEntriesByUuidRollbackByLocale"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/media/{uuid}/optimize": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Optimize an image
         * @description Re-encodes the image to reduce file size (dimensions unchanged) and writes it back to the same blob. Requires glueful/media and the `content.manage` permission.
         */
        post: operations["postV1AdminMediaByUuidOptimize"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api-keys/{uuid}/rotate": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Rotate an API key
         * @description Issues a new key inheriting the scopes/IPs/expiry of the old one, and sets the old key to expire after a grace window (`grace_hours`, default 24, max 720). Both keys work during the window. Returns the new plaintext once. Requires `system.access`.
         */
        post: operations["postV1AdminApikeysByUuidRotate"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/webhooks/subscriptions/{id}/rotate-secret": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Rotate a subscription secret
         * @description Generates a new signing secret and returns it once. Existing signatures using the old secret stop verifying.
         */
        post: operations["postV1AdminWebhooksSubscriptionsByIdRotatesecret"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/webhooks/subscriptions/{id}/test": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Send a test webhook
         * @description Delivers a signed `webhook.test` event to the subscription URL synchronously and returns the endpoint response.
         */
        post: operations["postV1AdminWebhooksSubscriptionsByIdTest"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/webhooks/deliveries/{id}/retry": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Retry a webhook delivery
         * @description Re-queues a failed or retrying delivery for another attempt.
         */
        post: operations["postV1AdminWebhooksDeliveriesByIdRetry"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/scheduled-tasks/{name}/run": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Run a scheduled task now
         * @description Queues the task's handler onto its queue to run asynchronously (it does not run inline). Requires `system.access`.
         */
        post: operations["postV1AdminScheduledtasksByNameRun"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/collections/{name}/access": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /** Replace the access policy */
        patch: operations["patchV1AdminCollectionsByNameAccess"];
        trace?: never;
    };
    "/collections/{name}/field-order": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /** Reorder a collection’s fields */
        patch: operations["patchV1AdminCollectionsByNameFieldorder"];
        trace?: never;
    };
    "/content-types/{slug}/schema": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /**
         * Update a content type's field schema
         * @description Replaces the schema wholesale (not a merge) and bumps the schema version. Filterable-field indexes are rebuilt out-of-band after commit.
         */
        patch: operations["patchV1AdminContenttypesBySlugSchema"];
        trace?: never;
    };
    "/api-keys/{uuid}/scopes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        /**
         * Replace an API key’s scopes
         * @description Overwrites the key’s scope list in place — the key value is unchanged. Used by the collections admin to grant/revoke per-collection access. Requires `system.access`.
         */
        patch: operations["patchV1AdminApikeysByUuidScopes"];
        trace?: never;
    };
}
export type webhooks = Record<string, never>;
export interface components {
    schemas: {
        PaginationMeta: {
            /** @example 1 */
            current_page: number;
            /** @example 25 */
            per_page: number;
            /** @example 137 */
            total: number;
            /** @example 6 */
            total_pages: number;
            /** @example 1 */
            from?: number;
            /** @example 25 */
            to?: number;
            /** @example true */
            has_next_page?: boolean;
            /** @example false */
            has_previous_page?: boolean;
        };
        PaginationLinks: {
            /**
             * Format: uri
             * @example /api/users?page=1
             */
            first?: string;
            /**
             * Format: uri
             * @example /api/users?page=6
             */
            last?: string;
            /** Format: uri */
            prev?: string | null;
            /** Format: uri */
            next?: string | null;
        };
        SuccessResponse: {
            /** @example true */
            success: boolean;
            /** @example Operation completed successfully */
            message: string;
            data?: {
                [key: string]: unknown;
            };
        };
        Error: {
            /**
             * @default false
             * @example false
             */
            success: boolean;
            message: string;
            data: {
                [key: string]: unknown;
            };
        };
        ErrorResponse: {
            /** @example false */
            success: boolean;
            /** @example Resource not found */
            message: string;
            error: {
                /** @example 404 */
                code: number;
                /**
                 * @example NOT_FOUND
                 * @enum {string}
                 */
                error_code: "BAD_REQUEST" | "UNAUTHORIZED" | "FORBIDDEN" | "NOT_FOUND" | "METHOD_NOT_ALLOWED" | "CONFLICT" | "UNPROCESSABLE_ENTITY" | "TOO_MANY_REQUESTS" | "INTERNAL_SERVER_ERROR" | "SERVICE_UNAVAILABLE" | "GATEWAY_TIMEOUT";
                /**
                 * Format: date-time
                 * @description ISO 8601 datetime when the error occurred.
                 */
                timestamp: string;
                /**
                 * @description Correlation identifier for tracing this request in logs.
                 * @example req_abc123
                 */
                request_id: string;
            };
        };
        WebhookEnvelope: {
            /** @example wh_evt_abc123 */
            id: string;
            /** @example user.created */
            event: string;
            /** Format: date-time */
            created_at: string;
            data: Record<string, never>;
        };
        ValidationErrorResponse: {
            /** @example false */
            success: boolean;
            /** @example Validation failed */
            message: string;
            errors: {
                [key: string]: string[];
            };
        };
        LoginRequest: {
            /** @description Username or email */
            username: string;
            /**
             * Format: password
             * @description User password
             */
            password: string;
            /**
             * @description Keep user logged in
             * @default false
             */
            remember_me: boolean;
        };
        LoginResponse: {
            success?: boolean;
            message?: string;
            data?: {
                /** @description JWT access token */
                access_token?: string;
                /** @description JWT refresh token */
                refresh_token?: string;
                /** @example Bearer */
                token_type?: string;
                /** @description Token expiration time in seconds */
                expires_in?: number;
                user?: {
                    /** @description User unique identifier */
                    id?: string;
                    /**
                     * Format: email
                     * @description Email address
                     */
                    email?: string;
                    /** @description Email verification status */
                    email_verified?: boolean;
                    /** @description Username */
                    username?: string;
                    /** @description Full name */
                    name?: string;
                    /** @description First name */
                    given_name?: string;
                    /** @description Last name */
                    family_name?: string;
                    /** @description Profile image URL */
                    picture?: string;
                    /** @description User locale (e.g., en-US) */
                    locale?: string;
                    /** @description Last update timestamp (Unix epoch) */
                    updated_at?: number;
                };
            };
        };
        RefreshTokenRequest: {
            /** @description The refresh token to exchange for new tokens */
            refresh_token: string;
        };
        User: {
            /**
             * Format: uuid
             * @description Unique user identifier
             */
            uuid?: string;
            /** @description User username */
            username?: string;
            /**
             * Format: email
             * @description User email address
             */
            email?: string;
            /**
             * @description User account status
             * @enum {string}
             */
            status?: "active" | "inactive" | "suspended";
            /**
             * Format: date-time
             * @description Account creation timestamp
             */
            created_at?: string;
            /**
             * Format: date-time
             * @description Last update timestamp
             */
            updated_at?: string;
        };
        CreateUserRequest: {
            /** @description Unique username */
            username: string;
            /**
             * Format: email
             * @description User email address
             */
            email: string;
            /**
             * Format: password
             * @description User password
             */
            password: string;
        };
        UpdateUserRequest: {
            /**
             * Format: email
             * @description New email address
             */
            email?: string;
            /**
             * @description New account status
             * @enum {string}
             */
            status?: "active" | "inactive" | "suspended";
        };
        HealthCheckResponse: {
            /**
             * @description Overall system health status
             * @enum {string}
             */
            status?: "healthy" | "unhealthy";
            /**
             * Format: date-time
             * @description Health check timestamp
             */
            timestamp?: string;
            services?: {
                database?: components["schemas"]["ServiceHealth"];
                cache?: components["schemas"]["ServiceHealth"];
                queue?: components["schemas"]["ServiceHealth"];
            };
        };
        ServiceHealth: {
            /**
             * @description Service availability status
             * @enum {string}
             */
            status?: "up" | "down";
            /** @description Response time in milliseconds */
            latency?: number;
            /** @description Additional status information */
            message?: string;
        };
        Extension: {
            /** @description Extension name */
            name?: string;
            /** @description Extension version */
            version?: string;
            /**
             * @description Extension status
             * @enum {string}
             */
            status?: "enabled" | "disabled";
            /**
             * @description Extension type
             * @enum {string}
             */
            type?: "core" | "optional";
            /** @description Extension description */
            description?: string;
            /** @description Required dependencies */
            dependencies?: string[];
        };
        ExtensionListResponse: {
            success?: boolean;
            data?: components["schemas"]["Extension"][];
        };
        Notification: {
            /** @description Notification ID */
            id?: number;
            /** @description Notification type */
            type?: string;
            /** @description Type of entity being notified */
            notifiable_type?: string;
            /** @description ID of entity being notified */
            notifiable_id?: string;
            /** @description Notification payload */
            data?: Record<string, never>;
            /**
             * Format: date-time
             * @description When notification was read
             */
            read_at?: string | null;
            /**
             * Format: date-time
             * @description When notification was created
             */
            created_at?: string;
        };
        NotificationListResponse: {
            success?: boolean;
            data?: components["schemas"]["Notification"][];
            meta?: components["schemas"]["PaginationMeta"];
        };
        FileUploadRequest: {
            /**
             * Format: binary
             * @description The file to upload
             */
            file: string;
        };
        FileUploadResponse: {
            success?: boolean;
            data?: {
                /** @description Uploaded file name */
                filename?: string;
                /** @description File size in bytes */
                size?: number;
                /** @description File MIME type */
                mime_type?: string;
                /** @description File access URL */
                url?: string;
            };
        };
    };
    responses: never;
    parameters: never;
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    getV1AdminAnalyticsSeries: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminAnalyticsSummary: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminAnalyticsBreakdown: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminCollections: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description All collections with their schema. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminCollections: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "name": "Jane",
                 *       "label": "example",
                 *       "fields": "example",
                 *       "access": "example",
                 *       "field_order": "example"
                 *     }
                 */
                "application/json": {
                    name: string;
                    label?: string | null;
                    fields?: {
                        name?: string;
                        type?: string;
                        settings?: unknown[];
                    }[];
                    access?: unknown[];
                    field_order?: unknown[];
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Collection created. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Validation failed */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @example false */
                        success: boolean;
                        message: string;
                        errors: {
                            [key: string]: string[];
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminNavigationMenus: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Menu summaries (slug, name, item_count, lock_version). */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminNavigationMenus: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description The created menu. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Slug already exists. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid slug/name. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminRenderThemes: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Selectable themes + the currently active theme. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminRenderThemes: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Created; response carries the new theme and the refreshed theme list. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid name/source, name taken, or the themes directory is not writable. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminRenderTemplates: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Merged listing with per-path origin (db|theme|default). */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminWorkflowQueue: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Paginated in-review items enriched with draft summaries. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminContenttypes: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description All content types. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            content_types?: {
                                id?: number;
                                uuid?: string;
                                slug?: string;
                                name?: string;
                                description?: string | null;
                                cache_ttl?: number | null;
                                public_delivery?: boolean;
                                mount_at_root?: boolean;
                                status?: string;
                                schema?: {
                                    name?: string;
                                    /** @enum {string} */
                                    type?: "string" | "text" | "number" | "boolean" | "datetime" | "enum" | "reference" | "asset" | "json" | "blocks";
                                    required?: boolean | null;
                                    localized?: boolean | null;
                                    filterable?: boolean | null;
                                    filter_type?: string | null;
                                    enum?: string[];
                                    format?: string | null;
                                    reference_type?: string | null;
                                    multiple?: boolean | null;
                                    max_items?: number | null;
                                    reference_slug_field?: string | null;
                                    block_types?: string[];
                                    pattern?: string | null;
                                    min?: number | null;
                                    max?: number | null;
                                }[];
                                schema_version?: number;
                                created_by?: string | null;
                                /** Format: date-time */
                                created_at?: string;
                                /** Format: date-time */
                                updated_at?: string | null;
                            }[];
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminContenttypes: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "slug": "example-slug",
                 *       "name": "Jane",
                 *       "description": "A short description.",
                 *       "cache_ttl": "example",
                 *       "public_delivery": true,
                 *       "mount_at_root": true,
                 *       "schema": "example"
                 *     }
                 */
                "application/json": {
                    /** @description Unique lowercase content-type slug (1–160 chars). */
                    slug: string;
                    /** @description Human-readable content-type name. */
                    name: string;
                    /** @description Optional description of the content type. */
                    description?: string | null;
                    /** @description Optional delivery Cache-Control max-age override in seconds. */
                    cache_ttl?: number | null;
                    /** @description Whether published delivery routes may be read without an API key. */
                    public_delivery?: boolean;
                    /** @description Whether entries serve at /{slug} instead of /{type}/{slug}. */
                    mount_at_root?: boolean;
                    schema?: {
                        name?: string;
                        type?: string;
                        required?: boolean | null;
                        localized?: boolean | null;
                        filterable?: boolean | null;
                        filter_type?: string | null;
                        enum?: string[];
                        format?: string | null;
                        reference_type?: string | null;
                        multiple?: boolean | null;
                        max_items?: number | null;
                        reference_slug_field?: string | null;
                        /** @description Picker-only block-type allowlist for a `blocks` field. */
                        block_types?: string[];
                        /** @description Anchored regex body a string/text value must fully match. */
                        pattern?: string | null;
                        /** @description Inclusive lower bound for a `number` field (ints coerce). */
                        min?: number | null;
                        /** @description Inclusive upper bound for a `number` field (ints coerce). */
                        max?: number | null;
                    }[];
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Content type created. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            content_type?: {
                                id?: number;
                                uuid?: string;
                                slug?: string;
                                name?: string;
                                description?: string | null;
                                cache_ttl?: number | null;
                                public_delivery?: boolean;
                                mount_at_root?: boolean;
                                status?: string;
                                schema?: {
                                    name?: string;
                                    /** @enum {string} */
                                    type?: "string" | "text" | "number" | "boolean" | "datetime" | "enum" | "reference" | "asset" | "json" | "blocks";
                                    required?: boolean | null;
                                    localized?: boolean | null;
                                    filterable?: boolean | null;
                                    filter_type?: string | null;
                                    enum?: string[];
                                    format?: string | null;
                                    reference_type?: string | null;
                                    multiple?: boolean | null;
                                    max_items?: number | null;
                                    reference_slug_field?: string | null;
                                    block_types?: string[];
                                    pattern?: string | null;
                                    min?: number | null;
                                    max?: number | null;
                                }[];
                                schema_version?: number;
                                created_by?: string | null;
                                /** Format: date-time */
                                created_at?: string;
                                /** Format: date-time */
                                updated_at?: string | null;
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid slug/name, duplicate slug, or invalid field schema. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminBlocktypes: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description All block types, active first. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            block_types?: {
                                uuid?: string;
                                slug?: string;
                                label?: string;
                                icon?: string | null;
                                category?: string | null;
                                description?: string | null;
                                active?: boolean;
                                schema?: {
                                    name?: string;
                                    /** @enum {string} */
                                    type?: "string" | "text" | "number" | "boolean" | "datetime" | "enum" | "reference" | "asset" | "json" | "blocks";
                                    required?: boolean | null;
                                    localized?: boolean | null;
                                    filterable?: boolean | null;
                                    filter_type?: string | null;
                                    enum?: string[];
                                    format?: string | null;
                                    reference_type?: string | null;
                                    multiple?: boolean | null;
                                    max_items?: number | null;
                                    reference_slug_field?: string | null;
                                    block_types?: string[];
                                    pattern?: string | null;
                                    min?: number | null;
                                    max?: number | null;
                                }[];
                            }[];
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminBlocktypes: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "slug": "example-slug",
                 *       "label": "example",
                 *       "icon": "example",
                 *       "category": "example",
                 *       "description": "A short description.",
                 *       "schema": "example"
                 *     }
                 */
                "application/json": {
                    /** @description Unique lowercase block-type slug (also the template name). */
                    slug: string;
                    label: string;
                    /** @description Lucide icon name shown in the block picker. */
                    icon?: string | null;
                    /** @description Free-form picker grouping ("Layout", "Content", …); presentation only. */
                    category?: string | null;
                    description?: string | null;
                    schema?: {
                        name?: string;
                        type?: string;
                        required?: boolean | null;
                        localized?: boolean | null;
                        filterable?: boolean | null;
                        filter_type?: string | null;
                        enum?: string[];
                        format?: string | null;
                        reference_type?: string | null;
                        multiple?: boolean | null;
                        max_items?: number | null;
                        reference_slug_field?: string | null;
                        /** @description Picker-only block-type allowlist for a `blocks` field. */
                        block_types?: string[];
                        /** @description Anchored regex body a string/text value must fully match. */
                        pattern?: string | null;
                        /** @description Inclusive lower bound for a `number` field (ints coerce). */
                        min?: number | null;
                        /** @description Inclusive upper bound for a `number` field (ints coerce). */
                        max?: number | null;
                    }[];
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Block type created. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            block_type?: {
                                uuid?: string;
                                slug?: string;
                                label?: string;
                                icon?: string | null;
                                category?: string | null;
                                description?: string | null;
                                active?: boolean;
                                schema?: {
                                    name?: string;
                                    /** @enum {string} */
                                    type?: "string" | "text" | "number" | "boolean" | "datetime" | "enum" | "reference" | "asset" | "json" | "blocks";
                                    required?: boolean | null;
                                    localized?: boolean | null;
                                    filterable?: boolean | null;
                                    filter_type?: string | null;
                                    enum?: string[];
                                    format?: string | null;
                                    reference_type?: string | null;
                                    multiple?: boolean | null;
                                    max_items?: number | null;
                                    reference_slug_field?: string | null;
                                    block_types?: string[];
                                    pattern?: string | null;
                                    min?: number | null;
                                    max?: number | null;
                                }[];
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Duplicate slug or invalid block schema (no nested blocks/localized/filterable fields). */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminEntries: {
        parameters: {
            query: {
                /** @description Content type slug to list. */
                type: string;
                /** @description Case-insensitive substring filter on the derived display title. */
                q?: string;
                /** @description Page number (default 1). */
                page?: number;
                /** @description Items per page (clamped to thallo.delivery.max_per_page; default default_per_page). */
                perPage?: number;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of entries. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            entries?: unknown[];
                            total?: number;
                            current_page?: number;
                            per_page?: number;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown content type slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Missing/invalid `type`. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminEntries: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "content_type": "example",
                 *       "locale": "example"
                 *     }
                 */
                "application/json": {
                    /** @description Slug of the content type to create an entry for. */
                    content_type: string;
                    /** @description BCP-47 locale for the seeded draft, e.g. "en". Defaults to the i18n default locale. */
                    locale?: string | null;
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Entry created with an empty draft. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            entry?: {
                                id?: number;
                                uuid?: string;
                                content_type_uuid?: string;
                                content_type?: string | null;
                                display_title?: string;
                                /** @enum {string} */
                                status?: "active" | "archived" | "deleted";
                                created_by?: string | null;
                                /** Format: date-time */
                                created_at?: string;
                                /** Format: date-time */
                                updated_at?: string | null;
                            };
                            draft?: {
                                id?: number;
                                entry_uuid?: string;
                                locale?: string;
                                fields?: Record<string, never>;
                                schema_version?: number;
                                lock_version?: number;
                                updated_by?: string | null;
                                /** Format: date-time */
                                updated_at?: string;
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown content type. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminIcons: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Sorted icon names. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Render pack unavailable. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unknown set. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminRegions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Regions with palettes. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminRegionsPreview: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "regions": "example"
                 *     }
                 */
                "application/json": {
                    /** @description array{blocks?: list<array<string,mixed>>, settings?: array<string,mixed>}> */
                    regions?: unknown[];
                };
            };
        };
        responses: {
            /** @description Rendered preview document. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Render pack unavailable. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Same validation a save would fail. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminSettingsGeneral: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Current general settings. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            settings?: {
                                site_name?: string;
                                site_preview_url?: string;
                                default_locale?: string;
                                default_per_page?: number;
                                max_per_page?: number;
                                cache_ttl?: number;
                                scheduler_enabled?: boolean;
                                webhooks_enabled?: boolean;
                                homepage_entry?: string;
                                site_logo?: string;
                                admin_url?: string;
                                /** @description Content types with public listings/archives. */
                                listing_types?: string[];
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    putV1AdminSettingsGeneral: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "site_name": "example",
                 *       "site_preview_url": "example",
                 *       "default_locale": "example",
                 *       "default_per_page": "example",
                 *       "max_per_page": "example",
                 *       "cache_ttl": "example",
                 *       "scheduler_enabled": true,
                 *       "webhooks_enabled": true,
                 *       "homepage_entry": "example",
                 *       "site_logo": "example",
                 *       "site_logo_dark": "example",
                 *       "site_favicon": "example",
                 *       "theme": "example",
                 *       "admin_url": "example",
                 *       "listing_types": "example"
                 *     }
                 */
                "application/json": {
                    site_name?: string | null;
                    site_preview_url?: string | null;
                    default_locale?: string | null;
                    default_per_page?: number | null;
                    max_per_page?: number | null;
                    cache_ttl?: number | null;
                    scheduler_enabled?: boolean | null;
                    webhooks_enabled?: boolean | null;
                    homepage_entry?: string | null;
                    /** @description Asset uuid of the site logo; '' clears (site name shows instead). */
                    site_logo?: string | null;
                    site_logo_dark?: string | null;
                    site_favicon?: string | null;
                    /** @description Live theme name; '' clears to the env/config default. */
                    theme?: string | null;
                    /** @description Admin SPA base URL for preview-bar deep links; '' clears. */
                    admin_url?: string | null;
                    /** @description Content types with public listings/archives; */
                    listing_types?: string[] | null;
                };
            };
        };
        responses: {
            /** @description Settings saved. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            settings?: {
                                site_name?: string;
                                site_preview_url?: string;
                                default_locale?: string;
                                default_per_page?: number;
                                max_per_page?: number;
                                cache_ttl?: number;
                                scheduler_enabled?: boolean;
                                webhooks_enabled?: boolean;
                                homepage_entry?: string;
                                site_logo?: string;
                                admin_url?: string;
                                /** @description Content types with public listings/archives. */
                                listing_types?: string[];
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid value (non-positive page size, max < default, …). */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminUsers: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "username": "example",
                 *       "email": "user@example.com",
                 *       "password": "example",
                 *       "first_name": "Jane",
                 *       "last_name": "Doe",
                 *       "role_slugs": "example"
                 *     }
                 */
                "application/json": {
                    username: string;
                    /** Format: email */
                    email: string;
                    password: string;
                    first_name?: string | null;
                    last_name?: string | null;
                    role_slugs?: unknown[];
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description User created; returns the new `uuid`. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Authentication required */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Missing the users.create permission */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Validation failed (invalid email/username/password, or username/email already taken). */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminExtensions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Installed extensions. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Authentication required */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Missing the system.access permission */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminExtensionsRegistry: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Catalog results, each with an `installed` flag. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminExtensionsEnable: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Extension enabled. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminExtensionsDisable: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Extension disabled. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminMedia: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Media page. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminApikeys: {
        parameters: {
            query?: {
                /** @description Filter by status (active|expired|revoked). */
                status?: "active" | "expired" | "revoked";
                /** @description Case-insensitive substring filter on the key name. */
                q?: string;
                /** @description Page number (default 1). */
                page?: number;
                /** @description Items per page (default 30, max 100). */
                per_page?: number;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description API key page. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            api_keys?: unknown[];
                            total?: number;
                            current_page?: number;
                            per_page?: number;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid query params. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminApikeys: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "name": "Jane",
                 *       "scopes": "example",
                 *       "allowed_ips": "example",
                 *       "expires_at": "example"
                 *     }
                 */
                "application/json": {
                    name: string;
                    scopes?: unknown[];
                    allowed_ips?: unknown[];
                    expires_at?: string | null;
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Created key + one-time plaintext. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            api_key?: {
                                uuid?: string;
                                name?: string;
                                key_prefix?: string;
                                owner_uuid?: string;
                                owner_label?: string | null;
                                scopes?: unknown[];
                                allowed_ips?: unknown[];
                                status?: string;
                                is_rotated?: boolean;
                                expires_at?: string | null;
                                revoked_at?: string | null;
                                created_at?: string | null;
                            };
                            plain?: string;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Validation failed. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminWebhooksSubscriptions: {
        parameters: {
            query?: {
                /** @description When true, return only active subscriptions. */
                active?: boolean;
                /** @description Page number (default 1). */
                page?: number;
                /** @description Items per page (default 25, max 100). */
                per_page?: number;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Subscriptions page. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            subscriptions?: unknown[];
                            pagination?: {
                                current_page?: number;
                                per_page?: number;
                                total?: number;
                                total_pages?: number;
                            } | null;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminWebhooksSubscriptions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    url?: string;
                    events?: unknown[];
                    metadata?: unknown[] | null;
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Created subscription + signing secret. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            uuid?: string;
                            url?: string;
                            events?: unknown[];
                            is_active?: boolean;
                            metadata?: unknown[] | null;
                            created_at?: string | null;
                            updated_at?: string | null;
                            secret?: string;
                        };
                    };
                };
            };
            /** @description Invalid URL or events. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminWebhooksDeliveries: {
        parameters: {
            query?: {
                /** @description Filter by status (pending|delivered|failed|retrying). */
                status?: string;
                /** @description Filter by subscription UUID. */
                subscription?: string;
                /** @description Page number (default 1). */
                page?: number;
                /** @description Items per page (default 25, max 100). */
                per_page?: number;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Deliveries page. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            deliveries?: unknown[];
                            pagination?: {
                                current_page?: number;
                                per_page?: number;
                                total?: number;
                                total_pages?: number;
                            } | null;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminCapabilities: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Enabled capabilities. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            capabilities?: unknown[];
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminHealth: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Health report. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            health?: {
                                status?: string;
                                version?: string;
                                environment?: string;
                                timestamp?: string;
                                php_version?: string;
                                memory_used?: number;
                                memory_peak?: number;
                                memory_limit?: string;
                                disk_free?: number;
                                disk_total?: number;
                                checks?: unknown[];
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminCache: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Cache status. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            cache?: {
                                driver?: string;
                                prefix?: string;
                                tags_enabled?: boolean;
                                key_count?: number;
                                stats?: unknown[];
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminCacheClear: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "content_type": "example"
                 *     }
                 */
                "application/json": {
                    content_type?: string | null;
                };
            };
        };
        responses: {
            /** @description Cleared; returns fresh status. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            cache?: {
                                driver?: string;
                                prefix?: string;
                                tags_enabled?: boolean;
                                key_count?: number;
                                stats?: unknown[];
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Validation failed */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @example false */
                        success: boolean;
                        message: string;
                        errors: {
                            [key: string]: string[];
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminScheduledtasks: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Scheduled tasks. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            tasks?: unknown[];
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminImportexportUpload: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Stored; returns disk + path. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Missing file, wrong type, or too large. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminCollectionsByName: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The collection and its schema. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminCollectionsByName: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Collection deleted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminCollectionsByNameRows: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Paginated rows. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminCollectionsByNameRows: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Row created. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminCollectionsByNameRows: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Rows deleted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminCollectionsByNameRowsByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The row. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminCollectionsByNameRowsByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Row deleted. */
            204: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    patchV1AdminCollectionsByNameRowsByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Row updated. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminNavigationMenusBySlug: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Menu + tree + lock_version + echoed locale. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown menu. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    putV1AdminNavigationMenusBySlug: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Renamed. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown menu. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminNavigationMenusBySlug: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Deleted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown menu. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminRenderTemplatesByPathVersionsByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                path: string;
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The immutable stored source. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown version for this path. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminRenderTemplatesByPathVersions: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                path: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Versions: {uuid, created_by, created_at, current}. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description This path has never been saved. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminRenderTemplatesByPath: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                path: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Source + origin; filesystem sources are the copy-from-disk start. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown theme, invalid path, or nothing at this path. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    putV1AdminRenderTemplatesByPath: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                path: string;
            };
            cookie?: never;
        };
        /** @description The full Twig source to save as the new current version. */
        requestBody: {
            content: {
                "application/json": {
                    source?: string;
                };
            };
        };
        responses: {
            /** @description Saved and live; response carries the new version uuid. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown theme. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid path/source, or policy violations ({line, message} list). */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminRenderTemplatesByPath: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                path: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Override deactivated. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No active override at this path. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminSeoMetaByEntryuuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                entryUuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The override row, or an empty object when unset. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    putV1AdminSeoMetaByEntryuuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                entryUuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The stored override row after the upsert. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Non-string field, over-length value, or unknown enum value. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminWorkflowEntriesByUuidByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description State row (draft default) + recent history. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminContenttypesBySlug: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The content type. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            content_type?: {
                                id?: number;
                                uuid?: string;
                                slug?: string;
                                name?: string;
                                description?: string | null;
                                cache_ttl?: number | null;
                                public_delivery?: boolean;
                                mount_at_root?: boolean;
                                status?: string;
                                schema?: {
                                    name?: string;
                                    /** @enum {string} */
                                    type?: "string" | "text" | "number" | "boolean" | "datetime" | "enum" | "reference" | "asset" | "json" | "blocks";
                                    required?: boolean | null;
                                    localized?: boolean | null;
                                    filterable?: boolean | null;
                                    filter_type?: string | null;
                                    enum?: string[];
                                    format?: string | null;
                                    reference_type?: string | null;
                                    multiple?: boolean | null;
                                    max_items?: number | null;
                                    reference_slug_field?: string | null;
                                    block_types?: string[];
                                    pattern?: string | null;
                                    min?: number | null;
                                    max?: number | null;
                                }[];
                                schema_version?: number;
                                created_by?: string | null;
                                /** Format: date-time */
                                created_at?: string;
                                /** Format: date-time */
                                updated_at?: string | null;
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No content type with that slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminContenttypesBySlug: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Content type deleted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No content type with that slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    patchV1AdminContenttypesBySlug: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "name": "Jane",
                 *       "description": "A short description.",
                 *       "cache_ttl": "example",
                 *       "public_delivery": true,
                 *       "mount_at_root": true
                 *     }
                 */
                "application/json": {
                    name?: string | null;
                    description?: string | null;
                    cache_ttl?: number | null;
                    public_delivery?: boolean | null;
                    /** @description Whether entries serve at /{slug} instead of /{type}/{slug}. */
                    mount_at_root?: boolean | null;
                };
            };
        };
        responses: {
            /** @description Content type updated. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            content_type?: {
                                id?: number;
                                uuid?: string;
                                slug?: string;
                                name?: string;
                                description?: string | null;
                                cache_ttl?: number | null;
                                public_delivery?: boolean;
                                mount_at_root?: boolean;
                                status?: string;
                                schema?: {
                                    name?: string;
                                    /** @enum {string} */
                                    type?: "string" | "text" | "number" | "boolean" | "datetime" | "enum" | "reference" | "asset" | "json" | "blocks";
                                    required?: boolean | null;
                                    localized?: boolean | null;
                                    filterable?: boolean | null;
                                    filter_type?: string | null;
                                    enum?: string[];
                                    format?: string | null;
                                    reference_type?: string | null;
                                    multiple?: boolean | null;
                                    max_items?: number | null;
                                    reference_slug_field?: string | null;
                                    block_types?: string[];
                                    pattern?: string | null;
                                    min?: number | null;
                                    max?: number | null;
                                }[];
                                schema_version?: number;
                                created_by?: string | null;
                                /** Format: date-time */
                                created_at?: string;
                                /** Format: date-time */
                                updated_at?: string | null;
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No content type with that slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid value (empty name). */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminContenttypesBySlugMigrations: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Schema migrations for the content type. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No content type with that slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminContenttypesBySlugMigrations: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "ops": "example"
                 *     }
                 */
                "application/json": {
                    ops: unknown[];
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Migration started; poll the returned migration row for progress. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No content type with that slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description A migration is already running. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid migration operations. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminContenttypesBySlugMigrationsByMigrationuuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
                migrationUuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The migration row with progress counters and failure report. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such migration. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminBlocktypesBySlug: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The block type with its schema. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            block_type?: {
                                uuid?: string;
                                slug?: string;
                                label?: string;
                                icon?: string | null;
                                category?: string | null;
                                description?: string | null;
                                active?: boolean;
                                schema?: {
                                    name?: string;
                                    /** @enum {string} */
                                    type?: "string" | "text" | "number" | "boolean" | "datetime" | "enum" | "reference" | "asset" | "json" | "blocks";
                                    required?: boolean | null;
                                    localized?: boolean | null;
                                    filterable?: boolean | null;
                                    filter_type?: string | null;
                                    enum?: string[];
                                    format?: string | null;
                                    reference_type?: string | null;
                                    multiple?: boolean | null;
                                    max_items?: number | null;
                                    reference_slug_field?: string | null;
                                    block_types?: string[];
                                    pattern?: string | null;
                                    min?: number | null;
                                    max?: number | null;
                                }[];
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminBlocktypesBySlug: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Block type deleted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description In use by current content, or a migration is active. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    patchV1AdminBlocktypesBySlug: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "label": "example",
                 *       "icon": "example",
                 *       "category": "example",
                 *       "description": "A short description.",
                 *       "schema": "example"
                 *     }
                 */
                "application/json": {
                    label: string;
                    /** @description Lucide icon name shown in the block picker. */
                    icon?: string | null;
                    /** @description Free-form picker grouping ("Layout", "Content", …); presentation only. */
                    category?: string | null;
                    description?: string | null;
                    schema?: {
                        name?: string;
                        type?: string;
                        required?: boolean | null;
                        localized?: boolean | null;
                        filterable?: boolean | null;
                        filter_type?: string | null;
                        enum?: string[];
                        format?: string | null;
                        reference_type?: string | null;
                        multiple?: boolean | null;
                        max_items?: number | null;
                        reference_slug_field?: string | null;
                        /** @description Picker-only block-type allowlist for a `blocks` field. */
                        block_types?: string[];
                        /** @description Anchored regex body a string/text value must fully match. */
                        pattern?: string | null;
                        /** @description Inclusive lower bound for a `number` field (ints coerce). */
                        min?: number | null;
                        /** @description Inclusive upper bound for a `number` field (ints coerce). */
                        max?: number | null;
                    }[];
                };
            };
        };
        responses: {
            /** @description Updated. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            block_type?: {
                                uuid?: string;
                                slug?: string;
                                label?: string;
                                icon?: string | null;
                                category?: string | null;
                                description?: string | null;
                                active?: boolean;
                                schema?: {
                                    name?: string;
                                    /** @enum {string} */
                                    type?: "string" | "text" | "number" | "boolean" | "datetime" | "enum" | "reference" | "asset" | "json" | "blocks";
                                    required?: boolean | null;
                                    localized?: boolean | null;
                                    filterable?: boolean | null;
                                    filter_type?: string | null;
                                    enum?: string[];
                                    format?: string | null;
                                    reference_type?: string | null;
                                    multiple?: boolean | null;
                                    max_items?: number | null;
                                    reference_slug_field?: string | null;
                                    block_types?: string[];
                                    pattern?: string | null;
                                    min?: number | null;
                                    max?: number | null;
                                }[];
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid block schema (no nested blocks/localized/filterable fields). */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminBlocktypesBySlugMigrations: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Schema migrations for the block type. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No block type with that slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminBlocktypesBySlugMigrations: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "ops": "example"
                 *     }
                 */
                "application/json": {
                    ops: unknown[];
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Migration started; poll the returned migration row for progress. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No block type with that slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description A migration is already active. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid migration operations. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminBlocktypesBySlugMigrationsByMigrationuuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
                migrationUuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The migration row with progress counters and failure report. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such migration. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminBlocktypesBySlugUsage: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Usage counts per content type, samples, and allowlist appearances. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminEntriesByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The entry. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            entry?: {
                                id?: number;
                                uuid?: string;
                                content_type_uuid?: string;
                                content_type?: string | null;
                                display_title?: string;
                                /** @enum {string} */
                                status?: "active" | "archived" | "deleted";
                                created_by?: string | null;
                                /** Format: date-time */
                                created_at?: string;
                                /** Format: date-time */
                                updated_at?: string | null;
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No entry with that UUID. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminEntriesByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Entry deleted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No entry with that UUID. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Entry is referenced by published content. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminEntriesByUuidDraftByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The draft. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            draft?: {
                                id?: number;
                                entry_uuid?: string;
                                locale?: string;
                                fields?: Record<string, never>;
                                schema_version?: number;
                                lock_version?: number;
                                updated_by?: string | null;
                                /** Format: date-time */
                                updated_at?: string;
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No draft for that entry/locale. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    putV1AdminEntriesByUuidDraftByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "fields": "example",
                 *       "lock_version": "example"
                 *     }
                 */
                "application/json": {
                    /** @description Draft field values keyed by the content type's field names. */
                    fields?: unknown[];
                    /** @description Optimistic-lock counter echoed from the last read. */
                    lock_version?: number | null;
                };
            };
        };
        responses: {
            /** @description Draft saved. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            draft?: {
                                id?: number;
                                entry_uuid?: string;
                                locale?: string;
                                fields?: Record<string, never>;
                                schema_version?: number;
                                lock_version?: number;
                                updated_by?: string | null;
                                /** Format: date-time */
                                updated_at?: string;
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No entry with that UUID. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Stale lock_version — the draft was modified by another writer. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Field validation failed against the content type schema. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminEntriesByUuidDraftByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Draft discarded. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No draft for that entry/locale. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminEntriesByUuidLocales: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Entry locale variants. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            locales?: {
                                locale?: string;
                                has_draft?: boolean;
                                is_published?: boolean;
                                route_slug?: string | null;
                                /** Format: date-time */
                                draft_updated_at?: string | null;
                                /** Format: date-time */
                                published_at?: string | null;
                                scheduled?: {
                                    publish?: string | null;
                                    unpublish?: string | null;
                                    last_failure?: {
                                        action?: string;
                                        run_at?: string | null;
                                        reason?: string;
                                    } | null;
                                } | null;
                            }[];
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No entry with that UUID. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminLocalesByLocaleUsage: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Published and draft entry counts for the locale. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            published_entries?: number;
                            draft_entries?: number;
                        };
                    };
                };
            };
            /** @description Not authenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Missing content.manage permission. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminEntriesByUuidVersionsByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Entry versions. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminEntriesByUuidRoutes: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Entry routes. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminContenttypesBySlugRedirects: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Redirects retrieved. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown content type slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminContenttypesBySlugRedirects: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "locale": "example",
                 *       "source_slug": "example",
                 *       "target": "example"
                 *     }
                 */
                "application/json": {
                    locale: string;
                    source_slug: string;
                    target: unknown[];
                    status?: number;
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Redirect created. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown content type or target. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Source slug conflicts with a live route. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid status, unsafe target URL, or ambiguous target. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminEntriesByUuidSchedules: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Schedules retrieved. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminExtensionsByVendorByNameReadme: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                vendor: string;
                name: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Rendered README (or found=false when the package ships none). */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Not modified (ETag matched). */
            304: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such installed extension. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminMediaByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Media item. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such media. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminMediaByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Deleted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such media. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    patchV1AdminMediaByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Updated media item. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such media. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminMediaByUuidUsage: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Referencing entries. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminApikeysByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description API key. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            api_key?: {
                                uuid?: string;
                                name?: string;
                                key_prefix?: string;
                                owner_uuid?: string;
                                owner_label?: string | null;
                                scopes?: unknown[];
                                allowed_ips?: unknown[];
                                status?: string;
                                is_rotated?: boolean;
                                expires_at?: string | null;
                                revoked_at?: string | null;
                                created_at?: string | null;
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such key. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminApikeysByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Revoked. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such key. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminWebhooksSubscriptionsById: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Subscription. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            uuid?: string;
                            url?: string;
                            events?: unknown[];
                            is_active?: boolean;
                            metadata?: unknown[] | null;
                            created_at?: string | null;
                            updated_at?: string | null;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such subscription. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminWebhooksSubscriptionsById: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Deleted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such subscription. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    patchV1AdminWebhooksSubscriptionsById: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    url?: string | null;
                    events?: unknown[] | null;
                    is_active?: boolean | null;
                    metadata?: unknown[] | null;
                };
            };
        };
        responses: {
            /** @description Updated subscription. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            uuid?: string;
                            url?: string;
                            events?: unknown[];
                            is_active?: boolean;
                            metadata?: unknown[] | null;
                            created_at?: string | null;
                            updated_at?: string | null;
                        };
                    };
                };
            };
            /** @description Invalid URL or events. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such subscription. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminWebhooksSubscriptionsByIdStats: {
        parameters: {
            query?: {
                /** @description Window in days (default 30). */
                days?: number;
            };
            header?: never;
            path: {
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Delivery statistics. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            uuid?: string;
                            period_days?: number;
                            total_deliveries?: number;
                            delivered?: number;
                            failed?: number;
                            pending?: number;
                            success_rate?: number;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such subscription. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminWebhooksDeliveriesById: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Delivery with payload + response. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            uuid?: string;
                            event?: string;
                            status?: string;
                            attempts?: number;
                            response_code?: number | null;
                            delivered_at?: string | null;
                            next_retry_at?: string | null;
                            created_at?: string | null;
                            payload?: unknown[] | null;
                            response_body?: string | null;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such delivery. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    getV1AdminImportexportJobsByUuidDownload: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The export result file (application/x-ndjson). */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such export job, or no result is available yet. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    putV1AdminNavigationMenusBySlugItems: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The updated editor payload. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown menu. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Stale lock_version. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid tree (kind, url, labels, depth, count, target). */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    putV1AdminEntriesByUuidRoutesByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "slug": "example-slug"
                 *     }
                 */
                "application/json": {
                    slug: string;
                };
            };
        };
        responses: {
            /** @description Route assigned. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No entry with that UUID. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Slug already in use. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Validation failed */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @example false */
                        success: boolean;
                        message: string;
                        errors: {
                            [key: string]: string[];
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminEntriesByUuidRoutesByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Route removed. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    putV1AdminRegionsBySlug: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "blocks": "example",
                 *       "settings": "example"
                 *     }
                 */
                "application/json": {
                    /** @description Ordered {id,type,data} block list. */
                    blocks?: unknown[];
                    /** @description Fixed per-region settings vocabulary. */
                    settings?: unknown[];
                };
            };
        };
        responses: {
            /** @description Region saved. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown region slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Out-of-palette block, schema violation, or unknown setting. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminCollectionsByNameFieldsByField: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
                field: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Field dropped. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminCollectionsByNameIndexesByField: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
                field: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Index removed. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminRedirectsByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Redirect deleted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No redirect with that UUID. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminEntriesByUuidSchedulesByScheduleuuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                scheduleUuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Schedule canceled. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Schedule is not pending. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    deleteV1AdminUsersByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description User deleted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Authentication required */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Missing the users.delete permission */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description No user with that UUID. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description You attempted to delete your own account. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    patchV1AdminUsersByUuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "username": "example",
                 *       "email": "user@example.com",
                 *       "status": "example",
                 *       "first_name": "Jane",
                 *       "last_name": "Doe",
                 *       "role_slugs": "example"
                 *     }
                 */
                "application/json": {
                    username?: string | null;
                    /** Format: email */
                    email?: string | null;
                    status?: string | null;
                    first_name?: string | null;
                    last_name?: string | null;
                    role_slugs?: unknown[] | null;
                };
            };
        };
        responses: {
            /** @description User updated. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Authentication required */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Missing the users.edit permission */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description No user with that UUID. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Validation failed (invalid email/username, or the new username/email is already taken). */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminCollectionsByNameFields: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "name": "Jane",
                 *       "type": "example",
                 *       "settings": "example"
                 *     }
                 */
                "application/json": {
                    name: string;
                    type: string;
                    settings?: unknown[];
                };
            };
        };
        responses: {
            /** @description Field added. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Validation failed */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @example false */
                        success: boolean;
                        message: string;
                        errors: {
                            [key: string]: string[];
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminCollectionsByNameIndexes: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "field": "example",
                 *       "unique": true
                 *     }
                 */
                "application/json": {
                    field: string;
                    unique?: boolean;
                };
            };
        };
        responses: {
            /** @description Index added. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Validation failed */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @example false */
                        success: boolean;
                        message: string;
                        errors: {
                            [key: string]: string[];
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminRenderTemplatesByPathVersionsByUuidRestore: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                path: string;
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Restored; response carries the NEW version uuid. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown version for this path. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description The stored version violates the CURRENT policy ({line, message} list). */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminWorkflowEntriesByUuidByLocaleSubmit: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The review state after the transition. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown entry/locale (no draft). */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Illegal transition from the current state. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminWorkflowEntriesByUuidByLocaleApprove: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The review state after the transition. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Self-review blocked. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Illegal transition from the current state. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminWorkflowEntriesByUuidByLocaleRequestchanges: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The review state after the transition. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Illegal transition from the current state. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description A note is required. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminWorkflowEntriesByUuidByLocaleWithdraw: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The review state after the transition. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Only the submitter or a reviewer may withdraw. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Illegal transition from the current state. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminBlocktypesBySlugActivate: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Active — back in the block picker. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            block_type?: {
                                uuid?: string;
                                slug?: string;
                                label?: string;
                                icon?: string | null;
                                category?: string | null;
                                description?: string | null;
                                active?: boolean;
                                schema?: {
                                    name?: string;
                                    /** @enum {string} */
                                    type?: "string" | "text" | "number" | "boolean" | "datetime" | "enum" | "reference" | "asset" | "json" | "blocks";
                                    required?: boolean | null;
                                    localized?: boolean | null;
                                    filterable?: boolean | null;
                                    filter_type?: string | null;
                                    enum?: string[];
                                    format?: string | null;
                                    reference_type?: string | null;
                                    multiple?: boolean | null;
                                    max_items?: number | null;
                                    reference_slug_field?: string | null;
                                    block_types?: string[];
                                    pattern?: string | null;
                                    min?: number | null;
                                    max?: number | null;
                                }[];
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminBlocktypesBySlugDeactivate: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Inactive — hidden from the picker. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            block_type?: {
                                uuid?: string;
                                slug?: string;
                                label?: string;
                                icon?: string | null;
                                category?: string | null;
                                description?: string | null;
                                active?: boolean;
                                schema?: {
                                    name?: string;
                                    /** @enum {string} */
                                    type?: "string" | "text" | "number" | "boolean" | "datetime" | "enum" | "reference" | "asset" | "json" | "blocks";
                                    required?: boolean | null;
                                    localized?: boolean | null;
                                    filterable?: boolean | null;
                                    filter_type?: string | null;
                                    enum?: string[];
                                    format?: string | null;
                                    reference_type?: string | null;
                                    multiple?: boolean | null;
                                    max_items?: number | null;
                                    reference_slug_field?: string | null;
                                    block_types?: string[];
                                    pattern?: string | null;
                                    min?: number | null;
                                    max?: number | null;
                                }[];
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unknown slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminEntriesByUuidLocalesByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "source_locale": "example",
                 *       "overwrite": true
                 *     }
                 */
                "application/json": {
                    source_locale?: string | null;
                    overwrite?: boolean;
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Locale draft created. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            draft?: {
                                id?: number;
                                entry_uuid?: string;
                                locale?: string;
                                fields?: Record<string, never>;
                                schema_version?: number;
                                lock_version?: number;
                                updated_by?: string | null;
                                /** Format: date-time */
                                updated_at?: string;
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No entry with that UUID. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Draft already exists. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid locale or source locale. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminEntriesByUuidPreviewByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "version_uuid": "example",
                 *       "theme": "example"
                 *     }
                 */
                "application/json": {
                    /** @description UUID of a historical version to pin instead of the current draft. */
                    version_uuid?: string | null;
                    /** @description Per-preview theme name, signed into the token; validated via */
                    theme?: string | null;
                };
            };
        };
        responses: {
            /** @description Preview token minted. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            token?: string;
                            /** Format: date-time */
                            expires_at?: string;
                            expires_in?: number;
                            theme_url?: string | null;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Validation failed */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @example false */
                        success: boolean;
                        message: string;
                        errors: {
                            [key: string]: string[];
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminEntriesByUuidPreviewByLocaleApply: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "token": "example",
                 *       "fields": "example"
                 *     }
                 */
                "application/json": {
                    token: string;
                    /** @description Working field values keyed by the content type's field names. */
                    fields?: unknown[];
                };
            };
        };
        responses: {
            /** @description Working copy applied to the preview session. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid or re-pointed token. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Version-pinned token (PREVIEW_VERSION_PINNED) or active block migration (BLOCK_MIGRATION_IN_PROGRESS). */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description The preview token has expired. */
            410: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Field validation failed. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminEntriesByUuidSchedulesByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "action": "example",
                 *       "run_at": "example"
                 *     }
                 */
                "application/json": {
                    action: string;
                    run_at: string;
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Schedule created or rescheduled. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Entry not found or deleted. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid schedule payload. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminEntriesByUuidPublishByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Entry published. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            version_uuid?: string | null;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No entry/draft to publish. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description A publish gate blocked the publish (e.g. review workflow: not approved). */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Draft fields fail schema validation. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminEntriesByUuidUnpublishByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Entry unpublished. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminEntriesByUuidRollbackByLocale: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                locale: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "version_uuid": "example"
                 *     }
                 */
                "application/json": {
                    /** @description UUID of the version to re-publish. */
                    version_uuid: string;
                };
            };
        };
        responses: {
            /** @description Rolled back — names the version ACTUALLY pinned (a newly materialized one when block migrations re-projected the fields). */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            version_uuid?: string;
                            version?: number;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Missing or invalid version_uuid (or it does not belong to this entry+locale). */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminMediaByUuidOptimize: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Optimized media + before/after sizes. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such media. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Not an image. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminApikeysByUuidRotate: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "grace_hours": "example"
                 *     }
                 */
                "application/json": {
                    grace_hours?: number | null;
                };
            };
        };
        responses: {
            /** @description New key + one-time plaintext + old key expiry. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            api_key?: {
                                uuid?: string;
                                name?: string;
                                key_prefix?: string;
                                owner_uuid?: string;
                                owner_label?: string | null;
                                scopes?: unknown[];
                                allowed_ips?: unknown[];
                                status?: string;
                                is_rotated?: boolean;
                                expires_at?: string | null;
                                revoked_at?: string | null;
                                created_at?: string | null;
                            };
                            plain?: string;
                            old_expires_at?: string;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such key. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Key is revoked. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Validation failed */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @example false */
                        success: boolean;
                        message: string;
                        errors: {
                            [key: string]: string[];
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminWebhooksSubscriptionsByIdRotatesecret: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description New signing secret. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such subscription. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminWebhooksSubscriptionsByIdTest: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Endpoint accepted the test. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such subscription. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Endpoint rejected or failed the test. */
            502: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
        };
    };
    postV1AdminWebhooksDeliveriesByIdRetry: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Delivery re-queued. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Delivery is not in a retryable state. */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such delivery. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    postV1AdminScheduledtasksByNameRun: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Task queued. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            name?: string;
                            job_id?: string;
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such task. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Task has no runnable handler. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    patchV1AdminCollectionsByNameAccess: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "read": "example",
                 *       "write": "example",
                 *       "delete": "example"
                 *     }
                 */
                "application/json": {
                    read?: string | null;
                    write?: string | null;
                    delete?: string | null;
                };
            };
        };
        responses: {
            /** @description Access policy updated. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Validation failed */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @example false */
                        success: boolean;
                        message: string;
                        errors: {
                            [key: string]: string[];
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    patchV1AdminCollectionsByNameFieldorder: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                name: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "field_order": "example"
                 *     }
                 */
                "application/json": {
                    field_order?: unknown[];
                };
            };
        };
        responses: {
            /** @description Field order updated. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Validation failed */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @example false */
                        success: boolean;
                        message: string;
                        errors: {
                            [key: string]: string[];
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    patchV1AdminContenttypesBySlugSchema: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                slug: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "schema": "example"
                 *     }
                 */
                "application/json": {
                    schema: {
                        name?: string;
                        type?: string;
                        required?: boolean | null;
                        localized?: boolean | null;
                        filterable?: boolean | null;
                        filter_type?: string | null;
                        enum?: string[];
                        format?: string | null;
                        reference_type?: string | null;
                        multiple?: boolean | null;
                        max_items?: number | null;
                        reference_slug_field?: string | null;
                        /** @description Picker-only block-type allowlist for a `blocks` field. */
                        block_types?: string[];
                        /** @description Anchored regex body a string/text value must fully match. */
                        pattern?: string | null;
                        /** @description Inclusive lower bound for a `number` field (ints coerce). */
                        min?: number | null;
                        /** @description Inclusive upper bound for a `number` field (ints coerce). */
                        max?: number | null;
                    }[];
                };
            };
        };
        responses: {
            /** @description Schema updated. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            content_type?: {
                                id?: number;
                                uuid?: string;
                                slug?: string;
                                name?: string;
                                description?: string | null;
                                cache_ttl?: number | null;
                                public_delivery?: boolean;
                                mount_at_root?: boolean;
                                status?: string;
                                schema?: {
                                    name?: string;
                                    /** @enum {string} */
                                    type?: "string" | "text" | "number" | "boolean" | "datetime" | "enum" | "reference" | "asset" | "json" | "blocks";
                                    required?: boolean | null;
                                    localized?: boolean | null;
                                    filterable?: boolean | null;
                                    filter_type?: string | null;
                                    enum?: string[];
                                    format?: string | null;
                                    reference_type?: string | null;
                                    multiple?: boolean | null;
                                    max_items?: number | null;
                                    reference_slug_field?: string | null;
                                    block_types?: string[];
                                    pattern?: string | null;
                                    min?: number | null;
                                    max?: number | null;
                                }[];
                                schema_version?: number;
                                created_by?: string | null;
                                /** Format: date-time */
                                created_at?: string;
                                /** Format: date-time */
                                updated_at?: string | null;
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No content type with that slug. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Invalid field schema. */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
    patchV1AdminApikeysByUuidScopes: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "scopes": "example"
                 *     }
                 */
                "application/json": {
                    scopes?: unknown[];
                };
            };
        };
        responses: {
            /** @description Updated key. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            api_key?: {
                                uuid?: string;
                                name?: string;
                                key_prefix?: string;
                                owner_uuid?: string;
                                owner_label?: string | null;
                                scopes?: unknown[];
                                allowed_ips?: unknown[];
                                status?: string;
                                is_rotated?: boolean;
                                expires_at?: string | null;
                                revoked_at?: string | null;
                                created_at?: string | null;
                            };
                        };
                    };
                };
            };
            /** @description Unauthenticated. */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Forbidden. */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description No such key. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
            /** @description Validation failed */
            422: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @example false */
                        success: boolean;
                        message: string;
                        errors: {
                            [key: string]: string[];
                        };
                    };
                };
            };
            /** @description Unexpected server error. */
            500: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
                        message?: string;
                        error?: {
                            code?: number;
                            timestamp?: string;
                            request_id?: string;
                        };
                    };
                };
            };
        };
    };
}
