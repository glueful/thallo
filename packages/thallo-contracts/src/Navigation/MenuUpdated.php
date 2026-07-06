<?php

declare(strict_types=1);

namespace Thallo\Contracts\Navigation;

use Glueful\Events\Contracts\BaseEvent;

/**
 * A menu (or its tree) was created/renamed/replaced/deleted. Cross-pack seam:
 * thallo-navigation dispatches it; thallo-render purges its page cache on it.
 */
final class MenuUpdated extends BaseEvent
{
    public function __construct(public readonly string $menuSlug)
    {
        parent::__construct();
    }
}
