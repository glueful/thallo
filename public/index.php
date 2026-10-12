<?php

declare(strict_types=1);

// The update guard (docs/upgrading.md, Updating from the admin): closes the site while an update
// runs. Plain PHP, loaded before vendor/; absent until provision installs it.
if (is_file(__DIR__ . '/../bootstrap/update-guard.php')) {
    require __DIR__ . '/../bootstrap/update-guard.php';
}

use Symfony\Component\HttpFoundation\Request;

// Bootstrap the framework application
$app = require_once __DIR__ . '/../bootstrap/app.php';

// Create request from globals
$request = Request::createFromGlobals();

// Handle request through Application
$response = $app->handle($request);

// Send the response
$response->send();

// Terminate lifecycle
$app->terminate($request, $response);
