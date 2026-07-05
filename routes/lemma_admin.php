<?php

declare(strict_types=1);

use App\Content\Http\Controllers\BlockMigrationController;
use App\Content\Http\Controllers\BlockTypeController;
use App\Content\Http\Controllers\ContentTypeController;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\Controllers\LocaleAdminController;
use App\Content\Http\Controllers\MigrationController;
use App\Content\Http\Controllers\PreviewController;
use App\Content\Http\Controllers\PublicationController;
use App\Content\Http\Controllers\RedirectController;
use App\Content\Http\Controllers\ScheduleController;
use App\Http\Controllers\ApiKeyAdminController;
use App\Http\Controllers\CacheAdminController;
use App\Http\Controllers\CapabilityAdminController;
use App\Http\Controllers\EmailSettingsController;
use App\Http\Controllers\ExtensionAdminController;
use App\Http\Controllers\GeneralSettingsController;
use App\Http\Controllers\HealthAdminController;
use App\Http\Controllers\ImportExportController;
use App\Http\Controllers\MediaAdminController;
use App\Http\Controllers\RegionAdminController;
use App\Http\Controllers\ScheduledTasksController;
use App\Http\Controllers\UserAdminController;
use Glueful\Api\Webhooks\Http\Controllers\WebhookController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin authoring API. Every route is gated by the `auth` middleware (a Bearer JWT or an
 * API key resolves the principal) PLUS a `lemma_permission:<permission>` RBAC check. The
 * required permission is named per route in its @description. Auto-discovered by
 * RouteManifest; the provider must NOT loadRoutesFrom() this file.
 */
