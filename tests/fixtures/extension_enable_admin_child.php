<?php

declare(strict_types=1);

// Enables <package> through the admin API's controller (POST /v1/admin/extensions/enable), with
// THALLO_TEST_PAUSE_BEFORE_EXECUTOR set: it prints `before-executor` and waits for a line on stdin
// right before the executor call. Prints the response status.
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Tests\Support\TestableExtensionAdminController;

$container = require __DIR__ . '/thallo_child_boot.php';
$context = $container->get(Glueful\Bootstrap\ApplicationContext::class);
$request = Request::create(
    '/v1/admin/extensions/enable',
    'POST',
    [],
    [],
    [],
    ['CONTENT_TYPE' => 'application/json'],
    (string) json_encode(['name' => $argv[1]]),
);
$response = (new TestableExtensionAdminController($context))->enable($request);
echo 'http=' . $response->getStatusCode() . "\n";
echo (string) $response->getContent() . "\n";
