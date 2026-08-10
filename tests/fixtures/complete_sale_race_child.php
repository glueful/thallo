<?php

/**
 * Standalone subprocess for `CompleteSaleTest`'s real-PostgreSQL race lanes (admin-order-creation
 * cycle 2, Task 13): runs ONE real complete-sale call end to end in a genuinely separate OS
 * process — and therefore a genuinely separate database connection/session — so its
 * `pending_payment -> paid` compare-and-set really contends with the other racer's. Boots the REAL
 * Thallo application (mirrors `tests/Support/TestApplication.php`'s `Framework::create()->boot()`
 * call and `product_link_race_child.php`'s identical preamble), then resolves
 * {@see \Thallo\Commerce\Http\AdminCompleteSaleController} from the container — so the DI wiring,
 * the coordinator, and both engine services are exactly the production ones.
 *
 * Tenant resolution is left at the harness default (sentinel mode, ''), which is also what the
 * parent test seeds its order with; no `thallo_system_flags` writes happen here.
 *
 * argv: 1=order uuid
 * stdout: JSON {status:int, body:mixed}
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Commerce\Http\AdminCompleteSaleController;

// The framework's env() reads $_ENV only; mirror the process environment into it BEFORE boot so
// every DB_* value resolves (same reason as product_link_race_child.php's identical preamble —
// otherwise this child silently boots against config defaults and wedges the parent test).
foreach (getenv() as $key => $value) {
    $_ENV[$key] ??= $value;
}

[, $orderUuid] = $argv;

$root = dirname(__DIR__, 2);
$app = Framework::create($root)
    ->withConfigDir($root . '/config')
    ->withEnvironment('testing')
    ->boot();
/** @var ApplicationContext $context */
$context = $app->getContext();

try {
    $controller = $context->getContainer()->get(AdminCompleteSaleController::class);
    $response = $controller->completeSale(
        Request::create(
            '/v1/admin/commerce/orders/' . $orderUuid . '/complete-sale',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{}',
        ),
        (string) $orderUuid,
    );

    $out = [
        'status' => $response->getStatusCode(),
        'body' => json_decode((string) $response->getContent(), true),
    ];
} catch (\Throwable $e) {
    $out = ['status' => 0, 'body' => ['exceptionClass' => $e::class, 'message' => $e->getMessage()]];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
