<?php

declare(strict_types=1);

namespace App\Providers;

use App\Capabilities\DefaultCapabilityRegistry;
use App\Setup\SetupService;
use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\EngineMediaUrlResolver;
use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\ReferenceFilterResolver;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Delivery\SortCompiler;
use App\Content\Console\PruneVersionsCommand;
use App\Content\Console\RunBlockBackfillCommand;
use App\Content\Console\SeedBlockTypesCommand;
use App\Content\Console\ResyncCommand;
use App\Content\Console\RunBackfillCommand;
use App\Content\Console\RunDueSchedulesCommand;
use App\Setup\Console\CreateAdminCommand;
use App\Setup\Console\DoctorCommand;
use App\Setup\Console\ProvisionCommand;
use App\Content\Backfill\BackfillRunner;
use App\Http\Controllers\AdminConfigController;
use App\Http\Controllers\ApiKeyAdminController;
use App\Http\Controllers\CacheAdminController;
use App\Http\Controllers\CapabilityAdminController;
use App\Http\Controllers\EmailSettingsController;
use App\Http\Controllers\ExtensionAdminController;
use App\Http\Controllers\GeneralSettingsController;
use App\Http\Controllers\HealthAdminController;
use App\Http\Controllers\IconInventoryController;
use App\Http\Controllers\ImportExportController;
use App\Http\Controllers\MediaAdminController;
use App\Http\Controllers\RegionAdminController;
use App\Http\Controllers\ScheduledTasksController;
use App\Http\Controllers\UserAdminController;
use App\Support\UserRoleAssignmentPolicy;
use App\Settings\GeneralSettings;
use App\Settings\SettingsStore;
use App\Content\Http\Controllers\BlockMigrationController;
use App\Content\Http\Controllers\BlockTypeController;
use App\Content\Http\Controllers\ContentTypeController;
use App\Http\Controllers\SetupController;
use App\Content\Http\Controllers\DeliveryController;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\Controllers\LocaleAdminController;
use App\Content\Http\Controllers\MigrationController;
use App\Content\Http\Controllers\PreviewController;
use App\Content\Http\Controllers\PublicationController;
use App\Content\Http\Controllers\RedirectController;
use App\Content\Http\Controllers\ScheduleController;
use App\Content\Http\Controllers\TaxonomyController;
use App\Content\ImportExport\LemmaContentExporter;
use App\Content\ImportExport\LemmaContentImporter;
use App\Content\Http\DeliveryEtag;
use App\Content\Events\EntryCreated;
use App\Content\Events\EntryDeleted;
use App\Content\Events\EntryPublished;
use App\Content\Events\EntryUnpublished;
use App\Content\Events\EntryUpdated;
use App\Content\Events\ModelCreated;
use App\Content\Events\ModelDeleted;
use App\Content\Events\ModelUpdated;
use App\Content\Http\DeliveryAccessMiddleware;
use App\Content\Http\OptionalApiKeyAuthMiddleware;
use App\Content\Http\RequireLemmaPermission;
use App\Content\Localization\ContentLocaleService;
use App\Content\Events\AssetAttached;
use App\Content\Events\AssetDetached;
use App\Analytics\AnalyticsBridgeListener;
use App\Collections\Audit\CollectionAuditListener;
use App\Content\Pipeline\Listeners\DispatchWebhookListener;
use App\Content\Pipeline\Listeners\InvalidateCacheTagsListener;
use App\Content\Pipeline\Listeners\ProjectPublishedReferencesListener;
use App\Content\Pipeline\Listeners\PurgeCdnListener;
use App\Content\Pipeline\Listeners\MediaUsageProjector;
use App\Content\Pipeline\Listeners\ReindexSearchListener;
use App\Content\Blocks\BlockMigrationGate;
use App\Content\Blocks\BlockRestoreProjector;
use App\Content\Blocks\EngineBlockEditableFieldResolver;
use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\BlockUsageScanner;
use App\Content\Blocks\Migration\BlockBackfillRunner;
use App\Content\Regions\EngineRegionReader;
use App\Content\Regions\RegionRepository;
use App\Content\Regions\RegionValidator;
use App\Content\Blocks\Migration\BlockInstanceWalker;
use App\Content\Blocks\Migration\BlockMigrationRepository;
use App\Content\Blocks\Migration\BlockMigrationService;
use App\Content\Pipeline\PublishEventEmitter;
use App\Content\Preview\EnginePreviewSessionVerifier;
use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewReader;
use App\Content\Preview\PreviewWorkingCopyStore;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\MigrationRepository;
use App\Content\Repositories\PublishedReferenceRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\ScheduleRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Retention\VersionPruner;
use App\Content\Schema\Migration\SchemaProjector;
use App\Content\Scheduling\ScheduleRunner;
use App\Content\Seo\CanonicalProjector;
use App\Content\Routing\RootMountGuard;
use App\Content\Seo\CanonicalPathBuilder;
use App\Settings\EngineAdminUrlProvider;
use App\Settings\EngineSiteLogoProvider;
use App\Content\Seo\PathRenderer;
use App\Content\Seo\RedirectRepository;
use App\Content\Seo\RouteResolver;
use App\Content\Services\MigrationService;
use App\Content\Authoring\EngineContentWriter;
use App\Content\Authoring\EngineDraftSummaryReader;
use App\Content\Delivery\EngineEntryTargetResolver;
use App\Content\Context\EngineLemmaContext;
use App\Content\Delivery\EngineContentDeliveryReader;
use App\Content\Delivery\EngineFacetCountsReader;
use App\Content\Delivery\EngineIndexableContentReader;
use App\Content\Schema\FieldTypes\DefaultFieldTypeRegistry;
use App\Content\Schema\FieldTypes\EditorialFieldTypes;
use App\Content\Services\PublishService;
use App\Content\Sanitization\TipTapHtmlSanitizer;
use App\Content\Validation\FieldValidator;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Lemma\Contracts\Authoring\ContentWriter;
use Glueful\Lemma\Contracts\Content\BlockEditableFieldResolver;
use Glueful\Lemma\Contracts\Content\RegionReader;
use Glueful\Lemma\Contracts\Content\RichHtmlSanitizer;
use Glueful\Lemma\Contracts\Authoring\DraftSummaryReader;
use Glueful\Lemma\Contracts\Authoring\PublishGate;
use Glueful\Lemma\Contracts\Delivery\EntryTargetResolver;
use Glueful\Lemma\Contracts\Delivery\MediaUrlResolver;
use Glueful\Lemma\Contracts\Settings\AdminUrlProvider;
use Glueful\Lemma\Contracts\Settings\SiteLogoProvider;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use Glueful\Lemma\Contracts\Context\LemmaContext;
use Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader;
use Glueful\Lemma\Contracts\Delivery\FacetCountsReader;
use Glueful\Lemma\Contracts\Delivery\PreviewSessionVerifier;
use Glueful\Lemma\Contracts\Delivery\PreviewThemeValidator;
use Glueful\Lemma\Contracts\Delivery\ReferenceTargetResolver;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;
use Glueful\Lemma\Contracts\Schema\FieldTypeRegistry;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Events\EventService;
use Glueful\Lemma\Collections\Events\CollectionCreated;
use Glueful\Lemma\Collections\Events\CollectionDropped;
use Glueful\Lemma\Collections\Events\CollectionRowCreated;
use Glueful\Lemma\Collections\Events\CollectionRowDeleted;
use Glueful\Lemma\Collections\Events\CollectionRowUpdated;
use Glueful\Lemma\Collections\Events\CollectionUpdated;
use Glueful\Extensions\ServiceProvider;
use Glueful\Support\FieldSelection\Projector;
use Psr\Container\ContainerInterface;

