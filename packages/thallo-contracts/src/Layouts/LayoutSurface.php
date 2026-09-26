<?php

declare(strict_types=1);

namespace Thallo\Contracts\Layouts;

/**
 * A kind of page a layout can describe (type layouts spec §3): the rows the Layouts page lists, the
 * samples its stage previews, the field blocks and required blocks of its layouts, its frame and
 * the starter a new layout opens on.
 */
interface LayoutSurface
{
    public function key(): string;

    /** The page kind, named for people ("Posts — single post"). */
    public function label(string $target): string;

    /** What a Save changes, beside the button ("Applies to every post"). */
    public function reach(string $target): string;

    /** @return list<array{target: string, label: string, enabled: bool, reason: ?string}> */
    public function targets(): array;

    /** @return list<array{id: string, label: string}> published items, up to 50 */
    public function samples(string $target, ?string $query): array;

    public function defaultSample(string $target): ?string;

    /** @return array<string,mixed> an in-memory sample context; nothing is written */
    public function placeholder(string $target): array;

    /** @return list<string> the field block slugs this surface adds to the general blocks */
    public function palette(): array;

    /** @return list<array{type: string, field?: string}> blocks a layout must hold exactly once */
    public function required(string $target): array;

    /** @return array<string,string> field name => field type (`text:rich` for a rich text field) */
    public function bindable(string $target): array;

    public function frame(): string;

    /** @return list<array<string,mixed>> a block tree without ids */
    public function starter(string $target): array;
}
