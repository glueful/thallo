<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use App\Content\Starter\Kinds\BlockTypeKind;

/**
 * Seeds every starter block type — the fixed library ({@see StarterBlockTypes}) plus pack
 * contributions, as {@see BlockTypeKind::definitions()} assembles them — that is not in the
 * table yet. Existing rows are never touched: an edited or deactivated starter is an admin
 * decision, and a slug with a custom definition keeps it.
 *
 * Runs at install (the whole library on a fresh instance) and on every `thallo:provision`
 * (so a starter added on upgrade lands, and an instance that only ever received migration
 * 021's 16-slug subset is completed on the next run).
 */
final class StarterBlockTypeSeeder
{
    public function __construct(
        private readonly BlockTypeRepository $blocks,
        private readonly BlockTypeKind $kind,
    ) {
    }

    /** @return array{created: list<string>, skipped: list<string>} slugs, in library order */
    public function seedMissing(): array
    {
        $created = [];
        $skipped = [];
        foreach ($this->kind->definitions() as $definition) {
            $slug = $definition->definitionKey;
            if ($this->blocks->findBySlug($slug) !== null) {
                $skipped[] = $slug;
                continue;
            }
            $this->blocks->create($definition->payload);
            $created[] = $slug;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
