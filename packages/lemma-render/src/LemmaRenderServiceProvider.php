<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Render\Templates\DatabaseTemplateLoader;
use Glueful\Lemma\Render\Templates\TemplateLinter;
use Glueful\Lemma\Render\Templates\TemplateRepository;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use Glueful\Lemma\Contracts\Content\BlockEditableFieldResolver;
use Glueful\Lemma\Contracts\Content\RichHtmlSanitizer;
use Glueful\Lemma\Contracts\Delivery\MediaUrlResolver;
use Glueful\Lemma\Contracts\Delivery\EntryTargetResolver;
use Glueful\Lemma\Contracts\Delivery\FacetCountsReader;
use Glueful\Lemma\Contracts\Delivery\PreviewThemeValidator;
use Glueful\Lemma\Contracts\Navigation\MenuReader;
use Glueful\Lemma\Contracts\Navigation\MenuUpdated;
use Glueful\Lemma\Render\Console\ClearRenderCacheCommand;
use Glueful\Lemma\Contracts\Delivery\PreviewSessionVerifier;
use Glueful\Lemma\Render\Http\Controllers\RenderController;
use Glueful\Lemma\Render\Http\Controllers\TemplatesAdminController;
use Glueful\Lemma\Render\Templates\TemplateCatalog;
use Glueful\Lemma\Render\Http\Middleware\PreviewSessionMiddleware;
use Glueful\Lemma\Render\Http\Middleware\RenderPageCache;
use Glueful\Lemma\Render\Listeners\PurgeRenderCacheOnMenuUpdate;
use Glueful\Lemma\Render\Listeners\PurgeRenderCacheOnTemplateUpdate;
use Glueful\Lemma\Render\Templates\TemplateUpdated;
use Psr\Container\ContainerInterface;

use function config;

