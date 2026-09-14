<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypeSeeder;
use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;

/**
 * Brings the test database's block types up to the shipped style declarations (visual builder
 * spec §1.7): seeds missing types, writes any declaration that differs from the starter or
 * contributed definition, and drops the process-shared memos a new request would not carry.
 */
trait SyncsBlockStyleDeclarations
{
    protected function syncBlockStyleDeclarations(): void
    {
        $this->container()->get(StarterBlockTypeSeeder::class)->seedMissing();
        $repo = $this->container()->get(BlockTypeRepository::class);
        $keys = ['style_capabilities', 'style_targets', 'flags', 'starter_content'];
        foreach ($this->container()->get(BlockTypeKind::class)->definitions() as $definition) {
            $payload = $definition->payload;
            $row = $repo->findBySlug((string) $payload['slug']);
            if ($row === null) {
                continue;
            }
            $differs = false;
            foreach ($keys as $key) {
                if (self::canonical($row[$key] ?? null) !== self::canonical($payload[$key] ?? null)) {
                    $differs = true;
                }
            }
            if ($differs) {
                $repo->updateStyle(
                    (string) $row['uuid'],
                    $payload['style_capabilities'] ?? null,
                    $payload['style_targets'] ?? null,
                    $payload['flags'] ?? null,
                    $payload['starter_content'] ?? null,
                );
            }
        }
        (new \ReflectionProperty($repo, 'schemas'))->setValue($repo, null);
        $this->container()->get(BlockStyleRegistry::class)->reset();
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            $value[$k] = self::canonical($v);
        }
        return $value;
    }
}
