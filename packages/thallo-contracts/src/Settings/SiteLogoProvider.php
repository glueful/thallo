<?php

declare(strict_types=1);

namespace Thallo\Contracts\Settings;

/**
 * The site logo's asset uuid for render surfaces (block-library spec §2:
 * the `logo` block reads it through the sandbox `site_logo()` function).
 * One source of truth — set in Settings → General.
 */
interface SiteLogoProvider
{
    /**
     * Asset uuid of the configured site logo, or null when unset.
     *
     * $variant (site-identity spec §2): 'light' (default) or 'dark' — the
     * dark-scheme override. Unknown variants MUST return null (defense in
     * depth under the extension's closed vocabulary); a dark request with no
     * dark upload returns null so templates fall back to the light logo.
     */
    public function siteLogoUuid(string $variant = 'light'): ?string;
}
