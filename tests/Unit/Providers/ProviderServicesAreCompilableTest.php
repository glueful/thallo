<?php

declare(strict_types=1);

namespace App\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;

/**
 * In production the framework compiles the container and REFUSES closure factories — and it
 * skips the ENTIRE provider whose services() carries one, taking every service it defines down
 * with it. Two closures in the app provider were enough to remove the capability registry from a
 * fresh production install, so no pack could boot and no thallo command existed. Factories must
 * be static method references (`[self::class, 'makeX']`) or class strings, never closures.
 */
final class ProviderServicesAreCompilableTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function providers(): iterable
    {
        $classes = [
            \App\Providers\ThalloServiceProvider::class,
            \Thallo\Account\AccountServiceProvider::class,
            \Thallo\Analytics\AnalyticsServiceProvider::class,
            \Thallo\Collections\CollectionsServiceProvider::class,
            \Thallo\Commerce\CommerceIntegrationServiceProvider::class,
            \Thallo\Importers\ImportersServiceProvider::class,
            \Thallo\Navigation\NavigationServiceProvider::class,
            \Thallo\Render\RenderServiceProvider::class,
            \Thallo\Search\SearchServiceProvider::class,
            \Thallo\Seo\SeoServiceProvider::class,
            \Thallo\Subscriptions\SubscriptionsIntegrationServiceProvider::class,
            \Thallo\Tenancy\TenancyServiceProvider::class,
            \Thallo\Workflow\WorkflowServiceProvider::class,
        ];
        foreach ($classes as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @dataProvider providers
     * @param class-string $provider
     */
    public function testNoServiceDefinitionUsesAClosureFactory(string $provider): void
    {
        self::assertTrue(method_exists($provider, 'services'), "{$provider} has no static services()");

        $offenders = [];
        foreach ($provider::services() as $id => $definition) {
            if (is_array($definition) && ($definition['factory'] ?? null) instanceof \Closure) {
                $offenders[] = $id;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "{$provider}::services() uses closure factories (not compilable in production)",
        );
    }
}
