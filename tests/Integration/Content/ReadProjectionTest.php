<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Http\Controllers\DeliveryController;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryShowQuery;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\Controllers\PublicationController;
use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewReader;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\MigrationService;
use App\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ReadProjectionTest extends AppTestCase
{
    public function testDeliveryProjectsNotYetMaterializedPublishedEntry(): void
    {
        [$typeSlug, $entry] = $this->publishedLaggingEntry();

        $resp = $this->container()->get(DeliveryController::class)
            ->show(new Request(), new DeliveryShowQuery(), $typeSlug, $entry);

        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getContent());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertSame(['heading' => 'Hi'], $data['fields']);
    }

    public function testAdminDraftReadProjects(): void
    {
        [$entry] = $this->draftLaggingEntry();

        $resp = $this->container()->get(EntryController::class)
            ->getDraft(new Request(), $entry, 'en');

        self::assertSame(200, $resp->getStatusCode());
        $draft = json_decode((string) $resp->getContent(), true)['data']['draft'];
        self::assertSame(['heading' => 'Hi'], $draft['fields']);
    }

    public function testAdminVersionsReadProjects(): void
    {
        [, $entry] = $this->publishedLaggingEntry();

        $resp = $this->container()->get(PublicationController::class)
            ->versions(new Request(), $entry, 'en');

        self::assertSame(200, $resp->getStatusCode());
        $version = json_decode((string) $resp->getContent(), true)['data']['versions'][0];
        self::assertSame(['heading' => 'Hi'], $version['fields']);
    }

    public function testPreviewTokenAgainstPreMigrationVersionProjects(): void
    {
        [, $entry, $version] = $this->publishedLaggingEntry();
        $token = (new PreviewMinter($this->appContext()))->mint($entry, 'en', $version);

        $preview = $this->container()->get(PreviewReader::class)->read($token);

        self::assertSame(['heading' => 'Hi'], $preview['fields']);
    }

    public function testDraftPreviewDuringMigrationProjects(): void
    {
        [$entry] = $this->draftLaggingEntry();
        $token = (new PreviewMinter($this->appContext()))->mint($entry, 'en');

        $preview = $this->container()->get(PreviewReader::class)->read($token);

        self::assertSame(['heading' => 'Hi'], $preview['fields']);
    }

    /** @return array{0:string,1:string,2:string} */
    private function publishedLaggingEntry(): array
    {
        $type = $this->createType();
        $entry = $this->entries()->createEntry($type, 'en', 1, 'user00000001');
        $this->connection()->table('entry_drafts')
            ->where('entry_uuid', '=', $entry)
            ->where('locale', '=', 'en')
            ->delete();
        $version = $this->versions()->appendVersion($entry, 'en', 1, ['title' => 'Hi'], 1, 'user00000001');
        $this->versions()->pin($entry, 'en', $version, 'user00000001');
        $this->migrate($type);

        return ['article', $entry, $version];
    }

    /** @return array{0:string,1:string} */
    private function draftLaggingEntry(): array
    {
        $type = $this->createType();
        $entry = $this->entries()->createEntry($type, 'en', 1, 'user00000001');
        $this->entries()->saveDraft($entry, 'en', ['title' => 'Hi'], 1, 0, 'user00000001');
        $this->migrate($type);

        return [$entry, $type];
    }

    private function createType(): string
    {
        return $this->types()->create([
            'slug' => 'article',
            'name' => 'Article',
            'public_delivery' => true,
            'schema' => [['name' => 'title', 'type' => 'string']],
        ]);
    }

    private function migrate(string $type): void
    {
        $this->container()->get(MigrationService::class)
            ->migrate($type, [['op' => 'rename', 'from' => 'title', 'to' => 'heading']], null);
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository($this->connection(), $this->appContext(), $this->types());
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
