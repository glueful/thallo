<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contracts;

use App\Tests\Support\AppTestCase;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Contracts\Authoring\ValidationFailed;

final class ContentWriterValidateTest extends AppTestCase
{
    private function seedType(): string
    {
        $uuid = 'type00000001';
        $this->connection()->table('content_types')->insert([
            'uuid' => $uuid,
            'slug' => 'post',
            'name' => 'Post',
            'schema' => json_encode([['name' => 'title', 'type' => 'string', 'required' => true]]),
            'schema_version' => 1,
        ]);
        return $uuid;
    }

    public function testValidateReturnsCleanedFieldsWithoutPersisting(): void
    {
        $type = $this->seedType();
        $writer = $this->container()->get(ContentWriter::class);

        $clean = $writer->validate($type, 'en', ['title' => 'Hi', 'sneaky' => 'x']);
        self::assertSame(['title' => 'Hi'], $clean); // unknown key dropped

        // Nothing persisted by validate().
        self::assertSame(0, (int) $this->connection()->table('entries')->count());
    }

    public function testValidateRejectsInvalidWithContractException(): void
    {
        // A pack (which cannot reference App\*) must be able to catch the failure as the
        // CONTRACT exception and read its errors — proving the exception doesn't leak across
        // the boundary.
        $type = $this->seedType();
        $writer = $this->container()->get(ContentWriter::class);
        try {
            $writer->validate($type, 'en', []); // missing required 'title'
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('title', $e->errors());
        }
    }
}