final class LemmaRenderServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            ThemeLocator::class => [
                'shared' => true,
                'factory' => [self::class, 'makeThemeLocator'],
            ],
            RenderContextExtension::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderContextExtension'],
            ],
            TwigFactory::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTwigFactory'],
            ],
            ReservedPaths::class => [
                'shared' => true,
                'factory' => [self::class, 'makeReservedPaths'],
            ],
            RenderController::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderController'],
            ],
            RenderPageCache::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderPageCache'],
            ],
            RenderErrorCache::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderErrorCache'],
            ],
            PurgeRenderCacheOnMenuUpdate::class => [
                'shared' => true,
                'factory' => [self::class, 'makePurgeRenderCacheOnMenuUpdate'],
            ],
            ClearRenderCacheCommand::class => [
                'shared' => true,
                'factory' => [self::class, 'makeClearRenderCacheCommand'],
            ],
            PreviewThemeValidator::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderThemeValidator'],
            ],
            PreviewSessionMiddleware::class => [
                'shared' => true,
                'factory' => [self::class, 'makePreviewSessionMiddleware'],
            ],
            TemplateRepository::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTemplateRepository'],
            ],
            TemplateLinter::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTemplateLinter'],
            ],
            PurgeRenderCacheOnTemplateUpdate::class => [
                'shared' => true,
                'factory' => [self::class, 'makePurgeRenderCacheOnTemplateUpdate'],
            ],
            TemplateCatalog::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTemplateCatalog'],
            ],
            TemplatesAdminController::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTemplatesAdminController'],
            ],
        ];
    }

    public static function makeTemplateCatalog(ContainerInterface $container): TemplateCatalog
    {
        $context = $container->get(ApplicationContext::class);
        return new TemplateCatalog(
            $container->get(TemplateRepository::class),
            $context->getBasePath() . '/themes',
            dirname(__DIR__) . '/themes',
        );
    }

    public static function makeTemplatesAdminController(ContainerInterface $container): TemplatesAdminController
    {
        return new TemplatesAdminController(
            $container->get(TemplateRepository::class),
            $container->get(TemplateLinter::class),
            $container->get(TemplateCatalog::class),
            $container->get(PreviewThemeValidator::class),
            $container->get(ThemeLocator::class),
            $container->get(EventService::class),
        );
    }

    public static function makePurgeRenderCacheOnTemplateUpdate(
        ContainerInterface $container,
    ): PurgeRenderCacheOnTemplateUpdate {
        return new PurgeRenderCacheOnTemplateUpdate($container);
    }

    public static function makeTemplateRepository(ContainerInterface $container): TemplateRepository
    {
        return new TemplateRepository($container->get(Connection::class));
    }

    public static function makeTemplateLinter(ContainerInterface $container): TemplateLinter
    {
        return new TemplateLinter($container->get(RenderContextExtension::class));
    }

    public static function makePreviewSessionMiddleware(
        ContainerInterface $container,
    ): PreviewSessionMiddleware {
        return new PreviewSessionMiddleware($container->get(PreviewSessionVerifier::class));
    }

    public static function makeRenderThemeValidator(ContainerInterface $container): RenderThemeValidator
    {
        $context = $container->get(ApplicationContext::class);
        return new RenderThemeValidator($context->getBasePath() . '/themes');
    }

    public static function makeClearRenderCacheCommand(
        ContainerInterface $container,
    ): ClearRenderCacheCommand {
        return new ClearRenderCacheCommand($container->get(CacheStore::class));
    }

    public static function makePurgeRenderCacheOnMenuUpdate(
        ContainerInterface $container,
    ): PurgeRenderCacheOnMenuUpdate {
        return new PurgeRenderCacheOnMenuUpdate($container);
    }

    public static function makeRenderErrorCache(ContainerInterface $container): RenderErrorCache
    {
        $context = $container->get(ApplicationContext::class);
        return new RenderErrorCache(
            $container->get(CacheStore::class),
            $container->get(ThemeLocator::class)->activePaths()['name'],
            (bool) config($context, 'lemma_render.cache_enabled', true),
            (int) config($context, 'lemma_render.cache_ttl', 3600),
        );
    }

    public static function makeRenderPageCache(ContainerInterface $container): RenderPageCache
    {
        $context = $container->get(ApplicationContext::class);
        return new RenderPageCache(
            // The SAME binding InvalidateCacheTagsListener invalidates (spec §3 pin) —
            // this identity is what makes zero-new-purge-code true.
            $container->get(CacheStore::class),
            $container->get(ThemeLocator::class)->activePaths()['name'],
            (bool) config($context, 'lemma_render.cache_enabled', true),
            (int) config($context, 'lemma_render.cache_ttl', 3600),
        );
    }

    public static function makeRenderController(ContainerInterface $container): RenderController
    {
        $context = $container->get(ApplicationContext::class);
        $dbTemplates = (bool) config($context, 'lemma_render.db_templates', true);
        return new RenderController(
            $context,
            $container->get(\Glueful\Lemma\Contracts\Delivery\PublicRouteResolver::class),
            $container->get(TwigFactory::class),
            $container->get(RenderContextExtension::class),
            $container->get(ReservedPaths::class),
            $container->get(RenderErrorCache::class),
            $container->get(\Psr\Log\LoggerInterface::class),
            $container->has(FacetCountsReader::class)
                ? $container->get(FacetCountsReader::class)
                : null,
            $container->has(PreviewSessionVerifier::class)
                ? $container->get(PreviewSessionVerifier::class)
                : null,
            $dbTemplates ? $container->get(TemplateRepository::class) : null,
            $dbTemplates ? $container->get(TemplateLinter::class) : null,
        );
    }

    public static function makeThemeLocator(ContainerInterface $container): ThemeLocator
    {
        $context = $container->get(ApplicationContext::class);
        return new ThemeLocator(
            (string) config($context, 'lemma_render.theme', 'default'),
            $context->getBasePath() . '/themes',
        );
    }

    public static function makeRenderContextExtension(ContainerInterface $container): RenderContextExtension
    {
        $context = $container->get(ApplicationContext::class);
        // MenuReader is OPTIONAL — render has no hard dependency on lemma-navigation.
        $menus = $container->has(MenuReader::class) ? $container->get(MenuReader::class) : null;
        // FacetCountsReader is likewise soft: no binding means facets() returns [].
        $facets = $container->has(FacetCountsReader::class)
            ? $container->get(FacetCountsReader::class)
            : null;
        return new RenderContextExtension(
            $menus instanceof MenuReader ? $menus : null,
            $container->get(EntryTargetResolver::class),
            (string) config($context, 'i18n.default_locale', 'en'),
            $facets instanceof FacetCountsReader ? $facets : null,
            // blocks() diagnostics (block-builder spec §6): provider-injected, never
            // read from Twig context.
            $container->get(\Psr\Log\LoggerInterface::class),
            (bool) config($context, 'app.debug', false),
            // safe_html (sanitizer spec §4): soft-bound; null fails CLOSED (escapes).
            $container->has(RichHtmlSanitizer::class)
                ? $container->get(RichHtmlSanitizer::class)
                : null,
            // media() (starter-library spec §3): soft-bound; null = always-null URLs.
            $container->has(MediaUrlResolver::class)
                ? $container->get(MediaUrlResolver::class)
                : null,
            // Edit-in-place marking (spec §2): soft-bound; null = never marks.
            $container->has(BlockEditableFieldResolver::class)
                ? $container->get(BlockEditableFieldResolver::class)
                : null,
        );
    }

    public static function makeTwigFactory(ContainerInterface $container): TwigFactory
    {
        $context = $container->get(ApplicationContext::class);
        $db = null;
        if ((bool) config($context, 'lemma_render.db_templates', true)) {
            $db = new DatabaseTemplateLoader(
                $container->get(TemplateRepository::class),
                $container->get(TemplateLinter::class),
                // The RESOLVED active theme (activePaths()['name']) — matches the page
                // cache's theme keying; a fallen-back locator must not apply another
                // theme's overrides.
                $container->get(ThemeLocator::class)->activePaths()['name'],
            );
        }
        return new TwigFactory(
            $container->get(ThemeLocator::class),
            $container->get(RenderContextExtension::class),
            $context->getBasePath() . '/storage/cache/twig',
            $db,
        );
    }

    public static function makeReservedPaths(ContainerInterface $container): ReservedPaths
    {
        $context = $container->get(ApplicationContext::class);
        return new ReservedPaths(
            array_values(array_map(strval(...), (array) config($context, 'lemma_render.reserved_prefixes', []))),
            array_values(array_map(strval(...), (array) config($context, 'lemma_render.reserved_exact', []))),
        );
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge the pack's tree under 'lemma_render'.
        $this->mergeConfig('lemma_render', require __DIR__ . '/../config/lemma-render.php');
    }

    public function boot(ApplicationContext $context): void
    {
        // OUTSIDE the capability gate (pack convention): schema must exist regardless
        // of whether rendered delivery is currently enabled.
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');

        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'lemma.render',
            label: 'Rendered delivery',
            description: 'Server-rendered pages from published content via filesystem Twig themes.',
        ));

        if ($registry->isEnabled('lemma.render')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/public-routes.php');

            // Theme assets only (never templates/theme.json). Mounted at BOOT — v1 theme
            // changes require a restart/cache rebuild (spec §4).
            $assets = app($context, ThemeLocator::class)->activePaths()['assets'];
            if (is_dir($assets)) {
                $this->serveFrontend('/theme-assets', $assets, ['spaFallback' => false]);
            }

            // The pack's ONE purge listener (spec §4): menu changes purge broadly.
            // Entry/type purges need no render code — InvalidateCacheTagsListener
            // already invalidates the tags the middleware stores under.
            $events = app($context, EventService::class);
            $events->addListener(
                MenuUpdated::class,
                [app($context, PurgeRenderCacheOnMenuUpdate::class), 'onMenuUpdated'],
            );

            // DB-edited templates (spec §5/§7): admin routes + purge listener only
            // when the feature is on — the kill-switch removes every
            // template-mutation pathway.
            if ((bool) config($context, 'lemma_render.db_templates', true)) {
                $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');
                $events->addListener(
                    TemplateUpdated::class,
                    [app($context, PurgeRenderCacheOnTemplateUpdate::class), 'onTemplateUpdated'],
                );
            }
        }

        // OUTSIDE the capability gate (analytics-pack precedent): an operator may need
        // to clear stale pages right after disabling the capability.
        $this->commands([ClearRenderCacheCommand::class]);
    }
}
