<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use App\Tests\Support\LemmaTestCase;

final class BlocksValidationTest extends LemmaTestCase
{
    private BlockTypeRepository $blocks;
    private FieldValidator $validator;
    private ContentTypeSchema $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blocks = new BlockTypeRepository($this->connection());
        $this->blocks->create(['slug' => 'hero', 'label' => 'Hero', 'schema' => [
            ['name' => 'heading', 'type' => 'string', 'required' => true],
            ['name' => 'author', 'type' => 'reference', 'reference_type' => 'blog'],
        ]]);
        $this->blocks->create(['slug' => 'quote', 'label' => 'Quote', 'schema' => [
            ['name' => 'text', 'type' => 'text'],
        ]]);
        // Container type (nesting amendment §A1): a blocks field inside a block schema.
        $this->blocks->create(['slug' => 'section', 'label' => 'Section', 'category' => 'Layout',
            'schema' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'content', 'type' => 'blocks', 'block_types' => ['hero']],
            ]]);
        $this->validator = new FieldValidator($this->connection(), $this->appContext(), $this->blocks);
        // The field allowlists ONLY hero — proving the picker-only rule below.
        $this->schema = ContentTypeSchema::fromArray([
            ['name' => 'body', 'type' => 'blocks', 'block_types' => ['hero']],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function clean(array $payload, bool $strict = false): array
    {
        return $this->validator->validate($this->schema, $payload, $strict);
    }

    public function testValidBlocksCleanPerBlockAndPreserveOrderAndIds(): void
    {
        $clean = $this->clean(['body' => [
            ['id' => 'aaaaaaaaaaaa', 'type' => 'hero', 'data' => [
                'heading' => 'Hi',
                'stale_key' => 'removed from schema long ago', // cleaned-payload strip (spec §3)
            ]],
            ['type' => 'quote', 'data' => ['text' => 'Words']], // missing id → generated
        ]]);
        $blocks = $clean['body'];
        self::assertSame('aaaaaaaaaaaa', $blocks[0]['id']);
        self::assertSame(['heading' => 'Hi'], $blocks[0]['data']); // stale key stripped
        self::assertSame('quote', $blocks[1]['type']);
        self::assertSame(12, strlen($blocks[1]['id'])); // server-generated nanoid
    }

    public function testKnownButOutsideAllowlistTypeIsAccepted(): void
    {
        // 'quote' is NOT in the field's block_types — picker-only rule (spec §1/§4).
        $clean = $this->clean(['body' => [['type' => 'quote', 'data' => ['text' => 'ok']]]]);
        self::assertSame('quote', $clean['body'][0]['type']);
    }

    public function testStructuralAndTypeErrorsUseDotPaths(): void
    {
        try {
            $this->clean(['body' => [
                ['id' => 'aaaaaaaaaaaa', 'type' => 'hero', 'data' => ['heading' => 123]],
                ['id' => 'bbbbbbbbbbbb', 'type' => 'ghost', 'data' => []],
                ['id' => 'aaaaaaaaaaaa', 'type' => 'quote', 'data' => []],
            ]]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            self::assertArrayHasKey('body.0.heading', $errors);        // per-block dot path
            self::assertArrayHasKey('body.1', $errors);                // unknown type
            self::assertStringContainsString('unknown block type', $errors['body.1']);
            self::assertArrayHasKey('body.2', $errors);                // duplicate id
            self::assertStringContainsString('duplicate', $errors['body.2']);
        }
    }

    public function testNonListValueAndMalformedItemsError(): void
    {
        try {
            $this->clean(['body' => ['not-a-block']]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.0', $e->errors());
        }
        try {
            $this->clean(['body' => 'nope']);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body', $e->errors());
        }
    }

    public function testDataMustBeAnObjectNotMissingStringOrList(): void
    {
        // {type:"quote", data:"oops"} must NOT pass just because quote has no
        // required fields — `data` is structurally an object (spec §1).
        foreach (
            [
                ['type' => 'quote', 'data' => 'oops'],   // string
                ['type' => 'quote'],                      // missing entirely
                ['type' => 'quote', 'data' => [1, 2]],    // non-empty list, not an object
            ] as $block
        ) {
            try {
                $this->clean(['body' => [$block]]);
                self::fail('expected ValidationException for data=' . json_encode($block['data'] ?? null));
            } catch (ValidationException $e) {
                self::assertArrayHasKey('body.0.data', $e->errors());
                self::assertStringContainsString('must be an object', $e->errors()['body.0.data']);
            }
        }
        // Empty object is fine (json '{}' decodes to [] in PHP — indistinguishable, allowed).
        try {
            $this->clean(['body' => [['type' => 'hero', 'data' => []]]]);
            self::fail('heading is required — but the DATA shape itself must be accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.0.heading', $e->errors()); // field error, NOT a data-shape error
            self::assertArrayNotHasKey('body.0.data', $e->errors());
        }
    }

    public function testStrictPublishRejectsDanglingReferenceInsideBlockData(): void
    {
        $payload = ['body' => [
            ['type' => 'hero', 'data' => ['heading' => 'Hi', 'author' => 'nope00000000']],
        ]];
        // Draft: lenient — dangling reference passes (top-level semantics, one level down).
        $this->clean($payload, strict: false);
        $this->addToAssertionCount(1);
        // Publish: strict — rejected with the block's dot path.
        try {
            $this->clean($payload, strict: true);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.0.author', $e->errors());
        }
    }

    public function testNestedBlocksValidateWithComposedDotPaths(): void
    {
        // Depth 1 (body) → 2 (section.content) — valid nesting.
        $clean = $this->clean(['body' => [
            ['type' => 'section', 'data' => ['title' => 'S', 'content' => [
                ['type' => 'hero', 'data' => ['heading' => 'Nested']],
            ]]],
        ]]);
        self::assertSame('Nested', $clean['body'][0]['data']['content'][0]['data']['heading']);
        self::assertSame(12, strlen($clean['body'][0]['data']['content'][0]['id'])); // ids generated at depth

        // Nested field error carries the COMPOSED path.
        try {
            $this->clean(['body' => [
                ['type' => 'section', 'data' => ['content' => [
                    ['type' => 'hero', 'data' => ['heading' => 123]],
                ]]],
            ]]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.0.content.0.heading', $e->errors());
        }
    }

    public function testDepthFourErrorsAtTheExactPath(): void
    {
        // section > section > section holds depth 3; its nested content field would
        // put items at depth 4 → the FIELD errors, nothing deeper validates. (section
        // inside section is OUTSIDE content's allowlist — doubling as the picker-only
        // acceptance proof at depth.)
        $deep = ['type' => 'section', 'data' => ['content' => [
            ['type' => 'section', 'data' => ['content' => [
                ['type' => 'section', 'data' => ['content' => [
                    ['type' => 'hero', 'data' => ['heading' => 'too deep']],
                ]]],
            ]]],
        ]]];
        try {
            $this->clean(['body' => [$deep]]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            self::assertArrayHasKey('body.0.content.0.content.0.content', $errors);
            self::assertStringContainsString('nesting depth', $errors['body.0.content.0.content.0.content']);
        }
        // Exactly at MAX (3) is fine.
        $ok = ['type' => 'section', 'data' => ['content' => [
            ['type' => 'section', 'data' => ['content' => [
                ['type' => 'hero', 'data' => ['heading' => 'depth three']],
            ]]],
        ]]];
        $this->clean(['body' => [$ok]]);
        $this->addToAssertionCount(1);
    }

    public function testRequiredBlockFieldMissingIsAlwaysAnError(): void
    {
        try {
            $this->clean(['body' => [['type' => 'hero', 'data' => []]]]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.0.heading', $e->errors());
        }
    }

    public function testBlockIdsMustBeUniqueAcrossTheWholeEntry(): void
    {
        // Two blocks FIELDS carrying the same block id: the canvas bridge keys on
        // bare ids, so uniqueness is entry-wide (visual-canvas spec §5), not
        // per-list. Same error copy/path style as the within-list rejection.
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'body', 'type' => 'blocks'],
            ['name' => 'sidebar', 'type' => 'blocks'],
        ]);
        try {
            $this->validator->validate($schema, [
                'body' => [['id' => 'dupe00000001', 'type' => 'quote', 'data' => ['text' => 'a']]],
                'sidebar' => [['id' => 'dupe00000001', 'type' => 'quote', 'data' => ['text' => 'b']]],
            ]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('sidebar.0', $e->errors());
            self::assertStringContainsString("duplicate block id 'dupe00000001'", $e->errors()['sidebar.0']);
        }

        // Distinct ids across fields validate.
        $clean = $this->validator->validate($schema, [
            'body' => [['id' => 'aaaaaaaaaaaa', 'type' => 'quote', 'data' => ['text' => 'a']]],
            'sidebar' => [['id' => 'bbbbbbbbbbbb', 'type' => 'quote', 'data' => ['text' => 'b']]],
        ]);
        self::assertSame('aaaaaaaaaaaa', $clean['body'][0]['id']);

        // NESTED lists share the entry-wide set too: a nested duplicate of a
        // top-level id rejects at the nested path.
        $nestedSchema = ContentTypeSchema::fromArray([['name' => 'body', 'type' => 'blocks']]);
        try {
            $this->validator->validate($nestedSchema, [
                'body' => [
                    ['id' => 'topid0000001', 'type' => 'quote', 'data' => ['text' => 'x']],
                    ['id' => 'sec000000001', 'type' => 'section', 'data' => ['content' => [
                        ['id' => 'topid0000001', 'type' => 'hero', 'data' => ['heading' => 'H']],
                    ]]],
                ],
            ]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.1.content.0', $e->errors());
        }
    }
}
