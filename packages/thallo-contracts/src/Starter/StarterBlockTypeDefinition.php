<?php

declare(strict_types=1);

namespace Thallo\Contracts\Starter;

/**
 * A pack's contribution to the starter block-type set — mirrors the fixed definitions
 * {@see \Thallo\Core\Content\Blocks\StarterBlockTypes} ships, but sourced from an installed pack instead
 * of hard-coded. Pure value object; carries no behavior. The app-owned
 * {@see \Thallo\Core\Content\Starter\Kinds\BlockTypeKind} converts each of these into its internal
 * StarterDefinition shape, validating scalar fields and the schema (through the same rule
 * {@see \Thallo\Core\Content\Blocks\BlockTypeRepository::assertBlockSchema()} enforces on the fixed set)
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
        /**
         * Capability id (e.g. `thallo.commerce`) this block type belongs to, or null for an
         * ungated contribution. A gated definition is seeded only while the capability is on
         * and is hidden from the block-type listing (never deleted) while it is off.
         */
        public ?string $requiresCapability = null,
    ) {
    }
}
