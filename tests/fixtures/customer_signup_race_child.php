<?php

/**
 * Standalone subprocess for `CustomerSignupTest`'s real-PostgreSQL email-uniqueness race.
 *
 * Runs ONE real `SignupCoordinator::verify()` — OTP verification, then activation through
 * `VerifiedAccountActivator` — in a genuinely separate OS process (and therefore a genuinely
 * separate database session), so its identity INSERT contends with the parent test process's
 * concurrent activation for the SAME email on the live `app_test` database.
 *
 * The parent installs a BEFORE INSERT trigger on `users` keyed by this child's `application_name`
 * that blocks on an advisory lock the parent holds. This child therefore parks immediately before
 * its physical INSERT — AFTER `UserRepository::create()`'s repeated emailExists()/usernameExists()
 * reads — so releasing the lock makes the INSERT hit the database unique constraint deterministically.
 * The activator's outer catch then recovers the loser as `existing_account_handoff`; that recovery
 * is what this child reports.
 *
 * argv: 1=args JSON {intentUuid, otp, applicationName}
 * stdout: JSON {status, outcome, exceptionClass, message}
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Signup\SignupCoordinator;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;

// env() reads $_ENV only; mirror the process env in so DB config (DB_PGSQL_*, pooling off)
// resolves instead of silently falling back to defaults. Same preamble as the other race child.
foreach (getenv() as $key => $value) {
    $_ENV[$key] ??= $value;
}

[, $argsJson] = $argv;
/** @var array<string,mixed> $args */
$args = json_decode((string) $argsJson, true, 512, JSON_THROW_ON_ERROR);

$root = dirname(__DIR__, 2);
$app = Framework::create($root)
    ->withConfigDir($root . '/config')
    ->withEnvironment('testing')
    ->boot();
/** @var ApplicationContext $context */
$context = $app->getContext();
$container = $context->getContainer();

// Tag THIS process's connection so the parent's BEFORE INSERT trigger targets exactly this
// child's inserts. The activator runs on the same shared Connection, so the tag persists through
// runAsTenant (which switches tenant context, not the physical connection).
$applicationName = (string) $args['applicationName'];
if (!preg_match('/\A[A-Za-z0-9_]+\z/', $applicationName)) {
    throw new \InvalidArgumentException('Unexpected application_name.');
}
$container->get(Connection::class)->getPDO()->exec("SET application_name = '{$applicationName}'");

$out = [];
try {
    $result = $container->get(SignupCoordinator::class)->verify(
        (string) $args['intentUuid'],
        (string) $args['otp'],
    );
    $out = [
        'status' => $result['status'] ?? null,
        'outcome' => $result['outcome'] ?? null,
        'exceptionClass' => null,
        'message' => null,
    ];
} catch (\Throwable $e) {
    $out = [
        'status' => null,
        'outcome' => null,
        'exceptionClass' => $e::class,
        'message' => $e->getMessage(),
    ];
}

echo json_encode($out, JSON_THROW_ON_ERROR);
