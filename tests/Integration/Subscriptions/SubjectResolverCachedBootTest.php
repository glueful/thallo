<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use App\Tests\Support\BootsFromExtensionProviderCache;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Glueful\Extensions\Subscriptions\Resolution\DefaultSubjectResolver;
use Glueful\Extensions\Subscriptions\SubscriptionService;
use Thallo\Subscriptions\EnginePreemptionServiceProvider;
use Thallo\Subscriptions\Resolver\ThalloSubjectResolver;
use Thallo\Subscriptions\SubscriptionsIntegrationServiceProvider;

/**
 * The `SubjectResolverInterface` rebind under the CACHED-PROVIDER boot mode — the sibling of
 * {@see EngineNativeRoutesCachedBootTest}, for the second thing that silently lost in production.
 *
 * Task 6 established that this pack CANNOT win `SubjectResolverInterface::class` through the
 * container DSL: `ContainerFactory::loadExtensionDefinitions()` merges provider defs id-by-id with
 * `$defs += $compiled` in resolver order (first-registered wins) and the pack's main provider is
 * ordered AFTER the engine, so a `services()` entry there resolves to the engine's
 * {@see DefaultSubjectResolver}. The id therefore has to be re-pinned on the already-built runtime
 * container.
 *
 * That rebind originally lived in `SubscriptionsIntegrationServiceProvider::register()` — which
 * `ExtensionManager::discover()` never calls on a cache hit (it assigns the cached providers and
 * RETURNS before `registerProviders()`), i.e. never in the boot mode production is REQUIRED to use.
 * The consequence was silent and total: every subject in production would have been validated
 * through the engine's `DefaultSubjectResolver` instead of {@see ThalloSubjectResolver}, with no
 * error anywhere — exactly the "binding silently loses" failure mode Task 6's review was about.
 * {@see EnginePreemptionServiceProvider} now performs it from `boot()`, which runs unconditionally
 * for every provider in BOTH discovery modes, at a negative `loadPriority()` so it is the FIRST
 * provider boot in the process and nothing can have singleton-cached the id before it.
 */
final class SubjectResolverCachedBootTest extends AppTestCase
{
    use BootsFromExtensionProviderCache;

    public function testSubjectResolverIsStillRepinnedOnACachedProviderBoot(): void
    {
        $cachedApp = $this->bootFromExtensionProviderCache([
            EnginePreemptionServiceProvider::class,
            \Glueful\Extensions\Subscriptions\SubscriptionsServiceProvider::class,
            SubscriptionsIntegrationServiceProvider::class,
        ]);

        try {
            // This boot really took `discover()`'s cache-hit early return, so NO provider's
            // register() ran — any mechanism that lived there is inert here.
            $manager = $this->assertBootUsedTheProviderCache($cachedApp);

            // The pre-emption provider must boot FIRST: the rebind has to land before anything can
            // resolve (and singleton-cache) the id, and the pack's main provider is ordered after
            // the engine, which is why the rebind cannot live there.
            $order = array_keys($manager->getProviders());
            self::assertSame(
                EnginePreemptionServiceProvider::class,
                $order[0] ?? null,
                'the pre-engine seam must be the first provider booted on the cached boot',
            );

            $container = $cachedApp->getContainer();

            // The actual contract.
            $resolver = $container->get(SubjectResolverInterface::class);
            self::assertInstanceOf(
                ThalloSubjectResolver::class,
                $resolver,
                'SubjectResolverInterface must resolve to this pack\'s resolver on a cached boot',
            );
            self::assertNotInstanceOf(DefaultSubjectResolver::class, $resolver);

            // Same object as the pack's own binding — proof this is the rebind winning, not some
            // coincidental second instance.
            self::assertSame($container->get(ThalloSubjectResolver::class), $resolver);

            // ...and the engine's own consumers get it too: SubscriptionService takes the interface
            // as a constructor dependency, so a lost rebind would silently validate every subject
            // through DefaultSubjectResolver. Read back off the built service, not the container id.
            $service = $container->get(SubscriptionService::class);
            self::assertInstanceOf(SubscriptionService::class, $service);
            $subjects = (new \ReflectionProperty(SubscriptionService::class, 'subjects'))->getValue($service);
            self::assertInstanceOf(
                ThalloSubjectResolver::class,
                $subjects,
                'the engine\'s own SubscriptionService must be constructed with Thallo\'s resolver',
            );
        } finally {
            self::resetSharedRepositoryConnection();
            self::restoreSharedPermissionProvider();
        }
    }
}
