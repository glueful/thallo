<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Tests\Support\AppTestCase;

final class ReferenceProjectionTest extends AppTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'author', 'type' => 'reference'],
            ],
        ]);
    }

    private function entries(): EntryRepository
    {
        return $this->container()->get(EntryRepository::class);
    }

    private function projection(): ReferenceProjectionRepository
    {
        return new ReferenceProjectionRepository($this->connection());
    }

    /** @return list<array<string,mixed>> */
    private function referenceRows(string $sourceUuid): array
    {
        return $this->connection()->table('entry_references')
            ->where('source_entry_uuid', '=', $sourceUuid)
            ->orderBy('id', 'ASC')
            ->get();
    }

    public function testDraftSaveDoesNotProjectReferenceRow(): void
    {
        $entries = $this->entries();
        $source = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $target = $entries->createEntry($this->type, 'en', 1, 'user00000001');

        $entries->saveDraft($source, 'en', ['title' => 'A', 'author' => $target], 1, 0, 'user00000001');

        self::assertSame([], $this->referenceRows($source));
    }

    public function testRebuildReplacesProjectionRatherThanDuplicating(): void
    {
        $entries = $this->entries();
        $source = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $first = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $second = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'author', 'type' => 'reference'],
        ]);

        $this->projection()->rebuildForEntry($source, $schema, ['author' => $first], 'en');
        $this->projection()->rebuildForEntry($source, $schema, ['author' => $second], 'en');

        $rows = $this->referenceRows($source);
        self::assertCount(1, $rows);
        self::assertSame($second, $rows[0]['target_entry_uuid']);
    }

    public function testReferencesToFindsSourceInReverse(): void
    {
        $entries = $this->entries();
        $source = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $target = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'author', 'type' => 'reference'],
        ]);

        $this->projection()->rebuildForEntry($source, $schema, ['author' => $target], 'en');

        self::assertSame([$source], $this->projection()->referencesTo($target));
    }

    public function testNonReferenceFieldDoesNotProject(): void
    {
        $entries = $this->entries();
        $source = $entries->createEntry($this->type, 'en', 1, 'user00000001');

        // 'title' is a plain string that happens to look like a uuid — it must NOT project.
        $entries->saveDraft($source, 'en', ['title' => 'someuuid0001'], 1, 0, 'user00000001');

        self::assertCount(0, $this->referenceRows($source));
    }

    public function testRebuildForEntryDirectlyDedupesAndReplaces(): void
    {
        $repo = $this->projection();
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'author', 'type' => 'reference'],
        ]);
        // duplicate targets in a list value dedupe to one row
        $repo->rebuildForEntry('source000001', $schema, ['author' => ['tgt000000001', 'tgt000000001']], 'en');
        self::assertCount(1, $this->referenceRows('source000001'));

        // rebuild replaces
        $repo->rebuildForEntry('source000001', $schema, ['author' => 'tgt000000002'], 'en');
        $rows = $this->referenceRows('source000001');
        self::assertCount(1, $rows);
        self::assertSame('tgt000000002', $rows[0]['target_entry_uuid']);
    }
}
