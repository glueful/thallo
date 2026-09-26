<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content\Layouts;

use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Layouts\EntrySurface;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * The entry surface (type layouts spec §3, §4.1): one layout per publicly delivered content type.
 * What a layout must place — the type's primary body, exactly once — and the starter it opens on
 * both follow the type's schema, so every supported type shape opens on a layout that is valid.
 */
final class EntrySurfaceTest extends AppTestCase
{
    private function types(): ContentTypeRepository
    {
        return $this->container()->get(ContentTypeRepository::class);
    }

    private function surface(): EntrySurface
    {
        return $this->container()->get(EntrySurface::class);
    }

    /** @param list<array<string,mixed>> $schema */
    private function type(string $slug, string $name, array $schema, bool $public = true): string
    {
        return $this->types()->create([
            'slug' => $slug, 'name' => $name, 'public_delivery' => $public, 'schema' => $schema,
        ]);
    }

    private function seedShapes(): void
    {
        $this->type('category', 'Categories', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'slug', 'type' => 'string', 'required' => true],
        ]);
        $this->type('post', 'Posts', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'excerpt', 'type' => 'text', 'format' => 'plain'],
            ['name' => 'cover', 'type' => 'asset'],
            ['name' => 'body', 'type' => 'blocks', 'required' => true],
            ['name' => 'categories', 'type' => 'reference', 'multiple' => true, 'filterable' => true,
                'reference_type' => 'category', 'reference_slug_field' => 'slug'],
        ]);
        $this->type('pages', 'Pages', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'blocks'],
        ]);
        $this->type('guide', 'Guides', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'content', 'type' => 'blocks'],
        ]);
        $this->type('note', 'Notes', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
        ]);
        $this->type('quote', 'Quotes', [
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'author', 'type' => 'string'],
        ]);
    }

    public function testTheRegistryHoldsTheEntrySurface(): void
    {
        $registry = $this->container()->get(LayoutSurfaceRegistry::class);
        self::assertInstanceOf(EntrySurface::class, $registry->get('entry'));
    }

    public function testTargetsAreThePubliclyDeliveredTypes(): void
    {
        $this->type('post', 'Posts', [['name' => 'title', 'type' => 'string']]);
        $this->type('secret', 'Secrets', [['name' => 'title', 'type' => 'string']], false);
        $gone = $this->type('gone', 'Gone', [['name' => 'title', 'type' => 'string']]);
        $this->types()->softDelete($gone);
        $targets = array_column($this->surface()->targets(), 'target');
        self::assertContains('post', $targets);
        self::assertNotContains('secret', $targets);
        self::assertNotContains('gone', $targets);
        self::assertSame('Posts — single post', $this->surface()->label('post'));
        self::assertSame('Applies to every post', $this->surface()->reach('post'));
    }

    public function testThePrimaryBodyIsRequiredOnce(): void
    {
        $this->seedShapes();
        $this->type('doc', 'Docs', [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'sidebar', 'type' => 'blocks'],
            ['name' => 'body', 'type' => 'blocks'],
        ]);
        self::assertSame([['type' => 'entry_content', 'field' => 'body']], $this->surface()->required('doc'));
        self::assertSame([['type' => 'entry_content', 'field' => 'content']], $this->surface()->required('guide'));
        self::assertSame([], $this->surface()->required('note'));
        self::assertSame([], $this->surface()->required('quote'));
    }

    public function testTheStarterFollowsTheSchema(): void
    {
        $this->seedShapes();
        $types = static fn (array $tree): array => array_map(
            static fn (array $block): string => $block['type']
                . (isset($block['data']['field']) ? ':' . $block['data']['field'] : ''),
            $tree,
        );
        self::assertSame(
            ['entry_terms:categories', 'entry_title', 'entry_date', 'entry_excerpt:excerpt', 'entry_cover:cover',
                'entry_content:body', 'entry_related'],
            $types($this->surface()->starter('post')),
        );
        // The seeded page, exactly: the title, then the body — no date.
        self::assertSame([
            ['type' => 'entry_title', 'data' => ['level' => 'h1'], 'settings' => []],
            ['type' => 'entry_content', 'data' => ['field' => 'body'], 'settings' => []],
        ], $this->surface()->starter('pages'));
        self::assertSame(['entry_title', 'entry_content:content'], $types($this->surface()->starter('guide')));
        self::assertSame(['entry_title', 'entry_field:body'], $types($this->surface()->starter('note')));
        self::assertSame('rich', $this->surface()->starter('note')[1]['data']['format']);
        // A body-less, non-article type: the title only.
        self::assertSame(['entry_title'], $types($this->surface()->starter('quote')));
    }

    public function testThePlaceholderWritesNothing(): void
    {
        $this->seedShapes();
        $before = $this->connection()->table('entries')->count();
        $placeholder = $this->surface()->placeholder('post');
        self::assertTrue($placeholder['placeholder']);
        self::assertSame('Sample post', $placeholder['fields']['title']);
        self::assertSame([], $placeholder['fields']['body']);
        self::assertNotEmpty($placeholder['published_at']);
        self::assertSame($before, $this->connection()->table('entries')->count());
    }
}
