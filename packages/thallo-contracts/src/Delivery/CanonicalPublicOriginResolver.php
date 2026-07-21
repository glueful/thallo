<?php

declare(strict_types=1);

namespace Thallo\Contracts\Delivery;

use Glueful\Bootstrap\ApplicationContext;

/**
 * The ONE trusted-origin algorithm packs compose public URLs against — the shared seam
 * {@see \App\Content\Media\TenantBlobPublicUrlProvider} delegates its host-selection precedence
 * to (task 6). Origins are derived only from configured/verified tenant records or the app's own
 * configured base URL — never from the incoming request's `Host` header — so a hostile `Host`
 * can never spoof a canonical origin.
 */
interface CanonicalPublicOriginResolver
{
    /** Return normalized scheme://host[:port] for the current request-bound tenant. */
    public function currentOrigin(ApplicationContext $c): string;

    /** Return normalized scheme://host[:port] for an explicitly owned tenant resource. */
    public function originForTenant(ApplicationContext $c, string $tenantUuid): string;
}
