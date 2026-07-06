<?php

declare(strict_types=1);

namespace Thallo\Contracts\Context;

/**
 * Scoped access to the core services a pack is allowed to use — the sanctioned
 * alternative to reaching for global helpers or app internals.
 */
interface Context
{
    public function defaultLocale(): string;

    /** @return list<string> */
    public function enabledLocales(): array;

    public function setting(string $key, mixed $default = null): mixed;

    /** Public path for an entry, e.g. "/en/post/hello". */
    public function renderPath(string $contentTypeSlug, string $locale, string $slug): string;
}
