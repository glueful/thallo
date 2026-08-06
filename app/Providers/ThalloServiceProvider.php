<?php

declare(strict_types=1);

namespace App\Providers;

use App\Capabilities\DefaultCapabilityRegistry;
use App\Setup\SetupService;
use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\EngineMediaUrlResolver;
use App\Content\Delivery\EngineMediaVariantUrlResolver;
use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\ReferenceFilterResolver;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Delivery\SortCompiler;
use App\Content\Delivery\ThalloCanonicalPublicOriginResolver;
use App\Content\Forms\DefaultFormSealer;
use App\Content\Forms\FormFieldDerivation;
use App\Content\Forms\FormMailSender;
use App\Content\Forms\FormNotifier;
use App\Content\Forms\FormSubmissionRepository;
use App\Content\Forms\Spam\DefaultFormGuard;
use App\Content\Forms\Spam\FormSubmissionGuard;
use App\Content\Media\TenantBlobPolicy;
use App\Content\Media\TenantBlobPublicUrlProvider;
use App\Content\Media\TenantBlobRouteMiddlewareProvider;
use App\Content\Authorization\OperatorBypass;
use App\Content\Authorization\AuthenticatedPrincipalResolver;
use App\Content\Authorization\PermissionAuthority;
use App\Content\Authorization\CapabilityCatalog;
use App\Content\Authorization\BuiltinRoleAvailabilityRepository;
use App\Content\Authorization\EffectiveRoleEvaluator;
use App\Content\Authorization\EffectiveRoleMatrix;
use App\Content\Authorization\PermissionImplicationSource;
use App\Content\Authorization\PermissionRequirementAuthority;
use App\Content\Authorization\PolicyManifest;
use App\Content\Authorization\RolePolicyDiagnostics;
use App\Content\Authorization\RoleMatrix;
use App\Content\Authorization\TenantMembershipRoleReader;
use App\Content\Authorization\TenantRoleOverrideRepository;
use App\Content\Authorization\TenantRolePolicyMutator;
use App\Content\Authorization\TenantRoleRepository;
use App\Content\Authorization\TenantRoleLifecycle;
use App\Content\Authorization\ThalloMembershipRoleAuthority;
use App\Http\Middleware\AdminTenantBindingMiddleware;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobCreatedHook;
use Glueful\Uploader\Contracts\BlobPublicUrlProvider;
use Glueful\Uploader\Contracts\BlobRouteMiddlewareProvider;
use Glueful\Uploader\Contracts\MediaProcessorInterface;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Tenancy\Events\DomainReverificationFailed;
use Glueful\Extensions\Tenancy\Events\DomainReverified;
use Glueful\Extensions\Tenancy\Events\DomainRevoked;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleAuthority;
use Thallo\Contracts\Content\FormSealer;
use Thallo\Tenancy\Reverification\DomainReverificationAuditListener;
use App\Content\Console\PruneVersionsCommand;
use App\Content\Console\PolicyManifestCommand;
use App\Content\Console\RetireAccountLinkCommand;
use App\Content\Console\RunBlockBackfillCommand;
use App\Content\Console\SeedBlockTypesCommand;
use App\Content\Console\SyncBlockTypesCommand;
use App\Content\Console\ResyncCommand;
use App\Content\Console\RunBackfillCommand;
use App\Content\Console\RunDueSchedulesCommand;
use App\Setup\Console\CreateAdminCommand;
use App\Setup\Console\DoctorCommand;
use App\Setup\Console\ProvisionCommand;
use App\Setup\Console\SuperuserGrantCommand;
use App\Setup\Console\SuperuserTransferCommand;
use App\Content\Backfill\BackfillRunner;
use App\Content\Indexing\FilterIndexJobDispatcher;
use App\Http\Controllers\AdminConfigController;
use App\Http\Controllers\AssignableRolesController;
use App\Http\Controllers\ApiKeyAdminController;
use App\Http\Controllers\CacheAdminController;
use App\Http\Controllers\CapabilityAdminController;
use App\Http\Controllers\ExtensionAdminController;
use App\Http\Controllers\FormSubmissionsController;
use App\Http\Controllers\FormSubmitController;
use App\Http\Controllers\GeneralSettingsController;
use App\Http\Controllers\HealthAdminController;
use App\Http\Controllers\IconInventoryController;
use App\Http\Controllers\ImportExportController;
use App\Http\Controllers\MediaAdminController;
use App\Http\Controllers\RegionAdminController;
use App\Http\Controllers\ScheduledTasksController;
use App\Http\Controllers\TenancyAccessController;
use App\Http\Controllers\UserAdminController;
use App\Http\Controllers\TenantHostCooldownController;
use App\Http\Controllers\TenantRolesController;
use App\Http\Controllers\SignupController;
use App\Signup\ContinuationTokens;
use App\Signup\CustomerSignupService;
use App\Signup\DefaultSignupDiagnostics;
use App\Signup\MemberSignupService;
use App\Signup\NullSignupChallenge;
use App\Signup\RejectingSignupChallenge;
use App\Signup\SignupChallenge;
use App\Signup\SignupConfig;
use App\Signup\SignupCoordinator;
use App\Signup\SignupIntentRepository;
use App\Signup\SignupMailSender;
use App\Signup\VerifiedAccountActivator;
use App\Signup\SignupRolePolicy;
use App\Signup\SignupTelemetry;
use App\Signup\SignupThrottle;
use App\Signup\SignupVerifier;
use App\Signup\WorkspaceSignupService;
use Thallo\Contracts\Tenancy\SignupDiagnostics;
use App\Support\AuthorityAudit;
use App\Support\AuthorityContinuityGuard;
use App\Support\AuthorityMutator;
use App\Support\RoleAuthority;
use App\Support\UserRoleAssignmentPolicy;
use App\Support\TenancyLifecycleAudit;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit as TenancyLifecycleAuditContract;
use Thallo\Contracts\Tenancy\RolePolicyDiagnostics as RolePolicyDiagnosticsContract;
use App\Settings\GeneralSettings;
use App\Settings\SettingsStore;
use App\Settings\SystemKeyReconciler;
use Thallo\Contracts\Settings\SystemKeyReconciler as SystemKeyReconcilerContract;
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
use App\Content\ImportExport\ContentExporter;
use App\Content\ImportExport\ContentImporter;
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
use App\Content\Http\RequirePermission;
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
use App\Content\Pipeline\Listeners\SeoMetaChangedListener;
use Thallo\Contracts\Seo\SeoMetaChanged;
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
use App\Content\Seo\EngineSeoHeadProvider;
use App\Content\Routing\RootMountGuard;
use App\Content\Seo\CanonicalPathBuilder;
use App\Settings\EngineAdminUrlProvider;
use App\Settings\EngineSiteFaviconProvider;
use App\Settings\EngineThemeAppearanceProvider;
use App\Settings\EngineThemeSettingProvider;
use App\Settings\EngineSiteLogoProvider;
use App\Content\Seo\PathRenderer;
use App\Content\Seo\RedirectRepository;
use App\Content\Seo\RouteResolver;
use App\Content\Services\MigrationService;
use App\Content\Authoring\EngineContentWriter;
use App\Content\Authoring\EngineDraftSummaryReader;
use App\Content\Authoring\EngineEntryExistenceReader;
use App\Content\Delivery\EngineEntryTargetResolver;
use App\Content\Context\EngineContext;
use App\Content\Delivery\EngineContentDeliveryReader;
use App\Content\Delivery\EngineEntryListReader;
use App\Content\Delivery\EngineFacetCountsReader;
use App\Content\Delivery\EngineIndexableContentReader;
use App\Content\Delivery\EnginePublishedEntryBlocksReader;
use App\Content\Schema\FieldTypes\DefaultFieldTypeRegistry;
use App\Content\Schema\FieldTypes\EditorialFieldTypes;
use App\Content\Services\PublishService;
use App\Content\Sanitization\TipTapHtmlSanitizer;
use App\Content\Validation\FieldValidator;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Contracts\Authorization\PermissionRequirementAuthority as PermissionRequirementAuthorityContract;
use Thallo\Contracts\Content\BlockEditableFieldResolver;
use Thallo\Contracts\Content\EntryExistenceReader;
use Thallo\Contracts\Content\RegionReader;
use Thallo\Contracts\Content\RichHtmlSanitizer;
use Thallo\Contracts\Authoring\DraftSummaryReader;
use Thallo\Contracts\Authoring\PublishGate;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Delivery\HomepageEntryProvider;
use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Contracts\Delivery\MediaVariantUrlResolver;
use Thallo\Contracts\Delivery\SeoHeadResolver;
use Thallo\Seo\Meta\SeoMetaResolver;
use Thallo\Contracts\Settings\AdminUrlProvider;
use Thallo\Contracts\Settings\SiteFaviconProvider;
use Thallo\Contracts\Settings\SiteLogoProvider;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Contracts\Settings\ThemeSettingProvider;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Context\Context;
use Thallo\Contracts\Delivery\ContentDeliveryReader;
use Thallo\Contracts\Delivery\EntryListReader;
use Thallo\Contracts\Delivery\FacetCountsReader;
use Thallo\Contracts\Delivery\PreviewSessionVerifier;
use Thallo\Contracts\Delivery\PreviewThemeValidator;
use Thallo\Contracts\Delivery\PublishedEntryBlocksReader;
use Thallo\Contracts\Delivery\ReferenceTargetResolver;
use Thallo\Contracts\Search\IndexableContentReader;
use Thallo\Contracts\Schema\FieldTypeRegistry;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Tenancy\System\SystemFlags;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Events\EventService;
use Glueful\Permissions\PermissionManager;
use Thallo\Collections\Events\CollectionCreated;
use Thallo\Collections\Events\CollectionDropped;
use Thallo\Collections\Events\CollectionRowCreated;
use Thallo\Collections\Events\CollectionRowDeleted;
use Thallo\Collections\Events\CollectionRowUpdated;
use Thallo\Collections\Events\CollectionUpdated;
use Glueful\Extensions\ServiceProvider;
use Glueful\Support\FieldSelection\Projector;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires the Thallo content engine into the application container.
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
 * Routes: routes/admin.php is NOT loaded here. The framework's RouteManifest
 * auto-discovers every routes/*.php file (underscore-prefixed partials excepted) during
 * the HTTP phase, which already runs AFTER extension boot(). Calling loadRoutesFrom()
 * in boot() would load the file a second time and the Router throws LogicException on a
 * duplicate static route. Auto-discovery is the framework's real mechanism for app routes.
 *
 * Config: config/thallo.php lives in the app config directory and is loaded by the
 * file-based config system, so it is already available as config('thallo.*'); mergeConfig
 * is therefore unnecessary (it would only re-supply the same values as defaults).
 */
