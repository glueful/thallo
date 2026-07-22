<?php

declare(strict_types=1);

$baseDomain = env('TENANCY_BASE_DOMAIN');
$defaultHosts = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('TENANCY_DEFAULT_HOSTS', ''))
)));

return [
    'membership' => [
        'role_authority' => App\Content\Authorization\ThalloMembershipRoleAuthority::class,
    ],
    'role_matrix' => [
        'owner' => [
            'content.view', 'content.create', 'content.edit', 'content.publish',
            'content.delete', 'content.manage', 'content.routes', 'navigation.manage',
            'seo.manage', 'templates.manage', 'analytics.read', 'workflow.review',
            'tenant.members.manage', 'tenant.domains.manage', 'tenant.roles.manage',
            'collections.manage', 'collections.schema.manage', 'collections.data.manage',
            'commerce.view', 'commerce.manage',
        ],
        'admin' => [
            'content.view', 'content.create', 'content.edit', 'content.publish',
            'content.delete', 'content.manage', 'content.routes', 'navigation.manage',
            'seo.manage', 'templates.manage', 'analytics.read', 'workflow.review',
            'collections.manage', 'collections.schema.manage', 'collections.data.manage',
            'commerce.view', 'commerce.manage',
        ],
        'member' => ['content.view', 'content.create', 'content.edit'],
        'viewer' => ['content.view'],
    ],
    // Tenant selection is distinct from lifecycle/management authority.
    'bypass_permissions' => ['tenancy.access_any'],
    'public_origin' => [
        'scheme' => env('TENANCY_PUBLIC_SCHEME', 'https'),
        'base_domain' => $baseDomain,
        'default_hosts' => $defaultHosts,
        'reserved_labels' => ['www', 'api', 'admin'],
    ],
];
