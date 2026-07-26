<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Search\ContentReindexer;
use Thallo\Search\Index\ResilientContentReindexer;
use Symfony\Component\HttpFoundation\Request;

/**
 * thallo-search is an always-loaded module whose behaviour is OPT-IN via the `thallo.search`
 * capability (disabled in config/thallo.php by default — a lean install resolves the no-op
 * reindexer and does not register /v1/search; see CapabilityGatingTest +
 * SearchEndpointTest::testRouteAbsentByDefaultBecauseCapabilityIsOff). This proves the other
 * direction: once the capability is switched on, the boot gate registers /v1/search and the
 * reindexer seam resolves to the real (resilient) implementation. Dedicated enabled boot via a
 * temp config/testing/thallo.php override (array_replace_recursive keeps every other thallo
 * key); the committed config/testing/extensions.php tenancy-off shield applies automatically.
 */
final class SearchEnablementTest extends AppTestCase
{
    private static ?ApplicationContext $enabledApp = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$enabledApp !== null) {
            return;
        }

        self::$enabledApp = self::bootAppWithConfigOverride('thallo', [
            'capabilities' => ['thallo.search' => true],
        ]);
    }

    public function testRouteRegisteredWhenEnabled(): void
    {
        // With Meilisearch unreachable in tests the handler fails closed (503); a running server
        // would return 200. Either way it is NOT 404 — the route is registered.
        $status = (new Application(self::$enabledApp))->handle(
            Request::create('/v1/search?q=x&locale=en', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
        )->getStatusCode();
        self::assertNotSame(404, $status, 'enabling the thallo.search capability must register /v1/search');
    }

    public function testReindexerBoundToResilientWhenEnabled(): void
    {
        $reindexer = self::$enabledApp->getContainer()->get(ContentReindexer::class);
        self::assertInstanceOf(ResilientContentReindexer::class, $reindexer);
    }
}
