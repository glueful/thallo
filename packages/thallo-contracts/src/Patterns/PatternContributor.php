<?php

declare(strict_types=1);

namespace Thallo\Contracts\Patterns;

/**
 * A pack's sections and templates for the page library (sections and templates design §3): pushed
 * into the {@see PatternContributorRegistry} from the pack's provider, the way its block types and
 * starters are. Page patterns only for now — a pattern names no region and no layout surface.
 */
interface PatternContributor
{
    /** Stable identity, unique across contributors (e.g. 'thallo.commerce'). */
    public function id(): string;

    /** @return list<PatternSection> */
    public function sections(): array;

    /** @return list<PatternTemplate> */
    public function templates(): array;
}
