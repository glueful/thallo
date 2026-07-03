<?php

declare(strict_types=1);

use Glueful\Lemma\Render\Http\Controllers\TemplatesAdminController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * DB-edited templates admin API. Triple-gated like the other packs:
 *   1. capability + kill-switch — this file loads only when lemma.render is enabled
 *      AND lemma_render.db_templates is true (else 404).
 *   2. auth — group middleware.
 *   3. lemma_permission — templates.manage on every route.
 *
 * Route grammar (spec §6): {path} spans slashes, so VERSION routes register FIRST and
 * every {path} is constrained to end in .twig — the parser stays deterministic
 * (…/entry/blog.twig/versions can never be swallowed as a generic show).
 */
$router->group(
    ['prefix' => '/v1/admin/render', 'middleware' => ['auth']],
    function (Router $router): void {
        $router->get('/templates/{path}/versions/{uuid}', [TemplatesAdminController::class, 'showVersion'])
            ->where('path', '.+\.twig')
            ->where('uuid', '[A-Za-z0-9_-]{12}')
            ->middleware('lemma_permission:templates.manage');
        $router->post('/templates/{path}/versions/{uuid}/restore', [TemplatesAdminController::class, 'restore'])
            ->where('path', '.+\.twig')
            ->where('uuid', '[A-Za-z0-9_-]{12}')
            ->middleware('lemma_permission:templates.manage');
        $router->get('/templates/{path}/versions', [TemplatesAdminController::class, 'versions'])
            ->where('path', '.+\.twig')
            ->middleware('lemma_permission:templates.manage');

        $router->get('/templates', [TemplatesAdminController::class, 'index'])
            ->middleware('lemma_permission:templates.manage');
        $router->get('/templates/{path}', [TemplatesAdminController::class, 'show'])
            ->where('path', '.+\.twig')
            ->middleware('lemma_permission:templates.manage');
        $router->put('/templates/{path}', [TemplatesAdminController::class, 'save'])
            ->where('path', '.+\.twig')
            ->middleware('lemma_permission:templates.manage');
        $router->delete('/templates/{path}', [TemplatesAdminController::class, 'delete'])
            ->where('path', '.+\.twig')
            ->middleware('lemma_permission:templates.manage');
    },
);
