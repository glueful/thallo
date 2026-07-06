<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\AppTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Thallo\Collections\Events\CollectionCreated;
use Symfony\Component\HttpFoundation\Request;

/**
 * Removability contract: with lemma.analytics DISABLED at boot, the admin route surface is entirely
 * absent — GET /v1/admin/analytics/summary returns 404 (route unregistered), not 401 from a
 * live-but-disabled auth gate.
 */
final class AnalyticsRemovabilityTest extends AppTestCase
{
    private static ?ApplicationContext $disabledApp = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass(); // shared ENABLED app

        self::$disabledApp ??= self::bootAppWithConfigOverride('lemma', [
            'capabilities' => ['lemma.analytics' => false],
        ]);
    }

    public function testDisabledBootAnalyticsRouteReturns404(): void
    {
        $response = (new Application(self::$disabledApp))->handle(
            Request::create('/v1/admin/analytics/summary', 'GET', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT'  => 'application/json',
            ]),
        );

        self::assertSame(
            404,
            $response->getStatusCode(),
            'Disabled-boot GET /v1/admin/analytics/summary must be 404 (route unregistered), got: '
            . $response->getStatusCode() . ' body: ' . $response->getContent()
        );
    }

    public function testDisabledBootSuppressesCollectionIngestion(): void
    {
        $container = self::$disabledApp->getContainer();
        $events = $container->get(EventService::class);
        $events->dispatch(new CollectionCreated('disabled_probe', 'admin', 'u-x'));

        $connection = $container->get(Connection::class);
        $count = (int) $connection->table('analytics_facts')
            ->where('event', 'collections.collection.created')
            ->where('subject_id', 'disabled_probe')
            ->count();

        self::assertSame(
            0,
            $count,
            'Disabled analytics must not ingest collection events — zero rows expected for this dispatch.'
        );
    }
}
