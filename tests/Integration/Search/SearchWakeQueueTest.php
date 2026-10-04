<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Search;

use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Search\SearchOnApp;
use Thallo\Search\Lifecycle\SearchWakeJob;
use Thallo\Search\SearchServiceProvider;

/**
 * The wake-up queued after a rebuild request lands on a queue the documented workers take
 * (`default`): a job on a queue nobody works waits forever. Handled, it reconciles the workspace.
 */
final class SearchWakeQueueTest extends AppTestCase
{
    public function testTheWakeUpGoesToTheDefaultQueue(): void
    {
        $db = $this->connection();
        $before = $db->table('queue_jobs')->count();

        (SearchServiceProvider::wake($this->appContext()))(['workspace' => null]);

        $rows = array_slice($db->table('queue_jobs')->orderBy('id', 'ASC')->get(), $before);
        self::assertCount(1, $rows);
        self::assertSame('default', $rows[0]['queue']);
        self::assertStringContainsString(str_replace('\\', '\\\\', SearchWakeJob::class), (string) $rows[0]['payload']);
    }

    public function testHandlingTheWakeUpBuildsWhatIsDue(): void
    {
        $site = new SearchOnApp(self::bootAppWithConfigOverride(
            'thallo',
            ['capabilities' => ['thallo.search' => true]],
        ));
        $site->publish('post', 'rose', 'Rose garden', 'Roses in the morning');

        (new SearchWakeJob(['workspace' => null], $site->app))->handle();

        $data = SearchOnApp::data($site->get('/v1/search?q=rose&locale=en'));
        self::assertSame(1, $data['total']);
    }
}
