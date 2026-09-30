<?php

declare(strict_types=1);

namespace Thallo\Contracts\Patterns;

/**
 * Where packs push their {@see PatternContributor}s (sections and templates design §3.1). Refuses
 * a contributor whole — never last-wins — when its id is taken, one of its slugs is taken by core or
 * another contributor, or one of its templates names a section that is not a page section.
 */
interface PatternContributorRegistry
{
    /** @throws \LogicException on a duplicate id, a slug collision or a bad reference */
    public function register(PatternContributor $contributor): void;

    /** @return list<PatternContributor> */
    public function all(): array;

    /**
     * A pack's layout patterns; refused whole on a taken id, a slug any pattern already holds, or a
     * surface no layout has.
     *
     * @throws \LogicException
     */
    public function registerLayout(LayoutPatternContributor $contributor): void;

    /** @return list<LayoutPatternContributor> */
    public function layoutContributors(): array;
}
