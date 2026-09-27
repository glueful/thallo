<?php

declare(strict_types=1);

namespace Thallo\Contracts\Layouts;

use Glueful\Events\Contracts\BaseEvent;

/**
 * A stored layout changed — saved, removed, or rewritten by a block migration, a style-class job or
 * a content-model change (type layouts spec §7.4). Dispatched after the outermost commit. A pack
 * whose pages render through its own cache (the shop's) purges them on it; `tenantUuid` names the
 * workspace the write ran for, null while tenancy is off.
 */
final class LayoutChanged extends BaseEvent
{
    public function __construct(
        public readonly string $surface,
        public readonly string $target,
        public readonly ?string $tenantUuid = null,
    ) {
        parent::__construct();
    }
}
