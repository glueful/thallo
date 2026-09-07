<?php

declare(strict_types=1);

namespace App\Tests\Integration\Subscriptions;

use App\Tests\Support\AppTestCase;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Container\Container as GluefulContainer;
use Glueful\Extensions\Subscriptions\Contracts\SubjectResolverInterface;
use Thallo\Subscriptions\EnginePreemptionServiceProvider;
use Thallo\Subscriptions\Resolver\ThalloSubjectResolver;

/**
 * Task 6 review finding (CRITICAL), now the guarantee it asked for: this pack re-pins
 * `SubjectResolverInterface::class` by mutating the already-built container from
 * `EnginePreemptionServiceProvider::boot()` (see that class for why neither `services()` nor
 * `register()` can win the id). Until framework 1.82.0 the inline production compile in
 * `ContainerFactory::create($context, prod: true)` always failed and fell back to the runtime
 * container, so the re-pin's `instanceof Glueful\Container\Container` guard never bit. From 1.82.0
 * the compile SUCCEEDS on every production boot, and from 1.82.1 the compiled container implements
 * `Glueful\Container\RebindableContainer` — the guard targets that interface now.
 *
 * This test drives the REAL `ContainerFactory::create($context, prod: true)` path (the exact call
 * `Framework::buildContainer()` makes) against the fully merged app container, then runs the
 * pre-engine re-pin against THAT compiled container the way `ExtensionManager::boot()` does in
 * production. Red means one of two regressions: compilation stopped succeeding (fallback to the
 * runtime container), or the re-pin stopped reaching the compiled container.
 */
final class SubjectResolverCompiledContainerGateTest extends AppTestCase
{
    public function testSubjectResolverInterfaceMustNotSilentlyRevertToTheEngineDefaultIfCompilationEverSucceeds(): void
    {
        $container = ContainerFactory::create($this->appContext(), prod: true);

        self::assertNotInstanceOf(
            GluefulContainer::class,
            $container,
            'Production compilation regressed: ContainerFactory::create(prod: true) fell back to the '
            . 'runtime container. Framework >= 1.82.0 compiles the full app container.',
        );

        // What ExtensionManager::boot() does first in production: the pre-engine seam boots
        // against the (compiled) app container and re-pins the resolver on it.
        $seam = new EnginePreemptionServiceProvider($container);
        $rebind = new \ReflectionMethod($seam, 'rebindSubjectResolver');
        $rebind->invoke($seam);

        $resolver = $container->get(SubjectResolverInterface::class);
        self::assertInstanceOf(
            ThalloSubjectResolver::class,
            $resolver,
            'The boot-time re-pin did not reach the compiled container: SubjectResolverInterface '
            . 'reverted to the engine\'s DefaultSubjectResolver. The guard must target '
            . 'Glueful\\Container\\RebindableContainer (framework >= 1.82.1).',
        );
        self::assertSame($container->get(ThalloSubjectResolver::class), $resolver);
    }
}
