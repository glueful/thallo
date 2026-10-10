<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The palette's generation and its recent replacement records (custom palette spec §5.3), read at the
 * same moment as whatever the caller reads — so the style schema's slots, its generation and its batch
 * describe one palette, and an editor applies each record once.
 */
interface PaletteHistoryReader
{
    /**
     * Run `$read` between two reads of the palette generation, again until they match, then the batch
     * of completed replacement records ending at that generation.
     *
     * @template T
     * @param callable(): T $read
     * @return array{0: T, 1: int, 2: array{after: int, through: int, records: list<array<string,mixed>>}}
     */
    public function read(callable $read): array;
}
