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
        /** Style declaration (visual builder spec §1.7): capability paths or groups. @var list<string>|null */
        public ?array $styleCapabilities = null,
        /** Named targets and the capability map ({@see \Thallo\Contracts\Style\StyleTargets::root()}). */
        public ?array $styleTargets = null,
        /** Block flags (`renders_children_inline`). @var array<string,bool>|null */
        public ?array $flags = null,
        /** Data a new block of this type starts with (inserted by the editor). @var array<string,mixed>|null */
        public ?array $starterContent = null,
        /**
         * The definition owns the block's fields: an install's sync replaces the stored schema with
         * this one — fields it dropped are removed, changed choices replaced — instead of only adding
         * missing fields. For a block whose fields the pack defines and authors do not extend.
         */
        public bool $ownsSchema = false,
    ) {
    }
}
