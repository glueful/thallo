<?php

declare(strict_types=1);

namespace App\Content\Starter\Kinds;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\StarterBlockTypes;
use App\Content\Schema\SchemaParseException;
use App\Content\Starter\AbstractStarterKind;
use App\Content\Starter\Fingerprint;
use App\Content\Starter\SeedContext;
use App\Content\Starter\StarterApplyResult;
use App\Content\Starter\StarterDefinition;
use Glueful\Database\Connection;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;
use Thallo\Contracts\Starter\StarterBlockTypeRegistry;

final class BlockTypeKind extends AbstractStarterKind
{
    public function __construct(
        private readonly BlockTypeRepository $blocks,
        private readonly Connection $db,
        private readonly ?StarterBlockTypeRegistry $contributors = null,
    ) {
    }

    public function kind(): string
    {
        return 'block_type';
    }

    public function definitions(): array
    {
        $fixed = array_map(static function (array $definition): StarterDefinition {
            $definition['active'] = (bool) ($definition['active'] ?? true);
            return new StarterDefinition(
                'block_type:' . $definition['slug'],
                (string) $definition['slug'],
                $definition,
            );
        }, StarterBlockTypes::definitions());

        $definitions = [...$fixed, ...$this->contributedDefinitions()];
        $this->assertNoDuplicates($definitions);

        return $definitions;
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

    /**
     * Converted contributed definitions, in registration/contributor order. Each contributor's
     * VOs are validated and converted to the internal {@see StarterDefinition} shape BEFORE this
     * method returns — nothing here or downstream (TenantSeeder/StarterSync) writes to storage
     * until the full fixed+contributed set has been assembled and passed the duplicate check in
     * {@see definitions()}.
     *
     * @return list<StarterDefinition>
     */
    private function contributedDefinitions(): array
    {
        $definitions = [];
        foreach ($this->contributors?->all() ?? [] as $contributor) {
            foreach ($contributor->blockTypeDefinitions() as $definition) {
                $definitions[] = $this->convert($definition);
            }
        }
        return $definitions;
    }

    private function convert(StarterBlockTypeDefinition $definition): StarterDefinition
    {
        $sourceId = trim($definition->sourceId);
        if ($sourceId === '') {
            throw new \InvalidArgumentException('starter block-type contribution has an empty sourceId');
        }
        $slug = trim($definition->slug);
        if ($slug === '') {
            throw new \InvalidArgumentException("starter block-type contribution '{$sourceId}' has an empty slug");
        }
        $label = trim($definition->label);
        if ($label === '') {
            throw new \InvalidArgumentException("starter block-type contribution '{$sourceId}' has an empty label");
        }

        // Same rule the fixed library satisfies (BlockTypeRepository::create()/updateSchema()):
        // no blocks/localized/filterable prohibitions plus full field-schema parsing.
        try {
            $this->blocks->assertBlockSchema($definition->schema);
        } catch (SchemaParseException $e) {
            throw new SchemaParseException(
                "starter block-type contribution '{$sourceId}' has an invalid schema: " . $e->getMessage(),
                previous: $e,
            );
        }

        return new StarterDefinition(
            sourceId: $definition->sourceId,
            definitionKey: $slug,
            payload: [
                'slug' => $slug,
                'label' => $label,
                'icon' => $definition->icon,
                'category' => $definition->category,
                'description' => $definition->description,
                'schema' => $definition->schema,
                'active' => true,
            ],
        );
    }

    /** @param list<StarterDefinition> $definitions */
    private function assertNoDuplicates(array $definitions): void
    {
        $seenSourceIds = [];
        $seenSlugs = [];
        foreach ($definitions as $definition) {
            if (isset($seenSourceIds[$definition->sourceId])) {
                throw new \InvalidArgumentException(
                    "duplicate starter block-type sourceId '{$definition->sourceId}'"
                );
            }
            $seenSourceIds[$definition->sourceId] = true;

            if (isset($seenSlugs[$definition->definitionKey])) {
                throw new \InvalidArgumentException(
                    "duplicate starter block-type slug '{$definition->definitionKey}'"
                );
            }
            $seenSlugs[$definition->definitionKey] = true;
        }
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
