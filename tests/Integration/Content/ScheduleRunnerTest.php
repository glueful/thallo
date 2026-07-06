<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Enums\ScheduleAction;
use App\Content\Enums\ScheduleStatus;
use App\Content\Events\EntryPublished;
use App\Content\Events\EntryUnpublished;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ScheduleRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Scheduling\ScheduleRunner;
use App\Tests\Support\AppTestCase;
use Glueful\Events\EventService;

final class ScheduleRunnerTest extends AppTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->type = $this->types()->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }

    public function testPublishScheduleFiresDeferredPublish(): void
    {
        $entry = $this->entry(['title' => 'V1']);
        $row = $this->due($entry, ScheduleAction::Publish);
        $captured = $this->spyEvents();

        self::assertSame(1, $this->runner()->run());

        self::assertSame('done', $this->schedules()->find($row['uuid'])['status']);
        self::assertNotNull($this->versions()->findPublication($entry, 'en'));
        self::assertCount(1, $captured['published']);
        self::assertSame($entry, $captured['published'][0]->entry);
    }

    public function testUnpublishScheduleFires(): void
    {
        $entry = $this->entry(['title' => 'V1']);
        $this->container()->get(\App\Content\Services\PublishService::class)->publish($entry, 'en', 'user00000001');
        $row = $this->due($entry, ScheduleAction::Unpublish);
        $captured = $this->spyEvents();

        self::assertSame(1, $this->runner()->run());

        self::assertSame('done', $this->schedules()->find($row['uuid'])['status']);
        self::assertNull($this->versions()->findPublication($entry, 'en'));
        self::assertCount(1, $captured['unpublished']);
        self::assertSame($entry, $captured['unpublished'][0]->entry);
    }

    public function testInvalidDraftAtFireTimeRecordsFailedAndLeavesEntryUnchanged(): void
    {
        $entry = $this->entry([]);
        $row = $this->due($entry, ScheduleAction::Publish);

        self::assertSame(1, $this->runner()->run());

        $stored = $this->schedules()->find($row['uuid']);
        self::assertSame('failed', $stored['status']);
        self::assertNotSame('', (string) $stored['failure_reason']);
        self::assertSame([], $this->versions()->versionsFor($entry, 'en'));
        self::assertNull($this->versions()->findPublication($entry, 'en'));
    }

    public function testSoftDeletedEntryIsCanceled(): void
    {
        $entry = $this->entry(['title' => 'V1']);
        $row = $this->due($entry, ScheduleAction::Publish);
        $this->entries()->softDelete($entry);

        self::assertSame(1, $this->runner()->run());

        $stored = $this->schedules()->find($row['uuid']);
        self::assertSame('canceled', $stored['status']);
        self::assertStringContainsString('target entry', (string) $stored['failure_reason']);
    }

    public function testUnpublishAlreadyUnpublishedIsDone(): void
    {
        $entry = $this->entry(['title' => 'V1']);
        $row = $this->due($entry, ScheduleAction::Unpublish);

        self::assertSame(1, $this->runner()->run());

        self::assertSame('done', $this->schedules()->find($row['uuid'])['status']);
        self::assertNull($this->versions()->findPublication($entry, 'en'));
    }

    public function testTerminalRowsAreNotRefired(): void
    {
        $entry = $this->entry(['title' => 'V1']);
        $row = $this->due($entry, ScheduleAction::Publish);
        $this->connection()->table('entry_schedules')
            ->where('uuid', '=', $row['uuid'])
            ->update(['status' => ScheduleStatus::Done->value]);

        self::assertSame(0, $this->runner()->run());
        self::assertNull($this->versions()->findPublication($entry, 'en'));
    }

    public function testAttemptsIncremented(): void
    {
        $entry = $this->entry(['title' => 'V1']);
        $row = $this->due($entry, ScheduleAction::Publish);

        $this->runner()->run();

        self::assertSame(1, (int) $this->schedules()->find($row['uuid'])['attempts']);
    }

    public function testPublishFailureRecordsFailedAndSuccessEmitsOnce(): void
    {
        $invalid = $this->entry([]);
        $failed = $this->due($invalid, ScheduleAction::Publish);
        $valid = $this->entry(['title' => 'V1']);
        $done = $this->due($valid, ScheduleAction::Publish);
        $captured = $this->spyEvents();

        self::assertSame(2, $this->runner()->run());

        self::assertSame('failed', $this->schedules()->find($failed['uuid'])['status']);
        self::assertSame('done', $this->schedules()->find($done['uuid'])['status']);
        self::assertSame([], $this->versions()->versionsFor($invalid, 'en'));
        self::assertCount(1, $captured['published']);
        self::assertSame($valid, $captured['published'][0]->entry);
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function entry(array $fields): string
    {
        $entry = $this->entries()->createEntry($this->type, 'en', 1, 'user00000001');
        $this->entries()->saveDraft($entry, 'en', $fields, 1, 0, 'user00000001');

        return $entry;
    }

    /**
     * @return array<string,mixed>
     */
    private function due(string $entry, ScheduleAction $action): array
    {
        return $this->schedules()->schedule(
            $entry,
            'en',
            $action,
            '2020-01-01T00:00:00Z',
            'user00000001',
        );
    }

    /**
     * @return \ArrayObject<string,list<object>>
     */
    private function spyEvents(): \ArrayObject
    {
        /** @var \ArrayObject<string,list<object>> $captured */
        $captured = new \ArrayObject(['published' => [], 'unpublished' => []]);
        $events = $this->container()->get(EventService::class);
        $events->addListener(
            EntryPublished::class,
            static function (EntryPublished $event) use ($captured): void {
                $captured['published'] = [...$captured['published'], $event];
            },
        );
        $events->addListener(
            EntryUnpublished::class,
            static function (EntryUnpublished $event) use ($captured): void {
                $captured['unpublished'] = [...$captured['unpublished'], $event];
            },
        );

        return $captured;
    }

    private function runner(): ScheduleRunner
    {
        return $this->container()->get(ScheduleRunner::class);
    }

    private function schedules(): ScheduleRepository
    {
        return $this->container()->get(ScheduleRepository::class);
    }

    private function entries(): EntryRepository
    {
        return $this->container()->get(EntryRepository::class);
    }

    private function types(): ContentTypeRepository
    {
        return new ContentTypeRepository($this->connection());
    }

    private function versions(): VersionRepository
    {
        return new VersionRepository($this->connection());
    }
}
