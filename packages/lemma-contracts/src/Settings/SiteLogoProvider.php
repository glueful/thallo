<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Settings;

/**
 * The site logo's asset uuid for render surfaces (block-library spec §2:
 * the `logo` block reads it through the sandbox `site_logo()` function).
 * One source of truth — set in Settings → General.
 */
interface SiteLogoProvider
{
    /** Asset uuid of the configured site logo, or null when unset. */
    public function siteLogoUuid(): ?string;
}
