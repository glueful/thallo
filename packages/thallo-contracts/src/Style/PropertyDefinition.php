<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * One managed setting (spec §1.3–1.4): its path, group, the value kinds it accepts, whether it
 * holds a sparse breakpoint map, and the token domain or the closed choices its values come from.
 */
final readonly class PropertyDefinition
{
    /**
     * @param list<ValueKind> $kinds
     * @param list<string>|null $choices
     */
    public function __construct(
        public string $path,
        public string $group,
        public array $kinds,
        public bool $responsive,
        public ?string $tokenDomain = null,
        public ?array $choices = null,
    ) {
    }

    public function accepts(ValueKind $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }
}
