<?php

declare(strict_types=1);

namespace Thallo\Workflow\Events;

use Glueful\Events\Contracts\BaseEvent;

/** A draft was submitted for review. Seam for future notification wiring. */
final class ReviewSubmitted extends BaseEvent
{
    public function __construct(
        public readonly string $entry,
        public readonly string $locale,
        public readonly ?string $actor,
    ) {
        parent::__construct();
    }
}
