<?php

declare(strict_types=1);

namespace Thallo\Contracts\Fields;

/**
 * Dynamic choices for a block schema field that names it as its `options_source` (search block
 * spec §3.9). The admin loads them for authors; the source decides who may.
 */
interface FieldOptionSource
{
    /** e.g. `thallo-search.scopes` */
    public function id(): string;

    /** The permission a caller needs, checked after the route's own. */
    public function permission(): string;

    /** @return list<array{value: string, label: string, available: bool, reason: ?string}> */
    public function options(): array;
}
