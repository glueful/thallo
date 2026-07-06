<?php

declare(strict_types=1);

namespace Thallo\Contracts\Events;

/**
 * Stable subscription surface for content lifecycle events. Packs (search, render,
 * analytics) type-hint this and read name()/payload() to react, without depending on
 * the concrete engine event classes.
 */
interface ContentLifecycleEvent
{
    /** Stable event name, e.g. "entry.created", "entry.published". */
    public function name(): string;

    /** @return array<string,mixed> Identity + change summary; never the full field set. */
    public function payload(): array;
}
