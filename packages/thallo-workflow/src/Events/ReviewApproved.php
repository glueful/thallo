<?php

declare(strict_types=1);

namespace Thallo\Workflow\Events;

use Glueful\Events\Contracts\BaseEvent;

/** A submission was approved. Seam for future notification wiring. */
final class ReviewApproved extends BaseEvent
{
    public function __construct(
        public readonly string $entry,
        public readonly string $locale,
        public readonly ?string $actor,
    ) {
        parent::__construct();
    }
}
