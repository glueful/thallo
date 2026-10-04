<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Jobs\RunConsoleCommandJob;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\SearchOnApp;
use Thallo\Search\Lifecycle\SearchWakeJob;

/**
 * The search pack's entry points, each through the path production takes: the scheduled jobs as
 * `config/schedule.php` declares them, boot recovery on a real request, the block's assets, and
 * suggestions on a site whose index was never built (search block spec Review Focus 1).
 */
final class SearchEntryPointsTest extends AppTestCase
{
    private SearchOnApp $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = new SearchOnApp(self::bootAppWithConfigOverride(
            'thallo',
            ['capabilities' => ['thallo.search' => true]],
        ));
    }

    public function testTheScheduledFullReconcileBuildsTheIndex(): void
    {
        $this->site->publish('post', 'rose', 'Rose garden', 'Roses in the morning');
        (new RunConsoleCommandJob($this->scheduled('search_reconcile_full'), $this->site->app))->handle();

        self::assertSame(1, SearchOnApp::data($this->site->get('/v1/search?q=rose&locale=en'))['total']);
    }

    public function testTheScheduledAvailabilityPurgeRuns(): void
    {
        (new RunConsoleCommandJob($this->scheduled('render_availability_purge'), $this->site->app))->handle();
        $this->addToAssertionCount(1); // it ran to completion through the job, as the scheduler runs it
    }

    public function testARequestQueuesAWakeUpForOutstandingDemandAndBuildsNothingItself(): void
    {
        $db = $this->site->app->getContainer()->get(\Glueful\Database\Connection::class);
        $before = $db->table('queue_jobs')->count();

        $res = $this->site->page('/');

        self::assertLessThan(500, $res->getStatusCode());
        $jobs = array_slice($db->table('queue_jobs')->orderBy('id', 'ASC')->get(), $before);
        $wakes = array_filter($jobs, static fn (array $j): bool => str_contains(
            (string) $j['payload'],
            str_replace('\\', '\\\\', SearchWakeJob::class),
        ));
        self::assertCount(1, $wakes, 'boot recovery queued the build');
        $built = $db->table('search_index_state')->whereNotNull('active_target')->count();
        self::assertSame(0, $built, 'not built in the request');
    }

    public function testSuggestionsOnANeverBuiltSiteSayRebuilding(): void
    {
        $res = $this->site->get('/_search/suggest?q=rose&locale=en');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getContent());
        self::assertSame('rebuilding', SearchOnApp::data($res)['state']);
        self::assertSame([], SearchOnApp::data($res)['items']);
    }

    public function testTheBlockAssetsRedirectToTheirFingerprintAndServeImmutably(): void
    {
        $plain = $this->site->page('/_thallo/search/search.js');
        self::assertSame(302, $plain->getStatusCode());
        $location = (string) $plain->headers->get('Location');
        self::assertMatchesRegularExpression('#^/_thallo/search/search-[a-f0-9]+\.js$#', $location);

        $served = $this->site->page($location);
        self::assertSame(200, $served->getStatusCode());
        self::assertStringContainsString('javascript', (string) $served->headers->get('Content-Type'));
        self::assertStringContainsString('immutable', (string) $served->headers->get('Cache-Control'));

        self::assertSame(404, $this->site->page('/_thallo/search/nope.js')->getStatusCode());
    }

    /** @return array<string, mixed> the job's parameters exactly as config/schedule.php declares them */
    private function scheduled(string $name): array
    {
        $jobs = (array) (require dirname(__DIR__, 3) . '/config/schedule.php')['jobs'];
        foreach ($jobs as $job) {
            if (($job['name'] ?? null) === $name) {
                return (array) $job['parameters'];
            }
        }
        self::fail("No scheduled job named {$name}.");
    }
}
