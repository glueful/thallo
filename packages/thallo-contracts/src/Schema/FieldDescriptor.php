<?php

declare(strict_types=1);

namespace Thallo\Contracts\Schema;

/**
 * Read-only view of a single content-type field. Packs and core contracts depend on
 * this, never on the concrete engine field class.
 */
interface FieldDescriptor
{
    public function name(): string;
    public function type(): string;
    public function isMultiple(): bool;
    public function referenceType(): ?string;
    public function referenceSlugField(): ?string;
    /** Text-field presentation format ('plain' | 'rich'); null for non-text fields. */
    public function format(): ?string;
}
