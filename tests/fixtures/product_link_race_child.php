<?php

/**
 * Standalone subprocess for `ProductLinkRaceTest`'s real-PostgreSQL race lanes
 * (Commerce-Slice-1 Task 8): runs ONE real {@see ProductLinkService} call end to end in a
 * genuinely separate OS process — and therefore a genuinely separate database connection/
 * session — so its advisory-lock claims really contend with the parent test process's own
 * held lock. Boots the REAL Thallo application (mirrors `tests/Support/TestApplication.php`'s
 * `Framework::create()->boot()` call) rather than a hand-built minimal container, so
 * `ProductLinkService` resolves its real dependencies (CatalogReader, EntryExistenceReader,
 * CommerceTenantResolution, EventService, …) exactly as it would in production. Schema is
 * already migrated by `composer test:migrate` before the parent test process runs; this child
 * performs no schema work of its own — it also does NOT touch `thallo_system_flags` itself
 * (mode (b) tenant resolution: schema widened + a persisted default tenant): the PARENT test
 * process writes those flags into the shared `app_test` database before launching any child, so
 * every process resolves the identical tenant from the same live row.
 *
 * argv: 1=action, 2=args JSON
 * actions: link | unlink
 * stdout: JSON {ok:bool, exceptionClass:?string, message:?string, row:?array}
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Thallo\Commerce\Links\ProductLinkService;

// The framework's env() reads $_ENV only. CI supplies config (DB_PGSQL_*, DB_POOLING_ENABLED)
// via the job environment, which PHP's variables_order (no `E` on the runners) and Dotenv's
// immutable-skip leave absent from $_ENV — this child then silently boots with config DEFAULTS
// (database "glueful", pooling ON) and wedges in the pool's acquire path instead of using the
// test database, blocking the parent test until its time limit. Mirror the process env into
// $_ENV BEFORE boot so every config value resolves — the same preamble as
// scripts/run-test-migrations.php, and for exactly the same reason.
foreach (getenv() as $key => $value) {
    $_ENV[$key] ??= $value;
}

[, $action, $argsJson] = $argv;
/** @var array<string,mixed> $args */
$args = json_decode((string) $argsJson, true, 512, JSON_THROW_ON_ERROR);

$root = dirname(__DIR__, 2);
$app = Framework::create($root)
    ->withConfigDir($root . '/config')
    ->withEnvironment('testing')
    ->boot();
/** @var ApplicationContext $context */
$context = $app->getContext();

$service = $context->getContainer()->get(ProductLinkService::class);

$out = [];
try {
    switch ($action) {
        case 'link':
            $row = $service->link(
                $context,
                (string) $args['productUuid'],
                (string) $args['entryUuid'],
                isset($args['expectedEntryUuid']) && $args['expectedEntryUuid'] !== null
                    ? (string) $args['expectedEntryUuid']
                    : null,
            );
            $out = ['ok' => true, 'exceptionClass' => null, 'message' => null, 'row' => $row];
            break;

        case 'unlink':
            $service->unlink($context, (string) $args['productUuid']);
            $out = ['ok' => true, 'exceptionClass' => null, 'message' => null, 'row' => null];
            break;

        default:
            throw new \RuntimeException("Unknown action: {$action}");
    }
} catch (\Throwable $e) {
    $out = ['ok' => false, 'exceptionClass' => $e::class, 'message' => $e->getMessage(), 'row' => null];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
