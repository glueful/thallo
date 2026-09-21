<?php

declare(strict_types=1);

namespace Thallo\Contracts\Settings;

/**
 * The admin SPA's base URL for the links that lead back into it (the preview bar's Edit/Design
 * deep links, the billing return). The setting first (Settings › General), deploy config next,
 * and failing both the site's own bundled admin — the implementation owns that chain.
 */
interface AdminUrlProvider
{
    /**
     * Absolute admin base URL, or null when there is none to link to (links hidden).
     *
     * `$origin` is the address the current request came in on (`https://example.com`), when the
     * caller has one: a site that was told nothing answers with its own admin on that address.
     */
    public function adminUrl(?string $origin = null): ?string;
}
