<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Palette;

use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Blocks\Sources\EntryVersionsSource;
use Thallo\Core\Content\Blocks\Sources\PublishedEntriesSource;
use Thallo\Core\Content\Blocks\Sources\SavedSectionsSource;
use Thallo\Core\Content\Palette\BrandColorUsage;
use Thallo\Core\Content\Palette\ColorTokenWalker;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Where a brand colour is used (custom palette spec §4.1): blocking documents — drafts, current
 * publications, regions (blocks and their own style), layouts (blocks and their frame), saved
 * sections, style classes — apart from historical versions, which never block clearing.
 */
final class BrandColorUsageTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @return array{type: string, value: string} */
    private static function tok(string $v): array
    {
        return ['type' => 'token', 'value' => $v];
    }

    /** @return array<string,mixed> */
    private static function heading(string $token, string $id = 'head00000001'): array
    {
        return ['id' => $id, 'type' => 'heading', 'data' => ['text' => 'x'],
            'settings' => ['style' => ['colors' => ['text' => self::tok($token)]]]];
    }

    private static function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'body', 'type' => 'blocks'],
        ]);
    }

    private static function source(string $id, DocumentRef ...$refs): BlockDocumentSource
    {
        return new class ($id, $refs) implements BlockDocumentSource {
            /** @param list<DocumentRef> $refs */
            public function __construct(private string $id, private array $refs)
            {
            }
            public function id(): string
            {
                return $this->id;
            }
            public function each(callable $fn): void
            {
                foreach ($this->refs as $ref) {
                    $fn($ref);
                }
            }
            public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
            {
                return false;
            }
        };
    }

    private function usage(BlockDocumentSource ...$sources): BrandColorUsage
    {
        return new BrandColorUsage(
            new BlockDocumentSources(...$sources),
            $this->container()->get(ColorTokenWalker::class),
            $this->connection(),
        );
    }

    public function testUsageSplitsBlockingFromHistorical(): void
    {
        $uuid = 'entry0000001';
        $schema = self::schema();
        $brand = self::heading('color.brand-1');
        $plain = self::heading('color.accent');
        $usage = $this->usage(
            self::source(EntryDraftsSource::ID, new DocumentRef(
                EntryDraftsSource::ID,
                $uuid,
                'en',
                '1',
                $schema,
                ['title' => 'Home', 'body' => [$plain]],
            )),
            self::source(PublishedEntriesSource::ID, new DocumentRef(
                PublishedEntriesSource::ID,
                $uuid,
                'en',
                'ver000000002',
                $schema,
                ['title' => 'Home', 'body' => [$brand]],
            )),
            self::source(
                EntryVersionsSource::ID,
                new DocumentRef(
                    EntryVersionsSource::ID,
                    'ver000000001',
                    'en',
                    '1',
                    $schema,
                    ['body' => [$brand]],
                    ['entry_uuid' => $uuid],
                ),
                new DocumentRef(
                    EntryVersionsSource::ID,
                    'ver000000002',
                    'en',
                    '1',
                    $schema,
                    ['body' => [$brand]],
                    ['entry_uuid' => $uuid],
                ),
            ),
        );
        $this->connection()->table('entry_publications')->insert([
            'entry_uuid' => $uuid, 'locale' => 'en', 'version_uuid' => 'ver000000002',
            'published_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $result = $usage->of(1);
        self::assertSame(1, $result['blocking']['total']);
        self::assertSame(
            [['uuid' => $uuid, 'title' => 'Home', 'locale' => 'en', 'draft' => false, 'published' => true]],
            $result['blocking']['entries'],
        );
        self::assertSame(1, $result['historical']['total'], 'ver1 is history; ver2 is the current publication');
        self::assertSame(1, $result['historical']['entries'][0]['versions']);
        self::assertFalse($result['blocking']['contrast_references']);
    }

    public function testRegionAndLayoutStyleFramesStyleClassesSectionsAndContrastTokensAreBlocking(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->connection()->table('regions')->insert([
            'slug' => 'header', 'blocks' => '[]', 'lock_version' => 0, 'updated_at' => $now,
            'settings' => json_encode(['style' => ['colors' => ['surface' => self::tok('color.brand-2-contrast')]]]),
        ]);
        $this->connection()->table('layouts')->insert([
            'id' => 'layout000001', 'surface' => 'entry', 'target' => 'page', 'blocks' => '[]', 'lock_version' => 0,
            'updated_at' => $now,
            'settings' => json_encode(['style' => ['colors' => ['surface' => self::tok('color.brand-2')]]]),
        ]);
        $this->connection()->table('style_classes')->insert([
            'id' => 'cls000000001', 'name' => 'Card', 'name_key' => 'card', 'version' => 1,
            'style' => json_encode(['hover' => ['colors' => ['text' => self::tok('color.brand-2')]]]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $usage = $this->usage(self::source(SavedSectionsSource::ID, new DocumentRef(
            SavedSectionsSource::ID,
            'sect00000001',
            null,
            '1',
            ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]),
            ['blocks' => [self::heading('color.brand-2')]],
            ['name' => 'Hero'],
        )));
        $result = $usage->of(2);
        self::assertSame(['header'], $result['blocking']['regions']);
        self::assertSame([['id' => 'entry:page', 'name' => 'page']], $result['blocking']['layouts']);
        self::assertSame([['id' => 'cls000000001', 'name' => 'Card']], $result['blocking']['style_classes']);
        self::assertSame([['id' => 'sect00000001', 'name' => 'Hero']], $result['blocking']['saved_sections']);
        self::assertSame(4, $result['blocking']['total']);
        self::assertTrue($result['blocking']['contrast_references'], 'the header names brand-2-contrast');
        self::assertSame(0, $usage->blockingTotal(3));
    }
}
