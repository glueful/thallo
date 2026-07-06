<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Content\Enums\ScheduleAction;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ScheduleRepository;
use App\Content\Scheduling\ScheduleRunner;
use App\Tests\Integration\Workflow\Concerns\GrantsPermissions;
use App\Tests\Support\AppTestCase;
use Thallo\Workflow\WorkflowService;
use Thallo\Workflow\WorkflowStateRepository;

/**
 * Spec-pinned scheduled-publish semantics: ONE uniform gate rule evaluated at RUN time
 * with the schedule's stored created_by actor. No approval + no bypass → the schedule
 * fails; a bypass-holding creator publishes (recorded as published_with_bypass); an
 * approved draft publishes for anyone.
 */
final class WorkflowScheduledPublishTest extends AppTestCase
{
    use GrantsPermissions;

    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }

    private function entry(): string
    {
        $entries = $this->container()->get(EntryRepository::class);
        $entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'V1'], 1, 0, 'user00000001');
        return $entry;
    }

    /** @return array<string,mixed> */
    private function due(string $entry, string $createdBy): array
    {
        return $this->container()->get(ScheduleRepository::class)->schedule(
            $entry,
            'en',
            ScheduleAction::Publish,
            '2020-01-01T00:00:00Z',
            $createdBy,
        );
    }

    public function testScheduledPublishOfUnapprovedContentFails(): void
    {
        $entry = $this->entry();
        $row = $this->due($entry, 'nobypass0001');

        self::assertSame(1, $this->container()->get(ScheduleRunner::class)->run());

        $stored = $this->container()->get(ScheduleRepository::class)->find($row['uuid']);
        self::assertSame('failed', $stored['status']);
        self::assertStringContainsString('approved review', (string) $stored['failure_reason']);
    }

    public function testScheduledPublishByBypassHolderSucceedsAndIsRecorded(): void
    {
        $entry = $this->entry();
        $admin = 'schedbyp' . substr(hash('sha256', __FUNCTION__), 0, 4);
        $this->grantPermission($admin, 'workflow.bypass');
        $row = $this->due($entry, $admin);

        self::assertSame(1, $this->container()->get(ScheduleRunner::class)->run());

        $stored = $this->container()->get(ScheduleRepository::class)->find($row['uuid']);
        self::assertSame('done', $stored['status']);
        $history = $this->container()->get(WorkflowStateRepository::class)->history($entry, 'en');
        self::assertSame('published_with_bypass', $history[0]['action']);
        self::assertSame($admin, $history[0]['actor_uuid']);
    }

    public function testScheduledPublishOfApprovedContentSucceeds(): void
    {
        $entry = $this->entry();
        $wf = $this->container()->get(WorkflowService::class);
        $wf->submit($entry, 'en', 'author000001', null);
        $wf->approve($entry, 'en', 'review000001', null);
        $row = $this->due($entry, 'nobypass0002');

        self::assertSame(1, $this->container()->get(ScheduleRunner::class)->run());

        $stored = $this->container()->get(ScheduleRepository::class)->find($row['uuid']);
        self::assertSame('done', $stored['status']);
        $history = $this->container()->get(WorkflowStateRepository::class)->history($entry, 'en');
        self::assertSame('published', $history[0]['action']);
    }
}
