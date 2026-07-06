<?php

declare(strict_types=1);

namespace Thallo\Workflow\Events;

use Glueful\Events\Contracts\BaseEvent;

/** A reviewer requested changes on a submission. Seam for future notification wiring. */
final class ChangesRequested extends BaseEvent
{
    public function __construct(
        public readonly string $entry,
        public readonly string $locale,
        public readonly ?string $actor,
        public readonly ?string $note,
    ) {
        parent::__construct();
    }
}
