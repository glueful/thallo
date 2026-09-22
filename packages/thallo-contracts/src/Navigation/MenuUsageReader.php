<?php

declare(strict_types=1);

namespace Thallo\Contracts\Navigation;

/**
 * Where a menu is shown through a Navigation block: the regions and the entries (draft or
 * published) that name it. A theme template that calls the menu by name is not a block and is
 * not listed.
 */
interface MenuUsageReader
{
    /** @return list<MenuUse> regions first, then entries; each place once */
    public function usage(string $menuSlug): array;
}
