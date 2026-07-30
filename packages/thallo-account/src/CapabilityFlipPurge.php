<?php

declare(strict_types=1);

namespace Thallo\Account;

use Glueful\Cache\CacheStore;
use Glueful\Cache\Contracts\EdgeCacheInterface;

/**
 * Reconciles cached chrome when the `thallo.accounts` capability flips between boots. A page cached
 * while accounts were ON keeps the account shell and its script tag; if the backing routes then
 * start 404-ing, that chrome is permanently broken on the cached page.
 *
 * The capability is deploy-time config, so there is NO flip event to listen for — this is a
 * boot-time persisted-state reconciler. An untagged marker records the last-seen state; a mismatch
 * on boot means the state changed while nothing was running to observe it. It has its OWN marker,
 * never Commerce's — sharing one would make whichever pack booted second see no flip. Mirrors
 * {@see \Thallo\Commerce\Shop\CapabilityFlipPurge}, but purges BOTH local caches.
 */
final class CapabilityFlipPurge
{
    // Its OWN marker. Sharing Commerce's would make whichever pack booted second see no flip.
    public const MARKER_KEY = 'thallo:accounts:capability-state';

    public function __construct(
        private readonly CacheStore $cache,
        private readonly ?EdgeCacheInterface $edge = null,
    ) {
    }

    public function reconcile(bool $enabled): void
    {
        $current = $enabled ? 'on' : 'off';
        $last = $this->cache->get(self::MARKER_KEY);
        if ($last === $current) {
            return;
        }

        // Marker absent (first boot ever, or the local store was flushed) -> record, no purge: a
        // flushed cache holds nothing stale. The marker is UNTAGGED so a purge can never delete its
        // own bookkeeping.
        if (is_string($last)) {
            // BOTH local tags. Commerce's own reconciler purges only `thallo:render:page`, which is
            // a gap for account chrome: the header renders on shop pages too, and those live in the
            // shop catalog cache. Purging one leaves the other serving a shell whose routes 404.
            $this->cache->invalidateTags(['thallo:render:page', 'thallo:shop:catalog']);

            if ($this->edge !== null && $this->edge->isEnabled()) {
                // Install-wide structural flip: the blunt primitive is the right one, and it is
                // skipped entirely when the edge is disabled.
                $this->edge->purgeAll();
            }
        }

        $this->cache->set(self::MARKER_KEY, $current);
    }
}
