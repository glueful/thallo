<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Enums\ScheduleAction;
use App\Content\Http\Controllers\ScheduleController;
use App\Content\Http\DTOs\ScheduleData;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ScheduleRepository;
use App\Tests\Support\AppTestCase;
use Glueful\Auth\UserIdentity;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

final class ScheduleApiTest extends AppTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }

    public function testPostCreatesPendingReturnsRowWithReplacedFalse(): void
    {
        $entry = $this->entry();

        $response = $this->controller()->store(
            $this->input(['action' => 'publish', 'run_at' => '2999-07-01T09:00:00Z']),
            $this->request(),
            $entry,
            'en',
        );

        self::assertSame(201, $response->getStatusCode());
        $schedule = $this->json($response)['data']['schedule'];
        self::assertFalse($schedule['replaced']);
        self::assertSame('pending', $schedule['status']);
        self::assertSame('user00000001', $schedule['created_by']);
    }

    public function testSecondPostSameActionReplacesAndReturnsReplacedTrue(): void
    {
        $entry = $this->entry();
        $this->controller()->store(
            $this->input(['action' => 'publish', 'run_at' => '2999-07-01T09:00:00Z']),
            $this->request(),
            $entry,
            'en',
        );
        $first = $this->schedules()->forEntry($entry)[0];
        $this->connection()->table('entry_schedules')
            ->where('uuid', '=', $first['uuid'])
            ->update(['status' => 'done']);
        $this->controller()->store(
            $this->input(['action' => 'publish', 'run_at' => '2999-07-02T09:00:00Z']),
            $this->request(),
            $entry,
            'en',
        );

        $response = $this->controller()->store(
            $this->input(['action' => 'publish', 'run_at' => '2999-07-03T09:00:00Z']),
            $this->request(),
            $entry,
            'en',
        );

        $schedule = $this->json($response)['data']['schedule'];
        self::assertTrue($schedule['replaced']);
        self::assertSame('2999-07-03T09:00:00Z', $schedule['run_at']);
        self::assertSame(['pending', 'done'], array_column($this->schedules()->forEntry($entry), 'status'));
    }

    public function testPostRejectsPastRunAt(): void
    {
        $response = $this->controller()->store(
            $this->input(['action' => 'publish', 'run_at' => '2020-01-01T00:00:00Z']),
            $this->request(),
            $this->entry(),
            'en',
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testPostRejectsNaiveRunAt(): void
    {
        $response = $this->controller()->store(
            $this->input(['action' => 'publish', 'run_at' => '2999-07-01T09:00:00']),
            $this->request(),
            $this->entry(),
            'en',
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testPostRejectsUnknownAction(): void
    {
        $response = $this->controller()->store(
            $this->input(['action' => 'archive', 'run_at' => '2999-07-01T09:00:00Z']),
            $this->request(),
            $this->entry(),
            'en',
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testPostRejectsMissingEntry(): void
    {
        $response = $this->controller()->store(
            $this->input(['action' => 'publish', 'run_at' => '2999-07-01T09:00:00Z']),
            $this->request(),
            'missingentry0',
            'en',
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->schedules()->forEntry('missingentry0'));
    }

    public function testPostRejectsDeletedEntry(): void
    {
        $entry = $this->entry();
        $this->entries()->softDelete($entry);

        $response = $this->controller()->store(
            $this->input(['action' => 'publish', 'run_at' => '2999-07-01T09:00:00Z']),
            $this->request(),
            $entry,
            'en',
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->schedules()->forEntry($entry));
    }

    public function testGetListsSchedulesIncludingHistory(): void
    {
        $entry = $this->entry();
        $row = $this->schedules()->schedule($entry, 'en', ScheduleAction::Publish, '2999-07-01T09:00:00Z', null);
        $this->connection()->table('entry_schedules')->where('uuid', '=', $row['uuid'])->update(['status' => 'done']);
        $this->schedules()->schedule($entry, 'en', ScheduleAction::Unpublish, '2999-07-02T09:00:00Z', null);

        $response = $this->controller()->index($this->request(), $entry);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['pending', 'done'], array_column($this->json($response)['data']['schedules'], 'status'));
    }

    public function testDeleteCancelsPendingRow(): void
    {
        $entry = $this->entry();
        $row = $this->schedules()->schedule($entry, 'en', ScheduleAction::Publish, '2999-07-01T09:00:00Z', null);

        $response = $this->controller()->destroy($this->request(), $entry, $row['uuid']);

        self::assertSame(200, $response->getStatusCode());
        $stored = $this->schedules()->find($row['uuid']);
        self::assertSame('canceled', $stored['status']);
        self::assertSame('user00000001', $stored['canceled_by']);
        self::assertNotNull($stored['canceled_at']);
    }

    public function testDeleteOnTerminalReturns409(): void
    {
        $entry = $this->entry();
        $row = $this->schedules()->schedule($entry, 'en', ScheduleAction::Publish, '2999-07-01T09:00:00Z', null);
        $this->connection()->table('entry_schedules')->where('uuid', '=', $row['uuid'])->update(['status' => 'done']);

        $response = $this->controller()->destroy($this->request(), $entry, $row['uuid']);

        self::assertSame(409, $response->getStatusCode());
    }

    public function testDeleteIsEntryScoped(): void
    {
        $entry = $this->entry();
        $other = $this->entry();
        $row = $this->schedules()->schedule($entry, 'en', ScheduleAction::Publish, '2999-07-01T09:00:00Z', null);

        $response = $this->controller()->destroy($this->request(), $other, $row['uuid']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('pending', $this->schedules()->find($row['uuid'])['status']);
    }

    public function testRunAtIsNormalizedToUtcInResponse(): void
    {
        $response = $this->controller()->store(
            $this->input(['action' => 'publish', 'run_at' => '2999-07-01T09:00:00+02:00']),
            $this->request(),
            $this->entry(),
            'en',
        );

        self::assertSame('2999-07-01T07:00:00Z', $this->json($response)['data']['schedule']['run_at']);
    }

    private function controller(): ScheduleController
    {
        return $this->container()->get(ScheduleController::class);
    }

    private function input(array $body): ScheduleData
    {
        /** @var ScheduleData $dto */
        $dto = $this->hydrate(ScheduleData::class, $body);
        return $dto;
    }

    /**
     * @param class-string<RequestData> $dtoClass
     * @param array<string,mixed> $body
     */
    private function hydrate(string $dtoClass, array $body): RequestData
    {
        return (new RequestDataHydrator())->hydrate($dtoClass, $body);
    }

    private function request(): Request
    {
        $request = new Request();
        $request->attributes->set('auth.user', new UserIdentity('user00000001'));

        return $request;
    }

    private function entry(): string
    {
        $entry = $this->entries()->createEntry($this->type, 'en', 1, 'user00000001');
        $this->entries()->saveDraft($entry, 'en', ['title' => 'V1'], 1, 0, 'user00000001');

        return $entry;
    }

    private function schedules(): ScheduleRepository
    {
        return $this->container()->get(ScheduleRepository::class);
    }

    private function entries(): EntryRepository
    {
        return $this->container()->get(EntryRepository::class);
    }

    /**
     * @return array<string,mixed>
     */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
