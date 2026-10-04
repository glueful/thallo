<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Cache\Contracts\EdgeCacheInterface;

/** A recording edge cache whose purges can fail a number of times or run a hook. */
final class FakeEdgeCache implements EdgeCacheInterface
{
    public int $purgeAllCalls = 0;

    /** @param (\Closure(): void)|null $onPurge */
    public function __construct(private int $failPurges = 0, private readonly ?\Closure $onPurge = null)
    {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getProvider(): ?string
    {
        return 'fake';
    }

    public function generateCacheHeaders(string $route, ?string $contentType = null): array
    {
        return [];
    }

    public function purgeUrl(string $url): bool
    {
        return true;
    }

    public function purgeByTag(string $tag): bool
    {
        return true;
    }

    public function purgeAll(): bool
    {
        $this->purgeAllCalls++;
        if ($this->onPurge !== null) {
            ($this->onPurge)();
        }
        if ($this->failPurges > 0) {
            $this->failPurges--;
            return false;
        }
        return true;
    }
}
