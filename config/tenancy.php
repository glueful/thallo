<?php

declare(strict_types=1);

$baseDomain = env('TENANCY_BASE_DOMAIN');
$defaultHosts = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('TENANCY_DEFAULT_HOSTS', ''))
)));

return [
    'public_origin' => [
        'scheme' => env('TENANCY_PUBLIC_SCHEME', 'https'),
        'base_domain' => $baseDomain,
        'default_hosts' => $defaultHosts,
        'reserved_labels' => ['www', 'api', 'admin'],
    ],
];
