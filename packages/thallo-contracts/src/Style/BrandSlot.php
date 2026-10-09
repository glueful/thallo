<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/** A configured brand colour (custom palette spec §2.3): the author's label and its light hex. */
final class BrandSlot
{
    public function __construct(public readonly string $name, public readonly string $hex)
    {
    }

    /** @return array{name:string,hex:string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'hex' => $this->hex];
    }
}
