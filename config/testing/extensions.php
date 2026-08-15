<?php

/**
 * Test-environment extensions baseline.
 *
 * TWO jobs, one mechanism (base ∪ dogfood, minus enforcement):
 *
 * 1. THE DOGFOOD-SUITE GUARANTEE (distribution posture split, 2026-08-15): the committed
 *    `config/extensions.php` is the DISTRIBUTION default — tier 1 plus the bundled
 *    Subscriptions engine only (see docs/internal/DISTRIBUTION.md §2). The suite, however,
 *    must keep exercising the everything-on dogfood posture (payments/commerce suites must
 *    never go silently unexercised — charter CI item). This override UNIONS the base list
 *    with the dogfood tier-2 set, so trimming base never trims the suite. The
 *    distribution-defaults smoke lane (`composer test:distribution`) sets this file aside to
 *    test the fresh-install posture as users receive it.
 *
 * 2. THE TENANCY-OFF TEST SHIELD (original job): `config/extensions.php` is mutated in place
 *    by `php glueful extensions:enable|disable` (and by enabling tenancy in dev, which adds
 *    the enable-managed ENFORCEMENT provider). Deriving from base would leak that dogfooding
 *    state into the tests — binding `CurrentTenantResolver`/`TenantTableRegistry` and breaking
 *    the clean-install baseline tests. This override therefore strips the enforcement provider
 *    unless the enforcement-on retrofit suite opts back in via THALLO_TENANCY_DEV_LINK=1.
 *
 * The union (rather than a pinned static list) keeps a base edit visible to tests when it is
 * NOT part of the trim — e.g. a newly shipped tier-1 extension appears here automatically.
 */

$base = require __DIR__ . '/../extensions.php';

$dogfoodTierTwo = [
    'Glueful\\Extensions\\Meilisearch\\MeilisearchProvider',
    'Glueful\\Extensions\\Commerce\\CommerceServiceProvider',
    'Glueful\\Extensions\\Payvia\\PayviaServiceProvider',
];

$enforcementProvider = 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider';
$keepEnforcement = in_array(getenv('THALLO_TENANCY_DEV_LINK'), ['1', 'true'], true);

$enabled = (array) ($base['enabled'] ?? []);
foreach ($dogfoodTierTwo as $provider) {
    if (!in_array($provider, $enabled, true)) {
        $enabled[] = $provider;
    }
}

$base['enabled'] = array_values(array_filter(
    $enabled,
    static fn (mixed $provider): bool => $keepEnforcement || $provider !== $enforcementProvider,
));

return $base;
