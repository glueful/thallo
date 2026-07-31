<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * Lists the site's published, publicly-delivered pages as convenience redirect targets (their
 * canonical paths). Pack-defines / app-provides: the host app binds an implementation over its
 * delivery layer, so a pack (e.g. thallo-account's settings surface) can offer "land on one of my
 * pages" without ever naming an app class. Never an authorization surface — the paths are already
 * public, and any chosen value is re-validated on save.
 */
interface PublishedPageDirectory
{
    /**
     * Published, publicly-delivered pages in the default locale, deduped by path and capped. Each
     * entry is `{label, path}` where `path` is a site-relative canonical path. Empty when no
     * delivery layer is available.
     *
     * @return list<array{label: string, path: string}>
     */
    public function publicPages(): array;
}
