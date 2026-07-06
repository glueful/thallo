<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\EntryController;
use App\Content\Http\DTOs\AssignRouteData;
use App\Content\Http\DTOs\CopyLocaleData;
use App\Content\Http\DTOs\CreateEntryData;
use App\Content\Http\DTOs\SaveDraftData;
use App\Content\Enums\ScheduleAction;
use App\Content\Localization\ContentLocaleService;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\ScheduleRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\FakeLocaleManager;
use App\Tests\Support\AppTestCase;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

final class EntryApiTest extends AppTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Post',
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
        );
    }

    /**
     * Hydrate a request DTO exactly as the router would, so DTO validation is exercised
     * before the controller sees it. (Thallo DTOs use only built-in rules, so no registry.)
     *
     * @param  class-string<RequestData> $dtoClass
     * @param  array<string,mixed>       $body
     */
    private function hydrate(string $dtoClass, array $body): RequestData
    {
        return (new RequestDataHydrator())->hydrate($dtoClass, $body);
    }

    private function createEntryUuid(): string
    {
        $resp = $this->controller()->store(
            $this->hydrate(CreateEntryData::class, ['content_type' => 'post', 'locale' => 'en']),
            new Request(),
        );

        return json_decode($resp->getContent(), true)['data']['entry']['uuid'];
    }

    private function createLocalizedEntryUuid(): string
    {
        (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'product',
            'name' => 'Product',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true, 'localized' => true],
                ['name' => 'price', 'type' => 'number'],
            ],
        ]);

        $resp = $this->controller()->store(
            $this->hydrate(CreateEntryData::class, ['content_type' => 'product', 'locale' => 'en']),
            new Request(),
        );

        return json_decode((string) $resp->getContent(), true)['data']['entry']['uuid'];
    }

    public function testCreateEntryReturnsEntryWithEmptyDraft(): void
    {
        $resp = $this->controller()->store(
            $this->hydrate(CreateEntryData::class, ['content_type' => 'post', 'locale' => 'en']),
            new Request(),
        );
        self::assertSame(201, $resp->getStatusCode());
    }

    public function testCreateEntryRejectsDisabledLocale(): void
    {
        $resp = $this->controller(new FakeLocaleManager())->store(
            $this->hydrate(CreateEntryData::class, ['content_type' => 'post', 'locale' => 'de']),
            new Request(),
        );

        self::assertSame(422, $resp->getStatusCode());
        self::assertNull($this->connection()->table('entries')->first());
    }

    public function testStoreResponseMatchesDtoShape(): void
    {
        $resp = $this->controller()->store(
            $this->hydrate(CreateEntryData::class, ['content_type' => 'post', 'locale' => 'en']),
            new Request(),
        );
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Entries\EntryCreateResultData::class);
        self::assertDataMatchesDtoShape($data['entry'], \App\Content\Http\DTOs\Responses\Entries\EntryData::class);
        self::assertDataMatchesDtoShape($data['draft'], \App\Content\Http\DTOs\Responses\Entries\DraftData::class);
    }

    public function testShowResponseMatchesDtoShape(): void
    {
        $uuid = $this->createEntryUuid();
        $resp = $this->controller()->show(new Request(), $uuid);
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Entries\EntryResultData::class);
        self::assertDataMatchesDtoShape($data['entry'], \App\Content\Http\DTOs\Responses\Entries\EntryData::class);
    }

    public function testGetDraftResponseMatchesDtoShape(): void
    {
        $uuid = $this->createEntryUuid();
        $resp = $this->controller()->getDraft(new Request(), $uuid, 'en');
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Entries\DraftResultData::class);
        self::assertDataMatchesDtoShape($data['draft'], \App\Content\Http\DTOs\Responses\Entries\DraftData::class);
    }

    public function testSaveDraftResponseMatchesDtoShape(): void
    {
        $uuid = $this->createEntryUuid();
        $resp = $this->controller()->saveDraft(
            $this->hydrate(SaveDraftData::class, ['fields' => ['title' => 'Hello'], 'lock_version' => 0]),
            new Request(),
            $uuid,
            'en',
        );
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Entries\DraftResultData::class);
        self::assertDataMatchesDtoShape($data['draft'], \App\Content\Http\DTOs\Responses\Entries\DraftData::class);
    }

    public function testSaveDraftRejectsStaleLockWith409(): void
    {
        $uuid = $this->createEntryUuid();
        $this->controller()->saveDraft(
            $this->hydrate(SaveDraftData::class, ['fields' => ['title' => 'A'], 'lock_version' => 0]),
            new Request(),
            $uuid,
            'en',
        );
        $resp = $this->controller()->saveDraft(
            $this->hydrate(SaveDraftData::class, ['fields' => ['title' => 'B'], 'lock_version' => 0]),
            new Request(),
            $uuid,
            'en',
        );
        self::assertSame(409, $resp->getStatusCode());
    }

    public function testSaveDraftValidatesFields(): void
    {
        $uuid = $this->createEntryUuid();
        $resp = $this->controller()->saveDraft(
            $this->hydrate(SaveDraftData::class, ['fields' => ['title' => 123], 'lock_version' => 0]),
            new Request(),
            $uuid,
            'en',
        );
        self::assertSame(422, $resp->getStatusCode());
    }

    public function testDiscardDraftDeletesWorkingCopy(): void
    {
        $uuid = $this->createEntryUuid();

        $resp = $this->controller()->discardDraft(new Request(), $uuid, 'en');

        self::assertSame(200, $resp->getStatusCode());
        self::assertNull((new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        ))->findDraft($uuid, 'en'));
    }

    public function testDestroySoftDeletesEntryAndBlocksReferencedDeletes(): void
    {
        $target = $this->createEntryUuid();
        $source = $this->createEntryUuid();
        $projection = new ReferenceProjectionRepository($this->connection());
        $projection->rebuildForEntry(
            $source,
            ContentTypeSchema::fromArray([['name' => 'related', 'type' => 'reference']]),
            ['related' => $target],
            'en',
        );

        $blocked = $this->controller()->destroy(new Request(), $target);
        self::assertSame(409, $blocked->getStatusCode());

        $ok = $this->controller()->destroy(new Request(), $source);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('deleted', $this->controllerEntryRepo()->findEntry($source)['status']);
    }

    public function testAssignListAndRemoveRoute(): void
    {
        $uuid = $this->createEntryUuid();

        $assign = $this->controller()->assignRoute(
            $this->hydrate(AssignRouteData::class, ['slug' => 'hello-world']),
            new Request(),
            $uuid,
            'en',
        );
        self::assertSame(200, $assign->getStatusCode());

        $routes = $this->controller()->routes(new Request(), $uuid);
        $data = json_decode((string) $routes->getContent(), true)['data'];
        self::assertSame('hello-world', $data['routes'][0]['slug']);

        $remove = $this->controller()->removeRoute(new Request(), $uuid, 'en');
        self::assertSame(200, $remove->getStatusCode());
        $afterRemove = json_decode(
            (string) $this->controller()->routes(new Request(), $uuid)->getContent(),
            true
        );
        self::assertSame([], $afterRemove['data']['routes']);
    }

    public function testLocaleDraftCanBeCopiedFromSourceLocale(): void
    {
        $uuid = $this->createEntryUuid();
        $this->controller(new FakeLocaleManager())->saveDraft(
            $this->hydrate(SaveDraftData::class, ['fields' => ['title' => 'English'], 'lock_version' => 0]),
            new Request(),
            $uuid,
            'en',
        );

        $resp = $this->controller(new FakeLocaleManager())->createLocaleDraft(
            $this->hydrate(CopyLocaleData::class, ['source_locale' => 'en']),
            new Request(),
            $uuid,
            'fr',
        );

        self::assertSame(201, $resp->getStatusCode());
        $draft = json_decode((string) $resp->getContent(), true)['data']['draft'];
        self::assertSame('fr', $draft['locale']);
        self::assertSame(['title' => 'English'], $draft['fields']);
        self::assertSame(0, $draft['lock_version']);
    }

    public function testLocaleDraftCopySeedsNonLocalizedAndOmitsLocalized(): void
    {
        $uuid = $this->createLocalizedEntryUuid();
        $this->controller(new FakeLocaleManager())->saveDraft(
            $this->hydrate(SaveDraftData::class, [
                'fields' => ['title' => 'English', 'price' => 42],
                'lock_version' => 0,
            ]),
            new Request(),
            $uuid,
            'en',
        );

        $resp = $this->controller(new FakeLocaleManager())->createLocaleDraft(
            $this->hydrate(CopyLocaleData::class, ['source_locale' => 'en']),
            new Request(),
            $uuid,
            'fr',
        );

        self::assertSame(201, $resp->getStatusCode());
        $draft = json_decode((string) $resp->getContent(), true)['data']['draft'];
        self::assertSame('fr', $draft['locale']);
        self::assertSame(['price' => 42], $draft['fields'], 'shared price copied, localized title omitted');
        self::assertArrayNotHasKey('title', $draft['fields']);
    }

    public function testRequiredLocalizedFieldLeftEmptyBlocksPublish(): void
    {
        $uuid = $this->createLocalizedEntryUuid();
        $this->controller(new FakeLocaleManager())->saveDraft(
            $this->hydrate(SaveDraftData::class, [
                'fields' => ['title' => 'English', 'price' => 42],
                'lock_version' => 0,
            ]),
            new Request(),
            $uuid,
            'en',
        );

        $this->controller(new FakeLocaleManager())->createLocaleDraft(
            $this->hydrate(CopyLocaleData::class, ['source_locale' => 'en']),
            new Request(),
            $uuid,
            'fr',
        );

        $publisher = $this->container()->get(\App\Content\Services\PublishService::class);

        $errors = [];
        try {
            $publisher->publish($uuid, 'fr', 'user00000001');
            self::fail('publishing a draft missing a required localized field must throw');
        } catch (\App\Content\Validation\ValidationException $e) {
            $errors = $e->errors();
        }

        self::assertArrayHasKey('title', $errors, 'required localized field left empty blocks publish');
        self::assertNull(
            (new \App\Content\Repositories\VersionRepository($this->connection()))->findPublication($uuid, 'fr'),
            'a validation-failed publish creates no publication row'
        );
    }

    public function testEntryLocalesSummarizeDraftsPublicationsAndRoutes(): void
    {
        $uuid = $this->createEntryUuid();
        $this->controller(new FakeLocaleManager())->createLocaleDraft(
            $this->hydrate(CopyLocaleData::class, ['source_locale' => 'en']),
            new Request(),
            $uuid,
            'fr',
        );
        $this->controller(new FakeLocaleManager())->assignRoute(
            $this->hydrate(AssignRouteData::class, ['slug' => 'hello-world']),
            new Request(),
            $uuid,
            'en',
        );

        $resp = $this->controller(new FakeLocaleManager())->locales(new Request(), $uuid);

        self::assertSame(200, $resp->getStatusCode());
        $locales = json_decode((string) $resp->getContent(), true)['data']['locales'];
        self::assertSame(['en', 'fr'], array_column($locales, 'locale'));
        self::assertSame(true, $locales[0]['has_draft']);
        self::assertSame('hello-world', $locales[0]['route_slug']);
        self::assertSame(true, $locales[1]['has_draft']);
        self::assertNull($locales[1]['route_slug']);
    }

    public function testLocalesSummaryIncludesScheduledBlock(): void
    {
        $uuid = $this->createEntryUuid();
        (new ScheduleRepository($this->connection()))->schedule(
            $uuid,
            'en',
            ScheduleAction::Publish,
            '2999-07-01T07:00:00Z',
            'user00000001',
        );

        $resp = $this->controller(new FakeLocaleManager())->locales(new Request(), $uuid);

        self::assertSame(200, $resp->getStatusCode());
        $locale = json_decode((string) $resp->getContent(), true)['data']['locales'][0];
        self::assertSame('2999-07-01T07:00:00Z', $locale['scheduled']['publish']);
        self::assertNull($locale['scheduled']['unpublish']);
        self::assertNull($locale['scheduled']['last_failure']);
    }

    private function controllerEntryRepo(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }
}
