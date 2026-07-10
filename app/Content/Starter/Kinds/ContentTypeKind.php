<?php

declare(strict_types=1);

namespace App\Content\Starter\Kinds;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Starter\AbstractStarterKind;
use App\Content\Starter\Fingerprint;
use App\Content\Starter\SeedContext;
use App\Content\Starter\StarterApplyResult;
use App\Content\Starter\StarterDefinition;
use Glueful\Database\Connection;

final class ContentTypeKind extends AbstractStarterKind
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly Connection $db,
    ) {
    }

    public function kind(): string
    {
        return 'content_type';
    }

    public function definitions(): array
    {
        return array_map(fn(array $payload): StarterDefinition => new StarterDefinition(
            sourceId: 'content_type:' . $payload['slug'],
            definitionKey: (string) $payload['slug'],
            payload: $payload,
        ), $this->payloads());
    }

    public function fingerprint(StarterDefinition $definition): string
    {
        $payload = $definition->payload;
        unset($payload['slug']);
        return Fingerprint::of($payload);
    }

    public function locateExact(string $definitionKey): ?array
    {
        $row = $this->types->findBySlug($definitionKey);
        return $row === null ? null : [
            'key' => $definitionKey,
            'fingerprint' => Fingerprint::of($this->normalizeRow($row)),
        ];
    }

    public function apply(StarterDefinition $definition, SeedContext $seed): StarterApplyResult
    {
        if ($this->types->findBySlug($definition->definitionKey) !== null) {
            return StarterApplyResult::SkippedCollision;
        }
        $this->types->create($definition->payload + ['created_by' => $seed->actorUuid]);
        return StarterApplyResult::Applied;
    }

    public function updateTo(
        StarterDefinition $definition,
        string $rowKey,
        SeedContext $seed,
    ): void {
        $row = $this->types->findBySlug($rowKey)
            ?? throw new \RuntimeException("content type {$rowKey} not found");
        if ($row['schema'] !== $definition->payload['schema']) {
            $this->types->updateSchema((string) $row['uuid'], $definition->payload['schema']);
        }
        $this->types->updateMeta((string) $row['uuid'], [
            'name' => $definition->payload['name'],
            'description' => $definition->payload['description'],
            'cache_ttl' => $definition->payload['cache_ttl'],
            'public_delivery' => $definition->payload['public_delivery'],
            'mount_at_root' => $definition->payload['mount_at_root'],
        ]);
    }

    public function rename(StarterDefinition $definition, string $oldKey): void
    {
        $this->db->table('content_types')->where('slug', '=', $oldKey)->update([
            'slug' => $definition->definitionKey,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function syncable(): bool
    {
        return true;
    }

    /** @return list<array<string,mixed>> */
    private function payloads(): array
    {
        return [
            $this->payload('pages', 'Pages', 'Generic static pages (e.g. About, Contact).', true, true, [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks', 'required' => true],
            ]),
            $this->payload('category', 'Categories', 'Groups posts into browsable archives.', true, false, [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'slug', 'type' => 'string', 'required' => true],
            ]),
            $this->payload('post', 'Posts', 'Dated articles and news (e.g. blog posts).', true, false, [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'excerpt', 'type' => 'text'],
                ['name' => 'cover', 'type' => 'asset'],
                ['name' => 'body', 'type' => 'blocks', 'required' => true],
                [
                    'name' => 'categories', 'type' => 'reference', 'reference_type' => 'category',
                    'multiple' => true, 'filterable' => true,
                ],
            ]),
        ];
    }

    /** @param list<array<string,mixed>> $schema @return array<string,mixed> */
    private function payload(
        string $slug,
        string $name,
        string $description,
        bool $public,
        bool $root,
        array $schema,
    ): array {
        return [
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'cache_ttl' => null,
            'public_delivery' => $public,
            'mount_at_root' => $root,
            'schema' => ContentTypeSchema::fromArray($schema)->toArray(),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeRow(array $row): array
    {
        return [
            'name' => (string) $row['name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'cache_ttl' => $row['cache_ttl'],
            'public_delivery' => (bool) $row['public_delivery'],
            'mount_at_root' => (bool) $row['mount_at_root'],
            'schema' => (array) $row['schema'],
        ];
    }
}
