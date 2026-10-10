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

    /** Every §1.3 property in table order: what a style class's declarations are validated against (§4.1). */
    public static function all(): self
    {
        return new self(array_keys(StyleSchema::properties()));
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
        // A hover path exists only beside its resting path (hover state spec §2.2), whichever was
        // listed first. A companion exists exactly where its anchor does: offered with it, nothing alone.
        foreach (array_keys($paths) as $path) {
            $resting = StyleSchema::restingPathOf($path) ?? StyleSchema::anchorOf($path);
            if ($resting !== null && !isset($paths[$resting])) {
                unset($paths[$path]);
            }
        }
        // Each companion right after its anchor, so a declaration's order still reads as written.
        $out = [];
        foreach (array_keys($paths) as $path) {
            if (StyleSchema::anchorOf($path) !== null) {
                continue;
            }
            $out[] = $path;
            $out = [...$out, ...array_keys(StyleSchema::ANCHORED, $path, true)];
        }
        return new self($out);
    }

    public function allows(string $path): bool
    {
        return in_array($path, $this->paths, true);
    }

    /**
     * The paths $keep keeps, in this set's order.
     *
     * @param callable(string): bool $keep
     */
    public function filter(callable $keep): self
    {
        return new self(array_values(array_filter($this->paths, $keep)));
    }

    /** @return list<string> */
    public function paths(): array
    {
        return $this->paths;
    }
}
