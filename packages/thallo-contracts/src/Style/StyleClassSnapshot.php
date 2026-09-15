<?php

declare(strict_types=1);

namespace Thallo\Contracts\Style;

/**
 * One site's style classes at one generation (visual builder spec §4.3): the generation names
 * exactly this set of records. Archived classes are held so a retained revision that references
 * one still resolves; an id the snapshot does not hold is not owned by the site.
 */
final readonly class StyleClassSnapshot
{
    /**
     * @param array<string, array{id: string, name: string, style: array<string,mixed>, archived: bool}> $classes
     *   keyed by id
     */
    public function __construct(public int $generation, public array $classes)
    {
    }

    public static function empty(): self
    {
        return new self(0, []);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->classes);
    }

    /** @return array{id: string, name: string, style: array<string,mixed>, archived: bool}|null */
    public function get(string $id): ?array
    {
        return $this->classes[$id] ?? null;
    }

    /**
     * The cascade layers for a block's `settings.classes`, in the block's order; ids the snapshot
     * does not hold are skipped.
     *
     * @param list<string> $ids
     * @return list<array{id: string, style: array<string,mixed>}>
     */
    public function refsFor(array $ids): array
    {
        $refs = [];
        foreach ($ids as $id) {
            $class = $this->classes[$id] ?? null;
            if ($class !== null) {
                $refs[] = (new StyleClassRef($class['id'], $class['style']))->toArray();
            }
        }
        return $refs;
    }
}
