<?php

declare(strict_types=1);

use Thallo\Seo\Http\Controllers\AdminSeoMetaController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin SEO meta overrides. Triple-gated like analytics:
 *   1. capability       — this file loads only when thallo.seo is enabled (else 404).
 *   2. auth             — group middleware.
 *   3. content_permission — per-route seo.manage.
 *   4. admin_tenant_binding — binds the operator's selected workspace so seo_meta reads/writes scope
 *      to it (mirrors routes/admin.php); inert until full resolution, tenant_bootstrap handles bootstrap.
 */
$router->group(
    [
        'prefix' => '/v1/admin',
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ],
    function (Router $router): void {
        $router->get('/seo/meta/{entryUuid}', [AdminSeoMetaController::class, 'show'])
            ->middleware('content_permission:seo.manage');
        $router->put('/seo/meta/{entryUuid}', [AdminSeoMetaController::class, 'update'])
            ->middleware('content_permission:seo.manage');
    },
);
