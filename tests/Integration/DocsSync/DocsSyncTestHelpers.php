<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\DocsSync;

use Glueful\Queue\QueueManager;
use Thallo\Core\Content\DocsSync\DocsSyncRunRepository;
use Thallo\Core\Content\DocsSync\DocsSyncService;
use Thallo\Core\Content\DocsSync\DocsSyncSource;
use Thallo\Core\Content\DocsSync\DocsSyncStore;

/**
 * What the docs sync tests share: a saved source, rows aged by writing old timestamps, and a
 * queue that records what was pushed instead of running it.
 */
trait DocsSyncTestHelpers
{
    protected function cleanDocsSync(): void
    {
        $db = $this->connection();
        $db->table('docs_sync_run_pages')->whereNotNull('id')->delete();
        $db->table('docs_sync_runs')->whereNotNull('id')->delete();
        $db->table('docs_sync_state')->where('site', '=', 'site')->delete();
    }

    /** @param array<string,mixed> $over */
    protected function saveSource(array $over = []): DocsSyncSource
    {
        $source = DocsSyncSource::fromArray($over + [
            'repo' => 'glueful/thallo', 'folder' => 'docs', 'follow' => 'release', 'prereleases' => true,
            'content_type' => 'docs', 'publish' => true, 'exclude' => [], 'locale' => 'en',
            'edit_branch' => 'main',
        ]);
        $this->container()->get(DocsSyncStore::class)->saveSource($source);
        return $source;
    }

    protected function recordingQueue(): RecordingQueue
    {
        return new RecordingQueue();
    }

    protected function service(?QueueManager $queue = null): DocsSyncService
    {
        $c = $this->container();
        return new DocsSyncService(
            $this->connection(),
            $c->get(DocsSyncStore::class),
            $c->get(DocsSyncRunRepository::class),
            $queue ?? $this->recordingQueue(),
            $this->appContext(),
        );
    }

    protected static function ago(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() - $seconds);
    }

    /** @param array<string,mixed> $set */
    protected function setState(array $set): void
    {
        $this->connection()->table('docs_sync_state')->where('site', '=', 'site')->update($set);
    }

    /** @param array<string,mixed> $set */
    protected function setRun(string $id, array $set): void
    {
        $this->connection()->table('docs_sync_runs')->where('id', '=', $id)->update($set);
    }

    /** @return array<string,mixed> */
    protected function runRow(string $id): array
    {
        return (array) $this->connection()->table('docs_sync_runs')->where('id', '=', $id)->first();
    }

    /** @return array<string,mixed> */
    protected function stateRow(): array
    {
        return (array) $this->connection()->table('docs_sync_state')->where('site', '=', 'site')->first();
    }
}
