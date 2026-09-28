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

    /**
     * The rows the Layouts page lists; `link` is an admin path a disabled row points to (where the
     * setting that closes it is changed), else null.
     *
     * `enabled: false` means the target's pages are off the site — the site answers them 404 — so a
     * layout kept there can be removed from the Layouts page. A surface that closes a row whose pages
     * still render must say so with `removable: false` on the row (the Layouts page reads it as given).
     *
     * @return list<array{
     *     target: string, label: string, enabled: bool, reason: ?string, link: ?string, removable?: bool
     * }>
     */
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

    /**
     * The loops a layout of this surface holds (type layouts spec §5.4, §5.6): each loop block type,
     * the name of its blocks field that is the card repeated once per item, and the field blocks
     * that belong only inside that card. Empty for a surface without loops.
     *
     * @return list<array{type: string, card: string, items: list<string>}>
     */
    public function loops(string $target): array;

    /** @return array<string,string> field name => field type (`text:rich` for a rich text field) */
    public function bindable(string $target): array;

    public function frame(): string;

    /**
     * The rendered-page cache tags a change to this surface's layout purges; empty for a surface
     * whose pages live in a cache of their own and are purged on {@see LayoutChanged}.
     *
     * @return list<string>
     */
    public function pageTags(string $target): array;

    /** @return list<array<string,mixed>> a block tree without ids */
    public function starter(string $target): array;
}
