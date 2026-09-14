<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

/**
 * The installed Thallo version, for templates (`site.version`) and the `thallo-version`
 * shortcode. Bound by the application; the render pack only consumes it.
 */
interface SiteVersionProvider
{
    /** The version without a leading `v` (e.g. `1.0.0-beta.26`); null for a development checkout. */
    public function installedVersion(): ?string;
}
