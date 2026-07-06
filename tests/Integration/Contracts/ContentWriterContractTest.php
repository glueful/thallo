<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contracts;

use App\Content\Validation\ValidationException;
use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Authoring\ContentWriter;

final class ContentWriterContractTest extends AppTestCase
{
    public function testContractResolvesToEngineAdapter(): void
    {
        self::assertInstanceOf(ContentWriter::class, $this->container()->get(ContentWriter::class));
    }

    public function testCreateDraftPersistsAnEntryAndCleanedDraft(): void
    {
        $typeUuid = $this->seedContentType();           // helper below
        $writer = $this->container()->get(ContentWriter::class);

        // 'sneaky' is not in the schema — validation must drop it, proving the writer
        // runs core validation rather than persisting raw input.
        $entryUuid = $writer->createDraft($typeUuid, 'en', ['title' => 'Hello', 'sneaky' => 'x'], 'usr000000001');

        self::assertNotEmpty($entryUuid);
        $entry = $this->connection()->table('entries')->where('uuid', $entryUuid)->first();
        self::assertNotNull($entry);
        $draft = $this->connection()->table('entry_drafts')
            ->where('entry_uuid', $entryUuid)->where('locale', 'en')->first();
        self::assertNotNull($draft);
        $fields = json_decode((string) $draft['fields'], true);
        self::assertSame(['title' => 'Hello'], $fields); // unknown key dropped by validation
    }

    public function testCreateDraftRejectsInvalidFields(): void
    {
        $typeUuid = $this->seedContentType();
        $writer = $this->container()->get(ContentWriter::class);

        // 'title' is required; omitting it must throw, not silently persist.
        // (The validator rejects absent/null required fields; '' is a valid string.)
        $this->expectException(ValidationException::class);
        $writer->createDraft($typeUuid, 'en', [], 'usr000000001');
    }

    /** Minimal content type row so createDraft has a schema to resolve. */
    private function seedContentType(): string
    {
        $uuid = 'type00000001';
        $this->connection()->table('content_types')->insert([
            'uuid'           => $uuid,
            'slug'           => 'post',
            'name'           => 'Post',
            'schema'         => json_encode([['name' => 'title', 'type' => 'string', 'required' => true]]),
            'schema_version' => 1,
        ]);
        return $uuid;
    }
}
