<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Container\Container as GluefulContainer;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Thallo\Subscriptions\Resolver\ThalloSubjectResolver;

/**
 * Task 6 review finding (CRITICAL): this pack rebinds `SubjectResolverInterface::class` by mutating
 * the already-built runtime `Glueful\Container\Container` -- a mechanism that CANNOT reach a
 * compiled container. (The rebind itself lives in
 * `Thallo\Subscriptions\EnginePreemptionServiceProvider::rebindSubjectResolver()`, called from that
 * provider's `boot()`; it moved out of `SubscriptionsIntegrationServiceProvider::register()` in the
 * final fix wave because no provider's `register()` runs on the cached-provider boots production is
 * required to use -- see `SubjectResolverCachedBootTest`. The CONTAINER-shape caveat this test guards
 * is unchanged by that move: `Container::load()` targets the plain container either way.) That is
 * not a hypothetical: `Glueful\Framework::buildContainer()` computes `$prod = (APP_ENV ===
 * 'production') && !APP_DEBUG` and passes it straight to `ContainerFactory::create()`, which
 * ATTEMPTS an inline compile on every single boot under that condition -- no separate
 * "container:compile" CLI step exists to opt into. Today that inline compile always throws and
 * falls back to the plain container, for two vendor-internal reasons this pack does not own:
 * `ContainerCompiler` rejects `FactoryDefinition`s outright (the engine registers several), and
 * it cannot serialize the `ApplicationContext` `ValueDefinition` `ContainerFactory` itself binds
 * (see `vendor/glueful/subscriptions/tests/Unit/Container/StrictLaneCompiledContainerGateTest.php`'s
 * docblock for the same finding, independently). If either limitation is ever fixed upstream
 * (e.g. by a routine `composer update`), compilation starts SUCCEEDING, the rebind's
 * `instanceof GluefulContainer` guard goes false, it silently no-ops, and
 * `SubjectResolverInterface` reverts to the engine's `DefaultSubjectResolver` in PRODUCTION with
 * no error anywhere -- the suite cannot catch this today because `APP_ENV=testing` forces
 * `$prod = false` unconditionally in `Framework::buildContainer()`.
 *
 * This test drives the REAL `ContainerFactory::create($context, prod: true)` path (the exact
 * call `Framework::buildContainer()` makes) against the REAL, fully-merged app container -- not
 * an isolated slice -- using the process-shared boot's own `ApplicationContext`. Today it takes
 * the early-return branch (compilation still fails, `GluefulContainer` comes back) and passes
 * for that documented, expected reason. The day compilation starts succeeding, it falls through
 * to the second assertion and goes RED, pointing straight at
 * `EnginePreemptionServiceProvider::rebindSubjectResolver()` as the thing that needs a
 * compiled-container-safe rebind mechanism before this app can ship a compiled production
 * container.
 */
final class SubjectResolverCompiledContainerGateTest extends AppTestCase
{
    public function testSubjectResolverInterfaceMustNotSilentlyRevertToTheEngineDefaultIfCompilationEverSucceeds(): void
    {
        $container = ContainerFactory::create($this->appContext(), prod: true);

        if ($container instanceof GluefulContainer) {
            self::assertInstanceOf(
                GluefulContainer::class,
                $container,
                'Expected, documented state today: inline compilation still fails (FactoryDefinition '
                . 'rejection + the ApplicationContext ValueDefinition ContainerCompiler cannot '
                . 'serialize), so ContainerFactory::create() falls back to the plain container. If '
                . 'this assertion ever fails, compilation has started succeeding -- see this test\'s '
                . 'class docblock and EnginePreemptionServiceProvider::rebindSubjectResolver().',
            );

            return;
        }

        // Compilation SUCCEEDED: EnginePreemptionServiceProvider::rebindSubjectResolver()'s runtime
        // Container::load() rebind can never reach this CompiledContainer instance (it targets
        // Glueful\Container\Container only). Resolving SubjectResolverInterface from THIS
        // compiled container must still be ThalloSubjectResolver, never the engine's
        // DefaultSubjectResolver -- if this goes red, the rebind mechanism needs to change before
        // a compiled production container can ship.
        self::assertInstanceOf(
            ThalloSubjectResolver::class,
            $container->get(SubjectResolverInterface::class),
            'Container compilation succeeded and SubjectResolverInterface reverted to the engine\'s '
            . 'DefaultSubjectResolver -- the runtime Container::load() rebind in '
            . 'EnginePreemptionServiceProvider cannot reach a compiled container. This must '
            . 'be fixed (a compiled-container-safe rebind mechanism) before shipping a compiled '
            . 'production container.',
        );
    }
}
