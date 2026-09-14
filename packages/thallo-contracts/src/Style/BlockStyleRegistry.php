<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * What each block type declares about styling (spec §1.7). Bound by the application to its block
 * type registry; the render pack and the validator consume it.
 */
interface BlockStyleRegistry
{
    /** Undeclared means none. */
    public function capabilitiesFor(string $type): StyleCapabilities;

    public function targetsFor(string $type): ?StyleTargets;

    /** @return array<string, mixed> e.g. `renders_children_inline` */
    public function flagsFor(string $type): array;

    /** @return list<string> the block type's region field names (its blocks-typed fields); unknown type = none */
    public function regionsFor(string $type): array;
}
