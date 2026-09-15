<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

use Glueful\Events\Contracts\BaseEvent;

/**
 * A style class record was written (visual builder spec §4.3): create, update, archive, lock or
 * unlock. `generation` is the site's style generation after the write. Render caches key on the
 * generation, so a listener's purge is hygiene for the previous generation's entries.
 */
final class StyleClassSaved extends BaseEvent
{
    public function __construct(public readonly string $id, public readonly int $generation)
    {
        parent::__construct();
    }
}
