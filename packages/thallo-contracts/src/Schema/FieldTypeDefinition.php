<?php

declare(strict_types=1);

namespace Thallo\Contracts\Schema;

/**
 * A registrable field type. The registry standardizes discovery/validation/widget/capability;
 * domain-specific metadata (column_type, localized, …) lives in each domain's own field payload.
 */
interface FieldTypeDefinition
{
    /** Namespaced key, e.g. "content.text" or "collections.decimal". */
    public function key(): string;

    public function label(): string;

    /** "scalar" | "array" | "json" — the broad shape of the stored value. */
    public function valueShape(): string;

    /** @return array<string,mixed> generic validation hints (required-capable, max length, …). */
    public function validationRules(): array;

    public function adminWidget(): string;

    /** @return array{filterable?:bool,sortable?:bool,indexable?:bool,multi?:bool,localized?:bool} */
    public function capabilities(): array;
}
