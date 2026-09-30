<?php

declare(strict_types=1);

namespace Thallo\Contracts\Patterns;

/**
 * One page template a pack contributes: its sections, by slug, in order — the pack's own sections
 * or core page sections. Offered whole or not at all.
 */
final class PatternTemplate
{
    /** @param list<string> $sections */
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly string $description,
        public readonly array $sections,
        public readonly string $category = 'Pages',
    ) {
    }
}
