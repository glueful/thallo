<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Settings;

/**
 * The admin SPA's base URL for render surfaces (admin-bar feature: the
 * preview bar's Edit/Design deep links). DB setting first (Settings →
 * General, auto-populated at web setup), deploy config as fallback —
 * the implementation owns that chain.
 */
interface AdminUrlProvider
{
    /** Absolute admin base URL, or null when unconfigured (links hidden). */
    public function adminUrl(): ?string;
}
