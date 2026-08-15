<?php

/**
 * Development-environment extensions overlay — THE DOGFOOD POSTURE.
 *
 * Repo-only (export-ignored; a distributed install never receives this file). The committed
 * `config/extensions.php` is the DISTRIBUTION default — tier 1 plus the bundled Subscriptions
 * engine (docs/internal/DISTRIBUTION.md §2). Development of Thallo itself runs everything, so
 * this overlay UNIONS the base list with the dogfood tier-2 set.
 *
 * Why union rather than a pinned list: `php glueful extensions:enable|disable` and the tenancy
 * enablement flow write to BASE. A static overlay would mask those writes in dev (enable would
 * appear to do nothing); the union lets every base edit — including the enforcement provider
 * line the workspaces flow manages — flow through, while guaranteeing tier 2 is always on for
 * dogfooding regardless of the trimmed base.
 */

$base = require __DIR__ . '/../extensions.php';

$dogfoodTierTwo = [
    'Glueful\\Extensions\\Meilisearch\\MeilisearchProvider',
    'Glueful\\Extensions\\Commerce\\CommerceServiceProvider',
    'Glueful\\Extensions\\Payvia\\PayviaServiceProvider',
];

$enabled = (array) ($base['enabled'] ?? []);
foreach ($dogfoodTierTwo as $provider) {
    if (!in_array($provider, $enabled, true)) {
        $enabled[] = $provider;
    }
}

$base['enabled'] = array_values($enabled);

return $base;
