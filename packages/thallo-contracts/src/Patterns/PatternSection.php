<?php

declare(strict_types=1);

namespace Thallo\Contracts\Patterns;

/**
 * One section a pack contributes to the page library: one block tree with no ids, built with
 * {@see PatternBlocks}. `requires` names what an editor must choose after inserting it — 'product'
 * for a section whose product block needs a product — or null.
 */
final class PatternSection
{
    /** @param array<string,mixed> $block */
    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        public readonly string $category,
        public readonly string $description,
        public readonly array $block,
        public readonly ?string $requires = null,
    ) {
    }
}
