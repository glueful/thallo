<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * Asks the renderer whether a block type's CURRENT template honours a style declaration it is
 * about to be given. A stored template that does not style a declared target refuses to load (the
 * template lint), so a declaration saved over such a template would break the block on every page
 * that uses it; whoever saves the declaration asks first and refuses with what to add.
 *
 * Optional: with no renderer bound nothing renders, and there is nothing to break.
 */
interface BlockTemplateTargetCheck
{
    /**
     * @return list<string> what the block's template would violate under `$targets`; empty when it
     *         honours them, or when the block has no template yet (the lint will hold the template
     *         to the declaration when it is written)
     */
    public function problems(string $blockSlug, StyleTargets $targets): array;
}
