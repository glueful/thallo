<?php

declare(strict_types=1);

namespace Thallo\Contracts\Schema;

interface FieldTypeRegistry
{
    public function register(FieldTypeDefinition $type): void;

    public function get(string $key): FieldTypeDefinition;

    public function has(string $key): bool;

    /** @return array<string,FieldTypeDefinition> keyed by ->key(). */
    public function all(): array;
}