final class ThalloServiceProvider extends ServiceProvider
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
            self::starterServices(),
            self::seoServices(),
            self::deliveryServices(),
            self::pipelineListenerServices(),
            self::previewServices(),
            self::importExportServices(),
            self::maintenanceServices(),
            self::contentControllerServices(),
            self::platformControllerServices(),
            self::consoleCommandServices(),
            self::formServices(),
            self::signupServices(),
            self::accountServices(),
        );
    }

    /**
     * Storefront account contracts, implemented by the app over the signup pipeline and the users
     * extension. The account PACK consumes only these interfaces, never `App\Signup`.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function accountServices(): array
    {
        $bind = static fn (string $impl): array => [
            'class' => $impl,
            'shared' => true,
            'autowire' => true,
        ];

        return [
            \Thallo\Contracts\Account\StorefrontAccountRegistration::class =>
                $bind(\App\Account\AppStorefrontAccountRegistration::class),
            \Thallo\Contracts\Account\StorefrontAccountRecovery::class =>
                $bind(\App\Account\AppStorefrontAccountRecovery::class),
            \Thallo\Contracts\Account\AccountNavigationRegistry::class =>
                $bind(\App\Account\InMemoryAccountNavigationRegistry::class),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function signupServices(): array
    {
        $autowired = static fn (string $class): array => [
            'class' => $class,
            'shared' => true,
            'autowire' => true,
        ];

        return [
            SignupConfig::class => $autowired(SignupConfig::class),
            SignupRolePolicy::class => $autowired(SignupRolePolicy::class),
            SignupIntentRepository::class => $autowired(SignupIntentRepository::class),
            SignupMailSender::class => $autowired(SignupMailSender::class),
            SignupTelemetry::class => $autowired(SignupTelemetry::class),
            SignupVerifier::class => $autowired(SignupVerifier::class),
            ContinuationTokens::class => $autowired(ContinuationTokens::class),
            SignupThrottle::class => $autowired(SignupThrottle::class),
            VerifiedAccountActivator::class => $autowired(VerifiedAccountActivator::class),
            MemberSignupService::class => $autowired(MemberSignupService::class),
            CustomerSignupService::class => $autowired(CustomerSignupService::class),
            WorkspaceSignupService::class => $autowired(WorkspaceSignupService::class),
            SignupCoordinator::class => $autowired(SignupCoordinator::class),
            SignupController::class => $autowired(SignupController::class),
            DefaultSignupDiagnostics::class => $autowired(DefaultSignupDiagnostics::class),
            SignupDiagnostics::class => [
                'factory' => static fn (ContainerInterface $container): SignupDiagnostics =>
                    $container->get(DefaultSignupDiagnostics::class),
                'shared' => true,
            ],
            SignupChallenge::class => [
                'factory' => [self::class, 'makeSignupChallenge'],
                'shared' => true,
            ],
        ];
    }

    public static function makeSignupChallenge(ContainerInterface $container): SignupChallenge
    {
        $context = $container->get(ApplicationContext::class);
        $provider = trim((string) config($context, 'signup.challenge.provider', ''));
        if ($provider === '') {
            return new NullSignupChallenge();
        }
        try {
            $challenge = $container->has($provider) ? $container->get($provider) : null;
            return $challenge instanceof SignupChallenge ? $challenge : new RejectingSignupChallenge();
        } catch (\Throwable) {
            return new RejectingSignupChallenge();
        }
    }

    /**
     * Form block backend (form-block spec): the sealed-descriptor sealer. Config-derived
     * (encryption key, descriptor lifetime vs render cache TTL, default recipient, time-trap
     * floor), so it needs a factory rather than autowire.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function formServices(): array
    {
        return [
            FormSealer::class => [
                'factory' => [self::class, 'makeFormSealer'],
                'shared' => true,
            ],
            FormSubmissionRepository::class => [
                'class' => FormSubmissionRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            DefaultFormGuard::class => [
                'factory' => [self::class, 'makeFormGuard'],
                'shared' => true,
            ],
            FormSubmissionGuard::class => [
                'factory' => [self::class, 'makeFormGuard'],
                'shared' => true,
            ],
            FormNotifier::class => [
                'factory' => [self::class, 'makeFormNotifier'],
                'shared' => true,
            ],
            FormSubmitController::class => [
                'class' => FormSubmitController::class,
                'shared' => true,
                'autowire' => true,
            ],
            FormSubmissionsController::class => [
                'class' => FormSubmissionsController::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public static function makeFormNotifier(ContainerInterface $container): FormNotifier
    {
        // FormMailSender is a soft seam: unbound → the notifier no-ops (spec §10).
        $sender = $container->has(FormMailSender::class) ? $container->get(FormMailSender::class) : null;
        return new FormNotifier(
            $sender instanceof FormMailSender ? $sender : null,
            $container->get(LoggerInterface::class),
        );
    }

    public static function makeFormGuard(ContainerInterface $container): DefaultFormGuard
    {
        $context = $container->get(ApplicationContext::class);
        return new DefaultFormGuard(
            $container->get(CacheStore::class),
            rateMax: (int) config($context, 'forms.rate_limit.max', 5),
            rateWindow: (int) config($context, 'forms.rate_limit.window', 60),
        );
    }

    public static function makeFormSealer(ContainerInterface $container): DefaultFormSealer
    {
        $context = $container->get(ApplicationContext::class);
        return new DefaultFormSealer(
            $container->get(EncryptionService::class),
            static fn (array $data): array => FormFieldDerivation::derive($data),
            cacheTtl: (int) config($context, 'render.cache_ttl', 3600),
            maxAge: (int) config($context, 'forms.descriptor_max_age', 1209600),
            buffer: (int) config($context, 'forms.descriptor_buffer', 3600),
            defaultRecipient: (string) config($context, 'forms.default_recipient', ''),
            minSeconds: (int) config($context, 'forms.min_seconds', 2),
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
     * (ContentWriter, ContentDeliveryReader, Context, ContentTypeReader), plus
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
                // Factory (not autowire): collects tag-registered thallo.publish_gate services
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
            EntryExistenceReader::class => [
                'class'    => EngineEntryExistenceReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \App\Content\Delivery\DeliveryItemShaper::class => [
                'class'    => \App\Content\Delivery\DeliveryItemShaper::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \App\Content\Delivery\ListingItemShaper::class => [
                'class'    => \App\Content\Delivery\ListingItemShaper::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \Thallo\Contracts\Delivery\HomepageEntryProvider::class => [
                'class'    => \App\Content\Delivery\EngineHomepageEntryProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \Thallo\Contracts\Delivery\PublicRouteResolver::class => [
                'class'    => \App\Content\Delivery\EnginePublicRouteResolver::class,
                'shared'   => true,
                'autowire' => true,
            ],
            FacetCountsReader::class => [
                'class'    => EngineFacetCountsReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            EntryListReader::class => [
                'class'    => EngineEntryListReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Commerce-Slice-2 Fix B: route-independent, tenant-scoped, published-only entry
            // read — the seam Thallo\Render\EntryBlocksRenderer composes to render a
            // route-less linked entry's blocks region (PublicRouteResolver::resolveEntry()
            // requires a live entry_routes row and cannot serve one).
            PublishedEntryBlocksReader::class => [
                'class'    => EnginePublishedEntryBlocksReader::class,
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
            Context::class => [
                'class'    => EngineContext::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \Thallo\Contracts\Schema\ContentTypeReader::class => [
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

    /** @return array<string, array<string, mixed>> */
    private static function starterServices(): array
    {
        $autowired = static fn(string $class): array => [
            'class' => $class,
            'shared' => true,
            'autowire' => true,
        ];

        return [
            \Thallo\Contracts\Starter\StarterContributorRegistry::class => $autowired(
                \App\Content\Starter\DefaultStarterContributorRegistry::class
            ),
            \Thallo\Contracts\Starter\StarterBlockTypeRegistry::class => $autowired(
                \App\Content\Starter\DefaultStarterBlockTypeRegistry::class
            ),
            \App\Content\Starter\Kinds\ContentTypeKind::class => $autowired(
                \App\Content\Starter\Kinds\ContentTypeKind::class
            ),
            \App\Content\Starter\Kinds\BlockTypeKind::class => $autowired(
                \App\Content\Starter\Kinds\BlockTypeKind::class
            ),
            \App\Content\Starter\Kinds\SettingKind::class => $autowired(
                \App\Content\Starter\Kinds\SettingKind::class
            ),
            \App\Content\Starter\Kinds\RegionKind::class => $autowired(
                \App\Content\Starter\Kinds\RegionKind::class
            ),
            \App\Content\Starter\Kinds\NavigationMenuKind::class => $autowired(
                \App\Content\Starter\Kinds\NavigationMenuKind::class
            ),
            \App\Content\Starter\Kinds\HomepageEntryKind::class => $autowired(
                \App\Content\Starter\Kinds\HomepageEntryKind::class
            ),
            \App\Content\Starter\StarterProvenanceRepository::class => $autowired(
                \App\Content\Starter\StarterProvenanceRepository::class
            ),
            \App\Content\Starter\StarterTransaction::class => $autowired(
                \App\Content\Starter\StarterTransaction::class
            ),
            \App\Content\Starter\StarterDefinitions::class => [
                'factory' => [self::class, 'makeStarterDefinitions'],
                'shared' => true,
            ],
            \App\Content\Starter\TenantSeeder::class => $autowired(
                \App\Content\Starter\TenantSeeder::class
            ),
            \App\Content\Starter\StarterSync::class => $autowired(\App\Content\Starter\StarterSync::class),
            \App\Content\Starter\DefaultStarterCoverageCheck::class => $autowired(
                \App\Content\Starter\DefaultStarterCoverageCheck::class
            ),
            \Thallo\Tenancy\Contracts\TenantSeedActivator::class => [
                'factory' => [self::class, 'makeTenantSeeder'],
                'shared' => true,
            ],
            \Thallo\Tenancy\Contracts\TenantSeedRepair::class => [
                'factory' => [self::class, 'makeTenantSeeder'],
                'shared' => true,
            ],
            \Thallo\Tenancy\Contracts\TenantStarterSync::class => [
                'factory' => [self::class, 'makeStarterSync'],
                'shared' => true,
            ],
            \Thallo\Tenancy\Contracts\StarterCoverageCheck::class => [
                'factory' => [self::class, 'makeStarterCoverageCheck'],
                'shared' => true,
            ],
            \App\Content\Starter\RawPdoWriteAudit::class => [
                'factory' => [self::class, 'makeRawPdoWriteAudit'],
                'shared' => true,
            ],
            \Thallo\Tenancy\Contracts\StaticWriteAudit::class => [
                'factory' => [self::class, 'makeRawPdoWriteAudit'],
                'shared' => true,
            ],
        ];
    }

    public static function makeStarterDefinitions(
        ContainerInterface $container
    ): \App\Content\Starter\StarterDefinitions {
        return new \App\Content\Starter\StarterDefinitions(
            $container->get(\App\Content\Starter\Kinds\ContentTypeKind::class),
            $container->get(\App\Content\Starter\Kinds\BlockTypeKind::class),
            $container->get(\App\Content\Starter\Kinds\SettingKind::class),
            $container->get(\App\Content\Starter\Kinds\RegionKind::class),
            $container->get(\App\Content\Starter\Kinds\NavigationMenuKind::class),
            $container->get(\App\Content\Starter\Kinds\HomepageEntryKind::class),
        );
    }

    public static function makeTenantSeeder(ContainerInterface $container): \App\Content\Starter\TenantSeeder
    {
        return $container->get(\App\Content\Starter\TenantSeeder::class);
    }

    public static function makeStarterSync(ContainerInterface $container): \App\Content\Starter\StarterSync
    {
        return $container->get(\App\Content\Starter\StarterSync::class);
    }

    public static function makeStarterCoverageCheck(
        ContainerInterface $container
    ): \Thallo\Tenancy\Contracts\StarterCoverageCheck {
        return $container->get(\App\Content\Starter\DefaultStarterCoverageCheck::class);
    }

    public static function makeRawPdoWriteAudit(
        ContainerInterface $container
    ): \App\Content\Starter\RawPdoWriteAudit {
        $context = $container->get(\Glueful\Bootstrap\ApplicationContext::class);
        return new \App\Content\Starter\RawPdoWriteAudit(base_path($context));
    }

    /**
     * Headless SEO/routing: path rendering, route resolution, and canonical-URL
     * projection (the factory-built services derive their config from thallo.seo.*).
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
            SeoHeadResolver::class => [
                'factory' => [self::class, 'makeSeoHeadProvider'],
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
                'alias' => ['delivery_access'],
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
            SeoMetaChangedListener::class => [
                'class' => SeoMetaChangedListener::class,
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
            SiteFaviconProvider::class => [
                'class'    => EngineSiteFaviconProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Live theme override (theme-setting spec §2): RAW-row provider —
            // the render pack's ActiveThemeSource soft-binds it.
            ThemeSettingProvider::class => [
                'class'    => EngineThemeSettingProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Theme color config (theme-color-config spec §4): saved accent/neutral
            // provider — the render pack's ThemeAppearanceSource soft-binds it.
            ThemeAppearanceProvider::class => [
                'class'    => EngineThemeAppearanceProvider::class,
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
            // One object, two interfaces: the batch seam IS the single-url
            // resolver, so the servability predicate cannot drift between them.
            MediaUrlBatchResolver::class => [
                'shared' => true,
                'factory' => [self::class, 'makeMediaUrlBatchResolver'],
            ],
            MediaVariantUrlResolver::class => [
                'shared' => true,
                'factory' => [self::class, 'makeMediaVariantUrlResolver'],
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
        return new PreviewWorkingCopyStore(
            $container->get(CacheStore::class),
            $container->get(\Thallo\Tenancy\Cache\TenantCacheSegment::class),
            $container->get(ApplicationContext::class),
        );
    }

    public static function makeSystemKeyReconciler(ContainerInterface $container): SystemKeyReconcilerContract
    {
        return $container->get(SystemKeyReconciler::class);
    }

    public static function makeTenantBlobPolicy(ContainerInterface $container): TenantBlobPolicy
    {
        $resolver = $container->has(CurrentTenantResolver::class)
            ? $container->get(CurrentTenantResolver::class)
            : null;

        return new TenantBlobPolicy(
            $container->get(ApplicationContext::class),
            $container->get(Connection::class),
            $container->get(SystemFlags::class),
            $container->get(TenantRuntimeReadiness::class),
            $container->get(WriteBarrier::class),
            $resolver,
            $container->has(\Thallo\Contracts\Tenancy\TenantWriteScope::class)
                ? $container->get(\Thallo\Contracts\Tenancy\TenantWriteScope::class)
                : null,
        );
    }

    public static function makeBlobCreatedHook(ContainerInterface $container): BlobCreatedHook
    {
        return $container->get(TenantBlobPolicy::class);
    }

    public static function makeBlobAccessPolicy(ContainerInterface $container): BlobAccessPolicy
    {
        return $container->get(TenantBlobPolicy::class);
    }

    public static function makeBlobPublicUrlProvider(ContainerInterface $container): BlobPublicUrlProvider
    {
        return new TenantBlobPublicUrlProvider(
            $container->get(ApplicationContext::class),
            $container->get(Connection::class),
            $container->get(SystemFlags::class),
            $container->has(FullTenantResolutionReadiness::class)
                ? $container->get(FullTenantResolutionReadiness::class)
                : null,
            $container->has(TenantAdministration::class)
                ? $container->get(TenantAdministration::class)
                : null,
            $container->has(TenantDomainAdministration::class)
                ? $container->get(TenantDomainAdministration::class)
                : null,
        );
    }

    public static function makeCanonicalPublicOriginResolver(
        ContainerInterface $container
    ): CanonicalPublicOriginResolver {
        return new ThalloCanonicalPublicOriginResolver(
            $container->get(SystemFlags::class),
            $container->has(CurrentTenantResolver::class)
                ? $container->get(CurrentTenantResolver::class)
                : null,
            $container->has(TenantAdministration::class)
                ? $container->get(TenantAdministration::class)
                : null,
            $container->has(TenantDomainAdministration::class)
                ? $container->get(TenantDomainAdministration::class)
                : null,
        );
    }

    public static function makeBlobRouteMiddlewareProvider(
        ContainerInterface $container
    ): BlobRouteMiddlewareProvider {
        return new TenantBlobRouteMiddlewareProvider();
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

    /** The batch interface resolves to the SHARED MediaUrlResolver instance. */
    public static function makeMediaUrlBatchResolver(ContainerInterface $container): EngineMediaUrlResolver
    {
        return $container->get(MediaUrlResolver::class);
    }

    public static function makeMediaVariantUrlResolver(ContainerInterface $container): EngineMediaVariantUrlResolver
    {
        $context = $container->get(ApplicationContext::class);
        // Candidate-generation gate mirrors UploadController::serveBlob's own check
        // (spec §3): a processor is bound AND uploads.image_processing.enabled. The resolver
        // itself stays bound so its MIME gate still omits invalid media. Incapable → valid
        // images degrade to {src, srcset: null}; never fabricate ?width= URLs.
        $capable = $container->has(MediaProcessorInterface::class)
            && (bool) config($context, 'uploads.image_processing.enabled', true);

        return new EngineMediaVariantUrlResolver(
            $container->get(Connection::class),
            api_prefix($context) . '/blobs',
            (bool) config($context, 'uploads.enabled', true),
            config($context, 'uploads.access', 'private'),
            (int) config($context, 'uploads.image_processing.max_width', 2048),
            $capable,
        );
    }

    /**
     * Full-graph snapshot export/import (the core `thallo.content` engine, tagged for the
     * import-export adapter registry) plus the admin import/export controller.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function importExportServices(): array
    {
        return [
            ContentExporter::class => [
                'class' => ContentExporter::class,
                'shared' => true,
                'autowire' => true,
                'tags' => ['import_export.exporter'],
            ],
            ContentImporter::class => [
                'class' => ContentImporter::class,
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
            FilterIndexJobDispatcher::class => [
                'class' => FilterIndexJobDispatcher::class,
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
     * Content-domain HTTP controllers, plus RequirePermission under the
     * `content_permission` container alias — how `->middleware('content_permission:...')`
     * resolves (Router::resolveMiddleware() does container->get('content_permission')).
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
            RequirePermission::class => [
                'factory' => [self::class, 'makeRequirePermission'],
                'shared' => true,
                'alias' => ['content_permission'],
            ],
            PermissionRequirementAuthority::class => [
                'factory' => [self::class, 'makePermissionRequirementAuthority'],
                'shared' => true,
                // Task 8 (admin-commerce-area plan, slice 3): aliased to the neutral
                // Thallo\Contracts\Authorization\PermissionRequirementAuthority contract so a
                // first-party pack (e.g. thallo-commerce's `/meta` endpoint) can depend on the
                // SAME shared instance without referencing this `App\` namespace directly — packs
                // may not depend on the engine app. The alias belongs on THIS (the concrete)
                // definition, not a separate binding for the contract — mirrors
                // packSlugLifecycleAuthorityDefinition()'s identical reasoning in
                // CommerceIntegrationServiceProvider.
                'alias' => [PermissionRequirementAuthorityContract::class],
            ],
            AdminTenantBindingMiddleware::class => [
                'factory' => [self::class, 'makeAdminTenantBinding'],
                'shared' => true,
                'alias' => ['admin_tenant_binding'],
            ],
            RoleMatrix::class => [
                'class' => RoleMatrix::class,
                'shared' => true,
                'autowire' => true,
            ],
            CapabilityCatalog::class => [
                'class' => CapabilityCatalog::class,
                'shared' => true,
                'autowire' => true,
            ],
            // The catalog IS the production PermissionImplicationSource (its `implies`
            // vocabulary drives satisfiersFor()) — bind through a factory that resolves
            // the SAME shared CapabilityCatalog instance rather than a second one.
            PermissionImplicationSource::class => [
                'factory' => static fn (ContainerInterface $container): PermissionImplicationSource =>
                    $container->get(CapabilityCatalog::class),
                'shared' => true,
            ],
            PolicyManifest::class => [
                'class' => PolicyManifest::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantRoleOverrideRepository::class => [
                'class' => TenantRoleOverrideRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantRoleRepository::class => [
                'class' => TenantRoleRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            BuiltinRoleAvailabilityRepository::class => [
                'class' => BuiltinRoleAvailabilityRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantRoleLifecycle::class => [
                'class' => TenantRoleLifecycle::class,
                'shared' => true,
                'autowire' => true,
            ],
            ThalloMembershipRoleAuthority::class => [
                'class' => ThalloMembershipRoleAuthority::class,
                'shared' => true,
                'autowire' => true,
            ],
            EffectiveRoleEvaluator::class => [
                'class' => EffectiveRoleEvaluator::class,
                'shared' => true,
                'autowire' => true,
            ],
            EffectiveRoleMatrix::class => [
                'class' => EffectiveRoleMatrix::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantRolePolicyMutator::class => [
                'class' => TenantRolePolicyMutator::class,
                'shared' => true,
                'autowire' => true,
            ],
            RolePolicyDiagnostics::class => [
                'class' => RolePolicyDiagnostics::class,
                'shared' => true,
                'autowire' => true,
            ],
            RolePolicyDiagnosticsContract::class => [
                'class' => RolePolicyDiagnostics::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantMembershipRoleReader::class => [
                'factory' => [self::class, 'makeMembershipRoleReader'],
                'shared' => true,
            ],
            OperatorBypass::class => [
                'factory' => [self::class, 'makeOperatorBypass'],
                'shared' => true,
            ],
            AuthenticatedPrincipalResolver::class => [
                'class' => AuthenticatedPrincipalResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            PermissionAuthority::class => [
                'class' => PermissionAuthority::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public static function makeMembershipRoleReader(ContainerInterface $container): TenantMembershipRoleReader
    {
        return new TenantMembershipRoleReader(
            $container->get(ApplicationContext::class),
            $container->has(CurrentTenantResolver::class)
                ? $container->get(CurrentTenantResolver::class)
                : null,
        );
    }

    public static function makeOperatorBypass(ContainerInterface $container): OperatorBypass
    {
        $permissions = $container->has(PermissionManager::class)
            ? $container->get(PermissionManager::class)
            : ($container->has('permission.manager') ? $container->get('permission.manager') : null);
        return new OperatorBypass(
            $container->get(ApplicationContext::class),
            $permissions instanceof PermissionManager ? $permissions : null,
            $container->has(AuditRecorderInterface::class)
                ? $container->get(AuditRecorderInterface::class)
                : null,
        );
    }

    public static function makeAuthorityAudit(ContainerInterface $container): AuthorityAudit
    {
        return new AuthorityAudit(
            $container->has(AuditRecorderInterface::class)
                ? $container->get(AuditRecorderInterface::class)
                : null,
        );
    }

    public static function makeTenancyLifecycleAudit(ContainerInterface $container): TenancyLifecycleAuditContract
    {
        return new TenancyLifecycleAudit(
            $container->has(AuditRecorderInterface::class)
                ? $container->get(AuditRecorderInterface::class)
                : null,
        );
    }

    public static function makeRequirePermission(ContainerInterface $container): RequirePermission
    {
        return new RequirePermission(
            $container->get(ApplicationContext::class),
            $container->get(PermissionRequirementAuthority::class),
        );
    }

    public static function makePermissionRequirementAuthority(
        ContainerInterface $container,
    ): PermissionRequirementAuthority {
        return new PermissionRequirementAuthority(
            $container->get(ApplicationContext::class),
            // Identity implications until a declarative source is bound (the capability
            // catalog becomes the production PermissionImplicationSource).
            $container->has(PermissionImplicationSource::class)
                ? $container->get(PermissionImplicationSource::class)
                : null,
            $container->get(TenantMembershipRoleReader::class),
            $container->get(EffectiveRoleMatrix::class),
            $container->get(OperatorBypass::class),
            $container->get(AuthenticatedPrincipalResolver::class),
            $container->get(PermissionAuthority::class),
        );
    }

    public static function makeAdminTenantBinding(ContainerInterface $container): AdminTenantBindingMiddleware
    {
        return new AdminTenantBindingMiddleware(
            $container->get(ApplicationContext::class),
            $container->get(AuthenticatedPrincipalResolver::class),
            $container->get(PermissionAuthority::class),
            $container->has(TenantAdministration::class)
                ? $container->get(TenantAdministration::class)
                : null,
            $container->has(TenantContextRunner::class)
                ? $container->get(TenantContextRunner::class)
                : null,
            $container->has(FullTenantResolutionReadiness::class)
                ? $container->get(FullTenantResolutionReadiness::class)
                : null,
        );
    }

    public static function makeTenancyAccessController(ContainerInterface $container): TenancyAccessController
    {
        return new TenancyAccessController(
            $container->get(AuthenticatedPrincipalResolver::class),
            $container->get(PermissionAuthority::class),
            $container->has(EffectiveRoleMatrix::class)
                ? $container->get(EffectiveRoleMatrix::class)
                : null,
            $container->has(TenantMembershipRoleReader::class)
                ? $container->get(TenantMembershipRoleReader::class)
                : null,
            $container->has(OperatorBypass::class) ? $container->get(OperatorBypass::class) : null,
        );
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
            AssignableRolesController::class => [
                'class' => AssignableRolesController::class,
                'shared' => true,
                'autowire' => true,
            ],
            UserRoleAssignmentPolicy::class => [
                'class' => UserRoleAssignmentPolicy::class,
                'shared' => true,
                'autowire' => true,
            ],
            RoleAuthority::class => [
                'class' => RoleAuthority::class,
                'shared' => true,
                'autowire' => true,
            ],
            AuthorityAudit::class => [
                'factory' => [self::class, 'makeAuthorityAudit'],
                'shared' => true,
            ],
            TenancyLifecycleAuditContract::class => [
                'factory' => [self::class, 'makeTenancyLifecycleAudit'],
                'shared' => true,
            ],
            TenantHostCooldownController::class => [
                'class' => TenantHostCooldownController::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantRolesController::class => [
                'class' => TenantRolesController::class,
                'shared' => true,
                'autowire' => true,
            ],
            AuthorityContinuityGuard::class => [
                'class' => AuthorityContinuityGuard::class,
                'shared' => true,
                'autowire' => true,
            ],
            AuthorityMutator::class => [
                'class' => AuthorityMutator::class,
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
            TenantBlobPolicy::class => [
                'factory' => [self::class, 'makeTenantBlobPolicy'],
                'shared' => true,
            ],
            BlobCreatedHook::class => [
                'factory' => [self::class, 'makeBlobCreatedHook'],
                'shared' => true,
            ],
            BlobAccessPolicy::class => [
                'factory' => [self::class, 'makeBlobAccessPolicy'],
                'shared' => true,
            ],
            BlobPublicUrlProvider::class => [
                'factory' => [self::class, 'makeBlobPublicUrlProvider'],
                'shared' => true,
            ],
            CanonicalPublicOriginResolver::class => [
                'factory' => [self::class, 'makeCanonicalPublicOriginResolver'],
                'shared' => true,
            ],
            BlobRouteMiddlewareProvider::class => [
                'factory' => [self::class, 'makeBlobRouteMiddlewareProvider'],
                'shared' => true,
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
            SettingsStore::class => [
                'class' => SettingsStore::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Platform-payments-settings spec Task 2: the encrypted write/read surface over the
            // unscoped SystemChannel for payvia.* gateway credentials — SystemChannel and
            // EncryptionService both autowire (constructor injection only, no container lookups
            // inside the class itself).
            \App\Settings\PlatformPaymentSettingsStore::class => [
                'class' => \App\Settings\PlatformPaymentSettingsStore::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Platform-payments-settings spec Task 3: the TEMPORARY read-only compatibility path
            // over the OLD tenant `settings` table — Task 4's override falls back to it until a
            // migration marker is written, Task 5's migration command drives it for
            // enumeration/verification/pruning. $table is left at its 'settings' default here
            // (autowiring never supplies a scalar); tests that need an isolated temporary table
            // construct the repository directly instead of resolving it from the container.
            \App\Settings\LegacyPlatformPaymentSettingsRepository::class => [
                'class' => \App\Settings\LegacyPlatformPaymentSettingsRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            \App\Settings\LegacyPlatformPaymentSettingsReader::class => [
                'class' => \App\Settings\LegacyPlatformPaymentSettingsReader::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Store-settings spec §3.3: thallo-commerce's pack-owned storage contract, satisfied
            // by SettingsStore rows (pack-defines/app-provides — the EngineMediaUrlResolver shape).
            \Thallo\Commerce\Settings\CommerceSettingsStore::class => [
                'class' => \App\Settings\CommerceSettingsBridge::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Public-account-surface plan Task 3: thallo-account's redirect-settings contract,
            // satisfied by SettingsStore rows (same pack-defines/app-provides shape as commerce).
            \Thallo\Account\Settings\AccountSettingsStore::class => [
                'class' => \App\Settings\AccountSettingsBridge::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Checkout-ui plan Task 3: signed-in email resolution for uncached storefront pages
            // (JWT claims carry no email) — a thin read over the users extension's
            // UserProviderInterface binding; fail-soft to anonymous on any lookup failure.
            \Thallo\Contracts\Account\StorefrontAccountIdentityReader::class => [
                'class' => \App\Account\AppStorefrontAccountIdentityReader::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Published site pages as convenience redirect targets (public-account-surface plan
            // Task 4, phase 2): pack-defines / app-provides over the delivery layer.
            \Thallo\Contracts\Delivery\PublishedPageDirectory::class => [
                'class' => \App\Content\Delivery\PublishedPageDirectoryBridge::class,
                'shared' => true,
                'autowire' => true,
            ],
            GeneralSettings::class => [
                'class' => GeneralSettings::class,
                'shared' => true,
                'autowire' => true,
            ],
            SystemKeyReconciler::class => [
                'class' => SystemKeyReconciler::class,
                'shared' => true,
                'autowire' => true,
            ],
            SystemKeyReconcilerContract::class => [
                'factory' => [self::class, 'makeSystemKeyReconciler'],
                'shared' => true,
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
            TenancyAccessController::class => [
                'factory' => [self::class, 'makeTenancyAccessController'],
                'shared' => true,
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
            PolicyManifestCommand::class => [
                'class' => PolicyManifestCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            SeedBlockTypesCommand::class => [
                'class' => SeedBlockTypesCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            SyncBlockTypesCommand::class => [
                'class' => SyncBlockTypesCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            RetireAccountLinkCommand::class => [
                'class' => RetireAccountLinkCommand::class,
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
            SuperuserGrantCommand::class => [
                'class' => SuperuserGrantCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            SuperuserTransferCommand::class => [
                'class' => SuperuserTransferCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // No-op: config/thallo.php is auto-loaded by the app config system, and DI
        // bindings are contributed declaratively via services(). Kept for lifecycle
        // symmetry and as the seam for future runtime registration.
    }

    public static function makeCapabilityRegistry(ContainerInterface $container): DefaultCapabilityRegistry
    {
        $context = $container->get(ApplicationContext::class);
        /** @var array<string,bool> $overrides */
        $overrides = (array) config($context, 'thallo.capabilities', []);

        // Settings › General search switch: the stored `search_enabled` row wins over the
        // deploy-time map, so the admin toggle applies on the next request with no restart
        // (searchEnabled() itself falls back to the map when no row exists, so this
        // assignment is a no-op on a rowless install). Fail-soft to the map alone — this
        // factory runs during EVERY boot, including CLI boots before the settings table
        // exists (fresh install, migrate:run).
        try {
            $overrides['thallo.search'] = $container->get(GeneralSettings::class)->searchEnabled();
        } catch (\Throwable) {
            // Pre-migration boot or DB down: the config map default stands.
        }

        return new DefaultCapabilityRegistry($overrides);
    }

    public static function makePathRenderer(ContainerInterface $container): PathRenderer
    {
        $context = $container->get(ApplicationContext::class);

        return new PathRenderer(
            (string) config($context, 'thallo.seo.route_template', '/{locale}/{type}/{slug}'),
            config($context, 'thallo.seo.public_url_base') === null
                ? null
                : (string) config($context, 'thallo.seo.public_url_base'),
            (string) config($context, 'i18n.default_locale', 'en')
        );
    }

    public static function makePublishService(ContainerInterface $c): PublishService
    {
        $gates = $c->has('thallo.publish_gate') ? $c->get('thallo.publish_gate') : [];
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

    public static function makeSeoHeadProvider(ContainerInterface $container): EngineSeoHeadProvider
    {
        return new EngineSeoHeadProvider(
            $container->get(ApplicationContext::class),
            $container->get(SeoMetaResolver::class),
            $container->get(CanonicalProjector::class),
            $container->get(CanonicalPublicOriginResolver::class),
            $container->get(HomepageEntryProvider::class),
            $container->get(RouteRepository::class),
            $container->get(ContentTypeRepository::class),
        );
    }

    public function boot(ApplicationContext $context): void
    {
        // Routes: routes/admin.php is auto-discovered by RouteManifest. Do NOT
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

        $container = $context->getContainer();
        $enabled = $container->has(SystemFlags::class)
            && $container->get(SystemFlags::class)->tenancyEnabled();
        self::assertBlobPolicyReady($container, $enabled);

        // Mount the compiled admin SPA at /admin via the framework seam: secure asset serving
        // + index.html deep-link fallback + cache split. No-ops (with a warning) if the bundle
        // is unbuilt. The /admin/config + /admin/setup static routes (routes/admin_spa.php)
        // keep precedence over the SPA catch-all via the router's static-first lookup.
        // Gated by thallo.admin.enabled so an operator can disable the default admin and bring
        // their own (the admin is a replaceable client of the /v1/admin API).
        if ((bool) config($context, 'thallo.admin.enabled', true)) {
            $this->serveFrontend(
                '/admin',
                (string) config($context, 'thallo.admin.bundle_path', dirname(__DIR__, 2) . '/public/admin'),
                ['name' => 'Thallo Admin'],
            );
        }

        $this->registerEventListeners($context);

        EditorialFieldTypes::register(app($context, FieldTypeRegistry::class));

        // Console: register Thallo's app commands. commands() is a console-only no-op in
        // the HTTP phase (runningInConsole() guards it), so this is free during requests.
        $this->commands([
            ResyncCommand::class,
            PruneVersionsCommand::class,
            PolicyManifestCommand::class,
            SeedBlockTypesCommand::class,
            SyncBlockTypesCommand::class,
            RetireAccountLinkCommand::class,
            RunBlockBackfillCommand::class,
            RunBackfillCommand::class,
            RunDueSchedulesCommand::class,
            DoctorCommand::class,
            ProvisionCommand::class,
            CreateAdminCommand::class,
            SuperuserGrantCommand::class,
            SuperuserTransferCommand::class,
        ]);
    }

    public static function assertBlobPolicyReady(ContainerInterface $container, bool $tenancyEnabled): void
    {
        if (!$tenancyEnabled) {
            return;
        }

        if (
            !$container->has(BlobCreatedHook::class)
            || !$container->get(BlobCreatedHook::class) instanceof TenantBlobPolicy
            || !$container->has(BlobAccessPolicy::class)
            || !$container->get(BlobAccessPolicy::class) instanceof TenantBlobPolicy
        ) {
            throw new \RuntimeException('Tenancy is enabled without the tenant blob policy.');
        }
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

        // `ThalloServiceProvider` (app provider) boots before `AnalyticsServiceProvider`
        // (pack provider), so CapabilityRegistry::isEnabled() would return false for
        // 'thallo.analytics' at this point (the capability is only registered during the pack's
        // own boot()). Read the capabilities override config directly instead — same semantics as
        // DefaultCapabilityRegistry::isEnabled() but without the "must be registered" prerequisite.
        $capOverrides = (array) config($context, 'thallo.capabilities', []);
        $analyticsOn = ($capOverrides['thallo.analytics'] ?? true) === true;

        // event class => list of listener service ids (lazy '@' form).
        //
        // PurgeCdnListener and ReindexSearchListener are CAPABILITY-GATED no-ops in a lean
        // install (no glueful/cdn / content reindexer): they self-skip at invocation, so wiring
        // them broadly is safe. PurgeCdnListener mirrors the cache listener's tag scope (entry
        // + model events, since both move thallo:type:{slug}). ReindexSearchListener is wired to
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
            // SEO override upserts (a clear included) → local + edge purge of the entry's
            // rendered pages (seo-head spec §5). Entry tag only — never type-level tags.
            SeoMetaChanged::class => [
                SeoMetaChangedListener::class,
            ],
        ];

        // Collection row CRUD → audit log + analytics facts. Gated on the pack being INSTALLED
        // (class_exists) so removing the pack drops this wiring cleanly with no dangling reference.
        // CollectionAuditListener is unconditional (installed-gated only): a disabled-but-installed
        // analytics pack must still audit programmatic row mutations. AnalyticsBridgeListener is
        // ENABLED-gated: disabling thallo.analytics hard-stops collection ingestion, consistent with
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
        // thallo.analytics hard-stops content ingestion, consistent with the pack's auth listeners,
        // the collection block above, and the read API (spec §7). The audit bridge (CollectionAuditListener)
        // remains unconditional/installed-gated and is unaffected by this gate.
        if ($analyticsOn) {
            $listeners[EntryCreated::class][]    = AnalyticsBridgeListener::class;
            $listeners[EntryUpdated::class][]    = AnalyticsBridgeListener::class;
            $listeners[EntryDeleted::class][]    = AnalyticsBridgeListener::class;
            $listeners[EntryPublished::class][]  = AnalyticsBridgeListener::class;
            $listeners[EntryUnpublished::class][] = AnalyticsBridgeListener::class;
        }

        $listeners[DomainReverificationFailed::class][] = DomainReverificationAuditListener::class;
        $listeners[DomainRevoked::class][] = DomainReverificationAuditListener::class;
        $listeners[DomainReverified::class][] = DomainReverificationAuditListener::class;

        foreach ($listeners as $eventClass => $serviceIds) {
            foreach ($serviceIds as $serviceId) {
                $events->addListener($eventClass, '@' . $serviceId);
            }
        }
    }
}
