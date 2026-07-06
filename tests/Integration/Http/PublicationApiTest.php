<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\PublicationController;
use App\Content\Http\DTOs\RollbackData;
use App\Content\Localization\ContentLocaleService;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\FakeLocaleManager;
use App\Tests\Support\AppTestCase;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class PublicationApiTest extends AppTestCase
{
    private string $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = $this->entries();
        $this->entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($this->entry, 'en', ['title' => 'Hello'], 1, 0, 'user00000001');
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    private function controller(): PublicationController
    {
        $versions = new VersionRepository($this->connection());
        return new PublicationController(new PublishService(
            $this->appContext(),
            $this->entries(),
            $versions,
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        ), $versions, new ContentLocaleService($this->appContext(), new FakeLocaleManager()));
    }

    /**
     * Hydrate a request DTO exactly as the router would, so DTO validation is exercised
     * before the controller sees it.
     *
     * @param  class-string<RequestData> $dtoClass
     * @param  array<string,mixed>       $body
     */
    private function hydrate(string $dtoClass, array $body): RequestData
    {
        return (new RequestDataHydrator())->hydrate($dtoClass, $body);
    }

    public function testPublishReturns200AndPins(): void
    {
        $resp = $this->controller()->publish(new Request(), $this->entry, 'en');
        self::assertSame(200, $resp->getStatusCode());
        self::assertNotNull((new VersionRepository($this->connection()))->findPublication($this->entry, 'en'));
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Publication\VersionResultData::class);
    }

    public function testPublishRejectsDisabledLocale(): void
    {
        $resp = $this->controller()->publish(new Request(), $this->entry, 'de');

        self::assertSame(422, $resp->getStatusCode());
        self::assertNull((new VersionRepository($this->connection()))->findPublication($this->entry, 'de'));
    }

    public function testUnpublishRemovesPin(): void
    {
        $this->controller()->publish(new Request(), $this->entry, 'en');
        $this->controller()->unpublish(new Request(), $this->entry, 'en');
        self::assertNull((new VersionRepository($this->connection()))->findPublication($this->entry, 'en'));
    }

    public function testRollbackReturns200ForOwnedVersion(): void
    {
        $publishResp = $this->controller()->publish(new Request(), $this->entry, 'en');
        $versionUuid = json_decode($publishResp->getContent(), true)['data']['version_uuid'];

        $resp = $this->controller()->rollback(
            $this->hydrate(RollbackData::class, ['version_uuid' => $versionUuid]),
            new Request(),
            $this->entry,
            'en',
        );
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        // Rollback's OWN shape (block-migrations spec §5): the ACTUALLY pinned
        // version_uuid + version number — not publish's bare {version_uuid}.
        self::assertDataMatchesDtoShape(
            $data,
            \App\Content\Http\DTOs\Responses\Publication\RollbackResultData::class,
        );
    }

    public function testRollbackRejectsMissingVersionAtHydration(): void
    {
        // Structural validation now lives in the DTO: a blank/absent version_uuid fails
        // hydration with a standard 422 before the controller runs.
        $this->expectException(ValidationException::class);
        $this->hydrate(RollbackData::class, []);
    }

    public function testRollbackRejectsUnknownVersionWith422(): void
    {
        // version_uuid is structurally valid but does not belong to this entry+locale —
        // the service raises a RuntimeException the controller maps to a domain 422.
        $resp = $this->controller()->rollback(
            $this->hydrate(RollbackData::class, ['version_uuid' => 'does-not-exist']),
            new Request(),
            $this->entry,
            'en',
        );
        self::assertSame(422, $resp->getStatusCode());
    }

    public function testVersionsListsPublishedHistoryNewestFirst(): void
    {
        $first = json_decode(
            (string) $this->controller()->publish(new Request(), $this->entry, 'en')->getContent(),
            true
        )['data']['version_uuid'];
        $this->entries()->saveDraft($this->entry, 'en', ['title' => 'Second'], 1, 1, 'user00000001');
        $second = json_decode(
            (string) $this->controller()->publish(new Request(), $this->entry, 'en')->getContent(),
            true
        )['data']['version_uuid'];

        $resp = $this->controller()->versions(new Request(), $this->entry, 'en');

        self::assertSame(200, $resp->getStatusCode());
        $versions = json_decode((string) $resp->getContent(), true)['data']['versions'];
        self::assertSame([$second, $first], array_column($versions, 'uuid'));
    }
}
