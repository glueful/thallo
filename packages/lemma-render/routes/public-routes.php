<?php

declare(strict_types=1);

use Glueful\Lemma\Render\Http\Controllers\RenderController;
use Glueful\Lemma\Render\Http\Middleware\PreviewSessionMiddleware;
use Glueful\Lemma\Render\Http\Middleware\RenderPageCache;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * The rendered site surface (loads only when lemma.render is enabled). GET /{path} with
 * a slash-spanning constraint lives in the router's '*' bucket — tried after every
 * static route and literal-first-segment bucket, i.e. a TRUE lowest-priority catch-all
 * (V2 §2). The controller's reserved-path guard returns standard JSON 404s for /v1 etc.
 *
 * Deliberately NO rate_limit on page views: this is the whole-site surface, not an API.
 * The abuse posture is the render cache (RenderPageCache per-path 200s + the fixed
 * single-body 404/410 keys in RenderErrorCache — bogus paths can't fill the cache or
 * re-render templates).
 */
// Preview session exit (preview-sessions spec §1) — registered BEFORE the token
// route so the literal segment wins.
$router->get('/_preview/exit', [RenderController::class, 'exit']);

// Preview-through-theme (preview spec §1): the signed token IS the authorization.
// Deliberately NO RenderPageCache middleware — the cache bypass is structural; a
// preview response can never enter or read the shared page cache. The static first
// segment wins over the '*'-bucket catch-all.
$router->get('/_preview/{token}', [RenderController::class, 'preview']);

// Token-scoped preview theme assets (preview-sessions spec §5): only the token's
// SIGNED theme is served; junk tokens and theme-less tokens 404. No page cache —
// preview assets are no-store like every other preview surface.
$router->get('/_preview-assets/{token}/{path}', [RenderController::class, 'previewAsset'])
    ->where('path', '.+');

// Canvas bridge support (visual-canvas spec §3): token-free STATIC assets injected
// into preview HTML — cacheable, and OpenAPI-excluded via the Default tag like the
// other HTML-surface routes. Literal first segments win over the '*' catch-all.
$router->get('/_preview.css', [RenderController::class, 'previewCss']);
$router->get('/_preview-bridge.js', [RenderController::class, 'previewBridgeJs']);

// Session detection runs BEFORE the page cache (preview-sessions spec §4): session
// state is not cache state, and verified sessions bypass the cache wholesale.
$router->get('/', [RenderController::class, 'home'])
    ->middleware([PreviewSessionMiddleware::class, RenderPageCache::class]);
$router->get('/{path}', [RenderController::class, 'page'])
    ->where('path', '.+')
    ->middleware([PreviewSessionMiddleware::class, RenderPageCache::class]);