/**
 * Wires the Lemma content engine into the application container.
 *
 * Registered in config/serviceproviders.php. The framework's ProviderClassResolver
 * folds app providers into the same provider list as composer extensions, so this
 * provider's services() are collected by the ContainerFactory and its register()/boot()
 * lifecycle is run by the ExtensionManager (it extends ServiceProvider, the gate
 * ExtensionManager::addProvider() requires).
 *
 * services() composes per-domain binding groups (repositories, the content engine +
 * contract implementations, SEO/routing, the delivery read path, pipeline listeners,
 * preview, import/export, maintenance, the HTTP controllers, and console commands). Each
 * group is a small private static method returning a partial binding array; services()
 * array_merges them so the registration reads as a table of contents. All bindings
 * autowire unless they need a factory (config-derived construction) or explicit arguments.
 *
 * Routes: routes/lemma_admin.php is NOT loaded here. The framework's RouteManifest
 * auto-discovers every routes/*.php file (underscore-prefixed partials excepted) during
 * the HTTP phase, which already runs AFTER extension boot(). Calling loadRoutesFrom()
 * in boot() would load the file a second time and the Router throws LogicException on a
 * duplicate static route. Auto-discovery is the framework's real mechanism for app routes.
 *
 * Config: config/lemma.php lives in the app config directory and is loaded by the
 * file-based config system, so it is already available as config('lemma.*'); mergeConfig
 * is therefore unnecessary (it would only re-supply the same values as defaults).
 */
