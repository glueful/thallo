<?php

declare(strict_types=1);

namespace Thallo\Contracts\Patterns;

/**
 * A pack's sections and templates for the layout editor (sections and templates design §3): each
 * names its surface and is built for the layout's target when the library serves it.
 */
interface LayoutPatternContributor
{
    /** Stable identity, unique among layout contributors (e.g. 'thallo.commerce'). */
    public function id(): string;

    /** @return list<LayoutSection> */
    public function layoutSections(): array;

    /** @return list<LayoutTemplate> */
    public function layoutTemplates(): array;
}
