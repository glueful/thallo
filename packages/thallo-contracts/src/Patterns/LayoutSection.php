<?php

declare(strict_types=1);

namespace Thallo\Contracts\Patterns;

/**
 * A layout section (sections and templates design §3.2): one block tree built for a target — bound
 * to that type's real fields — or null when it does not fit the target (a cover band for a type with
 * no cover). The tree carries no ids, as every pattern.
 */
final class LayoutSection
{
    /** @param \Closure(LayoutTarget): (array<string,mixed>|null) $build */
    public function __construct(
        public readonly string $slug,
        public readonly string $surface,
        public readonly string $label,
        public readonly string $category,
        public readonly string $description,
        public readonly \Closure $build,
    ) {
    }
}
