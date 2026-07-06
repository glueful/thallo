<?php

declare(strict_types=1);

namespace Thallo\Contracts\Navigation;

/**
 * Resolved, published-only navigation for render/frontends. Implemented by the
 * thallo-navigation pack; consumers treat absence (or null) as "no menu" — the render
 * pack's menu() helper yields [] so nothing hard-depends on navigation being installed.
 */
interface MenuReader
{
    /**
     * @return list<array{label:string, url:string, entry:?string, children:list<mixed>}>|null
     *   null when no such menu (or the capability is disabled).
     */
    public function menu(string $slug, string $locale): ?array;
}
