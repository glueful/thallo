<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\PreviewController;
use App\Content\Http\DTOs\MintPreviewData;
use App\Content\Http\DTOs\Responses\Preview\PreviewData;
use App\Content\Http\DTOs\Responses\Preview\PreviewMintData;
use App\Content\Http\DTOs\Responses\Preview\PreviewResultData;
use App\Content\Localization\ContentLocaleService;
use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewNotFoundException;
use App\Content\Preview\PreviewReader;
use App\Content\Preview\PreviewToken;
use App\Content\Preview\PreviewTokenException;
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
use Symfony\Component\HttpFoundation\Request;

final class PreviewApiTest extends AppTestCase
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

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    private function versions(): VersionRepository
    {
        return new VersionRepository($this->connection());
    }

    private function minter(): PreviewMinter
    {
        return new PreviewMinter($this->appContext());
    }

    private function reader(): PreviewReader
    {
        return new PreviewReader($this->appContext(), $this->entries(), $this->versions());
    }

    private function controller(): PreviewController
    {
        return new PreviewController(
            $this->minter(),
            $this->reader(),
            new ContentLocaleService($this->appContext(), new FakeLocaleManager()),
            $this->appContext(),
        );
    }

    /** A GET request whose `{token}` path param is supplied directly to show(). */
    private function get(): Request
    {
        return new Request();
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

    /** Seed an entry + a draft carrying the given title; return the entry uuid. */
    private function seedDraft(string $title): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'tester');
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, 'tester');
        return $uuid;
    }

    public function testMintReturnsTokenString(): void
    {
        $uuid = $this->seedDraft('Draft Title');
        $token = $this->minter()->mint($uuid, 'en', null);
        self::assertIsString($token);
        self::assertStringContainsString('.', $token);
    }

    public function testReadResolvesDraftFields(): void
    {
        $uuid = $this->seedDraft('Hello Draft');
        $token = $this->minter()->mint($uuid, 'en', null);

        $payload = $this->reader()->read($token);

        self::assertSame($uuid, $payload['entry_uuid']);
        self::assertSame('en', $payload['locale']);
        self::assertSame('Hello Draft', $payload['fields']['title']);
        self::assertNull($payload['version_uuid']);
    }

    public function testReadResolvesPinnedVersionFields(): void
    {
        $uuid = $this->seedDraft('Original Draft');
        $publish = new PublishService(
            $this->appContext(),
            $this->entries(),
            $this->versions(),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
        $versionUuid = $publish->publish($uuid, 'en', 'tester');

        // Move the draft on so draft != version, proving the reader serves the version.
        $this->entries()->saveDraft($uuid, 'en', ['title' => 'Newer Draft'], 1, 1, 'tester');

        $token = $this->minter()->mint($uuid, 'en', $versionUuid);
        $payload = $this->reader()->read($token);

        self::assertSame($versionUuid, $payload['version_uuid']);
        self::assertSame('Original Draft', $payload['fields']['title']);
    }

    public function testReadThrowsNotFoundForNonExistentEntry(): void
    {
        $token = $this->minter()->mint('does-not-xx', 'en', null);

        $this->expectException(PreviewNotFoundException::class);
        $this->reader()->read($token);
    }

    public function testReadPropagatesExpiredToken(): void
    {
        $uuid = $this->seedDraft('Whatever');
        // Mint directly with an already-past expiry using the same key the minter uses.
        $key = (string) config($this->appContext(), 'app.key', '');
        $token = PreviewToken::mint($uuid, 'en', null, time() - 1, $key);

        try {
            $this->reader()->read($token);
            self::fail('Expected expired PreviewTokenException');
        } catch (PreviewTokenException $e) {
            self::assertTrue($e->isExpired());
        }
    }

    public function testReadPropagatesTamperedToken(): void
    {
        $uuid = $this->seedDraft('Tamper Me');
        $token = $this->minter()->mint($uuid, 'en', null);
        // Flip the last character of the signature part.
        $tampered = substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A');

        $this->expectException(PreviewTokenException::class);
        $this->expectExceptionCode(PreviewTokenException::INVALID_SIGNATURE);
        $this->reader()->read($tampered);
    }

    public function testReadRejectsVersionBelongingToAnotherEntry(): void
    {
        // Entry A owns a real published version.
        $entryA = $this->seedDraft('A draft');
        $publish = new PublishService(
            $this->appContext(),
            $this->entries(),
            $this->versions(),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
        $versionA = $publish->publish($entryA, 'en', 'tester');

        // Entry B exists with its own draft. A token names entry B but points at
        // entry A's version_uuid — the reader must refuse to serve A's content.
        $entryB = $this->seedDraft('B draft');
        $token = $this->minter()->mint($entryB, 'en', $versionA);

        $this->expectException(PreviewNotFoundException::class);
        $this->reader()->read($token);
    }

    public function testReadRejectsVersionBelongingToAnotherLocale(): void
    {
        // The entry owns a real published 'en' version. A token names the SAME
        // entry but a different locale ('fr') while pointing at the 'en'
        // version_uuid — the reader must refuse (the locale half of the binding).
        $entry = $this->seedDraft('Locale-bound');
        $publish = new PublishService(
            $this->appContext(),
            $this->entries(),
            $this->versions(),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
        $versionEn = $publish->publish($entry, 'en', 'tester');

        $token = $this->minter()->mint($entry, 'fr', $versionEn);

        $this->expectException(PreviewNotFoundException::class);
        $this->reader()->read($token);
    }

    // --- Controller (HTTP layer) ---------------------------------------------

    public function testMintEndpointReturnsTokenEnvelope(): void
    {
        $uuid = $this->seedDraft('Mint Me');

        $resp = $this->controller()->mint($this->hydrate(MintPreviewData::class, []), new Request(), $uuid, 'en');

        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true)['data'];
        self::assertArrayHasKey('token', $data);
        self::assertArrayHasKey('expires_at', $data);
        self::assertArrayHasKey('expires_in', $data);
        self::assertIsString($data['token']);
        self::assertStringContainsString('.', $data['token']);
        self::assertSame($this->minter()->ttlSeconds(), $data['expires_in']);
    }

    public function testMintEndpointRejectsDisabledLocale(): void
    {
        $uuid = $this->seedDraft('Mint Me');

        $resp = $this->controller()->mint($this->hydrate(MintPreviewData::class, []), new Request(), $uuid, 'de');

        self::assertSame(422, $resp->getStatusCode());
    }

    public function testMintEndpointPinsVersionFromBody(): void
    {
        $uuid = $this->seedDraft('Pin Me');
        $publish = new PublishService(
            $this->appContext(),
            $this->entries(),
            $this->versions(),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
        $versionUuid = $publish->publish($uuid, 'en', 'tester');
        $this->entries()->saveDraft($uuid, 'en', ['title' => 'Moved On'], 1, 1, 'tester');

        $resp = $this->controller()->mint(
            $this->hydrate(MintPreviewData::class, ['version_uuid' => $versionUuid]),
            new Request(),
            $uuid,
            'en',
        );
        $token = json_decode($resp->getContent(), true)['data']['token'];

        // The minted token must resolve to the PINNED version, not the moved-on draft.
        $payload = $this->reader()->read($token);
        self::assertSame($versionUuid, $payload['version_uuid']);
        self::assertSame('Pin Me', $payload['fields']['title']);
    }

    public function testMintResponseMatchesDtoShape(): void
    {
        $uuid = $this->seedDraft('Shape Check');

        $resp = $this->controller()->mint($this->hydrate(MintPreviewData::class, []), new Request(), $uuid, 'en');

        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, PreviewMintData::class);
    }

    public function testShowResponseMatchesDtoShape(): void
    {
        $uuid = $this->seedDraft('Shape Check Read');
        $token = $this->minter()->mint($uuid, 'en', null);

        $resp = $this->controller()->show($this->get(), $token);

        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, PreviewResultData::class);
        self::assertDataMatchesDtoShape($data['preview'], PreviewData::class);
    }

    public function testShowReturnsDraftForValidToken(): void
    {
        $uuid = $this->seedDraft('Show Me');
        $token = $this->minter()->mint($uuid, 'en', null);

        $resp = $this->controller()->show($this->get(), $token);

        self::assertSame(200, $resp->getStatusCode());
        $preview = json_decode($resp->getContent(), true)['data']['preview'];
        self::assertSame($uuid, $preview['entry_uuid']);
        self::assertSame('Show Me', $preview['fields']['title']);
    }

    public function testShowReturns403ForTamperedToken(): void
    {
        $uuid = $this->seedDraft('Tamper');
        $token = $this->minter()->mint($uuid, 'en', null);
        $tampered = substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A');

        $resp = $this->controller()->show($this->get(), $tampered);

        self::assertSame(403, $resp->getStatusCode());
        $body = json_decode($resp->getContent(), true);
        self::assertFalse($body['success']);
    }

    public function testShowReturns403ForMalformedToken(): void
    {
        // No dot at all -> malformed, must still fail closed as 403 (never 200/500).
        $resp = $this->controller()->show($this->get(), 'not-a-real-token');

        self::assertSame(403, $resp->getStatusCode());
    }

    public function testShowReturns410ForExpiredToken(): void
    {
        $uuid = $this->seedDraft('Expired');
        $key = (string) config($this->appContext(), 'app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }
        $token = PreviewToken::mint($uuid, 'en', null, time() - 1, $key);

        $resp = $this->controller()->show($this->get(), $token);

        self::assertSame(410, $resp->getStatusCode());
    }

    public function testShowReturns404ForNonExistentEntry(): void
    {
        $token = $this->minter()->mint('does-not-xx', 'en', null);

        $resp = $this->controller()->show($this->get(), $token);

        self::assertSame(404, $resp->getStatusCode());
    }
}
