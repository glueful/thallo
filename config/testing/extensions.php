<?php

/**
 * Test-environment extensions baseline.
 *
 * The `enabled` list in `config/extensions.php` is mutated in place by `php glueful
 * extensions:enable|disable` (and by enabling tenancy in dev, which adds the enable-managed
 * ENFORCEMENT provider `Glueful\Extensions\Tenancy\TenancyServiceProvider`). Because the whole
 * suite shares one boot that reads that file, local dogfooding state would otherwise leak into
 * the tests — binding `CurrentTenantResolver`/`TenantTableRegistry` and breaking the clean-install
 * baseline tests (they assert enforcement is absent until tenancy is switched on).
 *
 * This override pins the testing baseline to the app config MINUS the enforcement provider, so the
 * default suite always sees the tenancy-off baseline regardless of what dev enabled. The
 * enforcement-on retrofit suite opts it back in via THALLO_TENANCY_DEV_LINK=1.
 */

$base = require __DIR__ . '/../extensions.php';

$enforcementProvider = 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider';
$keepEnforcement = in_array(getenv('THALLO_TENANCY_DEV_LINK'), ['1', 'true'], true);

$base['enabled'] = array_values(array_filter(
    (array) ($base['enabled'] ?? []),
    static fn (mixed $provider): bool => $keepEnforcement || $provider !== $enforcementProvider,
));

return $base;
