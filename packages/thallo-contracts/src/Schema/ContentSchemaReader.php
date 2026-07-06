<?php

declare(strict_types=1);

namespace Thallo\Contracts\Schema;

/**
 * Read-only view of a content-type schema.
 */
interface ContentSchemaReader
{
    /** @return list<FieldDescriptor> */
    public function fields(): array;

    public function field(string $name): ?FieldDescriptor;
}
