<?php

declare(strict_types=1);

// The production extensions controller (only its executor swapped for a spy), with
// THALLO_TEST_PAUSE_BEFORE_EXECUTOR set: it must not pause, since the pause is a test seam of
// TestableExtensionAdminController only. Prints `http=<status>`.
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Http\Controllers\ExtensionAdminController;
use Thallo\Core\Tests\Support\SpySchemaExecutor;

$container = require __DIR__ . '/thallo_child_boot.php';
$context = $container->get(Glueful\Bootstrap\ApplicationContext::class);
$controller = new class ($context) extends ExtensionAdminController {
    protected function schemaExecutor(): ExtensionSchemaExecutor
    {
        return new SpySchemaExecutor();
    }

    protected function hostToggleRefusal(): ?array
    {
        return null;
    }
};
$request = Request::create(
    '/v1/admin/extensions/enable',
    'POST',
    [],
    [],
    [],
    ['CONTENT_TYPE' => 'application/json'],
    (string) json_encode(['name' => 'glueful/media']),
);
echo 'http=' . $controller->enable($request)->getStatusCode() . "\n";
