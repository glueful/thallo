<?php

declare(strict_types=1);

use Thallo\Seo\Http\Controllers\RobotsController;
use Thallo\Seo\Http\Controllers\SeoMetaController;
use Thallo\Seo\Http\Controllers\SitemapController;
use Glueful\Routing\Router;

/** @var Router $router */

// Public SEO meta for the frontend <head>. No auth — published content only. Rate-limited
// like every other anonymous Thallo surface (per-IP): the meta lookup is uncached DB work.
$router->get('/v1/seo/meta/{type}/{slug}', [SeoMetaController::class, 'show'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap'])
    ->middleware('rate_limit');

// Sitemaps. Public, raw XML. Adaptive root + numbered page files.
$router->get('/sitemap.xml', [SitemapController::class, 'index'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap'])
    ->middleware('rate_limit');
$router->get('/sitemap/{n}.xml', [SitemapController::class, 'page'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap'])
    ->where('n', '\d+')
    ->middleware('rate_limit');

// robots.txt. Public, plain text.
$router->get('/robots.txt', [RobotsController::class, 'show'])
    ->middleware(['tenant_profile:public', 'tenant_bootstrap'])
    ->middleware('rate_limit');
