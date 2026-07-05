<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Settings;

/**
 * The site favicon's asset uuid for render surfaces (site-identity spec §2:
 * the layout reads it through the sandbox `site_favicon()` function, which
 * resolves it through the public-media predicate — an unservable blob emits
 * NO link tag). One source of truth — set in Settings → General.
 */
interface SiteFaviconProvider
{
    /** Asset uuid of the configured favicon, or null when unset. */
    public function faviconUuid(): ?string;
}
