<?php

declare(strict_types=1);

use Thallo\Analytics\Http\Controllers\AnalyticsController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin analytics read API. Triple-gated like collections:
 *   1. capability       — this file loads only when thallo.analytics is enabled (boot gate; else 404).
 *   2. auth             — group middleware: an authenticated session is required (401 otherwise).
 *   3. content_permission — per-route Aegis permission: analytics.read.
 *
 * `admin_tenant_binding` binds the operator's selected workspace (X-Tenant-Id) under full
 * resolution so tenant-scoped rollup reads return that workspace's data — mirroring the core admin
 * group order in routes/admin.php. It is inert outside full resolution; `tenant_bootstrap` binds
 * the single default tenant in bootstrap mode and passes through when tenancy is off.
 */
$router->group(
    [
        'prefix' => '/v1/admin',
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ],
    function (Router $router): void {
        $router->get('/analytics/series', [AnalyticsController::class, 'series'])
            ->middleware('content_permission:analytics.read');
        $router->get('/analytics/summary', [AnalyticsController::class, 'summary'])
            ->middleware('content_permission:analytics.read');
        $router->get('/analytics/breakdown', [AnalyticsController::class, 'breakdown'])
            ->middleware('content_permission:analytics.read');
    },
);
