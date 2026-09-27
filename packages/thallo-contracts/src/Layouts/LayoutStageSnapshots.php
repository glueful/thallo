<?php

declare(strict_types=1);

namespace Thallo\Contracts\Layouts;

/**
 * What a layout editing session shows on its stage (type layouts spec §5.2–§5.5): its working copy,
 * else its baseline. Null when the session's records are gone.
 */
interface LayoutStageSnapshots
{
    /**
     * @return array{source: string, layout: array{blocks: list<array<string,mixed>>, settings: array<string,mixed>},
     *     lock_version: int, epoch: ?string, revision: ?int, retired: bool, surface: string, target: string,
     *     sample: ?string}|null
     */
    public function snapshot(string $session): ?array;
}
