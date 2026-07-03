<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Schema\SchemaParseException;
use App\Tests\Support\LemmaTestCase;

final class BlockTypeRepositoryTest extends LemmaTestCase
{
    private function repo(): BlockTypeRepository
    {
        return new BlockTypeRepository($this->connection());
    }

    /** @return list<array<string,mixed>> */
    private function heroSchema(): array
    {
        return [
            ['name' => 'heading', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
            ['name' => 'link', 'type' => 'reference', 'reference_type' => 'blog'],
        ];
    }

    public function testCreateFindUpdateDeactivateRoundTrip(): void
    {
        $r = $this->repo();
        $uuid = $r->create(['slug' => 'hero', 'label' => 'Hero', 'icon' => 'i-lucide-star',
            'category' => 'Layout', 'schema' => $this->heroSchema()]);

        $row = $r->findBySlug('hero');
        self::assertSame('Hero', $row['label']);
        self::assertSame('Layout', $row['category']); // presentation-only picker grouping
        self::assertTrue((bool) $row['active']);
        self::assertSame('heading', $row['schema'][0]['name']);

        // Additive edit (block-migrations spec §1): appending a field is free.
        $r->updateSchema(
            $uuid,
            [...$this->heroSchema(), ['name' => 'badge', 'type' => 'string']],
            'Hero v2',
            null,
            null,
            'Content'
        );
        self::assertSame('Hero v2', $r->findByUuid($uuid)['label']);
        self::assertSame('Content', $r->findByUuid($uuid)['category']);
        self::assertCount(4, $r->findByUuid($uuid)['schema']);

        // Deactivate over delete (spec §2): row survives, flagged inactive.
        $r->setActive($uuid, false);
        self::assertFalse((bool) $r->findBySlug('hero')['active']);
        self::assertCount(1, $r->all());

        // schemasBySlug covers INACTIVE types too (existing content must keep validating).
        self::assertArrayHasKey('hero', $r->schemasBySlug());
    }

    public function testBlockSchemasMayNestBlocksButStillRejectLocalizedAndFilterable(): void
    {
        $r = $this->repo();
        // Lifted (nesting amendment §A1): blocks fields inside block schemas are allowed.
        $r->create(['slug' => 'section', 'label' => 'Section',
            'schema' => [['name' => 'content', 'type' => 'blocks']]]);
        self::assertNotNull($r->findBySlug('section'));

        foreach (
            [
                [['name' => 'title', 'type' => 'string', 'localized' => true]],
                [['name' => 'flag', 'type' => 'boolean', 'filterable' => true, 'filter_type' => 'boolean']],
            ] as $i => $schema
        ) {
            try {
                $r->create(['slug' => "bad{$i}", 'label' => 'Bad', 'schema' => $schema]);
                self::fail("expected SchemaParseException for case {$i}");
            } catch (SchemaParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInvalidUnderlyingFieldSchemaAlsoRejects(): void
    {
        $this->expectException(SchemaParseException::class);
        $this->repo()->create(['slug' => 'bad', 'label' => 'Bad',
            'schema' => [['name' => 'x', 'type' => 'nope']]]);
    }

    public function testPathUnsafeSlugRejectsAtTheRepository(): void
    {
        // The slug is the blocks/{slug}.twig contract — the DOMAIN enforces it, not
        // just the API DTO (rows written around the API included).
        foreach (['../evil', 'Has Space', 'UPPER', ''] as $slug) {
            try {
                $this->repo()->create(['slug' => $slug, 'label' => 'Bad', 'schema' => []]);
                self::fail("expected SchemaParseException for slug '{$slug}'");
            } catch (SchemaParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUpdateSchemaIsAdditiveOnly(): void
    {
        $repo = $this->repo();
        $uuid = $repo->create(['slug' => 'card', 'label' => 'Card', 'schema' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'body', 'type' => 'text'],
        ]]);

        // Additive: new field appended — allowed.
        $repo->updateSchema($uuid, [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'body', 'type' => 'text'],
            ['name' => 'icon', 'type' => 'string'],
        ], 'Card', null, null, null);
        self::assertCount(3, $repo->findBySlug('card')['schema']);

        // Retype of an existing field — allowed (visible validation on next save,
        // never silent loss; spec §1).
        $repo->updateSchema($uuid, [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'body', 'type' => 'string'],
            ['name' => 'icon', 'type' => 'string'],
        ], 'Card', null, null, null);

        // Removal (also the remove+add shape of a rename) — rejected.
        try {
            $repo->updateSchema($uuid, [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'heading', 'type' => 'text'], // body "renamed"
                ['name' => 'icon', 'type' => 'string'],
            ], 'Card', null, null, null);
            self::fail('expected SchemaParseException');
        } catch (SchemaParseException $e) {
            self::assertStringContainsString("cannot remove field 'body'", $e->getMessage());
        }
    }

    public function testApplyMigratedSchemaBypassesTheGuardAndDeleteRemovesTheRow(): void
    {
        $repo = $this->repo();
        $uuid = $repo->create(['slug' => 'gone', 'label' => 'Gone', 'schema' => [
            ['name' => 'a', 'type' => 'string'],
        ]]);

        // Internal path: removal allowed (the migration flow computed this schema).
        $repo->applyMigratedSchema($uuid, [['name' => 'b', 'type' => 'string']]);
        self::assertSame('b', $repo->findBySlug('gone')['schema'][0]['name']);

        $repo->deleteBySlug('gone');
        self::assertNull($repo->findBySlug('gone'));
    }
}