$router->group(['prefix' => '/v1/admin', 'middleware' => ['auth']], function (Router $router): void {
    // Content type (model) management.
    $router->get('/content-types', [ContentTypeController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    $router->post('/content-types', [ContentTypeController::class, 'store'])
        ->middleware('lemma_permission:content.manage');

    $router->get('/content-types/{slug}', [ContentTypeController::class, 'show'])
        ->middleware('lemma_permission:content.view');

    $router->patch('/content-types/{slug}', [ContentTypeController::class, 'update'])
        ->middleware('lemma_permission:content.manage');

    $router->patch('/content-types/{slug}/schema', [ContentTypeController::class, 'updateSchema'])
        ->middleware('lemma_permission:content.manage');

    // Destructive schema migrations: POST body is {ops:[{op:"rename",from,to}|{op:"delete",name}]};
    // responses wrap migration rows with status/progress/failure_report for polling.
    $router->post('/content-types/{slug}/migrations', [MigrationController::class, 'store'])
        ->middleware('lemma_permission:content.manage');

    $router->get('/content-types/{slug}/migrations', [MigrationController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    $router->get('/content-types/{slug}/migrations/{migrationUuid}', [MigrationController::class, 'show'])
        ->middleware('lemma_permission:content.view');

    $router->delete('/content-types/{slug}', [ContentTypeController::class, 'destroy'])
        ->middleware('lemma_permission:content.manage');

    // Block-type registry (block-builder spec §1): the reusable block schemas that
    // `blocks` fields compose. Same permissions as content-type schema management.
    $router->get('/block-types', [BlockTypeController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    $router->post('/block-types', [BlockTypeController::class, 'store'])
        ->middleware('lemma_permission:content.manage');

    $router->get('/block-types/{slug}', [BlockTypeController::class, 'show'])
        ->middleware('lemma_permission:content.view');

    $router->patch('/block-types/{slug}', [BlockTypeController::class, 'update'])
        ->middleware('lemma_permission:content.manage');

    $router->post('/block-types/{slug}/activate', [BlockTypeController::class, 'activate'])
        ->middleware('lemma_permission:content.manage');

    $router->post('/block-types/{slug}/deactivate', [BlockTypeController::class, 'deactivate'])
        ->middleware('lemma_permission:content.manage');

    // Block-type schema migrations (block-migrations spec §2): declared rename/delete
    // ops with an eager queued backfill; one active migration per type.
    $router->post('/block-types/{slug}/migrations', [BlockMigrationController::class, 'store'])
        ->middleware('lemma_permission:content.manage');

    $router->get('/block-types/{slug}/migrations', [BlockMigrationController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    $router->get('/block-types/{slug}/migrations/{migrationUuid}', [BlockMigrationController::class, 'show'])
        ->middleware('lemma_permission:content.view');

    // Usage scan + zero-usage hard delete (block-migrations spec §6).
    $router->get('/block-types/{slug}/usage', [BlockTypeController::class, 'usage'])
        ->middleware('lemma_permission:content.view');

    $router->delete('/block-types/{slug}', [BlockTypeController::class, 'destroy'])
        ->middleware('lemma_permission:content.manage');

    // Entry authoring (identity, drafts, preview).
    $router->get('/entries', [EntryController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    $router->post('/entries', [EntryController::class, 'store'])
        ->middleware('lemma_permission:content.create');

    $router->get('/entries/{uuid}', [EntryController::class, 'show'])
        ->middleware('lemma_permission:content.view');

    $router->get('/entries/{uuid}/draft/{locale}', [EntryController::class, 'getDraft'])
        ->middleware('lemma_permission:content.view');

    $router->put('/entries/{uuid}/draft/{locale}', [EntryController::class, 'saveDraft'])
        ->middleware('lemma_permission:content.edit');

    $router->delete('/entries/{uuid}/draft/{locale}', [EntryController::class, 'discardDraft'])
        ->middleware('lemma_permission:content.edit');

    $router->delete('/entries/{uuid}', [EntryController::class, 'destroy'])
        ->middleware('lemma_permission:content.delete');

    $router->get('/entries/{uuid}/locales', [EntryController::class, 'locales'])
        ->middleware('lemma_permission:content.view');

    $router->post('/entries/{uuid}/locales/{locale}', [EntryController::class, 'createLocaleDraft'])
        ->middleware('lemma_permission:content.create');

    // Per-locale content usage counts — used to warn before disabling a language.
    $router->get('/locales/{locale}/usage', [LocaleAdminController::class, 'usage'])
        ->middleware('lemma_permission:content.manage');

    $router->get('/entries/{uuid}/versions/{locale}', [PublicationController::class, 'versions'])
        ->middleware('lemma_permission:content.view');

    $router->get('/entries/{uuid}/routes', [EntryController::class, 'routes'])
        ->middleware('lemma_permission:content.view');

    $router->put('/entries/{uuid}/routes/{locale}', [EntryController::class, 'assignRoute'])
        ->middleware('lemma_permission:content.edit');

    $router->delete('/entries/{uuid}/routes/{locale}', [EntryController::class, 'removeRoute'])
        ->middleware('lemma_permission:content.edit');

    // SEO redirects: POST body is {locale, source_slug, target:{url|entry_uuid, content_type?, locale?}, status};
    // responses wrap redirect rows and their computed target_state (live|broken).
    $router->post('/content-types/{slug}/redirects', [RedirectController::class, 'store'])
        ->middleware('lemma_permission:content.routes');

    $router->get('/content-types/{slug}/redirects', [RedirectController::class, 'index'])
        ->middleware('lemma_permission:content.routes');

    $router->delete('/redirects/{uuid}', [RedirectController::class, 'destroy'])
        ->middleware('lemma_permission:content.routes');

    $router->post('/entries/{uuid}/preview/{locale}', [PreviewController::class, 'mint'])
        ->middleware('lemma_permission:content.view');

    // Loop C: apply the CURRENT working fields as an ephemeral preview. Reveals
    // UNSAVED edits through the preview token, so it takes the editor's permission.
    $router->post('/entries/{uuid}/preview/{locale}/apply', [EntryController::class, 'applyPreview'])
        ->middleware('lemma_permission:content.edit');

    // Scheduled publication: POST body is {action:"publish"|"unpublish", run_at:<absolute ISO-8601 with timezone>};
    // response wraps {schedule:{...row,replaced:bool}}. GET returns {schedules:[...history]}.
    $router->post('/entries/{uuid}/schedules/{locale}', [ScheduleController::class, 'store'])
        ->middleware('lemma_permission:content.publish');

    $router->get('/entries/{uuid}/schedules', [ScheduleController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    $router->delete('/entries/{uuid}/schedules/{scheduleUuid}', [ScheduleController::class, 'destroy'])
        ->middleware('lemma_permission:content.publish');

    // Publication lifecycle.
    $router->post('/entries/{uuid}/publish/{locale}', [PublicationController::class, 'publish'])
        ->middleware('lemma_permission:content.publish');

    $router->post('/entries/{uuid}/unpublish/{locale}', [PublicationController::class, 'unpublish'])
        ->middleware('lemma_permission:content.publish');

    $router->post('/entries/{uuid}/rollback/{locale}', [PublicationController::class, 'rollback'])
        ->middleware('lemma_permission:content.publish');

    // Instance settings — mailer config persisted to .env. Gated on `system.config` (superuser
    // only, per Aegis' seeded roles): these routes write MAIL_* to .env and the test action opens
    // an SMTP connection with the stored credentials, so they carry the same infrastructure-config
    // authority as the permission catalog — not day-to-day `content.manage`.
    $router->get('/settings/email', [EmailSettingsController::class, 'show'])
        ->middleware('lemma_permission:system.config');

    $router->put('/settings/email', [EmailSettingsController::class, 'update'])
        ->middleware('lemma_permission:system.config');

    $router->post('/settings/email/test', [EmailSettingsController::class, 'test'])
        ->middleware('lemma_permission:system.config');

    // Global chrome regions (header/footer block lists) — chrome is content policy.
    $router->get('/regions', [RegionAdminController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    // Renders UNSAVED region payloads through the real theme pipeline (never writes).
    $router->post('/regions/preview', [RegionAdminController::class, 'preview'])
        ->middleware('lemma_permission:content.view');

    $router->put('/regions/{slug}', [RegionAdminController::class, 'update'])
        ->middleware('lemma_permission:content.manage');

    // Instance General settings — site identity, default locale, delivery defaults, feature toggles
    // (persisted as LEMMA_* keys in .env).
    $router->get('/settings/general', [GeneralSettingsController::class, 'show'])
        ->middleware('lemma_permission:content.manage');

    $router->put('/settings/general', [GeneralSettingsController::class, 'update'])
        ->middleware('lemma_permission:content.manage');

    // Admin user management (app-owned policy over glueful/users' store primitives). The list/read
    // lives in glueful/users (`GET /v1/users`); creating and removing users is product policy.
    $router->post('/users', [UserAdminController::class, 'store'])
        ->middleware('lemma_permission:users.create');

    $router->patch('/users/{uuid}', [UserAdminController::class, 'update'])
        ->middleware('lemma_permission:users.edit');

    $router->delete('/users/{uuid}', [UserAdminController::class, 'destroy'])
        ->middleware('lemma_permission:users.delete');

    // Extensions — list/toggle installed glueful-extension packages + browse the Packagist catalog.
    // Enable/disable rewrites config/extensions.php (dev only). All gated by system.access.
    $router->get('/extensions', [ExtensionAdminController::class, 'index'])
        ->middleware('lemma_permission:system.access');

    $router->get('/extensions/registry', [ExtensionAdminController::class, 'registry'])
        ->middleware('lemma_permission:system.access');

    $router->post('/extensions/enable', [ExtensionAdminController::class, 'enable'])
        ->middleware('lemma_permission:system.access');

    $router->post('/extensions/disable', [ExtensionAdminController::class, 'disable'])
        ->middleware('lemma_permission:system.access');

    $router->get('/extensions/{vendor}/{name}/readme', [ExtensionAdminController::class, 'readme'])
        ->middleware('lemma_permission:system.access');

    // Media library — list/search over blobs + CMS metadata (alt/caption/tags) + usage.
    $router->get('/media', [MediaAdminController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    $router->get('/media/{uuid}', [MediaAdminController::class, 'show'])
        ->middleware('lemma_permission:content.view');

    $router->get('/media/{uuid}/usage', [MediaAdminController::class, 'usage'])
        ->middleware('lemma_permission:content.view');

    $router->post('/media/{uuid}/optimize', [MediaAdminController::class, 'optimize'])
        ->middleware('lemma_permission:content.manage');

    $router->patch('/media/{uuid}', [MediaAdminController::class, 'update'])
        ->middleware('lemma_permission:content.manage');

    $router->delete('/media/{uuid}', [MediaAdminController::class, 'destroy'])
        ->middleware('lemma_permission:content.manage');

    // API keys — system-wide list/create/rotate/revoke over the framework `api_keys` store. The
    // plaintext key is returned only on create/rotate. All gated by system.access.
    $router->get('/api-keys', [ApiKeyAdminController::class, 'index'])
        ->middleware('lemma_permission:system.access');

    $router->post('/api-keys', [ApiKeyAdminController::class, 'store'])
        ->middleware('lemma_permission:system.access');

    $router->get('/api-keys/{uuid}', [ApiKeyAdminController::class, 'show'])
        ->middleware('lemma_permission:system.access');

    $router->post('/api-keys/{uuid}/rotate', [ApiKeyAdminController::class, 'rotate'])
        ->middleware('lemma_permission:system.access');

    $router->patch('/api-keys/{uuid}/scopes', [ApiKeyAdminController::class, 'updateScopes'])
        ->middleware('lemma_permission:system.access');

    $router->delete('/api-keys/{uuid}', [ApiKeyAdminController::class, 'destroy'])
        ->middleware('lemma_permission:system.access');

    // Webhooks — surface the framework's webhook engine (subscriptions + deliveries) in the admin.
    // Routes delegate to the framework's WebhookController; the tables are materialized by the
    // 007_CreateWebhookTables migration so listing works before the first dispatch. All gated by
    // system.access.
    $router->get('/webhooks/subscriptions', [WebhookController::class, 'listSubscriptions'])
        ->middleware('lemma_permission:system.access');

    $router->post('/webhooks/subscriptions', [WebhookController::class, 'createSubscription'])
        ->middleware('lemma_permission:system.access');

    $router->get('/webhooks/subscriptions/{id}', [WebhookController::class, 'getSubscription'])
        ->middleware('lemma_permission:system.access');

    $router->patch('/webhooks/subscriptions/{id}', [WebhookController::class, 'updateSubscription'])
        ->middleware('lemma_permission:system.access');

    $router->delete('/webhooks/subscriptions/{id}', [WebhookController::class, 'deleteSubscription'])
        ->middleware('lemma_permission:system.access');

    $router->post('/webhooks/subscriptions/{id}/rotate-secret', [WebhookController::class, 'rotateSecret'])
        ->middleware('lemma_permission:system.access');

    $router->post('/webhooks/subscriptions/{id}/test', [WebhookController::class, 'testSubscription'])
        ->middleware('lemma_permission:system.access');

    $router->get('/webhooks/subscriptions/{id}/stats', [WebhookController::class, 'getSubscriptionStats'])
        ->middleware('lemma_permission:system.access');

    $router->get('/webhooks/deliveries', [WebhookController::class, 'listDeliveries'])
        ->middleware('lemma_permission:system.access');

    $router->get('/webhooks/deliveries/{id}', [WebhookController::class, 'getDelivery'])
        ->middleware('lemma_permission:system.access');

    $router->post('/webhooks/deliveries/{id}/retry', [WebhookController::class, 'retryDelivery'])
        ->middleware('lemma_permission:system.access');

    // Capabilities — reports installed packs whose capability is enabled by the switchboard.
    // Read-only; consumed by the admin SPA to mount only available modules.
    $router->get('/capabilities', [CapabilityAdminController::class, 'index'])
        ->middleware('lemma_permission:system.access');

    // Utilities — system ops tools (Health, Cache, Scheduled tasks). All gated by system.access.
    $router->get('/health', [HealthAdminController::class, 'show'])
        ->middleware('lemma_permission:system.access');

    $router->get('/cache', [CacheAdminController::class, 'show'])
        ->middleware('lemma_permission:system.access');

    $router->post('/cache/clear', [CacheAdminController::class, 'clear'])
        ->middleware('lemma_permission:system.access');

    $router->get('/scheduled-tasks', [ScheduledTasksController::class, 'index'])
        ->middleware('lemma_permission:system.access');

    $router->post('/scheduled-tasks/{name}/run', [ScheduledTasksController::class, 'run'])
        ->middleware('lemma_permission:system.access');

    // Import/Export: the glueful/import-export extension owns the job API (under /import-export), but
    // ships no route to download an export result or upload an import file — these Lemma routes fill
    // both gaps (the importer reads from the uploads disk; see config/import_export.php source_roots).
    $router->get('/import-export/jobs/{uuid}/download', [ImportExportController::class, 'download'])
        ->where('uuid', '[A-Za-z0-9_-]+')
        ->middleware('lemma_permission:content.manage');

    $router->post('/import-export/upload', [ImportExportController::class, 'upload'])
        ->middleware('lemma_permission:content.manage');
});
