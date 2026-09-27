<?php

declare(strict_types=1);

namespace Thallo\Contracts\Layouts;

/**
 * The saved layout for a page kind (type layouts spec §5.1, §7.2): what the render asks before it
 * chooses a template. Null for no layout — never saved, or removed.
 */
interface LayoutReader
{
    /**
     * @return array{blocks: list<array<string,mixed>>, settings: array<string,mixed>, lock_version: int}|null
     */
    public function for(string $surface, string $target): ?array;
}