final class LemmaServiceProvider extends ServiceProvider
{
    /**
     * Guards registerEventListeners() against a double-run. EventService::addListener
     * APPENDS with no dedup, so a second registration would make every listener fire
     * twice — harmless for idempotent cache invalidation, but a real bug for webhooks
     * (duplicate deliveries). ExtensionManager::boot() already guards each provider's
     * boot() to once per app lifecycle; this flag is cheap defence-in-depth on top.
     */
    private bool $listenersRegistered = false;

    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return array_merge(
            self::repositoryServices(),
            self::contentEngineServices(),
            self::seoServices(),
            self::deliveryServices(),
            self::pipelineListenerServices(),
            self::previewServices(),
            self::importExportServices(),
            self::maintenanceServices(),
            self::contentControllerServices(),
            self::platformControllerServices(),
            self::consoleCommandServices(),
        );
    }

    /**
     * Content storage repositories — each resolves Connection (RouteRepository also takes
     * the SEO RedirectRepository so route changes can retire stale redirects).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function repositoryServices(): array
    {
        return [
            BlockTypeRepository::class => [
                'class' => BlockTypeRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockMigrationRepository::class => [
                'class' => BlockMigrationRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockInstanceWalker::class => [
                'class' => BlockInstanceWalker::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockMigrationGate::class => [
                'class' => BlockMigrationGate::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockRestoreProjector::class => [
                'class' => BlockRestoreProjector::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockUsageScanner::class => [
                'class' => BlockUsageScanner::class,
                'shared' => true,
                'autowire' => true,
            ],
            ContentTypeRepository::class => [
                'class' => ContentTypeRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            EntryRepository::class => [
                'class' => EntryRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            VersionRepository::class => [
                'class' => VersionRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            RouteRepository::class => [
                'class' => RouteRepository::class,
                'shared' => true,
                'arguments' => ['@' . Connection::class, '@' . RedirectRepository::class],
            ],
            RedirectRepository::class => [
                'class' => RedirectRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReferenceProjectionRepository::class => [
                'class' => ReferenceProjectionRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            MigrationRepository::class => [
                'class' => MigrationRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            ScheduleRepository::class => [
                'class' => ScheduleRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * The content engine core and the contract implementations packs bind against
     * (ContentWriter, ContentDeliveryReader, LemmaContext, ContentTypeReader), plus
     * schema/validation, publishing, locale, the capability registry, and setup.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function contentEngineServices(): array
    {
        return [
            SetupService::class => [
                'class'    => SetupService::class,
                'shared'   => true,
                'autowire' => true,
            ],
            FieldTypeRegistry::class => [
                'class'    => DefaultFieldTypeRegistry::class,
                'shared'   => true,
                'autowire' => true,
            ],
            CapabilityRegistry::class => [
                'factory' => [self::class, 'makeCapabilityRegistry'],
                'shared' => true,
            ],
            SchemaProjector::class => [
                'class' => SchemaProjector::class,
                'shared' => false,
                'autowire' => true,
            ],
            FieldValidator::class => [
                'class' => FieldValidator::class,
                'shared' => true,
                'autowire' => true,
            ],
            ContentLocaleService::class => [
                'class' => ContentLocaleService::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublishService::class => [
                // Factory (not autowire): collects tag-registered lemma.publish_gate services
                // (workflow pack etc.); the tag collection is priority-ordered by the compiler.
                'factory' => [self::class, 'makePublishService'],
                'shared' => true,
            ],
            DraftSummaryReader::class => [
                'class'    => EngineDraftSummaryReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            EntryTargetResolver::class => [
                'class'    => EngineEntryTargetResolver::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \App\Content\Delivery\DeliveryItemShaper::class => [
                'class'    => \App\Content\Delivery\DeliveryItemShaper::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \Glueful\Lemma\Contracts\Delivery\HomepageEntryProvider::class => [
                'class'    => \App\Content\Delivery\EngineHomepageEntryProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \Glueful\Lemma\Contracts\Delivery\PublicRouteResolver::class => [
                'class'    => \App\Content\Delivery\EnginePublicRouteResolver::class,
                'shared'   => true,
                'autowire' => true,
            ],
            FacetCountsReader::class => [
                'class'    => EngineFacetCountsReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ContentWriter::class => [
                'class'    => EngineContentWriter::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ContentDeliveryReader::class => [
                'factory' => [self::class, 'makeContentDeliveryReader'],
                'shared'  => true,
            ],
            IndexableContentReader::class => [
                'factory' => [self::class, 'makeIndexableContentReader'],
                'shared'  => true,
            ],
            LemmaContext::class => [
                'class'    => EngineLemmaContext::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \Glueful\Lemma\Contracts\Schema\ContentTypeReader::class => [
                'class'    => \App\Content\Schema\EngineContentTypeReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            MigrationService::class => [
                'class' => MigrationService::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockMigrationService::class => [
                'class' => BlockMigrationService::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockBackfillRunner::class => [
                'class' => BlockBackfillRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * Headless SEO/routing: path rendering, route resolution, and canonical-URL
     * projection (the factory-built services derive their config from lemma.seo.*).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function seoServices(): array
    {
        return [
            PathRenderer::class => [
                'factory' => [self::class, 'makePathRenderer'],
                'shared' => true,
            ],
            CanonicalPathBuilder::class => [
                'class' => CanonicalPathBuilder::class,
                'shared' => true,
                'autowire' => true,
            ],
            RootMountGuard::class => [
                'class' => RootMountGuard::class,
                'shared' => true,
                'autowire' => true,
            ],
            RouteResolver::class => [
                'class' => RouteResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            CanonicalProjector::class => [
                'factory' => [self::class, 'makeCanonicalProjector'],
                'shared' => true,
            ],
        ];
    }

    /**
     * Delivery API (published-only read path): the repository, filter/sort compilers,
     * reference resolution (the ReferenceTargetResolver contract binds to the engine's
     * ReferenceFilterResolver), field projection, ETag, the controller, and the
     * delivery-scoped middleware aliases.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function deliveryServices(): array
    {
        return [
            DeliveryRepository::class => [
                'class' => DeliveryRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            FilterCompiler::class => [
                'class' => FilterCompiler::class,
                'shared' => true,
                'autowire' => true,
            ],
            SortCompiler::class => [
                'class' => SortCompiler::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReferenceResolver::class => [
                'class' => ReferenceResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReferenceFilterResolver::class => [
                'class' => ReferenceFilterResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReferenceTargetResolver::class => [
                'class' => ReferenceFilterResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            Projector::class => [
                'class' => Projector::class,
                'shared' => true,
                'autowire' => true,
            ],
            DeliveryEtag::class => [
                'class' => DeliveryEtag::class,
                'shared' => true,
                'autowire' => true,
            ],
            DeliveryController::class => [
                'class' => DeliveryController::class,
                'shared' => true,
                'autowire' => true,
            ],
            TaxonomyController::class => [
                'class' => TaxonomyController::class,
                'shared' => true,
                'autowire' => true,
            ],
            DeliveryAccessMiddleware::class => [
                'class' => DeliveryAccessMiddleware::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['lemma_delivery_access'],
            ],
            OptionalApiKeyAuthMiddleware::class => [
                'class' => OptionalApiKeyAuthMiddleware::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['optional_api_key'],
            ],
        ];
    }

    /**
     * Publish-pipeline listeners wired onto the event bus in registerEventListeners().
     * PurgeCdnListener and ReindexSearchListener are capability-gated no-ops in a lean
     * install (no glueful/cdn / content reindexer) — they self-skip at invocation.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function pipelineListenerServices(): array
    {
        return [
            PublishEventEmitter::class => [
                'class' => PublishEventEmitter::class,
                'shared' => true,
                'autowire' => true,
            ],
            InvalidateCacheTagsListener::class => [
                'class' => InvalidateCacheTagsListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublishedReferenceRepository::class => [
                'class' => PublishedReferenceRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            ProjectPublishedReferencesListener::class => [
                'class' => ProjectPublishedReferencesListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            DispatchWebhookListener::class => [
                'class' => DispatchWebhookListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            PurgeCdnListener::class => [
                'class' => PurgeCdnListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReindexSearchListener::class => [
                'class' => ReindexSearchListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            CollectionAuditListener::class => [
                'class' => CollectionAuditListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            AnalyticsBridgeListener::class => [
                'class' => AnalyticsBridgeListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            MediaUsageProjector::class => [
                'class' => MediaUsageProjector::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * Preview (the narrow draft door). Minter + reader derive the same APP_KEY signing
     * key; the controller wires the admin mint + public token read.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function previewServices(): array
    {
        return [
            PreviewMinter::class => [
                'class' => PreviewMinter::class,
                'shared' => true,
                'autowire' => true,
            ],
            PreviewReader::class => [
                'class' => PreviewReader::class,
                'shared' => true,
                'autowire' => true,
            ],
            PreviewSessionVerifier::class => [
                'class' => EnginePreviewSessionVerifier::class,
                'shared' => true,
                'autowire' => true,
            ],
            RichHtmlSanitizer::class => [
                'class' => TipTapHtmlSanitizer::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockEditableFieldResolver::class => [
                'class' => EngineBlockEditableFieldResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            SiteLogoProvider::class => [
                'class'    => EngineSiteLogoProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Global chrome regions (global-regions spec): storage + save
            // validation + the render-pack's soft-bound reader seam.
            RegionRepository::class => [
                'class'    => RegionRepository::class,
                'shared'   => true,
                'autowire' => true,
            ],
            RegionValidator::class => [
                'class'    => RegionValidator::class,
                'shared'   => true,
                'autowire' => true,
            ],
            RegionReader::class => [
                'class'    => EngineRegionReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            AdminUrlProvider::class => [
                'class'    => EngineAdminUrlProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            MediaUrlResolver::class => [
                'shared' => true,
                'factory' => [self::class, 'makeMediaUrlResolver'],
            ],
            // Factory (not autowire): the theme validator is a SOFT render-pack
            // binding — passed only when present, so core stays removability-clean.
            PreviewController::class => [
                'shared' => true,
                'factory' => [self::class, 'makePreviewController'],
            ],
            PreviewWorkingCopyStore::class => [
                'shared' => true,
                'factory' => [self::class, 'makePreviewWorkingCopyStore'],
            ],
        ];
    }

    public static function makePreviewController(ContainerInterface $container): PreviewController
    {
        return new PreviewController(
            $container->get(PreviewMinter::class),
            $container->get(PreviewReader::class),
            $container->get(ContentLocaleService::class),
            $container->get(ApplicationContext::class),
            $container->has(PreviewThemeValidator::class)
                ? $container->get(PreviewThemeValidator::class)
                : null,
        );
    }

    public static function makePreviewWorkingCopyStore(ContainerInterface $container): PreviewWorkingCopyStore
    {
        return new PreviewWorkingCopyStore($container->get(CacheStore::class));
    }

    public static function makeMediaUrlResolver(ContainerInterface $container): EngineMediaUrlResolver
    {
        $context = $container->get(ApplicationContext::class);
        return new EngineMediaUrlResolver(
            $container->get(Connection::class),
            api_prefix($context) . '/blobs',
            (bool) config($context, 'uploads.enabled', true),
            config($context, 'uploads.access', 'private'),
        );
    }

    /**
     * Full-graph snapshot export/import (the core `lemma.content` engine, tagged for the
     * import-export adapter registry) plus the admin import/export controller.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function importExportServices(): array
    {
        return [
            LemmaContentExporter::class => [
                'class' => LemmaContentExporter::class,
                'shared' => true,
                'autowire' => true,
                'tags' => ['import_export.exporter'],
            ],
            LemmaContentImporter::class => [
                'class' => LemmaContentImporter::class,
                'shared' => true,
                'autowire' => true,
                'tags' => ['import_export.importer'],
            ],
            ImportExportController::class => [
                'class' => ImportExportController::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * Background maintenance services: version retention pruning, destructive-schema
     * backfill, and the scheduled publish/unpublish runner.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function maintenanceServices(): array
    {
        return [
            VersionPruner::class => [
                'class' => VersionPruner::class,
                'shared' => true,
                'autowire' => true,
            ],
            BackfillRunner::class => [
                'class' => BackfillRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
            ScheduleRunner::class => [
                'class' => ScheduleRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * Content-domain HTTP controllers, plus RequireLemmaPermission under the
     * `lemma_permission` container alias — how `->middleware('lemma_permission:...')`
     * resolves (Router::resolveMiddleware() does container->get('lemma_permission')).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function contentControllerServices(): array
    {
        return [
            BlockTypeController::class => [
                'class' => BlockTypeController::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockMigrationController::class => [
                'class' => BlockMigrationController::class,
                'shared' => true,
                'autowire' => true,
            ],
            ContentTypeController::class => [
                'class' => ContentTypeController::class,
                'shared' => true,
                'autowire' => true,
            ],
            MigrationController::class => [
                'class' => MigrationController::class,
                'shared' => true,
                'autowire' => true,
            ],
            EntryController::class => [
                'class' => EntryController::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublicationController::class => [
                'class' => PublicationController::class,
                'shared' => true,
                'autowire' => true,
            ],
            RedirectController::class => [
                'class' => RedirectController::class,
                'shared' => true,
                'autowire' => true,
            ],
            ScheduleController::class => [
                'class' => ScheduleController::class,
                'shared' => true,
                'autowire' => true,
            ],
            LocaleAdminController::class => [
                'class' => LocaleAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            RequireLemmaPermission::class => [
                'class' => RequireLemmaPermission::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['lemma_permission'],
            ],
        ];
    }

    /**
     * Platform/admin HTTP controllers (config, users, extensions, media, API keys,
     * settings, cache, health, capabilities, scheduled tasks, setup) and the settings
     * stores they read.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function platformControllerServices(): array
    {
        return [
            AdminConfigController::class => [
                'class' => AdminConfigController::class,
                'shared' => true,
                'autowire' => true,
            ],
            UserAdminController::class => [
                'class' => UserAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            UserRoleAssignmentPolicy::class => [
                'class' => UserRoleAssignmentPolicy::class,
                'shared' => true,
                'autowire' => true,
            ],
            ExtensionAdminController::class => [
                'class' => ExtensionAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            MediaAdminController::class => [
                'class' => MediaAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            ApiKeyAdminController::class => [
                'class' => ApiKeyAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            GeneralSettingsController::class => [
                'class' => GeneralSettingsController::class,
                'shared' => true,
                'autowire' => true,
            ],
            RegionAdminController::class => [
                'class' => RegionAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            IconInventoryController::class => [
                'class' => IconInventoryController::class,
                'shared' => true,
                'autowire' => true,
            ],
            EmailSettingsController::class => [
                'class' => EmailSettingsController::class,
                'shared' => true,
                'autowire' => true,
            ],
            SettingsStore::class => [
                'class' => SettingsStore::class,
                'shared' => true,
                'autowire' => true,
            ],
            GeneralSettings::class => [
                'class' => GeneralSettings::class,
                'shared' => true,
                'autowire' => true,
            ],
            CacheAdminController::class => [
                'class' => CacheAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            HealthAdminController::class => [
                'class' => HealthAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            CapabilityAdminController::class => [
                'class' => CapabilityAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            ScheduledTasksController::class => [
                'class' => ScheduledTasksController::class,
                'shared' => true,
                'autowire' => true,
            ],
            SetupController::class => [
                'class' => SetupController::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * Console commands (also registered via commands() in boot()). Autowire fills each
     * command's BaseCommand (ContainerInterface, ApplicationContext) constructor.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function consoleCommandServices(): array
    {
        return [
            ResyncCommand::class => [
                'class' => ResyncCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            PruneVersionsCommand::class => [
                'class' => PruneVersionsCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            SeedBlockTypesCommand::class => [
                'class' => SeedBlockTypesCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            RunBlockBackfillCommand::class => [
                'class' => RunBlockBackfillCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            RunBackfillCommand::class => [
                'class' => RunBackfillCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            RunDueSchedulesCommand::class => [
                'class' => RunDueSchedulesCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            DoctorCommand::class => [
                'class' => DoctorCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            ProvisionCommand::class => [
                'class' => ProvisionCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            CreateAdminCommand::class => [
                'class' => CreateAdminCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // No-op: config/lemma.php is auto-loaded by the app config system, and DI
        // bindings are contributed declaratively via services(). Kept for lifecycle
        // symmetry and as the seam for future runtime registration.
    }

    public static function makeCapabilityRegistry(ContainerInterface $container): DefaultCapabilityRegistry
    {
        $context = $container->get(ApplicationContext::class);
        /** @var array<string,bool> $overrides */
        $overrides = (array) config($context, 'lemma.capabilities', []);

        return new DefaultCapabilityRegistry($overrides);
    }

    public static function makePathRenderer(ContainerInterface $container): PathRenderer
    {
        $context = $container->get(ApplicationContext::class);

        return new PathRenderer(
            (string) config($context, 'lemma.seo.route_template', '/{locale}/{type}/{slug}'),
            config($context, 'lemma.seo.public_url_base') === null
                ? null
                : (string) config($context, 'lemma.seo.public_url_base'),
            (string) config($context, 'i18n.default_locale', 'en')
        );
    }

    public static function makePublishService(ContainerInterface $c): PublishService
    {
        $gates = $c->has('lemma.publish_gate') ? $c->get('lemma.publish_gate') : [];
        if ($gates instanceof \Traversable) {
            $gates = iterator_to_array($gates);
        }
        return new PublishService(
            $c->get(ApplicationContext::class),
            $c->get(EntryRepository::class),
            $c->get(VersionRepository::class),
            $c->get(ContentTypeRepository::class),
            $c->get(FieldValidator::class),
            $c->get(ReferenceProjectionRepository::class),
            $c->has(PublishEventEmitter::class) ? $c->get(PublishEventEmitter::class) : null,
            $c->has(SchemaProjector::class) ? $c->get(SchemaProjector::class) : null,
            array_values(array_filter((array) $gates, static fn($g): bool => $g instanceof PublishGate)),
            $c->has(BlockMigrationGate::class) ? $c->get(BlockMigrationGate::class) : null,
            $c->has(BlockRestoreProjector::class) ? $c->get(BlockRestoreProjector::class) : null,
        );
    }

    public static function makeContentDeliveryReader(ContainerInterface $container): EngineContentDeliveryReader
    {
        return new EngineContentDeliveryReader(
            $container->get(DeliveryRepository::class),
            $container->get(CanonicalPathBuilder::class),
            $container->get(CanonicalProjector::class),
            $container->get(ContentTypeRepository::class),
        );
    }

    public static function makeIndexableContentReader(ContainerInterface $container): EngineIndexableContentReader
    {
        return new EngineIndexableContentReader(
            $container->get(DeliveryRepository::class),
            $container->get(CanonicalPathBuilder::class),
        );
    }

    public static function makeCanonicalProjector(ContainerInterface $container): CanonicalProjector
    {
        return new CanonicalProjector(
            $container->get(DeliveryRepository::class),
            $container->get(RouteRepository::class),
            $container->get(ContentTypeRepository::class),
            $container->get(CanonicalPathBuilder::class),
            (string) config($container->get(ApplicationContext::class), 'i18n.default_locale', 'en')
        );
    }

    public function boot(ApplicationContext $context): void
    {
        // Routes: routes/lemma_admin.php is auto-discovered by RouteManifest. Do NOT
        // call loadRoutesFrom() here — it would double-register the routes and the
        // Router throws on duplicate static paths.

        // These seed rows depend on Aegis' RBAC tables, whose extension migrations run
        // at DEPENDENT priority. Register the seeder in the same tier so it runs after
        // Aegis' lower-numbered migrations instead of before them as an app migration.
        $this->loadMigrationsFrom(
            dirname(__DIR__, 2) . '/database/dependent-migrations',
            MigrationPriority::DEPENDENT,
            'app:dependent'
        );

        // Mount the compiled admin SPA at /admin via the framework seam: secure asset serving
        // + index.html deep-link fallback + cache split. No-ops (with a warning) if the bundle
        // is unbuilt. The /admin/config + /admin/setup static routes (routes/lemma_admin_spa.php)
        // keep precedence over the SPA catch-all via the router's static-first lookup.
        // Gated by lemma.admin.enabled so an operator can disable the default admin and bring
        // their own (the admin is a replaceable client of the /v1/admin API).
        if ((bool) config($context, 'lemma.admin.enabled', true)) {
            $this->serveFrontend(
                '/admin',
                (string) config($context, 'lemma.admin.bundle_path', dirname(__DIR__, 2) . '/public/admin'),
                ['name' => 'Lemma Admin'],
            );
        }

        $this->registerEventListeners($context);

        EditorialFieldTypes::register(app($context, FieldTypeRegistry::class));

        // Console: register Lemma's app commands. commands() is a console-only no-op in
        // the HTTP phase (runningInConsole() guards it), so this is free during requests.
        $this->commands([
            ResyncCommand::class,
            PruneVersionsCommand::class,
            SeedBlockTypesCommand::class,
            RunBlockBackfillCommand::class,
            RunBackfillCommand::class,
            RunDueSchedulesCommand::class,
            DoctorCommand::class,
            ProvisionCommand::class,
            CreateAdminCommand::class,
        ]);
    }

    /**
     * Wire content-pipeline listeners onto the PSR-14 EventService.
     *
     * Listeners are registered lazily by service id ('@' . Listener::class): the
     * dispatcher resolves them from the container on first dispatch and invokes them as
     * callables (so each listener exposes __invoke($event)). This is the shared pattern
     * for every pipeline listener — extend $listeners with [eventClass => [...listeners]].
     */
    private function registerEventListeners(ApplicationContext $context): void
    {
        // addListener() appends with no dedup, so re-running this would double-fire every
        // listener — duplicate webhook deliveries. Refuse to register twice.
        if ($this->listenersRegistered) {
            return;
        }
        $this->listenersRegistered = true;

        $events = app($context, EventService::class);

        // `LemmaServiceProvider` (app provider) boots before `LemmaAnalyticsServiceProvider`
        // (pack provider), so CapabilityRegistry::isEnabled() would return false for
        // 'lemma.analytics' at this point (the capability is only registered during the pack's
        // own boot()). Read the capabilities override config directly instead — same semantics as
        // DefaultCapabilityRegistry::isEnabled() but without the "must be registered" prerequisite.
        $capOverrides = (array) config($context, 'lemma.capabilities', []);
        $analyticsOn = ($capOverrides['lemma.analytics'] ?? true) === true;

        // event class => list of listener service ids (lazy '@' form).
        //
        // PurgeCdnListener and ReindexSearchListener are CAPABILITY-GATED no-ops in a lean
        // install (no glueful/cdn / content reindexer): they self-skip at invocation, so wiring
        // them broadly is safe. PurgeCdnListener mirrors the cache listener's tag scope (entry
        // + model events, since both move lemma:type:{slug}). ReindexSearchListener is wired to
        // entry LIFECYCLE events only (publish/unpublish/update/delete) — the ones that change a
        // single entry's published index document; model/asset events don't.
        $listeners = [
            // Cache-tag invalidation (V1_DESIGN §5). Entry events drop the entry + type
            // tags; model events drop the type tag. ProjectPublishedReferencesListener
            // runs FIRST (listeners run in array order): the cache purge must see a
            // CURRENT published-reference projection, or a request racing the purge
            // could re-cache stale facet counts until the next event.
            EntryPublished::class => [
                ProjectPublishedReferencesListener::class,
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
            EntryUnpublished::class => [
                ProjectPublishedReferencesListener::class,
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
            EntryDeleted::class => [
                ProjectPublishedReferencesListener::class,
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
            EntryUpdated::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
            EntryCreated::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
            ],
            ModelCreated::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
            ],
            ModelUpdated::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
            ],
            ModelDeleted::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
            ],
            // Asset delta events (V1_DESIGN §8) are meaningful to external receivers
            // ("where is this asset used") but carry no cache tags — webhook only.
            AssetAttached::class => [DispatchWebhookListener::class, MediaUsageProjector::class],
            AssetDetached::class => [DispatchWebhookListener::class, MediaUsageProjector::class],
        ];

        // Collection row CRUD → audit log + analytics facts. Gated on the pack being INSTALLED
        // (class_exists) so removing the pack drops this wiring cleanly with no dangling reference.
        // CollectionAuditListener is unconditional (installed-gated only): a disabled-but-installed
        // analytics pack must still audit programmatic row mutations. AnalyticsBridgeListener is
        // ENABLED-gated: disabling lemma.analytics hard-stops collection ingestion, consistent with
        // the pack's auth listeners and the read API — no content or collection facts are written
        // while the capability is off (spec §7).
        if (class_exists(CollectionRowCreated::class)) {
            $listeners[CollectionRowCreated::class] = [CollectionAuditListener::class];
            $listeners[CollectionRowUpdated::class] = [CollectionAuditListener::class];
            $listeners[CollectionRowDeleted::class] = [CollectionAuditListener::class];
            $listeners[CollectionCreated::class] = [CollectionAuditListener::class];
            $listeners[CollectionUpdated::class] = [CollectionAuditListener::class];
            $listeners[CollectionDropped::class] = [CollectionAuditListener::class];

            if ($analyticsOn) {
                $listeners[CollectionRowCreated::class][] = AnalyticsBridgeListener::class;
                $listeners[CollectionRowUpdated::class][] = AnalyticsBridgeListener::class;
                $listeners[CollectionRowDeleted::class][] = AnalyticsBridgeListener::class;
                $listeners[CollectionCreated::class][] = AnalyticsBridgeListener::class;
                $listeners[CollectionUpdated::class][] = AnalyticsBridgeListener::class;
                $listeners[CollectionDropped::class][] = AnalyticsBridgeListener::class;
            }
        }

        // Content entry events → analytics facts. The analytics bridge is ENABLED-gated: disabling
        // lemma.analytics hard-stops content ingestion, consistent with the pack's auth listeners,
        // the collection block above, and the read API (spec §7). The audit bridge (CollectionAuditListener)
        // remains unconditional/installed-gated and is unaffected by this gate.
        if ($analyticsOn) {
            $listeners[EntryCreated::class][]    = AnalyticsBridgeListener::class;
            $listeners[EntryUpdated::class][]    = AnalyticsBridgeListener::class;
            $listeners[EntryDeleted::class][]    = AnalyticsBridgeListener::class;
            $listeners[EntryPublished::class][]  = AnalyticsBridgeListener::class;
            $listeners[EntryUnpublished::class][] = AnalyticsBridgeListener::class;
        }

        foreach ($listeners as $eventClass => $serviceIds) {
            foreach ($serviceIds as $serviceId) {
                $events->addListener($eventClass, '@' . $serviceId);
            }
        }
    }
}
