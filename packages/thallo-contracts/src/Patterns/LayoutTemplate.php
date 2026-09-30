<?php

declare(strict_types=1);

namespace Thallo\Contracts\Patterns;

/**
 * A layout template (sections and templates design §3.1, §4): the whole layout built for a target —
 * or null when it does not fit — and the Frame settings it brings. It names no sections: every layout
 * holds its surface's required block, which no section does, so a template is one complete tree.
 */
final class LayoutTemplate
{
    /**
     * @param \Closure(LayoutTarget): (list<array<string,mixed>>|null) $build
     * @param array<string,string> $settings width ('contained'|'full'), header and footer ('default'|'hidden');
     *                                       an omitted key is the default
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $surface,
        public readonly string $label,
        public readonly string $description,
        public readonly \Closure $build,
        public readonly array $settings = [],
    ) {
    }
}
