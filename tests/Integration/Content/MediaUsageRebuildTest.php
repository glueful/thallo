<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Symfony\Component\Console\Tester\CommandTester;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Console\MediaUsageRebuildCommand;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * "Used in" is kept from asset events as drafts are saved, and images inside blocks were not
 * counted until now: content saved before that is missing from it. The rebuild recomputes the
 * index from every draft, adding what is missing and dropping what no draft references.
 */
final class MediaUsageRebuildTest extends AppTestCase
{
    public function testTheRebuildCountsImagesInsideBlocksAndDropsStaleRows(): void
    {
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'usagepage', 'name' => 'Usage page',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = $this->container()->get(EntryRepository::class);
        $entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'Page', 'body' => [[
            'type' => 'hero', 'data' => ['title' => 'Hero', 'image' => 'b5abcdefghij'],
        ]]], 1, (int) $entries->findDraft($entry, 'en')['lock_version'], 'user00000001');
        // As content saved before images in blocks were counted: the index has lost the row, and
        // holds one no draft references.
        $pdo = $this->connection()->getPDO();
        $pdo->exec("DELETE FROM media_usage WHERE blob_uuid = 'b5abcdefghij'");
        $pdo->prepare('INSERT INTO media_usage (blob_uuid, entry_uuid, created_at) VALUES (?, ?, NOW())')
            ->execute(['gone00000001', $entry]);

        $tester = new CommandTester(new MediaUsageRebuildCommand($this->container(), self::$app));
        self::assertSame(0, $tester->execute([]));

        $rows = $this->connection()->table('media_usage')->where('entry_uuid', '=', $entry)->get();
        self::assertSame(['b5abcdefghij'], array_column($rows, 'blob_uuid'));
        self::assertStringContainsString('1 added', $tester->getDisplay());
        self::assertStringContainsString('1 removed', $tester->getDisplay());
    }
}
