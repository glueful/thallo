<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

use Thallo\Contracts\Schema\FieldDescriptor;

/**
 * Resolve a reference field's raw values (uuids and/or slugs) to canonical target uuids.
 */
interface ReferenceTargetResolver
{
    /**
     * @param list<string> $values
     * @return list<string>
     */
    public function resolve(FieldDescriptor $field, string $locale, array $values): array;
}
