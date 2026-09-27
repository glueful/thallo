<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\Migration\BlockBackfillRunner;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationService;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\LayoutsSource;
use Thallo\Core\Content\Layouts\LayoutRepository;
use Thallo\Core\Content\Layouts\LayoutResolver;
use Thallo\Core\Content\Layouts\LayoutWriteLock;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Style\Classes\StyleClassJobRunner;
use Thallo\Core\Content\Style\Classes\StyleClassJobService;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Content\Style\Classes\StyleClassUsage;
use Thallo\Core\Tests\Support\AppTestCase;
use Thallo\Core\Tests\Support\SyncsBlockStyleDeclarations;

/**
 * Layouts are stored block documents (type layouts spec §5.7): a block type's migration rewrites
 * them, a style class's usage counts them, remove-everywhere cleans them — each write conditional
 * on the version the walker read — and the resolver forgets what was written.
 */
final class LayoutDocumentsTest extends AppTestCase
{
    use SyncsBlockStyleDeclarations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncBlockStyleDeclarations();
        $this->container()->get(ContentTypeRepository::class)->create([
            'slug' => 'post', 'name' => 'Posts', 'public_delivery' => true, 'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->container()->get(LayoutResolver::class)->forget('entry', 'post');
        parent::tearDown();
    }

    /** @param list<array<string,mixed>> $extra */
    private function saveLayout(array $extra): void
    {
        $repo = $this->container()->get(LayoutRepository::class);
        $blocks = [
            ['id' => 'laybody00001', 'type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
            ...$extra,
        ];
        $this->container()->get(LayoutWriteLock::class)->within(
            'entry',
            'post',
            fn (): int => $repo->saveExpected('entry', 'post', $blocks, [], $repo->version('entry', 'post'), null),
        );
    }

    /** @return list<array<string,mixed>> */
    private function blocks(): array
    {
        return $this->container()->get(LayoutRepository::class)->find('entry', 'post')['blocks'];
    }

    /** @return list<DocumentRef> */
    private function documents(): array
    {
        $out = [];
        $this->container()->get(BlockDocumentSources::class)
            ->only(LayoutsSource::ID)
            ->each(static function ($source, DocumentRef $ref) use (&$out): void {
                $out[] = $ref;
            });
        return $out;
    }

    public function testALayoutIsADocumentWrittenOnlyAtTheVersionItWasRead(): void
    {
        $this->saveLayout([]);
        [$ref] = $this->documents();
        self::assertSame('entry:post', $ref->sourceId);
        self::assertSame('1', $ref->revision);

        $resolver = $this->container()->get(LayoutResolver::class);
        self::assertCount(1, $resolver->for('entry', 'post')['blocks']);
        $source = $this->container()->get(LayoutsSource::class);
        $changed = ['blocks' => [...$ref->fields['blocks'],
            ['id' => 'layhead00001', 'type' => 'heading', 'data' => ['text' => 'Added'], 'settings' => []]]];
        self::assertTrue($source->persist($ref, $changed));
        self::assertSame('2', $this->documents()[0]->revision);
        self::assertCount(2, $resolver->for('entry', 'post')['blocks'], 'the resolver forgot the old answer');
        self::assertFalse($source->persist($ref, $changed), 'a stale read writes nothing');
    }

    public function testABlockTypesMigrationRewritesLayouts(): void
    {
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'promo_tile', 'label' => 'Promo tile', 'schema' => [['name' => 'title', 'type' => 'string']],
        ]);
        $this->saveLayout([
            ['id' => 'laytile00001', 'type' => 'promo_tile', 'data' => ['title' => 'Kept'], 'settings' => []],
        ]);
        $tile = (string) (new BlockTypeRepository($this->connection()))->findBySlug('promo_tile')['uuid'];
        $migration = $this->container()->get(BlockMigrationService::class)
            ->migrate($tile, [['op' => 'rename', 'from' => 'title', 'to' => 'heading']], 'user00000001');

        $result = $this->container()->get(BlockBackfillRunner::class)->run($migration);
        self::assertSame(0, $result['failed']);
        self::assertSame(['heading' => 'Kept'], $this->blocks()[1]['data']);
    }

    public function testAStyleClassCountsAndRemovesItsUseInLayouts(): void
    {
        $band = $this->container()->get(StyleClassRepository::class)->create(['name' => 'Band', 'style' => [
            'radius' => ['type' => 'token', 'value' => 'radius.lg'],
        ]]);
        $this->saveLayout([['id' => 'layhead00001', 'type' => 'heading', 'data' => ['text' => 'Hi'],
            'settings' => ['classes' => [$band['id']]]]]);
        $resolver = $this->container()->get(LayoutResolver::class);
        self::assertNotNull($resolver->for('entry', 'post'));

        $usage = $this->container()->get(StyleClassUsage::class)->of($band['id'], $band['style']);
        self::assertSame(1, $usage['by_source']['layouts']);
        self::assertSame(1, $usage['references']);

        $job = $this->container()->get(StyleClassJobService::class)->queue($band['id'], 'remove');
        self::assertSame('completed', $this->container()->get(StyleClassJobRunner::class)->run($job)['status']);
        self::assertSame([], $this->blocks()[1]['settings'], 'the reference is gone');
        self::assertSame([], $resolver->for('entry', 'post')['blocks'][1]['settings'], 'and the resolver knows');
    }
}
