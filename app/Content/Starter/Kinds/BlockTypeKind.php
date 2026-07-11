<?php

declare(strict_types=1);

namespace App\Content\Starter\Kinds;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use App\Content\Starter\AbstractStarterKind;
use App\Content\Starter\Fingerprint;
use App\Content\Starter\SeedContext;
use App\Content\Starter\StarterApplyResult;
use App\Content\Starter\StarterDefinition;
use Glueful\Database\Connection;

final class BlockTypeKind extends AbstractStarterKind
{
    public function __construct(
        private readonly BlockTypeRepository $blocks,
        private readonly Connection $db,
    ) {
    }

    public function kind(): string
    {
        return 'block_type';
    }

    public function definitions(): array
    {
        return array_map(static function (array $definition): StarterDefinition {
            $definition['active'] = (bool) ($definition['active'] ?? true);
            return new StarterDefinition(
                'block_type:' . $definition['slug'],
                (string) $definition['slug'],
                $definition,
            );
        }, StarterBlockTypes::definitions());
    }

    public function fingerprint(StarterDefinition $definition): string
    {
        $payload = $definition->payload;
        unset($payload['slug']);
        return Fingerprint::of($payload);
    }

    public function locateExact(string $definitionKey): ?array
    {
        $row = $this->blocks->findBySlug($definitionKey);
        return $row === null ? null : [
            'key' => $definitionKey,
            'fingerprint' => Fingerprint::of($this->normalizeRow($row)),
        ];
    }

    public function apply(StarterDefinition $definition, SeedContext $seed): StarterApplyResult
    {
        if ($this->blocks->findBySlug($definition->definitionKey) !== null) {
            return StarterApplyResult::SkippedCollision;
        }
        $this->blocks->create($definition->payload);
        return StarterApplyResult::Applied;
    }

    public function updateTo(
        StarterDefinition $definition,
        string $rowKey,
        SeedContext $seed,
    ): void {
        $row = $this->blocks->findBySlug($rowKey)
            ?? throw new \RuntimeException("block type {$rowKey} not found");
        $payload = $definition->payload;
        $this->blocks->updateSchema(
            (string) $row['uuid'],
            $payload['schema'],
            (string) $payload['label'],
            isset($payload['icon']) ? (string) $payload['icon'] : null,
            isset($payload['description']) ? (string) $payload['description'] : null,
            isset($payload['category']) ? (string) $payload['category'] : null,
        );
        if ((bool) $row['active'] !== (bool) $payload['active']) {
            $this->blocks->setActive((string) $row['uuid'], (bool) $payload['active']);
        }
    }

    public function rename(StarterDefinition $definition, string $oldKey): void
    {
        $this->db->table('block_types')->where('slug', '=', $oldKey)->update([
            'slug' => $definition->definitionKey,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->blocks->resetSchemaMemo();
    }

    public function syncable(): bool
    {
        return true;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeRow(array $row): array
    {
        return [
            'label' => (string) $row['label'],
            'icon' => $row['icon'] === null ? null : (string) $row['icon'],
            'category' => $row['category'] === null ? null : (string) $row['category'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'schema' => (array) $row['schema'],
            'active' => (bool) $row['active'],
        ];
    }
}
