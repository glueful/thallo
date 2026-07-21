<?php

declare(strict_types=1);

namespace Thallo\Contracts\Starter;

/**
 * A pack's contribution to the starter block-type set — mirrors the fixed definitions
 * {@see \App\Content\Blocks\StarterBlockTypes} ships, but sourced from an installed pack instead
 * of hard-coded. Pure value object; carries no behavior. The app-owned
 * {@see \App\Content\Starter\Kinds\BlockTypeKind} converts each of these into its internal
 * StarterDefinition shape, validating scalar fields and the schema (through the same rule
 * {@see \App\Content\Blocks\BlockTypeRepository::assertBlockSchema()} enforces on the fixed set)
 * before any write path can see them.
 */
final readonly class StarterBlockTypeDefinition
{
    /** @param list<array<string,mixed>> $schema  StarterBlockTypes field-entry shape */
    public function __construct(
        public string $sourceId,
        public string $slug,
        public string $label,
        public string $icon,
        public string $category,
        public ?string $description,
        public array $schema,
    ) {
    }
}
