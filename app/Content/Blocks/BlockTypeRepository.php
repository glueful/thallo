<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\SchemaParseException;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

/**
 * The global block-type registry (block-builder spec §1–§2). Block types are reusable
 * mini-schemas: their `schema` is the same field-definition JSON content types use,
 * parsed through ContentTypeSchema with three EXTRA rules (assertBlockSchema): no
 * `blocks` fields (no nesting in v1), no `localized` fields (localization belongs to
 * the outer blocks field), no `filterable` fields (block data is never a filter
 * surface). Slugs are immutable after create — they are the blocks/{slug}.twig
 * template contract. Removal is DEACTIVATION only.
 */
final class BlockTypeRepository
{
    /** @var array<string, ContentTypeSchema>|null slug => parsed schema (active + inactive) */
    private ?array $schemas = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{slug: string, label: string, icon?: ?string, category?: ?string,
     *   description?: ?string, schema: list<array<string,mixed>>} $data
     * @return string the new uuid
     */
    public function create(array $data): string
    {
        // The slug is the durable blocks/{slug}.twig contract — a DOMAIN invariant,
        // enforced here and not only in the API DTO (rows written around the API
        // must not mint path-unsafe template names).
        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $data['slug']) !== 1) {
            throw new SchemaParseException(
                "block type slug '{$data['slug']}' must match [a-z][a-z0-9_-]{0,63}"
            );
        }
        $this->assertBlockSchema($data['schema']);
        $now = gmdate('Y-m-d H:i:s');
        $uuid = Utils::generateNanoID();
        $this->db->table('lemma_block_types')->insert([
            'uuid' => $uuid,
            'slug' => $data['slug'],
            'label' => $data['label'],
            'icon' => $data['icon'] ?? null,
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'schema' => (string) json_encode(array_values($data['schema'])),
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->schemas = null;
        return $uuid;
    }

    /** @return array<string,mixed>|null hydrated row (schema decoded) */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->table('lemma_block_types')->where('slug', '=', $slug)->first();
        return $row === null ? null : $this->hydrate((array) $row);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        $row = $this->db->table('lemma_block_types')->where('uuid', '=', $uuid)->first();
        return $row === null ? null : $this->hydrate((array) $row);
    }

    /** @return list<array<string,mixed>> active first, then label */
    public function all(): array
    {
        $out = [];
        foreach (
            $this->db->table('lemma_block_types')
                ->orderBy('active', 'DESC')
                ->orderBy('label', 'ASC')
                ->get() as $row
        ) {
            $out[] = $this->hydrate((array) $row);
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $schema slug stays immutable (spec §1) */
    public function updateSchema(
        string $uuid,
        array $schema,
        string $label,
        ?string $icon,
        ?string $description,
        ?string $category = null,
    ): void {
        $this->assertBlockSchema($schema);
        $this->db->table('lemma_block_types')->where('uuid', '=', $uuid)->update([
            'label' => $label,
            'icon' => $icon,
            'category' => $category,
            'description' => $description,
            'schema' => (string) json_encode(array_values($schema)),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->schemas = null;
    }

    public function setActive(string $uuid, bool $active): void
    {
        $this->db->table('lemma_block_types')->where('uuid', '=', $uuid)->update([
            'active' => $active ? 1 : 0,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->schemas = null;
    }

    /**
     * The validator's lookup: slug => parsed schema, ACTIVE AND INACTIVE (existing
     * content referencing a deactivated type must keep validating — spec §4).
     *
     * @return array<string, ContentTypeSchema>
     */
    public function schemasBySlug(): array
    {
        if ($this->schemas !== null) {
            return $this->schemas;
        }
        $this->schemas = [];
        foreach ($this->all() as $row) {
            $this->schemas[(string) $row['slug']] = ContentTypeSchema::fromArray($row['schema']);
        }
        return $this->schemas;
    }

    /**
     * §2 rules on TOP of normal field-schema parsing: parsing itself (via
     * ContentTypeSchema) rejects invalid types/enums/etc.; these two are the
     * blocks-specific prohibitions. `blocks` fields ARE allowed since the nesting
     * amendment (§A1) — the BlockDepth::MAX data cap replaced the schema-level ban.
     *
     * @param list<array<string,mixed>> $schema
     */
    public function assertBlockSchema(array $schema): void
    {
        foreach ($schema as $field) {
            $name = is_string($field['name'] ?? null) ? $field['name'] : '?';
            if ((bool) ($field['localized'] ?? false)) {
                throw new SchemaParseException(
                    "block field '{$name}': localization belongs to the outer blocks field"
                );
            }
            if ((bool) ($field['filterable'] ?? false)) {
                throw new SchemaParseException("block field '{$name}': block data is never filterable");
            }
        }
        ContentTypeSchema::fromArray($schema); // full semantic validation (throws SchemaParseException)
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        $row['schema'] = (array) json_decode((string) ($row['schema'] ?? '[]'), true);
        $row['active'] = (int) $row['active'];
        return $row;
    }
}
