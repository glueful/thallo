<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Search;

use Thallo\Contracts\Schema\ContentSchemaReader;
use Thallo\Contracts\Schema\FieldDescriptor;
use Thallo\Contracts\Search\IndexableContent;
use Thallo\Search\Index\DocumentBuilder;
use PHPUnit\Framework\TestCase;

final class DocumentBuilderTest extends TestCase
{
    /** @param array<string,string> $fieldTypes name => type */
    private function schema(array $fieldTypes): ContentSchemaReader
    {
        $fields = [];
        foreach ($fieldTypes as $name => $type) {
            [$type, $format] = array_pad(explode(':', $type, 2), 2, null);
            $fields[$name] = new class ($name, $type, $format) implements FieldDescriptor {
                public function __construct(private string $n, private string $t, private ?string $f)
                {
                }
                public function name(): string
                {
                    return $this->n;
                }
                public function type(): string
                {
                    return $this->t;
                }
                public function isMultiple(): bool
                {
                    return false;
                }
                public function referenceType(): ?string
                {
                    return null;
                }
                public function referenceSlugField(): ?string
                {
                    return null;
                }
                public function format(): ?string
                {
                    return $this->f;
                }
                public function enumValues(): array
                {
                    return [];
                }
            };
        }
        return new class ($fields) implements ContentSchemaReader {
            /** @param array<string,FieldDescriptor> $fields */
            public function __construct(private array $fields)
            {
            }
            public function fields(): array
            {
                return array_values($this->fields);
            }
            public function field(string $name): ?FieldDescriptor
            {
                return $this->fields[$name] ?? null;
            }
        };
    }

    /** @param array<string,mixed> $fields */
    private function content(array $fields, string $type = 'blog', ?string $label = 'my-slug'): IndexableContent
    {
        return new IndexableContent(
            entryUuid: 'e-1',
            locale: 'en',
            contentTypeUuid: 'ct-1',
            contentTypeSlug: $type,
            publicDelivery: true,
            href: '/en/blog/my-slug',
            entryLabel: $label,
            fields: $fields,
        );
    }

    public function testConventionIndexesStringAndTextFieldsWithTitleField(): void
    {
        $builder = new DocumentBuilder([]);
        $doc = $builder->build(
            $this->content(['title' => 'Hello', 'body' => 'World', 'views' => 5]),
            $this->schema(['title' => 'string', 'body' => 'text', 'views' => 'number']),
        );

        // Meilisearch document ids allow only alphanumerics, `-` and `_` — never `:`.
        self::assertSame('e-1_en', $doc['id']);
        self::assertSame($doc['id'], DocumentBuilder::documentId('e-1', 'en'));
        self::assertSame('e-1', $doc['entry_uuid']);
        self::assertSame('en', $doc['locale']);
        self::assertSame('blog', $doc['content_type_slug']);
        self::assertSame('ct-1', $doc['content_type_uuid']);
        // Visibility is resolved live at query time — never denormalized into documents.
        self::assertArrayNotHasKey('public_delivery', $doc);
        self::assertSame('Hello', $doc['title']);
        self::assertStringContainsString('World', $doc['body']);
        self::assertStringNotContainsString('5', $doc['body']); // number field skipped
    }

    public function testABodyIsIndexedAsTheWordsAReaderSeesNotAsItsMarkup(): void
    {
        // A snippet is shown to a visitor, and a tag name or a link target is not a word of the
        // page: rich text loses its tags, Markdown (a plain text body) its syntax.
        $doc = (new DocumentBuilder([]))->build(
            $this->content([
                'title' => 'Installing',
                'html' => '<h2>Requirements</h2><p>PHP &amp; <strong>PostgreSQL</strong>.</p>',
                'markdown' => "## Create a project\n\nRun `composer install`, then see [the guide](/docs/guide#x).\n\n"
                    . "```bash\n$ php glueful thallo:provision\n```\n\n"
                    . "| Key | Value |\n|---|---|\n| **APP_ENV** | production |\n"
                    . "\n- [x] done\n\n> quoted ![diagram](img.png)",
            ]),
            $this->schema(['title' => 'string', 'html' => 'text:rich', 'markdown' => 'text:plain']),
        );
        self::assertStringContainsString('Requirements PHP & PostgreSQL.', $doc['body']);
        self::assertStringNotContainsString('<', $doc['body']);
        self::assertStringNotContainsString('strong', $doc['body']);
        $kept = [
            'Create a project', 'composer install', 'the guide', 'php glueful thallo:provision',
            'APP_ENV', 'production', 'done', 'quoted', 'diagram',
        ];
        foreach ($kept as $words) {
            self::assertStringContainsString($words, $doc['body'], $words);
        }
        foreach (['##', '`', '](', '/docs/guide', '|', '---', '**', '[x]', 'img.png', '> '] as $syntax) {
            self::assertStringNotContainsString($syntax, $doc['body'], $syntax);
        }
    }

