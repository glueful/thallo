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
 * thallo-search is OPT-IN — absent from the default config/extensions.php allow-list (a lean
 * install binds no reindexer and does not register /v1/search; see CapabilityGatingTest +
 * SearchEndpointTest::testRouteAbsentByDefaultBecausePackIsOptIn). This proves the other
 * direction: once the provider is enabled, the boot gate registers /v1/search and binds the
 * (resilient) ContentReindexer. Dedicated enabled boot via a temp config/testing/extensions.php
 * override carrying the real allow-list + this provider.
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

        // The real allow-list plus thallo-search (opt-in, so not in the default list). Read
        // through config/testing/extensions.php (the committed tenancy-off test shield), NOT the
        // raw config/extensions.php: the latter is also what `extensions:enable` mutates in
        // place, so a dogfooding workstation with tenancy switched on (or another pack newly
        // wired in) would otherwise leak unrelated provider state into this search-only boot.
        /** @var array{enabled: list<string>} $base */
        $base = require dirname(__DIR__, 3) . '/config/testing/extensions.php';
        $enabled = $base['enabled'];
        $enabled[] = 'Thallo\\Search\\SearchServiceProvider';

        self::$enabledApp = self::bootAppWithConfigOverride('extensions', ['enabled' => $enabled]);
    }

    public function testRouteRegisteredWhenEnabled(): void
    {
        // With Meilisearch unreachable in tests the handler fails closed (503); a running server
        // would return 200. Either way it is NOT 404 — the route is registered.
        $status = (new Application(self::$enabledApp))->handle(
            Request::create('/v1/search?q=x&locale=en', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
        )->getStatusCode();
        self::assertNotSame(404, $status, 'enabling thallo-search must register /v1/search');
    }

    public function testReindexerBoundToResilientWhenEnabled(): void
    {
        $reindexer = self::$enabledApp->getContainer()->get(ContentReindexer::class);
        self::assertInstanceOf(ResilientContentReindexer::class, $reindexer);
    }
}
