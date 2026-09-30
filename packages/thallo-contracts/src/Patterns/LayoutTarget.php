<?php

declare(strict_types=1);

namespace Thallo\Contracts\Patterns;

/**
 * What a layout pattern is built for (sections and templates design §3.2): one target of one layout
 * surface, and the target type's schema fields exactly as stored — nothing dropped, so a builder in
 * core can rebuild the very schema, and one in a pack reads `name`, `type` and `format`. Off a
 * content type (the product page, the shop's pages) there are no fields.
 */
final class LayoutTarget
{
    /** @param list<array<string,mixed>> $fields the target type's schema fields, in schema order */
    public function __construct(
        public readonly string $surface,
        public readonly string $target,
        public readonly array $fields,
    ) {
    }
}
