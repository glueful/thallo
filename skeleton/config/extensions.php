<?php

/**
 * Extensions
 *
 * Composer discovers installed `glueful-extension` packages (see their
 * extra.glueful.provider). This file is the single activation allow-list for
 * INSTALLABLE EXTENSIONS ONLY: an installed extension does nothing until its
 * provider FQCN appears below. Thallo's internal modules are NOT extensions —
 * they are library-typed packages registered in config/serviceproviders.php
 * (modules-not-extensions spec, 2026-07-25) and never belong in this list.
 * The tenancy enforcement provider line is RUNTIME STATE written and removed
 * by the tenancy enablement flow — never add or strip it by hand; generic
 * enable/disable refuses it via the extensions.protected map.
 *
 * - Entries are plain string FQCNs (no ::class) so `php glueful extensions:enable|disable`
 *   can edit this list safely. Do not use conditionals/function calls here.
 * - Order is preserved; dependencies are reordered automatically.
 * - Empty = nothing loads. To kill everything fast, set `enabled => []`.
 *
 * Manage with: php glueful extensions:list | enable <name> | disable <name> | cache
 *
 * DISTRIBUTION DEFAULT (docs/internal/DISTRIBUTION.md §2, posture split 2026-08-15): this
 * list is tier 1 plus the bundled Subscriptions billing engine. Tier 2 (Commerce, Payvia,
 * Meilisearch) is installed-but-disabled — enable via the in-admin extensions browser or
 * `php glueful extensions:enable`. Thallo's own development/test environments re-enable
 * tier 2 through the config/{development,testing}/extensions.php overlays (repo-only).
 */

return [
    'enabled' => [
        'Glueful\Extensions\Aegis\Services\AegisServiceProvider',
        'Glueful\Extensions\Audit\AuditServiceProvider',
        'Glueful\Extensions\EmailNotification\EmailNotificationServiceProvider',
        'Glueful\Extensions\I18n\I18nServiceProvider',
        'Glueful\Extensions\ImportExport\ImportExportServiceProvider',
        'Glueful\Extensions\Media\MediaServiceProvider',
        'Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider',
        'Glueful\Extensions\Users\UsersServiceProvider',
    ],

    /**
     * Providers whose activation is OWNED by a lifecycle flow: every generic enable/disable
     * surface (framework CLI + controllers, and Thallo's own extensions admin) consults
     * ProtectedProviders::refusalFor() and refuses these before touching state. The tenancy
     * enforcement provider's line in `enabled` above is written/removed exclusively by the
     * workspaces enablement flow (packages/thallo-tenancy).
     */
    'protected' => [
        'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider' => [
            'reason' => 'Workspace enforcement is managed by the tenancy enablement flow — '
                . 'use Settings › Workspaces, not the generic extension toggle.',
            'managed_by' => 'glueful/tenancy enablement',
        ],
    ],

    /**
     * In-admin extension installer (composer require via the /extensions API).
     * Off in production unless EXTENSIONS_INSTALL_ENABLED is explicitly set.
     * Keep env() reads here — NOT inside `enabled` above (that must stay a
     * literal list the enable/disable writer can edit).
     */
    'install' => [
        'enabled'     => env('EXTENSIONS_INSTALL_ENABLED', env('APP_ENV') !== 'production'),
        'auto_enable' => (bool) env('EXTENSIONS_INSTALL_AUTO_ENABLE', true),
        'timeout'     => (int) env('EXTENSIONS_INSTALL_TIMEOUT', 600),
        'vendor'      => 'glueful/',
        // Absolute path to a CLI php for the detached install runner. Leave null to
        // auto-detect (PhpExecutableFinder, then PHP_BINARY). Set this when installs
        // hang in "queued" — under Apache/php-cgi/FPM, PHP_BINARY is not a usable CLI
        // interpreter and won't receive the command's argv.
        'php_binary'  => env('EXTENSIONS_INSTALL_PHP_BINARY') ?: null,
    ],
];
