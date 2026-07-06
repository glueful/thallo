<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\Migration\BlockInstanceWalker;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\Migration\RenameField;
use App\Tests\Support\AppTestCase;

final class BlockInstanceWalkerTest extends AppTestCase
{
    private BlockInstanceWalker $walker;
    private ContentTypeSchema $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $blocks = new BlockTypeRepository($this->connection());
        $blocks->create(['slug' => 'card', 'label' => 'Card', 'schema' => [
            ['name' => 'title', 'type' => 'string'],
        ]]);
        $blocks->create(['slug' => 'nest', 'label' => 'Nest', 'schema' => [
            ['name' => 'inner', 'type' => 'blocks'],
        ]]);
        $this->walker = new BlockInstanceWalker($blocks);
        $this->schema = ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'body', 'type' => 'blocks'],
        ]);
    }

    /** @return array<string,mixed> */
    private function fieldsWithNestedCard(): array
    {
        return ['title' => 'X', 'body' => [
            ['id' => 'a', 'type' => 'card', 'data' => ['title' => 'top']],
            ['id' => 'n', 'type' => 'nest', 'data' => ['inner' => [
                ['id' => 'b', 'type' => 'card', 'data' => ['title' => 'deep']],
            ]]],
        ]];
    }

    public function testSlugsInFindsNestedSlugs(): void
    {
        self::assertEqualsCanonicalizing(
            ['card', 'nest'],
            $this->walker->slugsIn($this->fieldsWithNestedCard(), $this->schema),
        );
        self::assertSame([], $this->walker->slugsIn(['title' => 'X'], $this->schema));
    }

    public function testSlugsInReportsUnknownTypesFromDataTruth(): void
    {
        // DATA truth: a slug the registry no longer knows (hard-deleted) is still
        // reported — the restore projector's deleted-type detection depends on it.
        $fields = ['body' => [
            ['id' => 'g', 'type' => 'ghost', 'data' => ['x' => '1']],
            'not-a-block', // malformed still skipped
        ]];
        self::assertSame(['ghost'], $this->walker->slugsIn($fields, $this->schema));
    }

    public function testRewriteAppliesOpsToMatchingInstancesOnlyNestedIncluded(): void
    {
        $ops = new MigrationOpSet([new RenameField('title', 'heading')]);
        [$out, $changed] = $this->walker->rewrite(
            $this->fieldsWithNestedCard(),
            $this->schema,
            'card',
            $ops,
        );
        self::assertTrue($changed);
        self::assertSame('top', $out['body'][0]['data']['heading']);
        self::assertArrayNotHasKey('title', $out['body'][0]['data']);
        self::assertSame('deep', $out['body'][1]['data']['inner'][0]['data']['heading']);
        // The entry's own top-level `title` is NOT a block field — untouched.
        self::assertSame('X', $out['title']);

        // Idempotent: re-running changes nothing (tolerant ops).
        [$again, $changedAgain] = $this->walker->rewrite($out, $this->schema, 'card', $ops);
        self::assertFalse($changedAgain);
        self::assertSame($out, $again);
    }

    public function testHasOpSourcesIsTheRemainingWorkPredicate(): void
    {
        $ops = new MigrationOpSet([new RenameField('title', 'heading')]);
        self::assertTrue($this->walker->hasOpSources($this->fieldsWithNestedCard(), $this->schema, 'card', $ops));
        [$out] = $this->walker->rewrite($this->fieldsWithNestedCard(), $this->schema, 'card', $ops);
        self::assertFalse($this->walker->hasOpSources($out, $this->schema, 'card', $ops));
        // An instance that never had the field is not remaining work.
        $sparse = ['body' => [['id' => 'c', 'type' => 'card', 'data' => []]]];
        self::assertFalse($this->walker->hasOpSources($sparse, $this->schema, 'card', $ops));
    }

    public function testMalformedItemsAndDepthCapAreLeftUntouched(): void
    {
        $deep = ['id' => 'x', 'type' => 'card', 'data' => ['title' => 'below-cap']];
        for ($i = 0; $i < 3; $i++) {
            $deep = ['id' => "n{$i}", 'type' => 'nest', 'data' => ['inner' => [$deep]]];
        }
        $fields = ['body' => [
            'not-a-block',
            ['id' => 'y', 'type' => 'ghost', 'data' => ['title' => 'z']],
            $deep,
        ]];
        $ops = new MigrationOpSet([new RenameField('title', 'heading')]);
        [$out, $changed] = $this->walker->rewrite($fields, $this->schema, 'card', $ops);
        self::assertFalse($changed);
        self::assertSame($fields, $out);
        self::assertFalse($this->walker->hasOpSources($fields, $this->schema, 'card', $ops));
    }
}
