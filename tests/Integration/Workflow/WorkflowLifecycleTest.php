<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Content\Events\EntryPublished;
use App\Content\Events\EntryUpdated;
use App\Tests\Support\AppTestCase;
use Glueful\Events\EventService;
use Thallo\Workflow\WorkflowService;

final class WorkflowLifecycleTest extends AppTestCase
{
    public function testDraftSaveInvalidatesInReview(): void
    {
        $wf = $this->container()->get(WorkflowService::class);
        $wf->submit('entrylife001', 'en', 'author000001', null);

        $this->container()->get(EventService::class)->dispatch(new EntryUpdated(
            entry: 'entrylife001',
            type: 'typeuuid0001',
            locale: 'en',
            version: null,
            actor: 'author000001',
        ));

        $o = $wf->overview('entrylife001', 'en');
        self::assertSame('draft', $o['state']);
        self::assertSame('edit_invalidated', $o['history'][0]['action']);
    }

    public function testDraftSaveDoesNotTouchChangesRequested(): void
    {
        $wf = $this->container()->get(WorkflowService::class);
        $wf->submit('entrylife003', 'en', 'author000001', null);
        $wf->requestChanges('entrylife003', 'en', 'review000001', 'fix it');

        $this->container()->get(EventService::class)->dispatch(new EntryUpdated(
            entry: 'entrylife003',
            type: 'typeuuid0001',
            locale: 'en',
            version: null,
            actor: 'author000001',
        ));

        self::assertSame('changes_requested', $wf->overview('entrylife003', 'en')['state']);
    }

    public function testPublishEventRecordsBypassAndResets(): void
    {
        $wf = $this->container()->get(WorkflowService::class);
        $wf->submit('entrylife002', 'en', 'author000001', null);

        $this->container()->get(EventService::class)->dispatch(new EntryPublished(
            entry: 'entrylife002',
            type: 'typeuuid0001',
            locale: 'en',
            version: 1,
            actor: 'admin0000001',
        ));

        $o = $wf->overview('entrylife002', 'en');
        self::assertSame('draft', $o['state']);
        self::assertSame('published_with_bypass', $o['history'][0]['action']);
        self::assertSame('admin0000001', $o['history'][0]['actor_uuid']);
    }
}
