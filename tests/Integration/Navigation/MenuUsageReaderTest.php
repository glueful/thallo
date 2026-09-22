<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Navigation;

use Thallo\Contracts\Navigation\MenuUsageReader;
use Thallo\Contracts\Navigation\MenuUse;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Tests\Support\AppTestCase;

/** Where a menu is shown, so deleting it can say what it would take down. */
final class MenuUsageReaderTest extends AppTestCase
{
    public function testItFindsRegionsAndEntriesThatShowTheMenu(): void
    {
        $regions = $this->container()->get(RegionRepository::class);
        $regions->save('header', [
            ['id' => 'h1', 'type' => 'navigation', 'data' => ['menu' => 'usage-main']],
        ], [], null);

        $types = new ContentTypeRepository($this->connection());
        $type = $types->create([
            'slug' => 'usage-page',
            'name' => 'Usage page',
            'schema' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $uuid = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', [
            'title' => 'Landing',
            'body' => [[
                'id' => 'c1',
                'type' => 'container',
                'data' => ['items' => [['id' => 'n1', 'type' => 'navigation', 'data' => ['menu' => 'usage-main']]]],
            ]],
        ], 1, 0, 'user00000001');
        $other = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($other, 'en', [
            'title' => 'Elsewhere',
            'body' => [['id' => 'n2', 'type' => 'navigation', 'data' => ['menu' => 'another']]],
        ], 1, 0, 'user00000001');

        $usage = $this->container()->get(MenuUsageReader::class)->usage('usage-main');

        self::assertCount(2, $usage);
        self::assertSame([MenuUse::REGION, 'header', 'Header'], [$usage[0]->kind, $usage[0]->id, $usage[0]->label]);
        self::assertSame([MenuUse::ENTRY, $uuid, 'Landing', 'usage-page'], [
            $usage[1]->kind,
            $usage[1]->id,
            $usage[1]->label,
            $usage[1]->contentType,
        ]);
        self::assertSame([], $this->container()->get(MenuUsageReader::class)->usage('nowhere'));
    }
}
