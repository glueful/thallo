<?php

declare(strict_types=1);

use Thallo\Workflow\Http\Controllers\WorkflowController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin review-workflow API. Triple-gated like the other packs:
 *   1. capability       — this file loads only when lemma.workflow is enabled (else 404).
 *   2. auth             — group middleware.
 *   3. lemma_permission — per-route slug. Withdraw is gated content.view only: a reviewer
 *      may lack content.edit; the submitter-or-reviewer rule is enforced in the service (403).
 */
$router->group(
    ['prefix' => '/v1/admin/workflow', 'middleware' => ['auth']],
    function (Router $router): void {
        $router->post('/entries/{uuid}/{locale}/submit', [WorkflowController::class, 'submit'])
            ->middleware('lemma_permission:content.edit');
        $router->post('/entries/{uuid}/{locale}/approve', [WorkflowController::class, 'approve'])
            ->middleware('lemma_permission:workflow.review');
        $router->post('/entries/{uuid}/{locale}/request-changes', [WorkflowController::class, 'requestChanges'])
            ->middleware('lemma_permission:workflow.review');
        $router->post('/entries/{uuid}/{locale}/withdraw', [WorkflowController::class, 'withdraw'])
            ->middleware('lemma_permission:content.view');
        $router->get('/entries/{uuid}/{locale}', [WorkflowController::class, 'show'])
            ->middleware('lemma_permission:content.view');
        $router->get('/queue', [WorkflowController::class, 'queue'])
            ->middleware('lemma_permission:workflow.review');
    },
);
