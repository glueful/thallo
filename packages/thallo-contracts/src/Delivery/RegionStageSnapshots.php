<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * What the header & footer stage renders for one session (regions-stage spec §4.2, §4.4): the
 * session's working copy if it has one, else the baseline it was minted with, else null (the
 * session expired). Read ONCE per render.
 */
interface RegionStageSnapshots
{
    /**
     * @return array<string,mixed>|null `{source: 'working'|'baseline', regions, epoch: ?string,
     *                                   revision: ?int}`, or null when the session expired
     */
    public function snapshot(string $session): ?array;
}
