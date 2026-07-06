<?php

declare(strict_types=1);

namespace Thallo\Seo;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Glueful\Cache\CacheStore;
use Glueful\Events\EventService;
use Thallo\Contracts\Delivery\ContentDeliveryReader;
use Thallo\Contracts\Events\ContentLifecycleEvent;
use Thallo\Seo\Cache\FrameworkSitemapCache;
use Thallo\Seo\Cache\SitemapCache;
use Thallo\Seo\Http\Controllers\AdminSeoMetaController;
use Thallo\Seo\Http\Controllers\SeoMetaController;
use Thallo\Seo\Http\Controllers\RobotsController;
use Thallo\Seo\Http\Controllers\SitemapController;
use Thallo\Seo\Listeners\SitemapCacheInvalidator;
use Thallo\Seo\Meta\SeoMetaRepository;
use Thallo\Seo\Meta\SeoMetaResolver;
use Thallo\Seo\Sitemap\RobotsBuilder;
use Thallo\Seo\Sitemap\SitemapBuilder;
use Psr\Container\ContainerInterface;

final class SeoServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            SeoMetaRepository::class => [
                'class' => SeoMetaRepository::class, 'shared' => true, 'autowire' => true,
            ],
            SeoMetaResolver::class => [
                'shared' => true, 'factory' => [self::class, 'makeSeoMetaResolver'],
            ],
            SeoMetaController::class => [
                'class' => SeoMetaController::class, 'shared' => true, 'autowire' => true,
            ],
            AdminSeoMetaController::class => [
                'class' => AdminSeoMetaController::class, 'shared' => true, 'autowire' => true,
            ],
            SitemapCache::class => [
                'shared' => true, 'factory' => [self::class, 'makeSitemapCache'],
            ],
            SitemapBuilder::class => [
                'shared' => true, 'factory' => [self::class, 'makeSitemapBuilder'],
            ],
            SitemapController::class => [
                'class' => SitemapController::class, 'shared' => true, 'autowire' => true,
            ],
            RobotsBuilder::class => [
                'shared' => true, 'factory' => [self::class, 'makeRobotsBuilder'],
            ],
            RobotsController::class => [
                'class' => RobotsController::class, 'shared' => true, 'autowire' => true,
            ],
            SitemapCacheInvalidator::class => [
                'class' => SitemapCacheInvalidator::class, 'shared' => true, 'autowire' => true,
            ],
        ];
    }

    public static function makeRobotsBuilder(ContainerInterface $container): RobotsBuilder
    {
        $context = $container->get(ApplicationContext::class);
        return new RobotsBuilder(
            (array) config($context, 'lemma_seo.robots', []),
            (string) config($context, 'lemma.seo.public_url_base', ''),
        );
    }

    public static function makeSitemapCache(ContainerInterface $container): SitemapCache
    {
        return new FrameworkSitemapCache($container->get(CacheStore::class));
    }

    public static function makeSitemapBuilder(ContainerInterface $container): SitemapBuilder
    {
        $context = $container->get(ApplicationContext::class);
        return new SitemapBuilder(
            $container->get(ContentDeliveryReader::class),
            $container->get(SitemapCache::class),
            (string) config($context, 'lemma.seo.public_url_base', ''),
        );
    }

    public static function makeSeoMetaResolver(ContainerInterface $container): SeoMetaResolver
    {
        $context = $container->get(ApplicationContext::class);
        $repo = $container->get(SeoMetaRepository::class);
        /** @var array<string,mixed> $defaults */
        $defaults = (array) config($context, 'lemma_seo.defaults', []);
        return new SeoMetaResolver(
            $container->get(ContentDeliveryReader::class),
            static fn (string $entryUuid, string $locale): ?array => $repo->find($entryUuid, $locale),
            fallbacks: (array) config($context, 'lemma_seo.fallbacks', []),
            defaults: [
                'site_name' => (string) ($defaults['site_name'] ?? 'Lemma'),
                'default_og_image' => (string) ($defaults['default_og_image'] ?? ''),
                'title_template' => (string) ($defaults['title_template'] ?? '{title} — {site_name}'),
            ],
        );
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge the pack's own tree under 'lemma_seo'.
        $this->mergeConfig('lemma_seo', require __DIR__ . '/../config/lemma-seo.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'lemma.seo',
            label: 'SEO',
            description: 'Sitemaps, per-entry SEO meta, and robots.txt.',
        ));

        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'thallo-seo',
        );

        if ($registry->isEnabled('lemma.seo')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/public-routes.php');
            $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');

            // Any content lifecycle change can alter the published-URL set — drop the sitemap
            // cache. BaseContentEvent implements ContentLifecycleEvent, and the framework
            // dispatches concrete events to interface-typed listeners (getEventTypes()).
            $events = app($context, EventService::class);
            $invalidator = app($context, SitemapCacheInvalidator::class);
            $events->addListener(ContentLifecycleEvent::class, [$invalidator, 'onContentChanged']);
        }
    }
}
