<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Tests\Support\AppTestCase;

final class RichFieldSanitizationTest extends AppTestCase
{
    public function testTopLevelRichFieldIsSanitizedInTheCleanedPayload(): void
    {
        $validator = new FieldValidator($this->connection(), $this->appContext());
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
            ['name' => 'note', 'type' => 'text'], // plain — must stay UNTOUCHED
        ]);
        $clean = $validator->validate($schema, [
            'body' => '<p>ok</p><script>alert(1)</script><p onclick="x">two</p>',
            'note' => '<script>kept verbatim — escaping is the renderer\'s job</script>',
        ]);
        self::assertStringNotContainsString('<script', $clean['body']);
        self::assertStringNotContainsString('onclick', $clean['body']);
        self::assertStringContainsString('<p>ok</p>', $clean['body']);
        self::assertStringContainsString('<script>', $clean['note']); // plain text untouched
    }

    public function testRichFieldInsideABlockIsSanitizedThroughTheRecursion(): void
    {
        $blocks = new BlockTypeRepository($this->connection());
        $blocks->create(['slug' => 'prose', 'label' => 'Prose', 'schema' => [
            ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
        ]]);
        $validator = new FieldValidator($this->connection(), $this->appContext(), $blocks);
        $schema = ContentTypeSchema::fromArray([['name' => 'content', 'type' => 'blocks']]);

        $clean = $validator->validate($schema, ['content' => [
            ['type' => 'prose', 'data' => ['body' => '<p>fine</p><svg onload=alert(1)></svg>']],
        ]]);
        $body = $clean['content'][0]['data']['body'];
        self::assertStringContainsString('<p>fine</p>', $body);
        self::assertStringNotContainsString('<svg', $body);
        self::assertStringNotContainsString('onload', $body);
    }
}
