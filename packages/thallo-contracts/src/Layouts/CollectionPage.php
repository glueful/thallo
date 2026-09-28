<?php

declare(strict_types=1);

namespace Thallo\Contracts\Layouts;

/**
 * A listing or archive page's variables, as its frame and its blocks read them (type layouts plan B):
 * built once from the public route resolver's `listing`/`archive` answer, so the live page and the
 * layout stage's sample hand the same page to the same blocks. The frame reads the top-level keys
 * (`items`, `pagination`, `type`, `type_name`, `term`, `field`, `type_listing`); every block reads
 * the same values under `layout_context`, which the renderer threads to any depth.
 */
final class CollectionPage
{
    /**
     * @param array<string,mixed> $result the resolver's answer for the page (`kind` listing or archive)
     * @param string $path the page's path (a `/page/{n}` suffix included)
     * @return array<string,mixed>
     */
    public static function context(array $result, string $path): array
    {
        $listing = is_array($result['listing'] ?? null) ? $result['listing'] : [];
        $page = (int) ($listing['page'] ?? 1);
        $totalPages = max(1, (int) ($listing['total_pages'] ?? 1));
        // The base path strips a trailing /page/{n}; page 2's prev is the BARE base (canonical —
        // /page/1 301s).
        $base = $page > 1 ? (string) preg_replace('#/page/\d+$#', '', $path) : $path;
        $archive = ($result['kind'] ?? null) === 'archive';
        return self::page(
            (string) ($result['type'] ?? ''),
            (string) ($listing['type_name'] ?? $result['type'] ?? ''),
            is_array($listing['items'] ?? null) ? array_values($listing['items']) : [],
            [
                'page' => $page,
                'per_page' => (int) ($listing['per_page'] ?? 0),
                'total' => (int) ($listing['total'] ?? 0),
                'total_pages' => $totalPages,
                'prev_path' => $page <= 1 ? null : ($page === 2 ? $base : $base . '/page/' . ($page - 1)),
                'next_path' => $page < $totalPages ? $base . '/page/' . ($page + 1) : null,
            ],
            $archive && is_array($result['term'] ?? null) ? $result['term'] : null,
            $archive ? (string) ($result['field'] ?? '') : null,
            is_array($result['type_listing'] ?? null) ? $result['type_listing'] : null,
            $archive && is_string($result['term_description_format'] ?? null)
                ? $result['term_description_format']
                : null,
        );
    }

    /**
     * An empty page for the layout stage when there is nothing to sample: no items, one page, and
     * the one sample item the stage's placeholder card shows.
     *
     * @param array<string,mixed> $placeholderItem
     * @param array<string,mixed>|null $term
     * @param array<string,mixed>|null $typeListing
     * @return array<string,mixed>
     */
    public static function placeholder(
        string $type,
        string $typeName,
        array $placeholderItem,
        ?array $term = null,
        ?string $field = null,
        ?array $typeListing = null,
        ?string $termDescriptionFormat = null,
    ): array {
        $vars = self::page(
            $type,
            $typeName,
            [],
            ['page' => 1, 'per_page' => 0, 'total' => 0, 'total_pages' => 1, 'prev_path' => null, 'next_path' => null],
            $term,
            $field,
            $typeListing,
            $termDescriptionFormat,
        );
        $vars['layout_context']['placeholder_item'] = $placeholderItem;
        return $vars;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $pagination
     * @param array<string,mixed>|null $term
     * @param array<string,mixed>|null $typeListing
     * @return array<string,mixed>
     */
    private static function page(
        string $type,
        string $typeName,
        array $items,
        array $pagination,
        ?array $term,
        ?string $field,
        ?array $typeListing,
        ?string $termDescriptionFormat,
    ): array {
        $page = [
            'items' => $items,
            'pagination' => $pagination,
            'type' => $type,
            'type_name' => $typeName,
            'term' => $term,
            'field' => $field,
            'term_description_format' => $termDescriptionFormat,
        ];
        return $page + ['type_listing' => $typeListing, 'layout_context' => $page];
    }
}
