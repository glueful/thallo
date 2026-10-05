<?php

declare(strict_types=1);

namespace Thallo\Contracts\Fonts;

/**
 * The font library at one generation: every decision a render makes about typefaces (which utility a
 * block gets, which faces the stylesheet declares, its hash) is taken from one of these.
 */
interface FontLibrarySnapshotView
{
    /** Increases with every library change; one generation is one library. */
    public function generation(): int;

    /** An uploaded family, current or removed; null when the ID is not in this workspace's library. */
    public function family(string $id): ?FontFamilyView;

    /** @return list<FontFamilyView> the current (not removed) uploaded families, by name */
    public function active(): array;

    /**
     * How a stored typeface ID resolves: a reserved built-in, a current uploaded family, or neither
     * (removed, purged, from another workspace, or not an ID) — which renders as `inherit`.
     *
     * @return 'builtin'|'uploaded'|'missing'
     */
    public function resolution(string $id): string;
}
