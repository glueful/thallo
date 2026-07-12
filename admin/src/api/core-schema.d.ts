export interface paths {
    "/rbac/roles": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List all roles
         * @description Retrieves a paginated list of roles with optional filtering, or a hierarchical tree view when `tree=true`. Requires the `roles.view` permission.
         */
        get: operations["getRbacRoles"];
        put?: never;
        /**
         * Create new role
         * @description Creates a role. Body: `name` (required), `slug` (required), `description`, `parent_uuid`, `status`, `metadata`. Requires the `roles.create` permission.
         */
        post: operations["postRbacRoles"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/roles/stats": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get role statistics
         * @description Retrieves aggregate role statistics (totals, active/system counts, by-level breakdown). Requires the `roles.view` permission.
         */
        get: operations["getRbacRolesStats"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/roles/bulk": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Bulk role operations
         * @description Performs a bulk action across multiple roles. Body: `action` (required; one of delete, activate, deactivate), `role_ids` (required), `force`. Requires the `roles.edit` permission.
         */
        post: operations["postRbacRolesBulk"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/permissions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List all permissions
         * @description Retrieves a paginated list of permissions with optional filtering. Requires the `roles.view` permission.
         */
        get: operations["getRbacPermissions"];
        put?: never;
        /**
         * Create new permission
         * @description Creates a permission. Body: `name` (required), `slug` (required), `description`, `category`, `resource_type`, `metadata`. Requires the `system.config` permission.
         */
        post: operations["postRbacPermissions"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/permissions/stats": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get permission statistics
         * @description Retrieves aggregate permission statistics (totals, system count, by-category and by-resource-type breakdowns, direct assignment count). Requires the `roles.view` permission.
         */
        get: operations["getRbacPermissionsStats"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/permissions/cleanup-expired": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Cleanup expired permissions
         * @description Removes all expired permission assignments. Requires the `system.config` permission.
         */
        post: operations["postRbacPermissionsCleanupexpired"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/permissions/categories": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get permission categories
         * @description Retrieves all available permission categories. Requires the `roles.view` permission.
         */
        get: operations["getRbacPermissionsCategories"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/permissions/resource-types": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get resource types
         * @description Retrieves all available resource types. Requires the `roles.view` permission.
         */
        get: operations["getRbacPermissionsResourcetypes"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/permissions/batch-assign": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Batch assign permissions
         * @description Assigns multiple permissions to a user. Body: `user_uuid` (required), `permissions` (required; array of {permission, resource, options}), `options`. Requires the `system.config` permission.
         */
        post: operations["postRbacPermissionsBatchassign"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/permissions/batch-revoke": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Batch revoke permissions
         * @description Revokes multiple permissions from a user. Body: `user_uuid` (required), `permission_slugs` (required). Requires the `system.config` permission.
         */
        post: operations["postRbacPermissionsBatchrevoke"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/check-permission": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Check user permission
         * @description Checks whether a user has a specific permission. Body: `user_uuid` (required), `permission` (required), `resource`, `context`. Requires the `users.view` permission.
         */
        post: operations["postRbacCheckpermission"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/user-roles/stats": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get user-role statistics
         * @description Retrieves statistics about user-role assignments (totals, active/expired counts, users-with-roles count). Requires the `roles.view` permission.
         */
        get: operations["getRbacUserrolesStats"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/user-roles/cleanup-expired": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Cleanup expired role assignments
         * @description Removes all expired role assignments. Requires the `roles.assign` permission.
         */
        post: operations["postRbacUserrolesCleanupexpired"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/audit-logs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List audit log
         * @description Returns the normalized, append-only audit trail filtered + paginated (newest first). Filters (all optional query params): `actor` (actor_uuid), `action`, `category`, `target_type`, `target_uuid`, `from`/`to` (ISO/datetime bounds on occurred_at), `page`, `per_page` (1-100, default 25). Requires the `audit.view` permission.
         */
        get: operations["getAuditlogs"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/email/templates": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** GET /email/templates */
        get: operations["getEmailTemplates"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/email/settings": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** GET /email/settings */
        get: operations["getEmailSettings"];
        /** PUT /email/settings */
        put: operations["putEmailSettings"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/email/settings/test": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** POST /email/settings/test */
        post: operations["postEmailSettingsTest"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/i18n/locales": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List Locales
         * @description Lists all stored locales ordered by code, including disabled ones. Requires the `i18n.view` permission.
         */
        get: operations["i18nLocalesIndex"];
        put?: never;
        /**
         * Create Locale
         * @description Creates a stored locale. The first stored locale is forced to enabled/default. Setting `is_default` clears the previous default. Missing/malformed fields, a duplicate `code`, or a `fallback_locale` that would create a fallback cycle are rejected with 422. Body: `code` (required), `name` (required), `native_name`, `enabled`, `is_default`, `fallback_locale`, `direction` (ltr|rtl), `region`. Requires the `i18n.manage` permission.
         */
        post: operations["i18nLocalesStore"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/i18n/translations": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List Translations
         * @description Lists persisted translations ordered by key, optionally filtered by locale and domain. Requires the `i18n.view` permission.
         */
        get: operations["i18nTranslationsIndex"];
        put?: never;
        /**
         * Create or Update Translation
         * @description Upserts a translation on its `(domain, locale, key)` identity: an existing row is updated (and reactivated) in place, otherwise a new row is inserted. Body: `key` (required), `value` (required; max 65,535 bytes, may contain {param} placeholders), `domain` (default: messages), `locale` (default: en). Requires the `i18n.manage` permission.
         */
        post: operations["i18nTranslationsStore"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/i18n/missing": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List Missing Translations
         * @description Lists recorded missing translation keys with hit counts, most recently seen first. Rows only accumulate while `i18n.missing_tracking` is enabled. Requires the `i18n.view` permission.
         */
        get: operations["i18nMissingIndex"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/i18n/import": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Import Translation Catalog
         * @description Imports an inline JSON catalog and upserts each row into the translation store. The `catalog` value is either a list of rows or an object with a `translations` list; each row carries `domain`, `locale`, `key`, and `value` (max 65,535 bytes). Server-side file imports are CLI-only. Body: `catalog` (required; catalog object or array of translation rows). Requires the `i18n.import` permission.
         */
        post: operations["i18nImport"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/i18n/export": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Export Translation Catalog
         * @description Exports persisted translations as a JSON catalog, optionally filtered by locale and domain. Requires the `i18n.export` permission.
         */
        get: operations["i18nExport"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/import-export/adapters": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List Import/Export Adapters
         * @description Lists the importer and exporter adapters registered through the `import_export.importer` and `import_export.exporter` service tags, with their keys and labels. Requires the `import_export.view` permission.
         */
        get: operations["importExportAdaptersIndex"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/import-export/imports": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Queue Import Job
         * @description Creates an import job for a registered importer adapter, plans deterministic batches, and queues one batch job per batch. Defaults to `dry_run` mode; pass `mode=commit` to write. Body: `adapter` (required; importer adapter key, see GET /import-export/adapters), `path` (required; relative source file path under the configured source disk root), `disk` (source storage disk, default: uploads), `mime_type` (optional source MIME type hint), `metadata` (optional source metadata passed to the adapter's supports()/plan(); size_bytes is ignored), `mode` (import mode: dry_run|commit, default: dry_run), `batch_size` (requested records per batch, default: 500; the adapter's plan decides), `options` (adapter-specific options, available to the adapter during plan()). Requires the `import_export.run_import` permission.
         */
        post: operations["importExportImportsStore"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/import-export/exports": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Queue Export Job
         * @description Creates an export job for a registered exporter adapter, plans deterministic batches, and queues one batch job per batch. Exports always run in commit mode. Body: `adapter` (required; exporter adapter key, see GET /import-export/adapters), `format` (requested output format, default: ndjson; interpreted by the adapter's plan), `batch_size` (requested records per batch, default: 500; the adapter's plan decides), `filters` (adapter-specific record filters, available to the adapter during plan()), `options` (adapter-specific options, available to the adapter during plan()). Requires the `import_export.run_export` permission.
         */
        post: operations["importExportExportsStore"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/import-export/jobs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List Import/Export Jobs
         * @description Lists the caller's import/export jobs, newest first, optionally filtered by type and status. Users with `import_export.manage_all` can see all jobs. Requires the `import_export.view` permission.
         */
        get: operations["importExportJobsIndex"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/search": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Universal search
         * @description Performs a search query across an explicitly allowlisted index. The route requires the `meilisearch.search` permission, applies the configured server-side scope filter, and only accepts configured safe search parameters.
         */
        get: operations["getApiSearch"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/search/admin/status": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get index status
         * @description Retrieves status information for all Meilisearch indexes including primary keys, creation dates, and update timestamps. Requires admin privileges.
         */
        get: operations["getApiSearchAdminStatus"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/auth/verify-email": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Verify Email
         * @description Sends a verification code to the provided email. Body: `email` (required).
         */
        post: operations["postV1AuthVerifyemail"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/auth/verify-otp": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Verify OTP
         * @description Verifies the one-time password (OTP) sent to a user's email. When purpose=password_reset, returns a short-lived reset_token to submit to POST /auth/reset-password. Body: `email` (required), `otp` (required), `purpose` (optional; use password_reset for the reset flow).
         */
        post: operations["postV1AuthVerifyotp"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/auth/resend-otp": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Resend OTP
         * @description Resends the one-time password (OTP) to the user's email. Body: `email` (required).
         */
        post: operations["postV1AuthResendotp"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/auth/forgot-password": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Forgot Password
         * @description Initiates the password reset process by sending a reset code. Body: `email` (required).
         */
        post: operations["postV1AuthForgotpassword"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/auth/reset-password": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Reset Password
         * @description Resets the user's password using the single-use reset_token returned by POST /auth/verify-otp with purpose=password_reset. Body: `reset_token` (required), `password` (required).
         */
        post: operations["postV1AuthResetpassword"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/2fa/enable": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Enable Two-Factor Authentication
         * @description Begins 2FA enrollment for the authenticated user: emails a 6-digit PIN and returns a short-lived challenge_token. Submit both to POST /2fa/verify to complete enrollment.
         */
        post: operations["2faEnable"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/2fa/verify": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Verify Two-Factor Code
         * @description Verifies the emailed PIN against a challenge_token. No auth header is required — the challenge_token authenticates the request. For a login challenge it completes login and returns the full session (identical to POST /auth/login); for an enrollment challenge it returns just {success, message}. Body: `challenge_token` (required), `code` (required, 6-digit PIN).
         */
        post: operations["2faVerify"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/2fa/disable": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Disable Two-Factor Authentication
         * @description Disables 2FA for the authenticated user. Requires a recent 2FA verification on the current session (within the configured freshness window); otherwise re-elevation is required.
         */
        post: operations["2faDisable"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/me": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get Current User
         * @description Returns the authenticated principal's account plus a nested `profile` object. Supports REST dot-path field selection via `?fields=` (e.g. `?fields=id,email`, `?fields=email,profile.first_name`); unknown/disallowed fields are pruned. Exposable columns are config-driven (`config/users.php`, `me` audience); `password`/`deleted_at` are never exposed.
         */
        get: operations["usersMe"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/users": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List Users
         * @description Paginated list of users + nested public profile (the `users` audience). Off by default; enabled via `USERS_USER_LIST_ENABLED=true`. Requires the `users.view` permission. Supports `?page`/`?per_page` (clamped), per-item `?fields=`, and `?filter[...]`/`?sort`/`?search` over username + profile name (email only when `allow_email_filter`). Soft-deleted profiles never affect membership or order.
         */
        get: operations["usersIndex"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/_preview/exit": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** GET /_preview/exit */
        get: operations["getPreviewExit"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/sitemap.xml": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** sitemap.xml (adaptive urlset/index) */
        get: operations["getSitemapxml"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/robots.txt": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** robots.txt */
        get: operations["getRobotstxt"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/admin/config": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Admin SPA runtime config
         * @description Unauthenticated bootstrap config the admin SPA fetches at startup: `apiBase`, `sitePreviewUrl`, `defaultLocale`, and whether first-run setup has completed (`installed`). A plain JSON document (no `data` envelope) so one compiled bundle works across installs.
         */
        get: operations["getAdminConfig"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/admin/setup": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * First-run web setup
         * @description Unauthenticated, self-locking first-run setup: creates the first admin and writes site settings. Returns 409 forever once the instance is installed — a second "first" admin can never be created.
         */
        post: operations["postAdminSetup"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/_forms/submit": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** POST /_forms/submit */
        post: operations["postFormsSubmit"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/auth/login": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * User Login
         * @description Authenticates a user with username/email and password
         */
        post: operations["postV1AuthLogin"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/auth/validate-token": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Validate Token
         * @description Validates the current authentication token
         */
        post: operations["postV1AuthValidatetoken"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/auth/refresh-token": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Refresh Token
         * @description Generates new access token using a valid refresh token
         */
        post: operations["postV1AuthRefreshtoken"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/auth/logout": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * User Logout
         * @description Invalidates the current authentication token
         */
        post: operations["postV1AuthLogout"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/auth/refresh-permissions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Refresh User Permissions
         * @description Updates the session with fresh user permissions and returns a new token
         */
        post: operations["postV1AuthRefreshpermissions"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/blobs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Upload File
         * @description Upload a file via multipart form data or base64 encoding.
         */
        post: operations["postV1Blobs"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/extensions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** GET /v1/extensions */
        get: operations["getV1Extensions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/extensions/catalog": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** GET /v1/extensions/catalog */
        get: operations["getV1ExtensionsCatalog"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/extensions/install": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** POST /v1/extensions/install */
        post: operations["postV1ExtensionsInstall"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/extensions/enable": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** POST /v1/extensions/enable */
        post: operations["postV1ExtensionsEnable"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/extensions/disable": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** POST /v1/extensions/disable */
        post: operations["postV1ExtensionsDisable"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/roles/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get role details
         * @description Retrieves a role with its hierarchy chain, child roles and assigned-user count. Requires the `roles.view` permission.
         */
        get: operations["getRbacRolesByUuid"];
        /**
         * Update role
         * @description Updates a role. Body: `name`, `description`, `parent_uuid`, `status`, `metadata`. Requires the `roles.edit` permission.
         */
        put: operations["putRbacRolesByUuid"];
        post?: never;
        /**
         * Delete role
         * @description Deletes a role, optionally forcing deletion when it has dependencies. Requires the `roles.delete` permission.
         */
        delete: operations["deleteRbacRolesByUuid"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/roles/{uuid}/users": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get users with role
         * @description Retrieves a paginated list of users assigned to the role. Requires the `roles.view` permission.
         */
        get: operations["getRbacRolesByUuidUsers"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/roles/{uuid}/permissions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List role permissions
         * @description Retrieves the permission grants for a role. Requires the `roles.view` permission.
         */
        get: operations["getRbacRolesByUuidPermissions"];
        /**
         * Replace role permissions
         * @description Replaces the role's permissions with exactly the supplied set (grants the missing ones, revokes the rest). Body: `permission_uuids` (required array; empty clears all). Requires the `roles.edit` permission.
         */
        put: operations["putRbacRolesByUuidPermissions"];
        /**
         * Assign permissions to role
         * @description Grants one or more permissions to a role without removing existing grants. Body: `permission_uuids` (required array). Requires the `roles.edit` permission.
         */
        post: operations["postRbacRolesByUuidPermissions"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/permissions/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get permission details
         * @description Retrieves a permission with its assigned-user count. Requires the `roles.view` permission.
         */
        get: operations["getRbacPermissionsByUuid"];
        /**
         * Update permission
         * @description Updates a permission. Body: `name`, `description`, `category`, `metadata`. Requires the `system.config` permission.
         */
        put: operations["putRbacPermissionsByUuid"];
        post?: never;
        /**
         * Delete permission
         * @description Deletes a permission, optionally forcing deletion when still assigned. Requires the `system.config` permission.
         */
        delete: operations["deleteRbacPermissionsByUuid"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/users/{user_uuid}/roles": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get user roles
         * @description Retrieves all roles assigned to a specific user. Requires the `users.view` permission.
         */
        get: operations["getRbacUsersByUseruuidRoles"];
        /**
         * Replace user roles
         * @description Replaces all of a user's roles with the specified set. Body: `role_uuids` (required), `scope`, `expires_at`, `assigned_by`. Requires the `roles.assign` permission.
         */
        put: operations["putRbacUsersByUseruuidRoles"];
        /**
         * Assign roles to user
         * @description Assigns multiple roles to a user. Body: `role_uuids` (required), `scope`, `expires_at`, `assigned_by`. Requires the `roles.assign` permission.
         */
        post: operations["postRbacUsersByUseruuidRoles"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/users/{user_uuid}/permissions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get user direct permissions
         * @description Retrieves all permissions directly assigned to a user (not from roles). Requires the `users.view` permission.
         */
        get: operations["getRbacUsersByUseruuidPermissions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/users/{user_uuid}/effective-permissions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get user effective permissions
         * @description Retrieves all effective permissions for a user (direct + role-based). Requires the `users.view` permission.
         */
        get: operations["getRbacUsersByUseruuidEffectivepermissions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/users/{user_uuid}/access-overview": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get user access overview
         * @description Retrieves a complete access overview for a user (roles + direct and effective permissions). Requires the `users.view` permission.
         */
        get: operations["getRbacUsersByUseruuidAccessoverview"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/users/{user_uuid}/role-history": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get user role history
         * @description Retrieves a paginated role-assignment history for a user. Requires the `users.view` permission.
         */
        get: operations["getRbacUsersByUseruuidRolehistory"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/audit-logs/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get audit log entry
         * @description Returns a single audit row by its uuid. Requires the `audit.view` permission.
         */
        get: operations["getAuditlogsByUuid"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/import-export/jobs/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Show Import/Export Job
         * @description Retrieves one caller-owned job with its progress counters, links, and all of its batches. Users with `import_export.manage_all` can retrieve any job. Requires the `import_export.view` permission.
         */
        get: operations["importExportJobsShow"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/import-export/jobs/{uuid}/errors": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List Import/Export Job Errors
         * @description Retrieves the stored row errors for one caller-owned job. Errors are capped per severity; once the cap is reached, further errors only increment the job's `error_overflow_count`. Users with `import_export.manage_all` can retrieve errors for any job. Requires the `import_export.view` permission.
         */
        get: operations["importExportJobsErrors"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/import-export/jobs/{uuid}/report": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Show Import/Export Job Report
         * @description Returns the latest stored report for a caller-owned job, or builds one on demand from the current job state (type, adapter, status, totals, failed and overflow counts). Users with `import_export.manage_all` can retrieve reports for any job. Requires the `import_export.view` permission.
         */
        get: operations["importExportJobsReport"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/api/search/{index}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Search specific index
         * @description Performs a search query on a specific allowlisted index. The route requires the `meilisearch.search` permission, applies the configured server-side scope filter, and only accepts configured safe search parameters.
         */
        get: operations["getApiSearchByIndex"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/users/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get User by UUID
         * @description Returns another user's account plus their public `profile`. Off by default — enabled via `USERS_USER_LOOKUP_ENABLED=true` (or `config/users.php`) — and requires the `users.view` permission. Supports REST dot-path field selection via `?fields=`; unknown/disallowed fields are pruned. Exposable columns are config-driven (`config/users.php`, `users` audience), which is intentionally narrower than the `me` audience.
         */
        get: operations["usersShow"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/collections/{name}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** List rows in a collection */
        get: operations["getV1CollectionsByName"];
        put?: never;
        /** Create a row */
        post: operations["postV1CollectionsByName"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/collections/{name}/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Get a row by UUID */
        get: operations["getV1CollectionsByNameByUuid"];
        put?: never;
        post?: never;
        /** Delete a row */
        delete: operations["deleteV1CollectionsByNameByUuid"];
        options?: never;
        head?: never;
        /** Update a row */
        patch: operations["patchV1CollectionsByNameByUuid"];
        trace?: never;
    };
    "/v1/menus/{slug}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Resolved navigation menu
         * @description Published-only tree: entry items resolve to live public paths; items whose target is not published are omitted with their subtree. Labels follow the locale fallback chain (requested → default → any).
         */
        get: operations["getV1MenusBySlug"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/_preview-assets/{token}/{path}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** GET /_preview-assets/{token}/{path} */
        get: operations["getPreviewassetsByTokenByPath"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/seo/meta/{type}/{slug}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Resolved SEO meta for a published entry
         * @description Resolution per field: per-entry override → per-type fallback field → site default. Canonical/hreflang are intentionally absent — they live on the core delivery `seo` object.
         */
        get: operations["getV1SeoMetaByTypeBySlug"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/sitemap/{n}.xml": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** sitemap page file */
        get: operations["getSitemapNxml"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/content/{type}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * List published entries of a content type
         * @description Published entries only. Cursor pagination by default; `page`/`perPage` switches to offset. Filter and sort are accepted only on filterable fields.
         */
        get: operations["getV1ContentByType"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/content/{type}/facets": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** GET /v1/content/{type}/facets */
        get: operations["getV1ContentByTypeFacets"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/content/{type}/{slugOrUuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Get a single published entry by slug or UUID
         * @description Resolved by route slug or 12-char entry UUID; published only (draft/unpublished → 404). Supports `If-None-Match` → 304.
         */
        get: operations["getV1ContentByTypeBySlugoruuid"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/content/{type}/archive/{field}/{term}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** GET /v1/content/{type}/archive/{field}/{term} */
        get: operations["getV1ContentByTypeArchiveByFieldByTerm"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/preview/{token}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Read a draft via a signed preview token
         * @description Unauthenticated — the token in the path is the only credential, and this is the only way to read unpublished content. Returns the draft, or the version the token pins.
         */
        get: operations["getV1PreviewByToken"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/blobs/{uuid}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Retrieve Blob
         * @description Retrieve blob file content with optional image resizing.
         */
        get: operations["getV1BlobsByUuid"];
        put?: never;
        post?: never;
        /**
         * Delete Blob
         * @description Soft-delete a blob and remove its underlying file from storage
         */
        delete: operations["deleteV1BlobsByUuid"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/blobs/{uuid}/info": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Blob Metadata
         * @description Retrieve blob metadata without downloading the file content
         */
        get: operations["getV1BlobsByUuidInfo"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/email/templates/{key}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /** PUT /email/templates/{key} */
        put: operations["putEmailTemplatesByKey"];
        post?: never;
        /** DELETE /email/templates/{key} */
        delete: operations["deleteEmailTemplatesByKey"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/roles/{uuid}/revoke": {
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
         * Revoke role from user
         * @description Revokes the role from a user. Body: `user_uuid` (required). Requires the `roles.assign` permission.
         */
        delete: operations["deleteRbacRolesByUuidRevoke"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/roles/{role_uuid}/revoke-users": {
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
         * Bulk revoke role from users
         * @description Revokes a role from multiple users. Body: `user_uuids` (required). Requires the `roles.assign` permission.
         */
        delete: operations["deleteRbacRolesByRoleuuidRevokeusers"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/roles/{uuid}/permissions/{permission_uuid}": {
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
         * Revoke permission from role
         * @description Removes a single permission grant from a role. Requires the `roles.edit` permission.
         */
        delete: operations["deleteRbacRolesByUuidPermissionsByPermissionuuid"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/permissions/{uuid}/revoke": {
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
         * Revoke permission from user
         * @description Revokes the permission from a user. Body: `user_uuid` (required). Requires the `system.config` permission.
         */
        delete: operations["deleteRbacPermissionsByUuidRevoke"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/users/{user_uuid}/roles/{role_uuid}": {
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
         * Revoke specific role from user
         * @description Revokes a specific role from a user. Requires the `roles.assign` permission.
         */
        delete: operations["deleteRbacUsersByUseruuidRolesByRoleuuid"];
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/roles/{uuid}/assign": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Assign role to user
         * @description Assigns the role to a user. Body: `user_uuid` (required), `scope`, `expires_at`, `assigned_by`. Requires the `roles.assign` permission.
         */
        post: operations["postRbacRolesByUuidAssign"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/roles/{role_uuid}/assign-users": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Bulk assign role to users
         * @description Assigns a role to multiple users. Body: `user_uuids` (required), `scope`, `expires_at`, `assigned_by`. Requires the `roles.assign` permission.
         */
        post: operations["postRbacRolesByRoleuuidAssignusers"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/permissions/{uuid}/assign": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Assign permission to user
         * @description Assigns the permission directly to a user. Body: `user_uuid` (required), `resource`, `expires_at`, `constraints`, `granted_by`. Requires the `system.config` permission.
         */
        post: operations["postRbacPermissionsByUuidAssign"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/rbac/users/{user_uuid}/check-role": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Check if user has role
         * @description Checks whether a user has a specific role. Body: `role_slug` (required), `scope`. Requires the `users.view` permission.
         */
        post: operations["postRbacUsersByUseruuidCheckrole"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/email/templates/{key}/test": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** POST /email/templates/{key}/test */
        post: operations["postEmailTemplatesByKeyTest"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/import-export/jobs/{uuid}/cancel": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Cancel Import/Export Job
         * @description Cancels a caller-owned pending, planning, queued, or running job and dispatches ImportExportJobCancelled. Batches that have not been claimed yet observe the cancellation and exit; an in-flight batch finishes its current run. Users with `import_export.manage_all` can cancel any job. Requires the `import_export.cancel` permission.
         */
        post: operations["importExportJobsCancel"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/import-export/jobs/{uuid}/retry": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Retry Import/Export Job
         * @description Re-queues the failed batches of a caller-owned job whose adapter implements RetryableAdapterInterface and reports retryable() === true. Each failed batch is reset to pending and re-delivered in full, so retryable adapters must apply records idempotently (upsert by a stable source key). Users with `import_export.manage_all` can retry any job. Requires the `import_export.retry` permission.
         */
        post: operations["importExportJobsRetry"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/import-export/jobs/{uuid}/failed-records/export": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Export Failed Records
         * @description Writes the stored failed-record errors for a caller-owned job to a managed private ndjson or csv file. Users with `import_export.manage_all` can export failures for any job. Body: `format` (output format: ndjson|csv, default: ndjson). Requires the `import_export.export_failed_records` permission.
         */
        post: operations["importExportJobsFailedRecordsExport"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/collections/{name}/bulk": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Bulk-create rows (all-or-nothing) */
        post: operations["postV1CollectionsByNameBulk"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/v1/blobs/{uuid}/signed-url": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Generate Signed URL
         * @description Generate a temporary signed URL for accessing a private blob.
         */
        post: operations["postV1BlobsByUuidSignedurl"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/i18n/locales/{code}": {
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
         * Update Locale
         * @description Partially updates a stored locale by code. All body fields are optional; `fallback_locale` is cycle-checked, `is_default: true` clears the previous default, and the only stored default locale cannot be cleared or disabled. Body: `name`, `native_name`, `enabled`, `is_default`, `fallback_locale`, `direction` (ltr|rtl), `region`. Requires the `i18n.manage` permission.
         */
        patch: operations["i18nLocalesUpdate"];
        trace?: never;
    };
    "/i18n/translations/{uuid}": {
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
         * Update Translation Value
         * @description Updates the value of one persisted translation by UUID. Body: `value` (required; new translated message, max 65,535 bytes). Requires the `i18n.manage` permission.
         */
        patch: operations["i18nTranslationsUpdate"];
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
    getRbacRoles: {
        parameters: {
            query?: {
                /** @description Page number for pagination (default: 1) */
                page?: number;
                /** @description Number of items per page (default: 25) */
                per_page?: number;
                /** @description Search term for role name or slug */
                search?: string;
                /** @description Filter by role status */
                status?: "active" | "inactive";
                /** @description Filter by role hierarchy level */
                level?: number;
                /** @description Return roles as a hierarchical tree structure */
                tree?: boolean;
                /** @description Include soft-deleted roles */
                include_deleted?: boolean;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Roles retrieved successfully */
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
            /** @description Permission denied */
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
    postRbacRoles: {
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
                 *       "slug": "example-slug",
                 *       "description": "A short description.",
                 *       "parent_uuid": "example",
                 *       "level": "example",
                 *       "status": "example",
                 *       "metadata": "example"
                 *     }
                 */
                "application/json": {
                    name: string;
                    slug: string;
                    description?: string | null;
                    parent_uuid?: string | null;
                    level?: number | null;
                    status?: string | null;
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
            /** @description Role created successfully */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format or validation errors */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role name or slug already exists */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    getRbacRolesStats: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Role statistics retrieved successfully */
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
            /** @description Permission denied */
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
    postRbacRolesBulk: {
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
                 *       "action": "example",
                 *       "role_ids": "example",
                 *       "force": true
                 *     }
                 */
                "application/json": {
                    action: string;
                    role_ids: string[];
                    force?: boolean;
                };
            };
        };
        responses: {
            /** @description Bulk operation completed */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    getRbacPermissions: {
        parameters: {
            query?: {
                /** @description Page number for pagination (default: 1) */
                page?: number;
                /** @description Number of items per page (default: 25) */
                per_page?: number;
                /** @description Search term for permission name or slug */
                search?: string;
                /** @description Filter by permission category */
                category?: string;
                /** @description Filter by resource type */
                resource_type?: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Permissions retrieved successfully */
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
            /** @description Permission denied */
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
    postRbacPermissions: {
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
                 *       "slug": "example-slug",
                 *       "description": "A short description.",
                 *       "category": "example",
                 *       "resource_type": "example",
                 *       "metadata": "example"
                 *     }
                 */
                "application/json": {
                    name: string;
                    slug: string;
                    description?: string | null;
                    category?: string | null;
                    resource_type?: string | null;
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
            /** @description Permission created successfully */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Permission name or slug already exists */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    getRbacPermissionsStats: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Permission statistics retrieved successfully */
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
            /** @description Permission denied */
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
    postRbacPermissionsCleanupexpired: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Expired permissions cleaned up */
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
            /** @description Permission denied */
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
    getRbacPermissionsCategories: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Permission categories retrieved successfully */
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
            /** @description Permission denied */
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
    getRbacPermissionsResourcetypes: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Resource types retrieved successfully */
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
            /** @description Permission denied */
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
    postRbacPermissionsBatchassign: {
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
                 *       "user_uuid": "example",
                 *       "permissions": "example",
                 *       "options": "example"
                 *     }
                 */
                "application/json": {
                    user_uuid: string;
                    permissions: unknown[];
                    options?: unknown[];
                };
            };
        };
        responses: {
            /** @description Batch permission assignment completed */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    postRbacPermissionsBatchrevoke: {
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
                 *       "user_uuid": "example",
                 *       "permission_slugs": "example"
                 *     }
                 */
                "application/json": {
                    user_uuid: string;
                    permission_slugs: string[];
                };
            };
        };
        responses: {
            /** @description Batch permission revocation completed */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    postRbacCheckpermission: {
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
                 *       "user_uuid": "example",
                 *       "permission": "example",
                 *       "resource": "example",
                 *       "context": "example"
                 *     }
                 */
                "application/json": {
                    user_uuid: string;
                    permission: string;
                    resource?: string;
                    context?: unknown[];
                };
            };
        };
        responses: {
            /** @description Permission check completed */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    getRbacUserrolesStats: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description User-role statistics retrieved successfully */
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
            /** @description Permission denied */
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
    postRbacUserrolesCleanupexpired: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Expired role assignments cleaned up */
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
            /** @description Permission denied */
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
    getAuditlogs: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Audit log retrieved */
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
                            occurred_at?: string;
                            actor_uuid?: string | null;
                            actor_label?: string | null;
                            action?: string;
                            category?: string;
                            target_type?: string | null;
                            target_uuid?: string | null;
                            target_label?: string | null;
                            changes?: unknown[] | null;
                            context?: unknown[] | null;
                            created_at?: string | null;
                        }[];
                    };
                };
            };
            /** @description Not authenticated */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Missing audit.view permission */
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
    getEmailTemplates: {
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
    getEmailSettings: {
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
    putEmailSettings: {
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
    postEmailSettingsTest: {
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
    i18nLocalesIndex: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Locales retrieved */
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
            /** @description Forbidden */
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
    i18nLocalesStore: {
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
            /** @description Locale created */
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
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Validation failed (missing/malformed fields, duplicate code, or fallback cycle) */
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
    i18nTranslationsIndex: {
        parameters: {
            query?: {
                /** @description Filter by locale code */
                locale?: string;
                /** @description Filter by translation domain */
                domain?: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Translations retrieved */
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
            /** @description Forbidden */
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
    i18nTranslationsStore: {
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
            /** @description Translation saved */
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
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Validation failed (missing/oversized key/value or malformed locale) */
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
    i18nMissingIndex: {
        parameters: {
            query?: {
                /** @description Filter by locale code */
                locale?: string;
                /** @description Filter by translation domain */
                domain?: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Missing translations retrieved */
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
            /** @description Forbidden */
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
    i18nImport: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Catalog imported (returns imported row count) */
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
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Validation failed (missing or malformed catalog payload) */
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
    i18nExport: {
        parameters: {
            query?: {
                /** @description Filter by locale code */
                locale?: string;
                /** @description Filter by translation domain */
                domain?: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Catalog exported */
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
            /** @description Forbidden */
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
    importExportAdaptersIndex: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Adapters retrieved */
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
            /** @description Permission denied (import_export.view) */
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
    importExportImportsStore: {
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
            /** @description Import job queued */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unknown adapter or source not supported by the adapter */
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
            /** @description Permission denied (import_export.run_import) */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Validation failed (missing adapter or path) */
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
    importExportExportsStore: {
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
            /** @description Export job queued */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unknown adapter */
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
            /** @description Permission denied (import_export.run_export) */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Validation failed (missing adapter) */
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
    importExportJobsIndex: {
        parameters: {
            query?: {
                /** @description Filter by job type */
                type?: "import" | "export";
                /** @description Filter by status */
                status?: "pending" | "planning" | "queued" | "running" | "completed" | "failed" | "cancelled";
                /** @description Maximum jobs to return, 1-200 (default: 50) */
                limit?: number;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Jobs retrieved */
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
            /** @description Permission denied (import_export.view) */
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
    getApiSearch: {
        parameters: {
            query: {
                /** @description Index name to search (without prefix) */
                index: string;
                /** @description Search query string (empty string returns all documents) */
                q?: string;
                /** @description Filter expression using Meilisearch syntax; combined with the server-side scope filter */
                filter?: string;
                /** @description Attributes to get facet distribution for */
                facets?: string;
                /** @description Attributes to sort by (format: attribute:direction) */
                sort?: string;
                /** @description Maximum number of results to return (default: 20) */
                limit?: number;
                /** @description Number of results to skip for pagination */
                offset?: number;
                /** @description Configured retrievable attributes to include in results */
                attributesToRetrieve?: string;
                /** @description Attributes to highlight matches in */
                attributesToHighlight?: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Search results retrieved successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Missing index parameter */
            400: {
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
            /** @description Search permission or scope required */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Index not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    getApiSearchAdminStatus: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Index status retrieved successfully */
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
            /** @description Admin privileges required */
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
    postV1AuthVerifyemail: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Verification code has been sent to your email */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            email?: string;
                            expires_in?: number;
                        };
                    };
                };
            };
            /** @description Invalid email address */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Email not found */
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
    postV1AuthVerifyotp: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description OTP verified successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid OTP */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description OTP expired */
            401: {
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
    postV1AuthResendotp: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description OTP resent successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            email?: string;
                            expires_in?: number;
                        };
                    };
                };
            };
            /** @description Invalid email address */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Email not found */
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
    postV1AuthForgotpassword: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Password reset instructions sent to email */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            email?: string;
                            expires_in?: number;
                        };
                    };
                };
            };
            /** @description Invalid email format */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Email not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    postV1AuthResetpassword: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Password has been reset successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid password format */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Email not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    "2faEnable": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Two-factor code sent */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            challenge_token?: string;
                            expires_in?: number;
                            delivered_to?: string;
                        };
                    };
                };
            };
            /** @description Authentication required */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
            /** @description Too many requests */
            429: {
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
    "2faVerify": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Verification successful */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid or expired verification */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Too many requests */
            429: {
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
    "2faDisable": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Two-factor authentication disabled */
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
            /** @description Recent two-factor verification is required to perform this action */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Too many requests */
            429: {
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
    usersMe: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Current user account and profile */
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
            /** @description User not found */
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
    usersIndex: {
        parameters: {
            query?: {
                /** @description Page number for pagination (default: 1) */
                page?: number;
                /** @description Items per page (clamped to configured max) */
                per_page?: number;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Paginated users */
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
            /** @description Missing the users.view permission */
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
    getPreviewExit: {
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
    getSitemapxml: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Sitemap XML. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/xml": string;
                };
            };
            /** @description No public_url_base configured. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "text/plain": string;
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
    getRobotstxt: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description robots.txt content. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "text/plain": string;
                };
            };
            /** @description No public_url_base configured. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "text/plain": string;
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
    getAdminConfig: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Runtime config: apiBase, sitePreviewUrl, defaultLocale, installed. */
            200: {
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
    postAdminSetup: {
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
                 *       "site_name": "example",
                 *       "admin_email": "user@example.com",
                 *       "admin_password": "example",
                 *       "locale": "example",
                 *       "admin_url": "example"
                 *     }
                 */
                "application/json": {
                    site_name: string;
                    /** Format: email */
                    admin_email: string;
                    admin_password: string;
                    locale: string;
                    /** @description The admin SPA's own origin — sent by the web setup form. */
                    admin_url?: string | null;
                };
            };
        };
        responses: {
            /** @description Setup complete; the first admin was created. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Already installed — setup is permanently locked. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid setup payload (site name, admin email/password, locale). */
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
    postFormsSubmit: {
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
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    postV1AuthLogin: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    username?: string;
                    password?: string;
                    provider?: string | null;
                    remember?: boolean | null;
                };
            };
        };
        responses: {
            /** @description Login successful */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            access_token?: string;
                            token_type?: string;
                            expires_in?: number;
                            refresh_token?: string;
                            user?: unknown[];
                            two_factor_required?: boolean | null;
                            challenge_token?: string | null;
                            delivered_to?: string | null;
                        };
                    };
                };
            };
            /** @description Missing required fields */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid credentials */
            401: {
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
    postV1AuthValidatetoken: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Token is valid */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            user?: unknown[];
                            is_valid?: boolean;
                        };
                    };
                };
            };
            /** @description Invalid or expired token */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    postV1AuthRefreshtoken: {
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
                 *       "refresh_token": "example"
                 *     }
                 */
                "application/json": {
                    refresh_token: string;
                };
            };
        };
        responses: {
            /** @description Successful response */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            access_token?: string;
                            refresh_token?: string;
                            expires_in?: number;
                            token_type?: string;
                            user?: unknown[];
                        };
                    };
                };
            };
            /** @description Missing refresh token */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid refresh token */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    postV1AuthLogout: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Logout successful */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthorized - not logged in */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    postV1AuthRefreshpermissions: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Permissions refreshed successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            access_token?: string;
                            refresh_token?: string;
                            permissions?: unknown[];
                            updated_at?: string;
                        };
                    };
                };
            };
            /** @description Missing or invalid token */
            400: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unauthorized - invalid token */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    postV1Blobs: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "multipart/form-data": {
                    /** Format: binary */
                    file: string;
                    path_prefix?: string;
                    /** @enum {string} */
                    visibility?: "public" | "private";
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
            /** @description Upload successful */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            type?: string;
                            url?: string;
                            thumb_url?: string | null;
                            mime_type?: string;
                            size_bytes?: number;
                            width?: number | null;
                            height?: number | null;
                            duration_s?: number | null;
                            filename?: string;
                            path?: string;
                            blob_uuid?: string;
                            visibility?: string;
                        };
                    };
                };
            };
            /** @description Missing file upload or invalid base64 data */
            400: {
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
            /** @description File too large */
            413: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unsupported file type */
            415: {
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
    getV1Extensions: {
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
    getV1ExtensionsCatalog: {
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
    postV1ExtensionsInstall: {
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
                 *       "package": "example"
                 *     }
                 */
                "application/json": {
                    package: string;
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
    postV1ExtensionsEnable: {
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
                 *       "package": "example"
                 *     }
                 */
                "application/json": {
                    package: string;
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
    postV1ExtensionsDisable: {
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
                 *       "package": "example"
                 *     }
                 */
                "application/json": {
                    package: string;
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
    getRbacRolesByUuid: {
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
            /** @description Role details retrieved successfully */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role not found */
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
    putRbacRolesByUuid: {
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
                 *       "name": "Jane",
                 *       "description": "A short description.",
                 *       "parent_uuid": "example",
                 *       "status": "example",
                 *       "metadata": "example"
                 *     }
                 */
                "application/json": {
                    name?: string | null;
                    description?: string | null;
                    parent_uuid?: string | null;
                    status?: string | null;
                    metadata?: unknown[] | null;
                };
            };
        };
        responses: {
            /** @description Role updated successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format or validation errors */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    deleteRbacRolesByUuid: {
        parameters: {
            query?: {
                /** @description Force delete even if assigned to users or has children */
                force?: boolean;
            };
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Role deleted successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Cannot delete role (has dependencies) */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role not found */
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
    getRbacRolesByUuidUsers: {
        parameters: {
            query?: {
                /** @description Page number for pagination (default: 1) */
                page?: number;
                /** @description Number of items per page (default: 25) */
                per_page?: number;
            };
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Role users retrieved successfully */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role not found */
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
    getRbacRolesByUuidPermissions: {
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
            /** @description Role permissions retrieved successfully */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role not found */
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
    putRbacRolesByUuidPermissions: {
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
                 *       "permission_uuids": "example"
                 *     }
                 */
                "application/json": {
                    permission_uuids?: string[];
                };
            };
        };
        responses: {
            /** @description Role permissions updated successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    postRbacRolesByUuidPermissions: {
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
                 *       "permission_uuids": "example"
                 *     }
                 */
                "application/json": {
                    permission_uuids?: string[];
                };
            };
        };
        responses: {
            /** @description Permissions assigned to role successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    getRbacPermissionsByUuid: {
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
            /** @description Permission details retrieved successfully */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Permission not found */
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
    putRbacPermissionsByUuid: {
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
                 *       "name": "Jane",
                 *       "description": "A short description.",
                 *       "category": "example",
                 *       "metadata": "example"
                 *     }
                 */
                "application/json": {
                    name?: string | null;
                    description?: string | null;
                    category?: string | null;
                    metadata?: unknown[] | null;
                };
            };
        };
        responses: {
            /** @description Permission updated successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Permission not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    deleteRbacPermissionsByUuid: {
        parameters: {
            query?: {
                /** @description Force delete even if assigned to users */
                force?: boolean;
            };
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Permission deleted successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Cannot delete permission (still assigned) */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Permission not found */
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
    getRbacUsersByUseruuidRoles: {
        parameters: {
            query?: {
                /** @description JSON-encoded scope filter */
                scope?: string;
            };
            header?: never;
            path: {
                user_uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description User roles retrieved successfully */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description User not found */
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
    putRbacUsersByUseruuidRoles: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                user_uuid: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "role_uuids": "example",
                 *       "scope": "example",
                 *       "expires_at": "example"
                 *     }
                 */
                "application/json": {
                    role_uuids?: string[];
                    scope?: unknown[];
                    expires_at?: string | null;
                };
            };
        };
        responses: {
            /** @description User roles updated successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    postRbacUsersByUseruuidRoles: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                user_uuid: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "role_uuids": "example",
                 *       "scope": "example",
                 *       "expires_at": "example"
                 *     }
                 */
                "application/json": {
                    role_uuids?: string[];
                    scope?: unknown[];
                    expires_at?: string | null;
                };
            };
        };
        responses: {
            /** @description Roles assigned successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    getRbacUsersByUseruuidPermissions: {
        parameters: {
            query?: {
                /** @description Return only active permissions (default: true) */
                active_only?: boolean;
            };
            header?: never;
            path: {
                user_uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description User permissions retrieved successfully */
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
            /** @description Permission denied */
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
    getRbacUsersByUseruuidEffectivepermissions: {
        parameters: {
            query?: {
                /** @description JSON-encoded scope filter */
                scope?: string;
            };
            header?: never;
            path: {
                user_uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description User effective permissions retrieved successfully */
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
            /** @description Permission denied */
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
    getRbacUsersByUseruuidAccessoverview: {
        parameters: {
            query?: {
                /** @description JSON-encoded scope filter */
                scope?: string;
            };
            header?: never;
            path: {
                user_uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description User access overview retrieved successfully */
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
            /** @description Permission denied */
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
    getRbacUsersByUseruuidRolehistory: {
        parameters: {
            query?: {
                /** @description Page number for pagination (default: 1) */
                page?: number;
                /** @description Number of items per page (default: 25) */
                per_page?: number;
                /** @description Include deleted role assignments (default: true) */
                include_deleted?: boolean;
            };
            header?: never;
            path: {
                user_uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description User role history retrieved successfully */
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
            /** @description Permission denied */
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
    getAuditlogsByUuid: {
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
            /** @description Audit log entry retrieved */
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
                            occurred_at?: string;
                            actor_uuid?: string | null;
                            actor_label?: string | null;
                            action?: string;
                            category?: string;
                            target_type?: string | null;
                            target_uuid?: string | null;
                            target_label?: string | null;
                            changes?: unknown[] | null;
                            context?: unknown[] | null;
                            created_at?: string | null;
                        };
                    };
                };
            };
            /** @description Not authenticated */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Missing audit.view permission */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Audit log entry not found */
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
    importExportJobsShow: {
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
            /** @description Job retrieved */
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
            /** @description Permission denied (import_export.view) */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Job not found */
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
    importExportJobsErrors: {
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
            /** @description Errors retrieved */
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
            /** @description Permission denied (import_export.view) */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Job not found */
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
    importExportJobsReport: {
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
            /** @description Report retrieved */
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
            /** @description Permission denied (import_export.view) */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Job not found */
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
    getApiSearchByIndex: {
        parameters: {
            query?: {
                /** @description Search query string (empty string returns all documents) */
                q?: string;
                /** @description Filter expression using Meilisearch syntax; combined with the server-side scope filter */
                filter?: string;
                /** @description Attributes to get facet distribution for */
                facets?: string;
                /** @description Attributes to sort by (format: attribute:direction) */
                sort?: string;
                /** @description Maximum number of results to return (default: 20) */
                limit?: number;
                /** @description Number of results to skip for pagination */
                offset?: number;
                /** @description Configured retrievable attributes to include in results */
                attributesToRetrieve?: string;
                /** @description Attributes to highlight matches in */
                attributesToHighlight?: string;
            };
            header?: never;
            path: {
                index: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Search results retrieved successfully */
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
            /** @description Search permission or scope required */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Index not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    usersShow: {
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
            /** @description User account and public profile */
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
            /** @description Missing the users.view permission */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description User not found */
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
    getV1CollectionsByName: {
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
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    postV1CollectionsByName: {
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
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    getV1CollectionsByNameByUuid: {
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
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    deleteV1CollectionsByNameByUuid: {
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
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    patchV1CollectionsByNameByUuid: {
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
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    getV1MenusBySlug: {
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
            /** @description The resolved menu tree. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    getPreviewassetsByTokenByPath: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                token: string;
                path: string;
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
    getV1SeoMetaByTypeBySlug: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                type: string;
                slug: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Resolved meta descriptor for the entry+locale. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Unknown content type, or no published entry for the route. */
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
    getSitemapNxml: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                n: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Sitemap page XML. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/xml": string;
                };
            };
            /** @description Page number out of range. */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "text/plain": string;
                };
            };
            /** @description No public_url_base configured. */
            409: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "text/plain": string;
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
    getV1ContentByType: {
        parameters: {
            query?: {
                /** @description Content locale to read. Single-entry reads walk the configured i18n fallback chain; when omitted, this defaults to the i18n default locale. */
                locale?: string;
                /** @description Sort by a filterable field, `sort=field:asc` or `sort=field:desc`. Defaults to `published_at:desc`. */
                sort?: string;
                /** @description Opaque keyset cursor taken from a previous response's `next_cursor`. Cursor (default) mode only. */
                cursor?: string;
                /** @description Page number. Supplying `page` or `perPage` switches the response to the offset-pagination envelope. */
                page?: number;
                /** @description Items per page for offset pagination (clamped to delivery.max_per_page). */
                perPage?: number;
                /** @description Typed filters on filterable fields using bracket syntax `filter[field][op]=value`. Operators: eq, neq, gt, gte, lt, lte, in. Only fields declared filterable are accepted. */
                filter?: string[];
            };
            header?: never;
            path: {
                type: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description A page of published entries (cursor mode by default; offset mode replaces `data` with the item array plus top-level pagination keys). */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            items?: {
                                uuid?: string | null;
                                locale?: string | null;
                                version?: number | null;
                                /** Format: date-time */
                                published_at?: string | null;
                                fields?: Record<string, never>;
                            }[];
                            next_cursor?: string | null;
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
            /** @description Filter or sort references a non-filterable field or an unsupported operator. */
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
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    getV1ContentByTypeFacets: {
        parameters: {
            query: {
                /** @description Comma-separated filterable reference field names to count, e.g. `fields=categories,tags`. */
                fields: string;
                /** @description Content locale; defaults to the i18n default locale. */
                locale?: string;
                /** @description Max terms per field (default 100, capped at 500). */
                limit?: number;
            };
            header?: never;
            path: {
                type: string;
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
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    getV1ContentByTypeBySlugoruuid: {
        parameters: {
            query?: {
                /** @description Content locale to read (defaults to the i18n default locale). */
                locale?: string;
            };
            header?: never;
            path: {
                type: string;
                slugOrUuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The published entry with SEO metadata. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            uuid?: string | null;
                            locale?: string | null;
                            version?: number | null;
                            /** Format: date-time */
                            published_at?: string | null;
                            fields?: Record<string, never>;
                            seo?: Record<string, never>;
                        };
                    };
                };
            };
            /** @description Not Modified — the supplied If-None-Match ETag still matches the published version. */
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
            /** @description Unknown content type, or no published entry for the given slug/UUID. */
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
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    getV1ContentByTypeArchiveByFieldByTerm: {
        parameters: {
            query?: {
                /** @description Content locale to read. Single-entry reads walk the configured i18n fallback chain; when omitted, this defaults to the i18n default locale. */
                locale?: string;
                /** @description Sort by a filterable field, `sort=field:asc` or `sort=field:desc`. Defaults to `published_at:desc`. */
                sort?: string;
                /** @description Opaque keyset cursor taken from a previous response's `next_cursor`. Cursor (default) mode only. */
                cursor?: string;
                /** @description Page number. Supplying `page` or `perPage` switches the response to the offset-pagination envelope. */
                page?: number;
                /** @description Items per page for offset pagination (clamped to delivery.max_per_page). */
                perPage?: number;
                /** @description Typed filters on filterable fields using bracket syntax `filter[field][op]=value`. Operators: eq, neq, gt, gte, lt, lte, in. Only fields declared filterable are accepted. */
                filter?: string[];
            };
            header?: never;
            path: {
                type: string;
                field: string;
                term: string;
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
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    getV1PreviewByToken: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                token: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description The previewed draft (or pinned version). */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: {
                            preview?: {
                                entry_uuid?: string;
                                locale?: string;
                                version_uuid?: string | null;
                                version?: number | null;
                                schema_version?: number;
                                fields?: Record<string, never>;
                            };
                        };
                    };
                };
            };
            /** @description Invalid or malformed preview token. */
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
            /** @description The token's target entry/version no longer exists. */
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
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    getV1BlobsByUuid: {
        parameters: {
            query?: {
                /** @description Resize target width in pixels (images only) */
                width?: number;
                /** @description Resize target height in pixels (images only) */
                height?: number;
                /** @description Output quality 1-100 (images only) */
                quality?: number;
                /** @description Output format for conversion (images only) */
                format?: string;
                /** @description Resize fit mode (images only) */
                fit?: string;
            };
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description File content with appropriate Content-Type header */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/octet-stream": string;
                };
            };
            /** @description Authentication required for private blob */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
            /** @description Blob not found */
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
    deleteV1BlobsByUuid: {
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
            /** @description Blob deleted */
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
                        };
                    };
                };
            };
            /** @description Authentication required */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Blob not found */
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
    getV1BlobsByUuidInfo: {
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
            /** @description Blob metadata retrieved */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success: boolean;
                        message: string;
                        data: Record<string, never>;
                    };
                };
            };
            /** @description Authentication required */
            401: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Blob not found */
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
    putEmailTemplatesByKey: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                key: string;
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
    deleteEmailTemplatesByKey: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                key: string;
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
    deleteRbacRolesByUuidRevoke: {
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
            /** @description Role revoked successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role or user not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    deleteRbacRolesByRoleuuidRevokeusers: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                role_uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Bulk role revocation completed */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    deleteRbacRolesByUuidPermissionsByPermissionuuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
                permission_uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Permission revoked from role successfully */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role not found */
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
    deleteRbacPermissionsByUuidRevoke: {
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
            /** @description Permission revoked successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Permission or user not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    deleteRbacUsersByUseruuidRolesByRoleuuid: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                user_uuid: string;
                role_uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Role revoked successfully */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description User or role not found */
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
    postRbacRolesByUuidAssign: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "user_uuid": "example",
                 *       "scope": "example",
                 *       "expires_at": "example"
                 *     }
                 */
                "application/json": {
                    user_uuid: string;
                    scope?: unknown[];
                    expires_at?: string | null;
                };
            };
        };
        responses: {
            /** @description Role assigned successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role or user not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    postRbacRolesByRoleuuidAssignusers: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                role_uuid: string;
            };
            cookie?: never;
        };
        requestBody?: {
            content: {
                /**
                 * @example {
                 *       "user_uuids": "example",
                 *       "scope": "example",
                 *       "expires_at": "example"
                 *     }
                 */
                "application/json": {
                    user_uuids?: string[];
                    scope?: unknown[];
                    expires_at?: string | null;
                };
            };
        };
        responses: {
            /** @description Bulk role assignment completed */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Role not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    postRbacPermissionsByUuidAssign: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "user_uuid": "example",
                 *       "resource": "example",
                 *       "expires_at": "example",
                 *       "constraints": "example"
                 *     }
                 */
                "application/json": {
                    user_uuid: string;
                    resource?: string;
                    expires_at?: string | null;
                    constraints?: unknown[] | null;
                };
            };
        };
        responses: {
            /** @description Permission assigned successfully */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Permission or user not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    postRbacUsersByUseruuidCheckrole: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                user_uuid: string;
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                /**
                 * @example {
                 *       "role_slug": "example",
                 *       "scope": "example"
                 *     }
                 */
                "application/json": {
                    role_slug: string;
                    scope?: unknown[];
                };
            };
        };
        responses: {
            /** @description Role check completed */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid request format */
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
            /** @description Permission denied */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
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
    postEmailTemplatesByKeyTest: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                key: string;
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
    importExportJobsCancel: {
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
            /** @description Job cancelled */
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
            /** @description Permission denied (import_export.cancel) */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Job not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Invalid status transition (job already completed, failed, or cancelled) */
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
    importExportJobsRetry: {
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
            /** @description Retry queued */
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
            /** @description Permission denied (import_export.retry) */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Job not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Adapter is not retryable */
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
    importExportJobsFailedRecordsExport: {
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
            /** @description Failed records exported */
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
            /** @description Permission denied (import_export.export_failed_records) */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Job not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Validation failed */
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
    postV1CollectionsByNameBulk: {
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
            /** @description Rows created. */
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
            /** @description Too Many Requests. */
            429: {
                headers: {
                    /** @description Seconds to wait before retrying. */
                    "Retry-After"?: number;
                    /** @description Request quota for the current window. */
                    "X-RateLimit-Limit"?: number;
                    /** @description Requests remaining in the current window. */
                    "X-RateLimit-Remaining"?: number;
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        success?: boolean;
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
    postV1BlobsByUuidSignedurl: {
        parameters: {
            query?: {
                /** @description URL lifetime in seconds (default: 3600, max: 604800) */
                ttl?: number;
            };
            header?: never;
            path: {
                uuid: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Signed URL generated */
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
                            signed_url?: string;
                            expires_in?: number;
                            expires_at?: string;
                            native_url?: string;
                        };
                    };
                };
            };
            /** @description Signed URLs are disabled */
            400: {
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
            /** @description Blob not found */
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
    i18nLocalesUpdate: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                code: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Locale updated */
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
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Locale not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Validation failed (empty payload, code change, malformed fields, fallback cycle, or clearing/disabling the only default) */
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
    i18nTranslationsUpdate: {
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
            /** @description Translation updated */
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
            /** @description Forbidden */
            403: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Translation not found */
            404: {
                headers: {
                    [name: string]: unknown;
                };
                content?: never;
            };
            /** @description Validation failed (missing or oversized value) */
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
}
