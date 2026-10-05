<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Fonts;

use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Blocks\Sources\EntryVersionsSource;
use Thallo\Core\Content\Blocks\Sources\LayoutsSource;
use Thallo\Core\Content\Blocks\Sources\PublishedEntriesSource;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;
use Thallo\Core\Content\Blocks\Sources\SavedSectionsSource;
use Thallo\Core\Content\Fonts\FontUsage;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Where a family is used (block typeface spec §2.6): every block-bearing document — drafts,
 * publications, retained versions, the header and footer, layouts, saved sections — at a target's
 * `settings.style.typography.family` and a part's `settings.parts.<name>.typography.family`, nested
 * blocks included; plus style classes and the Appearance assignments (plan Task 8).
 */
final class FontUsageTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    private const ID = 'Ab3dE5fG7hJ9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
    }

    /** @return array<string,mixed> a Heading whose typeface is `$id` */
    private static function heading(string $id): array
    {
        return ['type' => 'heading', 'data' => ['text' => 'Hi'],
            'settings' => ['style' => ['typography' => ['family' => ['type' => 'font', 'value' => $id]]]]];
    }

    /** @return array<string,mixed> a Links block whose link PART is set in `$id` */
    private static function linksPart(string $id): array
    {
        return ['type' => 'links', 'data' => ['items' => []],
            'settings' => ['parts' => ['link' => ['typography' => ['family' => ['type' => 'font', 'value' => $id]]]]]];
    }

    /** One in-memory source of documents, standing in for a stored table. */
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

    /**
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $meta
     */
    private static function doc(
        string $type,
        string $id,
        ?string $locale,
        array $blocks,
        array $meta = [],
        ?string $title = null,
    ): DocumentRef {
        $fields = ['blocks' => $blocks] + ($title !== null ? ['title' => $title] : []);
        return new DocumentRef($type, $id, $locale, '1', ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'blocks', 'type' => 'blocks'],
        ]), $fields, $meta);
    }

    private function usage(BlockDocumentSources $sources): FontUsage
    {
        $this->container()->get(SettingsStore::class)->clearCache();
        return new FontUsage(
            $sources,
            $this->container()->get(BlockStyleRegistry::class),
            $this->connection(),
            $this->container()->get(GeneralSettings::class),
        );
    }

    public function testEverySourceIsScanned(): void
    {
        $heading = [self::heading(self::ID)];
        $sources = new BlockDocumentSources(
            self::source(
                EntryDraftsSource::ID,
                self::doc(EntryDraftsSource::ID, 'entrydraft01', 'en', $heading, title: 'Draft page'),
            ),
            self::source(
                PublishedEntriesSource::ID,
                self::doc(PublishedEntriesSource::ID, 'entrypubl001', 'fr', $heading, title: 'Publiée'),
            ),
            self::source(
                EntryVersionsSource::ID,
                self::doc(EntryVersionsSource::ID, 'version00001', 'en', $heading, ['entry_uuid' => 'entryold0001']),
            ),
            self::source(RegionsSource::ID, self::doc(RegionsSource::ID, 'header', null, $heading)),
            self::source(
                LayoutsSource::ID,
                self::doc(LayoutsSource::ID, 'entry:post', null, $heading, ['surface' => 'entry', 'target' => 'post']),
            ),
            self::source(
                SavedSectionsSource::ID,
                self::doc(SavedSectionsSource::ID, 'section00001', null, [self::linksPart(self::ID)], ['name' => 'L']),
            ),
        );
        $this->connection()->table('style_classes')->insert([
            'id' => 'classfont001', 'name' => 'Brand type', 'name_key' => 'brand type', 'version' => 1,
            'style' => json_encode(['typography' => ['family' => ['type' => 'font', 'value' => self::ID]]]),
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->container()->get(SettingsStore::class)->putMany([
            'theme_font_text_family' => self::ID,
            'theme_font_headings_family' => 'serif',
        ]);

        $usage = $this->usage($sources)->of(self::ID);

        $entries = array_column($usage['entries'], null, 'uuid');
        self::assertSame([
            'uuid' => 'entrydraft01', 'title' => 'Draft page', 'locale' => 'en',
            'draft' => true, 'published' => false, 'versions' => false,
        ], $entries['entrydraft01']);
        self::assertTrue($entries['entrypubl001']['published']);
        self::assertSame('fr', $entries['entrypubl001']['locale']);
        self::assertTrue($entries['entryold0001']['versions']);
        self::assertSame(['header'], $usage['regions']);
        self::assertSame([['id' => 'entry:post', 'name' => 'post']], $usage['layouts']);
        self::assertSame([['id' => 'section00001', 'name' => 'L']], $usage['saved_sections'], 'a part is found');
        self::assertSame([['id' => 'classfont001', 'name' => 'Brand type']], $usage['style_classes']);
        self::assertSame(['text' => true, 'headings' => false], $usage['appearance']);
    }

    public function testNestedBlocksAreFoundAndOtherFamiliesAreNot(): void
    {
        $card = ['type' => 'card', 'data' => ['body' => [self::heading(self::ID)]], 'settings' => []];
        $sources = new BlockDocumentSources(
            self::source(EntryDraftsSource::ID, self::doc(EntryDraftsSource::ID, 'entrydraft01', 'en', [$card])),
            self::source(RegionsSource::ID, self::doc(RegionsSource::ID, 'footer', null, [self::heading('serif')])),
        );
        $usage = $this->usage($sources)->of(self::ID);
        self::assertSame(['entrydraft01'], array_column($usage['entries'], 'uuid'));
        self::assertSame([], $usage['regions']);
        self::assertSame([], $this->usage($sources)->of('Zz9yX8wV7uT6')['entries']);
    }

    /** The card's counts (final review): every family at once, one scan, as many places as of() names. */
    public function testCountsEveryFamilyInOneScan(): void
    {
        $other = 'Zz9yX8wV7uT6';
        $sources = new BlockDocumentSources(
            self::source(
                EntryDraftsSource::ID,
                self::doc(EntryDraftsSource::ID, 'entrydraft01', 'en', [
                    self::heading(self::ID),
                    self::heading($other),
                ]),
            ),
            self::source(RegionsSource::ID, self::doc(RegionsSource::ID, 'header', null, [self::heading(self::ID)])),
        );
        $this->container()->get(SettingsStore::class)->putMany(['theme_font_headings_family' => $other]);
        $usage = $this->usage($sources);
        self::assertSame(
            [self::ID => 2, $other => 2, 'Nn0nE0fG7hJ9' => 0],
            $usage->counts([self::ID, $other, 'Nn0nE0fG7hJ9']),
        );
    }

    public function testTheRealRegistryIsScanned(): void
    {
        $usage = $this->container()->get(FontUsage::class)->of(self::ID);
        self::assertSame(
            ['entries' => [], 'regions' => [], 'layouts' => [], 'saved_sections' => [], 'style_classes' => [],
                'appearance' => ['text' => false, 'headings' => false]],
            $usage,
        );
    }
}
