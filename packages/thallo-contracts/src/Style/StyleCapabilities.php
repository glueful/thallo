<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * The managed style paths a block type supports (spec §1.7). Declared as exact paths or as
 * group shorthand; undeclared means none. There is no wildcard.
 */
final readonly class StyleCapabilities
{
    /** @param list<string> $paths exact property paths, declaration order, deduplicated */
    private function __construct(public array $paths)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<string>|null $pathsOrGroups
     * @throws \InvalidArgumentException for an unknown path or group
     */
    public static function fromDeclaration(?array $pathsOrGroups): self
    {
        if ($pathsOrGroups === null || $pathsOrGroups === []) {
            return self::none();
        }
        $paths = [];
        foreach ($pathsOrGroups as $entry) {
            if (!is_string($entry)) {
                throw new \InvalidArgumentException('style capabilities must be strings');
            }
            if (StyleSchema::property($entry) !== null) {
                $paths[$entry] = true;
                continue;
            }
            $expanded = StyleSchema::pathsInGroup($entry);
            if ($expanded === []) {
                throw new \InvalidArgumentException(sprintf('unknown style capability "%s"', $entry));
            }
            foreach ($expanded as $path) {
                $paths[$path] = true;
            }
        }
        return new self(array_keys($paths));
    }

    public function allows(string $path): bool
    {
        return in_array($path, $this->paths, true);
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }
}
