<?php

declare(strict_types=1);

namespace Thallo\Contracts\Authoring;

/**
 * What a REPEATABLE import needs beyond {@see ContentWriter}, which only ever creates: land on
 * the entries a previous run made. Find one by its URL or by a field it carries (a source file's
 * path), read what it holds now, replace its draft, give it its URL.
 *
 * The same guarantees as the rest of the authoring surface: a draft is validated before it is
 * written ({@see ValidationFailed}), and a URL is assigned as the admin assigns one — refused when
 * another entry holds it, and leaving a redirect behind when it changes.
 */
interface ContentUpserter
{
    /** The entry served at this type's `{slug}` in the locale, or null. */
    public function findBySlug(string $contentTypeUuid, string $locale, string $slug): ?string;

    /**
     * The first live entry of the type whose draft holds exactly `$value` in the string field
     * `$field`, or null. `$field` must be a field name of the type; anything else finds nothing.
     */
    public function findByField(string $contentTypeUuid, string $locale, string $field, string $value): ?string;

    /**
     * Every live entry of the type that holds a non-empty string in `$field`, as entry uuid →
     * that value — what an import compares its folder with to say which files are gone.
     *
     * @return array<string,string>
     */
    public function fieldValues(string $contentTypeUuid, string $locale, string $field): array;

    /**
     * What the entry holds now in the locale; null for an unknown entry or locale.
     *
     * @return array{fields: array<string,mixed>, slug: ?string, published: bool}|null
     */
    public function current(string $entryUuid, string $locale): ?array;

    /**
     * Replace the entry's draft in the locale with `$fields`, validated first.
     *
     * @param array<string,mixed> $fields
     * @throws ValidationFailed
     */
    public function updateDraft(string $entryUuid, string $locale, array $fields, ?string $actor = null): void;

    /**
     * Serve the entry at `{slug}`. A changed slug leaves a redirect from the old one.
     *
     * @throws \RuntimeException when another entry, or the site's root namespace, holds the slug
     */
    public function assignSlug(string $entryUuid, string $contentTypeUuid, string $locale, string $slug): void;
}
