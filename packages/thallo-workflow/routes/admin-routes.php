<?php

declare(strict_types=1);

use Thallo\Workflow\Http\Controllers\WorkflowController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin review-workflow API. Triple-gated like the other packs:
 *   1. capability       — this file loads only when thallo.workflow is enabled (else 404).
 *   2. auth             — group middleware.
 *   3. content_permission — per-route slug. Withdraw is gated content.view only: a reviewer
 *      may lack content.edit; the submitter-or-reviewer rule is enforced in the service (403).
 *   4. admin_tenant_binding — binds the operator's selected workspace so workflow_review_states/
 *      transitions scope to it (mirrors routes/admin.php); inert until full resolution.
 */
$router->group(
    [
        'prefix' => '/v1/admin/workflow',
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ],
    function (Router $router): void {
        $router->post('/entries/{uuid}/{locale}/submit', [WorkflowController::class, 'submit'])
            ->middleware('content_permission:content.edit');
        $router->post('/entries/{uuid}/{locale}/approve', [WorkflowController::class, 'approve'])
            ->middleware('content_permission:workflow.review');
        $router->post('/entries/{uuid}/{locale}/request-changes', [WorkflowController::class, 'requestChanges'])
            ->middleware('content_permission:workflow.review');
        $router->post('/entries/{uuid}/{locale}/withdraw', [WorkflowController::class, 'withdraw'])
            ->middleware('content_permission:content.view');
        $router->get('/entries/{uuid}/{locale}', [WorkflowController::class, 'show'])
            ->middleware('content_permission:content.view');
        $router->get('/queue', [WorkflowController::class, 'queue'])
            ->middleware('content_permission:workflow.review');
    },
);
