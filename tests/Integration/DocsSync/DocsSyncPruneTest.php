<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Glueful\Storage\StorageManager;
use Thallo\Core\Content\Jobs\DocsSyncPruneJob;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Pruning (docs sync design §4) runs from a recurring task, so an idle site still loses its old
 * archives after a day and its old runs after the retention, keeping the last sync's history.
 */
final class DocsSyncPruneTest extends AppTestCase
{
    use DocsSyncTestHelpers;

    protected function tearDown(): void
    {
        $disk = $this->container()->get(StorageManager::class)->disk('uploads');
        foreach (['run000000001', 'run000000002'] as $id) {
            $disk->deleteDirectory('docs-sync/' . $id);
        }
        $this->cleanDocsSync();
        parent::tearDown();
    }

    private function finishedRun(string $id, int $finishedSecondsAgo): void
    {
        $db = $this->connection();
        $db->table('docs_sync_runs')->insert([
            'id' => $id, 'revision' => 1, 'source' => '{}', 'mode' => 'commit', 'status' => 'finished',
            'outcome' => 'success', 'created_at' => self::ago($finishedSecondsAgo + 60),
            'finished_at' => self::ago($finishedSecondsAgo),
        ]);
        $db->table('docs_sync_run_pages')->insert(['run_id' => $id, 'path' => 'a.md', 'status' => 'created']);
        $this->container()->get(StorageManager::class)->disk('uploads')
            ->write('docs-sync/' . $id . '/token.zip', 'zip bytes');
    }

    private function prune(): void
    {
        $job = new DocsSyncPruneJob([], $this->appContext());
        $job->useService($this->service());
        $job->handle();
    }

    public function testAnIdleSiteIsPrunedAcrossBothDeadlines(): void
    {
        $disk = $this->container()->get(StorageManager::class)->disk('uploads');
        $this->saveSource();
        $this->finishedRun('run000000001', 3600);          // the last sync, an hour ago
        $this->finishedRun('run000000002', 3 * 86400);     // an older one
        $this->setState(['synced_run' => 'run000000001']);

        $this->prune();
        self::assertTrue($disk->fileExists('docs-sync/run000000001/token.zip'), 'under a day old: kept');
        self::assertFalse($disk->fileExists('docs-sync/run000000002/token.zip'), 'over a day old: gone');

        // A day on: the last sync's archive goes too, its history stays.
        $this->setRun('run000000001', ['finished_at' => self::ago(2 * 86400)]);
        $this->prune();
        self::assertFalse($disk->fileExists('docs-sync/run000000001/token.zip'));
        self::assertNotSame([], $this->runRow('run000000001'));
        self::assertNotSame([], $this->runRow('run000000002'));

        // Past the retention (30 days): the old run and its pages go; the last sync stays.
        $this->setRun('run000000001', ['finished_at' => self::ago(31 * 86400)]);
        $this->setRun('run000000002', ['finished_at' => self::ago(31 * 86400)]);
        $this->prune();
        self::assertSame([], $this->runRow('run000000002'));
        $pages = fn (string $run) => $this->connection()->table('docs_sync_run_pages')
            ->where('run_id', '=', $run)->get();
        self::assertCount(0, $pages('run000000002'));
        self::assertNotSame([], $this->runRow('run000000001'));
        self::assertCount(1, $pages('run000000001'));
    }
}
