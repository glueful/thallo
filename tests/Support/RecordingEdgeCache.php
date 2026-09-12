<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Cache\Contracts\EdgeCacheInterface;

/**
 * A "real CDN is installed" EdgeCacheInterface stand-in that reports itself enabled
 * (isEnabled() === true, getProvider() !== null) and records every purgeByTag() call.
 *
 * The core default is NullEdgeCache (isEnabled() === false): the PurgeCdnListener must
 * treat that as "no CDN" and skip. Swapping this recording edge cache in proves the
 * present-env positive path — the listener DOES purge by the delivery surrogate tags.
 */
final class RecordingEdgeCache implements EdgeCacheInterface
{
    /** @var list<string> Every tag passed to purgeByTag(), in order. */
    public array $purgedTags = [];

    public function isEnabled(): bool
    {
        return true;
    }

    public function getProvider(): ?string
    {
        return 'recording';
    }

    /**
     * @return array<string, string>
     */
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
        $this->purgedTags[] = $tag;
        return true;
    }

    public function purgeAll(): bool
    {
        return true;
    }
}
