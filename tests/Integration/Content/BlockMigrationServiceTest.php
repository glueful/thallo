<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\Migration\BlockMigrationRepository;
use App\Content\Blocks\Migration\BlockMigrationService;
use App\Content\Schema\SchemaParseException;
use App\Content\Services\ActiveMigrationException;
use App\Tests\Support\AppTestCase;

final class BlockMigrationServiceTest extends AppTestCase
{
    private function service(): BlockMigrationService
    {
        return $this->container()->get(BlockMigrationService::class);
    }

    private function blockType(): string
    {
        return (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'card',
            'label' => 'Card',
            'schema' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'note', 'type' => 'text'],
            ],
        ]);
    }

    public function testDeclareValidatesFlipsAndRecords(): void
    {
        $type = $this->blockType();
        $uuid = $this->service()->migrate($type, [
            ['op' => 'rename', 'from' => 'title', 'to' => 'heading'],
            ['op' => 'delete', 'name' => 'note'],
        ], 'user00000001');

        $blocks = new BlockTypeRepository($this->connection());
        $schema = $blocks->findBySlug('card')['schema'];
        self::assertSame(['heading'], array_column($schema, 'name'));

        $repo = new BlockMigrationRepository($this->connection(), $blocks);
        $row = $repo->find($uuid);
        self::assertSame('running', $row['status']);
        self::assertCount(2, $row['ops']);
    }

    public function testInvalidOpsRejectAndSecondDeclarationBlocks(): void
    {
        $type = $this->blockType();

        try {
            $this->service()->migrate($type, [['op' => 'rename', 'from' => 'nope', 'to' => 'x']], null);
            self::fail('expected SchemaParseException');
        } catch (SchemaParseException) {
            $this->addToAssertionCount(1);
        }

        $this->service()->migrate($type, [['op' => 'delete', 'name' => 'note']], null);
        try {
            $this->service()->migrate($type, [['op' => 'rename', 'from' => 'title', 'to' => 'heading']], null);
            self::fail('expected ActiveMigrationException');
        } catch (ActiveMigrationException) {
            $this->addToAssertionCount(1);
        }
    }
}