    public function testAFieldThatHoldsAPathOrAUrlIsNotProse(): void
    {
        // A docs page carries the file it came from and where to edit it: not what it is about.
        $doc = (new DocumentBuilder([]))->build(
            $this->content([
                'title' => 'Installing',
                'summary' => 'Get it running.',
                'source_path' => 'getting-started/01-install.md',
                'edit_url' => 'https://github.com/acme/site/edit/main/docs/install.md',
                'note' => 'Either/or is a phrase, and so stays.',
            ]),
            $this->schema([
                'title' => 'string', 'summary' => 'string', 'source_path' => 'string',
                'edit_url' => 'string', 'note' => 'string',
            ]),
        );
        self::assertStringContainsString('Get it running.', $doc['body']);
        self::assertStringContainsString('Either/or is a phrase', $doc['body']);
        self::assertStringNotContainsString('01-install.md', $doc['body']);
        self::assertStringNotContainsString('github.com', $doc['body']);
    }

    public function testTitleFallbackChainUsesEntryLabelThenFirstStringField(): void
    {
        $builder = new DocumentBuilder([]);

        // No `title` field → entryLabel.
        $doc = $builder->build(
            $this->content(['body' => 'text here'], label: 'the-label'),
            $this->schema(['body' => 'text']),
        );
        self::assertSame('the-label', $doc['title']);

        // No `title` field and no entryLabel → first indexed string field value.
        $doc2 = $builder->build(
            $this->content(['headline' => 'First', 'body' => 'text'], label: null),
            $this->schema(['headline' => 'string', 'body' => 'text']),
        );
        self::assertSame('First', $doc2['title']);
    }

    public function testEmptyStringTitleFallsBackLikeMissingTitle(): void
    {
        // A present-but-empty title field must not defeat the fallback chain.
        $builder = new DocumentBuilder([]);
        $doc = $builder->build(
            $this->content(['title' => '', 'body' => 'text'], label: 'the-label'),
            $this->schema(['title' => 'string', 'body' => 'text']),
        );
        self::assertSame('the-label', $doc['title']);
    }

    public function testPerTypeOverrideTitleBodyExcludeAndWeightOrder(): void
    {
        $builder = new DocumentBuilder([
            'blog' => [
                'title_field' => 'headline',
                'body_fields' => ['summary', 'body'],
                'exclude_fields' => ['secret'],
                'weights' => ['summary' => 5, 'body' => 1],
            ],
        ]);

        $doc = $builder->build(
            $this->content([
                'headline' => 'H', 'summary' => 'SUM', 'body' => 'BODY', 'secret' => 'nope',
            ]),
            $this->schema(['headline' => 'string', 'summary' => 'text', 'body' => 'text', 'secret' => 'string']),
        );

        self::assertSame('H', $doc['title']);
        self::assertStringNotContainsString('nope', $doc['body']);   // excluded
        // Higher weight first: summary before body.
        self::assertLessThan(strpos($doc['body'], 'BODY'), strpos($doc['body'], 'SUM'));
    }

    public function testValidateReportsUnknownAndNonStringConfiguredFields(): void
    {
        $builder = new DocumentBuilder([
            'blog' => ['title_field' => 'ghost', 'body_fields' => ['views']],
        ]);
        $warnings = $builder->validate('blog', $this->schema(['title' => 'string', 'views' => 'number']));

        self::assertNotSame([], $warnings);
        $joined = implode(' | ', $warnings);
        self::assertStringContainsString('ghost', $joined);  // unknown field
        self::assertStringContainsString('views', $joined);  // non-string field
    }
}
