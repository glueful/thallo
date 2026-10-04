<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support\Search;

use Thallo\Search\Lifecycle\Clock;

/** A clock tests move by hand. */
final class FixedClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-10-04 12:00:00')
    {
        $this->now = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    public function now(): string
    {
        return $this->now->format('Y-m-d H:i:s');
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
