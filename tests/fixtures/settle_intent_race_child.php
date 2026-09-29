<?php

/**
 * Standalone subprocess for `RetireIntentCommandTest`'s settlement race: settles one payment intent
 * in a genuinely separate database session, holding the settled row uncommitted long enough for the
 * parent to try superseding it. Raw PDO, no application boot: the parent owns every other step.
 *
 * argv: 1=intent uuid, 2=milliseconds to hold the row before committing
 * stdout: "locked" once the settlement holds the row, then "committed"
 */

declare(strict_types=1);

$env = static fn (string $key, string $default): string => (string) (getenv($key) ?: $default);
$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $env('DB_PGSQL_HOST', '127.0.0.1'),
        $env('DB_PGSQL_PORT', '5432'),
        $env('DB_PGSQL_DATABASE', 'app_test'),
    ),
    $env('DB_PGSQL_USERNAME', 'postgres'),
    $env('DB_PGSQL_PASSWORD', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$uuid = (string) ($argv[1] ?? '');
$holdMs = (int) ($argv[2] ?? 1500);

// What a settling webhook does to an open intent (Payvia's close(): status + retired key).
$pdo->beginTransaction();
$settle = $pdo->prepare(
    "UPDATE payment_intents SET status = 'closed', idempotency_key = payable_type || ':' || payable_id || ':' "
    . "|| uuid WHERE uuid = ? AND status IN ('initializing', 'open')"
);
$settle->execute([$uuid]);
fwrite(STDOUT, "locked\n");
fflush(STDOUT);
usleep($holdMs * 1000);
$pdo->commit();
fwrite(STDOUT, "committed\n");
