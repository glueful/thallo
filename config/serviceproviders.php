<?php

/**
 * Application Service Providers
 *
 * The application's own service providers, loaded in declared order. These are
 * app-local classes (not composer-discovered extensions) and are always loaded.
 * Use string FQCNs (no ::class) so tooling can edit the list safely.
 */

return [
    'enabled' => [
        'Glueful\\Extensions\\Tenancy\\TenancyControlPlaneProvider',
        'App\\Providers\\ThalloServiceProvider',
        // 'App\\Providers\\EventServiceProvider',
    ],
];
