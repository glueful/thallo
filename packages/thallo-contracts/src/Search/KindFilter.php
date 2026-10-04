<?php

declare(strict_types=1);

namespace Thallo\Contracts\Search;

/**
 * Which of a kind's documents an audience may match, applied inside the engine's query (search
 * block spec §3.4): none, all, or only some subtypes (for entries, the visible content types).
 */
final class KindFilter
{
    public const NONE = 'none';
    public const ALL = 'all';
    public const SUBTYPES = 'subtypes';

    /** @param list<string> $subtypes */
    private function __construct(
        public readonly string $mode,
        public readonly array $subtypes,
    ) {
    }

    public static function none(): self
    {
        return new self(self::NONE, []);
    }

    public static function all(): self
    {
        return new self(self::ALL, []);
    }

    /** @param list<string> $subtypes an empty list is {@see none()} */
    public static function subtypes(array $subtypes): self
    {
        return $subtypes === [] ? self::none() : new self(self::SUBTYPES, array_values(array_unique($subtypes)));
    }
}
