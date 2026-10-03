<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Routing;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Routing\RouteCache;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Http\CommerceMetaController;
use Thallo\Core\Capabilities\Activation\ActivationRunner;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Tests\Support\ActivationRunners;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\CapabilityBaseline;
use Thallo\Core\Tests\Support\ResetsCommerceActivation;
use Thallo\Core\Tests\Support\RestoresPermissionRows;

/**
 * The compiled route table is keyed by the capability-state snapshot (feature activation spec §3.2,
 * §3.6): the context's route-signature input is the snapshot's version, the one the registry
 * decides from. A table compiled under one capability state is never served by a context booted
 * under another — turning a capability on serves its routes, and turning it off removes them, on the
 * next context, with no cache to clear.
 *
 * Each context here boots as a request does: it loads the compiled table if its signature matches
 * and otherwise compiles and saves its own. Unlike bootAppWithConfigOverride(), nothing deletes the
 * table between boots.
 */
final class CapabilityRouteTableTest extends AppTestCase
{
    use ActivationRunners;
    use ResetsCommerceActivation;
    use RestoresPermissionRows;

    private const COMMERCE_ROUTE = '/v1/admin/commerce/meta';

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPermissionRows();
        $this->resetCommerceActivation();
        @unlink($this->tableFile());
    }

    protected function tearDown(): void
    {
        ActivationRunner::$crashProbe = null;
        $this->resetCommerceActivation();
        $this->restorePermissionRows();
        $this->removeActivationTempFiles();
        CapabilityBaseline::restore($this->connection()->getPDO());
        @unlink($this->tableFile());
        RouteManifest::reset();
        \Glueful\Extensions\ServiceProvider::resetLoadedRoutes();
        parent::tearDown();
    }

    private function tableFile(): string
    {
        return (new RouteCache($this->appContext()))->getCacheFilePath();
    }

    /** A context booted as a request is: it reuses the compiled table when its signature matches. */
    private static function request(): ApplicationContext
    {
        RouteManifest::reset();
        \Glueful\Extensions\ServiceProvider::resetLoadedRoutes();
        $root = dirname(__DIR__, 3);
        return Framework::create($root)
            ->withConfigDir($root . '/config')
            ->withEnvironment('testing')
            ->boot()
            ->getContext();
    }

    /** Whether Commerce's own route answers the path (the site's catch-all page route answers any). */
    private static function serves(ApplicationContext $context, string $path): bool
    {
        $match = $context->getContainer()->get(Router::class)->match(Request::create($path, 'GET'));
        $handler = ($match['route'] ?? null)?->getHandler();
        return is_array($handler) && ($handler[0] ?? null) === CommerceMetaController::class;
    }

    private function states(): CapabilityStateStore
    {
        return $this->container()->get(CapabilityStateStore::class);
    }

    public function testTurningCommerceOnServesItsRoutesOnTheNextContext(): void
    {
        $before = self::request();                                     // Commerce off: compiled without it
        self::assertFalse(self::serves($before, self::COMMERCE_ROUTE));
        self::assertFileExists($this->tableFile(), 'the off-state table was compiled and saved');

        $gen = $this->container()->get(ActivationStore::class)->startOrJoin('thallo.commerce', 't')->generation;
        $this->runner()->run('thallo.commerce', $gen, freshBoot: false);
        $this->runner()->run('thallo.commerce', $gen, freshBoot: true);
        self::assertTrue($this->states()->fresh('thallo.commerce'));

        self::assertTrue(self::serves(self::request(), self::COMMERCE_ROUTE), 'the next context serves it');
    }

    public function testTurningOffRemovesAccessOnTheNextContext(): void
    {
        $this->states()->put('thallo.commerce', true);
        self::assertTrue(self::serves(self::request(), self::COMMERCE_ROUTE));   // compiled with Commerce

        $this->states()->put('thallo.commerce', false);
        self::assertFalse(self::serves(self::request(), self::COMMERCE_ROUTE), 'the next context drops it');
    }

    public function testAContextThatBootedBeforeTheSwitchCannotMakeItsRebuiltTableUsable(): void
    {
        $stale = self::request();                                      // booted under N, Commerce off
        @unlink($this->tableFile());                                   // cold: nothing compiled yet
        $this->states()->put('thallo.commerce', true);                 // the switch moves to N+1
        (new RouteCache($stale))->save($stale->getContainer()->get(Router::class));   // A saves late
        self::assertFileExists($this->tableFile());

        self::assertTrue(
            self::serves(self::request(), self::COMMERCE_ROUTE),
            'a context under N+1 rejects the table saved under N',
        );
    }

    public function testAContextUnderTheSameStateReusesTheCompiledTable(): void
    {
        self::request();                                               // compiles and saves
        $next = self::request();
        self::assertTrue(
            $next->getContainer()->get(Router::class)->wasLoadedFromCache(),
            'keying by the state must not make every request recompile',
        );
    }

    public function testATableKeyedWithoutASnapshotIsNeverReused(): void
    {
        // A context whose snapshot can't be taken (its container can't give one) keys its table so
        // that no other context, unavailable or not, ever matches it.
        $failing = new class () implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('database unreachable');
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
        $keys = [];
        foreach ([1, 2] as $attempt) {
            $context = new ApplicationContext(dirname(__DIR__, 3), 'testing');
            \Thallo\Core\Providers\CoreServiceProvider::keyRouteTableByCapabilityState($context, $failing);
            $keys[] = $context->routeSignatureInputs()['thallo.capability_state'] ?? null;
        }
        self::assertStringStartsWith('unavailable', (string) $keys[0]);
        self::assertNotSame($keys[0], $keys[1], 'two unavailable contexts never share a table');
    }

    public function testAFailureMidFinalizationKeepsTheOldRoutesAndItStaysOff(): void
    {
        self::assertFalse(self::serves(self::request(), self::COMMERCE_ROUTE));
        $gen = $this->container()->get(ActivationStore::class)->startOrJoin('thallo.commerce', 't')->generation;
        $this->runner()->run('thallo.commerce', $gen, freshBoot: false);
        ActivationRunner::$crashProbe = static function (string $at): void {
            if ($at === 'before_commit') {
                throw new \RuntimeException('killed');
            }
        };
        try {
            $this->runner()->run('thallo.commerce', $gen, freshBoot: true);
        } catch (\RuntimeException) {
        }
        ActivationRunner::$crashProbe = null;

        self::assertNotTrue($this->states()->fresh('thallo.commerce'), 'still off');
        self::assertFalse(self::serves(self::request(), self::COMMERCE_ROUTE), 'the old table still answers');
    }
}
