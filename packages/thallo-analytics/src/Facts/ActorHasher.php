<?php

declare(strict_types=1);

namespace Thallo\Analytics\Facts;

/**
 * One-way, per-instance salted hash of an actor id for the privacy-minimized active-actor table.
 * The raw id is never stored there; only this digest, which preserves uniqueness for counting.
 */
final class ActorHasher
{
    public function __construct(private readonly string $key)
    {
    }

    public function hash(string $actorId): string
    {
        return hash_hmac('sha256', $actorId, $this->key);
    }
}
