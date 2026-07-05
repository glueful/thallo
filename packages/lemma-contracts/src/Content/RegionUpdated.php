<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Content;

use Glueful\Events\Contracts\BaseEvent;

/**
 * A global chrome region (header/footer) was saved. Cross-pack seam: the app's
 * region admin dispatches it; lemma-render purges its page cache on it —
 * chrome appears on every page, so the purge is broad (global-regions spec §11).
 */
final class RegionUpdated extends BaseEvent
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct();
    }
}
