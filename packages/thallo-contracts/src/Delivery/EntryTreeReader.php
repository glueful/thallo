<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Every published entry of a type as a navigation tree, for templates (a docs sidebar, previous
 * and next). Where {@see EntryListReader} gives a block its newest few, this gives a section of
 * the site its whole table of contents: grouped by an enum field in the ENUM'S own order, sorted
 * inside a group by a number field and then by title, capped at {@see self::MAX}.
 *
 * Items carry what navigation needs and nothing else — never the entries' bodies. Like the list
 * reader, the result carries its own cache tags (the broad `thallo:type:{slug}`: any page
 * published, moved or retitled changes every sidebar), and a gate failure — an unknown type, or
 * one the site does not deliver publicly — is an empty tree, never a throw.
 */
interface EntryTreeReader
{
    public const MAX = 500;

    /**
     * @return array{
     *     groups: list<array{key:string,label:string,items:list<array<string,mixed>>}>,
     *     items: list<array{uuid:string,slug:string,href:string,title:string,summary:?string,group:string}>,
     *     cache_tags: list<string>
     * }
     */
    public function tree(string $type, string $locale, string $groupField, string $orderField): array;
}
