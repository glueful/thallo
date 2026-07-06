<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\EntryController;
use App\Content\Http\DTOs\CreateEntryData;
use App\Content\Http\DTOs\Requests\EntryListQuery;
use App\Content\Http\DTOs\SaveDraftData;
use App\Content\Localization\ContentLocaleService;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Schema\Migration\SchemaProjector;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\FakeLocaleManager;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

final class EntryListApiTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'page', 'name' => 'Page',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }

    private function controller(LocaleManagerInterface $locales = new FakeLocaleManager()): EntryController
    {
        return new EntryController(
            $this->appContext(),
            new EntryRepository(
                $this->connection(),
                $this->appContext(),
                new ContentTypeRepository($this->connection()),
            ),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new RouteRepository($this->connection()),
            new ReferenceProjectionRepository($this->connection()),
            new ContentLocaleService($this->appContext(), $locales),
            $this->container()->get(SchemaProjector::class),
        );
    }

    /** @param class-string<RequestData> $dto @param array<string,mixed> $body */
    private function hydrate(string $dto, array $body): RequestData
    {
        return (new RequestDataHydrator())->hydrate($dto, $body);
    }

    private function newEntryWithTitle(string $title): string
    {
        $create = $this->controller()->store(
            $this->hydrate(CreateEntryData::class, ['content_type' => 'page', 'locale' => 'en']),
            new Request(),
        );
        $uuid = json_decode((string) $create->getContent(), true)['data']['entry']['uuid'];
        $this->controller()->saveDraft(
            $this->hydrate(SaveDraftData::class, ['fields' => ['title' => $title], 'lock_version' => 0]),
            new Request(),
            $uuid,
            'en',
        );
        return $uuid;
    }

    /** @param array<string,mixed> $query */
    private function listQuery(array $query): EntryListQuery
    {
        /** @var EntryListQuery $dto */
        $dto = (new RequestDataHydrator())->hydrate(EntryListQuery::class, [], [], $query);
        return $dto;
    }

    public function testListReturnsDraftInclusiveRowsWithDisplayTitleAndStatus(): void
    {
        $this->newEntryWithTitle('Home');
        $resp = $this->controller()->index($this->listQuery(['type' => 'page']));

        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertCount(1, $data['entries']);
        $row = $data['entries'][0];
        self::assertSame('Home', $row['display_title'], 'display title derives from default-locale draft title');
        self::assertSame('draft', $row['status'], 'an unpublished entry is draft (the list is draft-inclusive)');
        self::assertSame(['en'], $row['locales']);
        self::assertArrayHasKey('uuid', $row);
        self::assertArrayHasKey('updated_at', $row);
        self::assertSame(1, $data['total']);
        self::assertSame(1, $data['current_page']);
    }

    public function testUnknownTypeReturns404(): void
    {
        $resp = $this->controller()->index($this->listQuery(['type' => 'nope']));
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testMissingTypeIsRejectedByValidation(): void
    {
        // `type` is required on EntryListQuery, so hydration fails (the router turns this into a
        // 422 in the HTTP flow) BEFORE the controller runs — the 422 is the DTO's job, not index()'s.
        $this->expectException(\Glueful\Validation\ValidationException::class);
        $this->listQuery([]);
    }

    public function testDisplayTitleFallsBackToUuidWhenNoTitleOrRoute(): void
    {
        // Create an entry but never save a title field.
        $create = $this->controller()->store(
            $this->hydrate(CreateEntryData::class, ['content_type' => 'page', 'locale' => 'en']),
            new Request(),
        );
        $uuid = json_decode((string) $create->getContent(), true)['data']['entry']['uuid'];

        $resp = $this->controller()->index($this->listQuery(['type' => 'page']));
        $row = json_decode((string) $resp->getContent(), true)['data']['entries'][0];
        self::assertSame($uuid, $row['display_title'], 'falls back to the entry uuid');
    }

    public function testQueryFilterMatchesDisplayTitle(): void
    {
        $this->newEntryWithTitle('Alpha');
        $this->newEntryWithTitle('Beta');

        $resp = $this->controller()->index($this->listQuery(['type' => 'page', 'q' => 'alph']));
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertCount(1, $data['entries']);
        self::assertSame(1, $data['total']);
        self::assertSame('Alpha', $data['entries'][0]['display_title']);
    }

    public function testListResponseMatchesDtoShape(): void
    {
        $this->newEntryWithTitle('Home');
        $resp = $this->controller()->index($this->listQuery(['type' => 'page']));
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Entries\EntryListData::class);
        self::assertDataMatchesDtoShape(
            $data['entries'][0],
            \App\Content\Http\DTOs\Responses\Entries\EntryListItemData::class,
        );
    }

    private function publishService(): PublishService
    {
        return new PublishService(
            $this->appContext(),
            new EntryRepository(
                $this->connection(),
                $this->appContext(),
                new ContentTypeRepository($this->connection()),
            ),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
    }

    /**
     * Verify that listForType returns status='published' for an entry whose
     * entry_publications row exists. Without this, a broken publication lookup
     * would return 'draft' for every entry and the test would still pass.
     */
    public function testPublishedEntryAppearsAsPublishedInList(): void
    {
        $uuid = $this->newEntryWithTitle('Published Page');

        // Publish through the real PublishService so the entry_publications row is written.
        $this->publishService()->publish($uuid, 'en', 'user00000001');

        $resp = $this->controller()->index($this->listQuery(['type' => 'page']));
        self::assertSame(200, $resp->getStatusCode());

        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertCount(1, $data['entries']);
        $row = $data['entries'][0];
        self::assertSame($uuid, $row['uuid']);
        self::assertSame('published', $row['status'], 'a published entry must appear as published in the admin list');
    }
}
