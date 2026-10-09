<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The palette's job state (custom palette spec §4.4, §5.2): which brand slots a running replacement
 * is replacing, which it reserves as destinations, and where each replacement is going — what the
 * pickers need to hide a slot being replaced and to say what it is being replaced by.
 */
interface PaletteStatusReader
{
    /**
     * @return array<int, array{reserved: bool, replacing: array{to: string, contrast_to: ?string}|null}>
     *         keyed by slot; a slot absent from the map is neither replacing nor reserved
     */
    public function statuses(): array;
}
