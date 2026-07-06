<?php

declare(strict_types=1);

namespace Thallo\Contracts\Authoring;

/**
 * High-level content authoring for packs (e.g. importers). Packs ask core to create
 * drafts and publish; they never touch content repositories or tables directly.
 */
interface ContentWriter
{
    /**
     * Create a new entry with an initial draft for $locale.
     *
     * @param array<string,mixed> $fields
     * @return string The new entry uuid.
     */
    public function createDraft(string $contentTypeUuid, string $locale, array $fields, ?string $actor = null): string;

    /**
     * Validate (and clean) a content payload against the content type's schema WITHOUT
     * persisting — for dry-run / preview flows. Returns the cleaned payload; throws a
     * {@see ValidationFailed} on invalid input.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     * @throws ValidationFailed
     */
    public function validate(string $contentTypeUuid, string $locale, array $fields): array;

    /**
     * Publish the current draft for $entryUuid/$locale.
     *
     * @return string The publication (version) uuid.
     */
    public function publish(string $entryUuid, string $locale, ?string $actor = null): string;
}
