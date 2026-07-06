<?php

declare(strict_types=1);

use Thallo\Analytics\Http\Controllers\AnalyticsController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin analytics read API. Triple-gated like collections:
 *   1. capability       — this file loads only when lemma.analytics is enabled (boot gate; else 404).
 *   2. auth             — group middleware: an authenticated session is required (401 otherwise).
 *   3. lemma_permission — per-route Aegis permission: analytics.read.
 */
$router->group(
    ['prefix' => '/v1/admin', 'middleware' => ['auth']],
    function (Router $router): void {
        $router->get('/analytics/series', [AnalyticsController::class, 'series'])
            ->middleware('lemma_permission:analytics.read');
        $router->get('/analytics/summary', [AnalyticsController::class, 'summary'])
            ->middleware('lemma_permission:analytics.read');
        $router->get('/analytics/breakdown', [AnalyticsController::class, 'breakdown'])
            ->middleware('lemma_permission:analytics.read');
    },
);
