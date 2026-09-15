<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * One style class as a cascade layer (visual builder spec §1.6, §4.1): its id and its
 * declarations — the shape `CascadeResolver::resolve()` takes in list order.
 */
final readonly class StyleClassRef
{
    /** @param array<string,mixed> $style the class's `style` (§1 schema, sparse breakpoints and resets included) */
    public function __construct(public string $id, public array $style)
    {
    }

    /** @return array{id: string, style: array<string,mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'style' => $this->style];
    }
}
