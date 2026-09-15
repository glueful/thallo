<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryVersionsSource;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Visual builder spec §4.5: every block-bearing source persists through a conditional update on
 * the lock version the reader handed out — a concurrent change is a refused write, never a lost
 * one — and every ordinary writer bumps it.
 */
final class ConditionalDocumentWritesTest extends AppTestCase
{
    private static function heading(string $text): array
    {
        return ['id' => 'head00000001', 'type' => 'heading', 'data' => ['text' => $text], 'settings' => []];
    }

    public function testARegionPersistedFromAStaleLockVersionIsRefusedAndASaveBumpsIt(): void
    {
        $regions = new RegionRepository($this->connection());
        $regions->save('footer', [self::heading('one')], [], 'user00000001');
        $source = new RegionsSource($this->connection());
        $refs = [];
        $source->each(static function (DocumentRef $ref) use (&$refs): void {
            $refs[$ref->sourceId] = $ref;
        });
        $ref = $refs['footer'];
        self::assertSame('0', $ref->revision, 'a fresh region starts at lock version 0');

        // An ordinary save lands between the read and the write: the write is refused.
        $regions->save('footer', [self::heading('two')], [], 'user00000001');
        self::assertFalse($source->persist($ref, ['blocks' => [self::heading('job')]]), 'stale: refused');
        self::assertSame('two', $regions->find('footer')['blocks'][0]['data']['text'], 'the save survived');

        $source->each(static function (DocumentRef $ref) use (&$refs): void {
            $refs[$ref->sourceId] = $ref;
        });
        self::assertSame('1', $refs['footer']->revision, 'the save bumped the lock version');
        self::assertTrue($source->persist($refs['footer'], ['blocks' => [self::heading('job')]]));
        self::assertSame('job', $regions->find('footer')['blocks'][0]['data']['text']);
    }

    public function testARetainedVersionPersistedFromAStaleLockVersionIsRefused(): void
    {
        $types = $this->container()->get(ContentTypeRepository::class);
        $typeUuid = $types->create(['slug' => 'page', 'name' => 'Page', 'schema' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'body', 'type' => 'blocks'],
        ]]);
        $entries = $this->container()->get(EntryRepository::class);
        $entryUuid = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $versions = $this->container()->get(VersionRepository::class);
        $number = $versions->reserveNextVersionNumber($entryUuid, 'en');
        $fields = ['title' => 'v1', 'body' => [self::heading('one')]];
        $uuid = $versions->appendVersion($entryUuid, 'en', $number, $fields, 1, null);

        $source = $this->container()->get(EntryVersionsSource::class);
        $refs = [];
        $source->each(static function (DocumentRef $ref) use (&$refs): void {
            $refs[$ref->sourceId] = $ref;
        });
        $ref = $refs[$uuid];
        self::assertSame('0', $ref->revision);
        self::assertTrue($source->persist($ref, ['title' => 'v1', 'body' => [self::heading('first')]]));
        self::assertFalse($source->persist($ref, ['title' => 'v1', 'body' => [self::heading('second')]]), 'stale');
        self::assertSame('first', $versions->findVersionByUuid($uuid)['fields']['body'][0]['data']['text']);
    }
}
