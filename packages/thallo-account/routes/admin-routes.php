<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Thallo\Account\Http\AccountSettingsController;

/** @var Router $router */

/*
 * Admin API for the storefront account surface (public-account-surface plan Task 4). Loaded ONLY
 * inside the `thallo.accounts` capability gate (AccountServiceProvider::boot()), so the route is
 * ABSENT (404) when the capability is off — never an always-present app route. Gated like the other
 * packs' admin routes:
 *   1. capability          — this file loads only while thallo.accounts is enabled.
 *   2. auth                — group middleware.
 *   3. tenant profile/bootstrap/binding — the operator's selected workspace is bound, so the
 *      tenant-owned redirect settings scope to it (mirrors routes/admin.php and the commerce pack).
 *   4. content_permission  — `content.manage` on every method.
 *
 * Routes carry an explicit `thallo.account.admin.*` name + a `Thallo Settings` OpenAPI tag so the
 * operation id is globally unique and escapes the reserved "Admin" tag (the same technique every
 * pack admin controller uses).
 */
$router->group(
    [
        'prefix' => '/v1/admin/settings',
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ],
    function (Router $router): void {
        $router->get('/accounts', [AccountSettingsController::class, 'show'])
            ->middleware('content_permission:content.manage')
            ->name('thallo.account.admin.settings.show');
        $router->put('/accounts', [AccountSettingsController::class, 'update'])
            ->middleware('content_permission:content.manage')
            ->name('thallo.account.admin.settings.update');
    },
);
