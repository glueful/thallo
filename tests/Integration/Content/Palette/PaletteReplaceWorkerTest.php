<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Thallo\Core\Content\Jobs\RunPaletteReplaceJob;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\Palette\PaletteFixtures;
use Thallo\Core\Tests\Support\Palette\PaletteReplaceFixtures;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * A replacement queued by a request runs from a worker process that shares nothing with it
 * (custom palette spec §4.4): the queue row carries the job and its workspace, on the queue the
 * documented workers take.
 */
final class PaletteReplaceWorkerTest extends AppTestCase
{
    use PaletteFixtures;
    use PaletteReplaceFixtures;
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replaceWorld();
    }

    public function testAQueuedReplacementRunsFromAFreshWorker(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        [$uuid] = $this->publishedEntryNaming('color.brand-1');
        $before = $this->connection()->table('queue_jobs')->count();
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $rows = array_slice($this->connection()->table('queue_jobs')->orderBy('id', 'ASC')->get(), $before);
        self::assertCount(1, $rows, 'queued after the start committed');
        self::assertSame('default', $rows[0]['queue']);
        self::assertStringContainsString('"workspace"', (string) $rows[0]['payload']);
        self::assertStringContainsString($job, (string) $rows[0]['payload']);
        // a new application, as a worker process boots one: nothing shared with the request that queued it
        $worker = self::bootAppWithConfigOverride('thallo', []);
        (new RunPaletteReplaceJob(['job_id' => $job, 'workspace' => null], $worker))->handle();
        self::assertSame('completed', $this->jobs()->find($job)?->status);
        self::assertSame('color.accent', $this->publishedToken($uuid));
        self::assertNull($this->palette()->brand(1));
    }

    public function testResumeQueuesTheSamePayload(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        $this->connection()->table('palette_jobs')->where('id', '=', $job)
            ->update(['heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 600)]);
        $before = $this->connection()->table('queue_jobs')->count();
        $this->service()->resume($job);
        $rows = array_slice($this->connection()->table('queue_jobs')->orderBy('id', 'ASC')->get(), $before);
        self::assertCount(1, $rows);
        self::assertStringContainsString($job, (string) $rows[0]['payload']);
    }

    public function testAWorkerSeesABlockTypeInstalledByAnotherProcessAfterItsFirstJob(): void
    {
        $this->configure(1, 'Gold', '#8a6a2a');
        $this->configure(2, 'Rose', '#c98a8a');
        // the worker's first job fills its block-type memo
        $this->runner()->run($this->service()->start(2, 'color.accent', null, 'user00000001'));
        // as the worker read them
        $this->container()->get(\Thallo\Core\Content\Blocks\BlockTypeRepository::class)->schemasBySlug();
        // another process installs a block type with a colour content field, and a page uses it
        (new \Thallo\Core\Content\Blocks\BlockTypeRepository($this->connection()))->create([
            'slug' => 'pfbadge', 'label' => 'Badge',
            'schema' => [['name' => 'ink', 'type' => 'token', 'domain' => 'color']],
        ]);
        $uuid = $this->draftNaming('color.accent');
        $this->connection()->table('entry_drafts')->where('entry_uuid', '=', $uuid)->update(['fields' => json_encode([
            'title' => 'Page',
            'body' => [['id' => 'badge0000001', 'type' => 'pfbadge',
                'data' => ['ink' => ['type' => 'token', 'value' => 'color.brand-1']], 'settings' => []]],
        ])]);
        $job = $this->service()->start(1, 'color.accent', null, 'user00000001');
        self::assertSame('completed', $this->runner()->run($job)['status']);
        $fields = $this->draftFields($uuid);
        $ink = $fields['body'][0]['data']['ink']['value'];
        self::assertSame('color.accent', $ink, 'the new type\'s colour was rewritten');
    }
}
