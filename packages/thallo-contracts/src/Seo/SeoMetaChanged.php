<?php

declare(strict_types=1);

namespace Thallo\Contracts\Seo;

use Glueful\Events\Contracts\BaseEvent;

/**
 * An entry's seo_meta override changed (upsert, including an empty-values clear).
 * Cross-pack seam (the MenuUpdated precedent): thallo-seo dispatches it; the app's
 * SeoMetaChangedListener purges the entry's cached rendered pages locally and at the
 * edge (seo-head spec §5).
 */
final class SeoMetaChanged extends BaseEvent
{
    public function __construct(
        public readonly string $entryUuid,
        public readonly string $locale,
    ) {
        parent::__construct();
    }
}
