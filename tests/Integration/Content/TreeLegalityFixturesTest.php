<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Integration\Content;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Tests\Support\AppTestCase;

/**
 * Visual builder spec §5.2: the server's half of the shared legality fixtures. Every case's
 * candidate tree goes through the real validator: `ok` validates, an error path is the exact
 * dot path reported, and `null` means the server never sees such a tree (the builder alone
 * refuses it). The builder is stricter than the API by design — `enforce_block_types` is the
 * server's switch — so the two expectations differ where the fixture says so.
 */
final class TreeLegalityFixturesTest extends AppTestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/structure/legality';

    /** @return iterable<string, array{0: array<string,mixed>}> */
    public static function cases(): iterable
    {
        foreach (glob(self::FIXTURES . '/*.json') ?: [] as $file) {
            $doc = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            foreach ($doc['cases'] as $case) {
                if ($case['expect']['server'] === null || !isset($case['candidate'])) {
                    continue;
                }
                yield basename($file, '.json') . ' / ' . $case['name'] => [$case];
            }
        }
    }

    /**
     * @dataProvider cases
     * @param array<string,mixed> $case
     */
    public function testTheServerAgreesOnTheCandidateTree(array $case): void
    {
        $blocks = new BlockTypeRepository($this->connection());
        foreach ($case['blockTypes'] as $type) {
            $blocks->create(['slug' => $type['slug'], 'label' => $type['label'], 'schema' => $type['schema']]);
        }
        $fields = [];
        foreach ($case['rootSlots'] as $name => $slot) {
            $fields[] = [
                'name' => $name,
                'type' => 'blocks',
                'block_types' => $slot['block_types'],
                'enforce_block_types' => (bool) ($slot['enforce_block_types'] ?? false),
            ];
        }
        $schema = ContentTypeSchema::fromArray($fields);
        $validator = new FieldValidator($this->connection(), $this->appContext(), $blocks);
        try {
            $validator->validate($schema, $case['candidate']['fields']);
            self::assertSame('ok', $case['expect']['server'], 'the server accepted a tree it should refuse');
        } catch (ValidationException $e) {
            self::assertNotSame('ok', $case['expect']['server'], json_encode($e->errors()));
            self::assertArrayHasKey($case['expect']['server'], $e->errors(), json_encode(array_keys($e->errors())));
        }
    }

    public function testBothRuntimesReadTheSameFixtureFiles(): void
    {
        $spec = (string) file_get_contents(__DIR__ . '/../../../admin/src/__tests__/structure-legality.spec.ts');
        self::assertStringContainsString('fixtures/structure/legality', $spec);
        self::assertNotEmpty(glob(self::FIXTURES . '/*.json'));
    }
}
