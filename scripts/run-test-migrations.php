<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Framework;

require dirname(__DIR__) . '/vendor/autoload.php';

// The framework's env() reads $_ENV only. CI supplies config (DB_PGSQL_*, etc.) via
// the shell/job environment, which PHP's variables_order (often no `E`) and Dotenv's
// immutable-skip can leave absent from $_ENV — so the framework silently falls back to
// config DEFAULTS (e.g. database 'glueful' instead of app_test, pooling on) even
// though getenv() has the right values. Mirror the process env into $_ENV so every
// config value resolves, then force pooling off (a sequential test run needs no pool).
// Done BEFORE the .env load so createImmutable keeps these values.
foreach (getenv() as $key => $value) {
    $_ENV[$key] ??= $value;
}
$_ENV['DB_POOLING_ENABLED'] = 'false';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$envValue = static function (string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? getenv($key);

    return $value === false || $value === null ? $default : (string) $value;
};

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if ($envValue('APP_ENV', 'development') !== 'testing') {
    $fail('Refusing to run test migrations outside APP_ENV=testing.');
}

$database = $envValue('DB_PGSQL_DATABASE', '');
if ($database !== 'app_test' && !str_ends_with((string) $database, '_test')) {
    $fail("Refusing to run test migrations against non-test database '{$database}'.");
}

$context = Framework::create($root)
    ->withConfigDir($root . '/config')
    ->withEnvironment('testing')
    ->boot()
    ->getContext();

// Diagnostic: the framework MUST resolve the same database the verification below
// checks. A mismatch here is the "applied but missing" symptom.
fwrite(STDOUT, sprintf(
    "Framework resolved: DB_PGSQL_DATABASE=%s pooling=%s (verification target=%s)\n",
    var_export(env('DB_PGSQL_DATABASE', '(config default)'), true),
    env('DB_POOLING_ENABLED', true) ? 'on' : 'off',
    var_export($database, true)
));

$manager = new MigrationManager($root . '/database/migrations', null, $context);
$frameworkMigrations = $root . '/vendor/glueful/framework/migrations';
$frameworkSources = [
    'auth' => 'glueful/framework',
    'uploads' => 'glueful/framework:uploads',
    'queue' => 'glueful/framework:queue',
    'scheduler' => 'glueful/framework:scheduler',
    'notifications' => 'glueful/framework:notifications',
    'metrics' => 'glueful/framework:metrics',
];

foreach ($frameworkSources as $dir => $source) {
    $manager->addMigrationPath($frameworkMigrations . '/' . $dir, MigrationPriority::FOUNDATION, $source);
}

$manager->addMigrationPath(
    $root . '/vendor/glueful/users/migrations',
    MigrationPriority::IDENTITY,
    'glueful/users'
);
$manager->addMigrationPath(
    $root . '/vendor/glueful/i18n/migrations',
    MigrationPriority::DEFAULT,
    'glueful/i18n'
);
$manager->addMigrationPath(
    $root . '/vendor/glueful/import-export/migrations',
    MigrationPriority::DEFAULT,
    'glueful/import-export'
);
$manager->addMigrationPath(
    $root . '/vendor/glueful/audit/migrations',
    MigrationPriority::DEFAULT,
    'glueful/audit'
);
$manager->addMigrationPath(
    $root . '/vendor/glueful/aegis/migrations',
    MigrationPriority::DEPENDENT,
    'glueful/aegis'
);
$manager->addMigrationPath(
    $root . '/vendor/glueful/email-notification/migrations',
    MigrationPriority::DEPENDENT,
    'glueful/email-notification'
);
$manager->addMigrationPath(
    $root . '/packages/thallo-analytics/migrations',
    MigrationPriority::DEPENDENT,
    'thallo-analytics'
);
$manager->addMigrationPath(
    $root . '/packages/thallo-collections/migrations',
    MigrationPriority::DEPENDENT,
    'thallo-collections'
);
$manager->addMigrationPath(
    $root . '/packages/thallo-seo/migrations',
    MigrationPriority::DEPENDENT,
    'thallo-seo'
);
$manager->addMigrationPath(
    $root . '/packages/thallo-workflow/migrations',
    MigrationPriority::DEPENDENT,
    'thallo-workflow'
);
$manager->addMigrationPath(
    $root . '/packages/thallo-navigation/migrations',
    MigrationPriority::DEPENDENT,
    'thallo-navigation'
);
$manager->addMigrationPath(
    $root . '/packages/thallo-render/migrations',
    MigrationPriority::DEPENDENT,
    'thallo-render'
);
$manager->addMigrationPath(
    $root . '/database/dependent-migrations',
    MigrationPriority::DEPENDENT,
    'app:dependent'
);

$pending = $manager->getPendingMigrations();

if ($pending === []) {
    fwrite(STDOUT, "No pending migrations found.\n");
} else {
    fwrite(STDOUT, sprintf("Found %d pending migration(s)\n", count($pending)));
    foreach ($pending as $file) {
        fwrite(STDOUT, ' - ' . basename($file) . PHP_EOL);
    }

    $result = $manager->migrate($pending);
    foreach ($result['applied'] as $file) {
        fwrite(STDOUT, 'Applied: ' . $file . PHP_EOL);
    }
    foreach ($result['failed'] as $file) {
        fwrite(STDERR, 'Failed: ' . $file . PHP_EOL);
    }

    if ($result['failed'] !== []) {
        $fail('Test migrations failed.');
    }
}

$requiredTables = [
    'users',
    'roles',
    'permissions',
    'blobs',
    'audit_logs',
    'import_export_jobs',
    'import_export_reports',
    'i18n_locales',
    'content_types',
    'entries',
    'entry_redirects',
    'entry_schedules',
];

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $envValue('DB_PGSQL_HOST', '127.0.0.1'),
    $envValue('DB_PGSQL_PORT', '5432'),
    $database
);
$pdo = new PDO($dsn, (string) $envValue('DB_PGSQL_USERNAME', 'postgres'), (string) $envValue('DB_PGSQL_PASSWORD', ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$schema = $envValue('DB_PGSQL_SCHEMA', 'public');
$placeholders = implode(', ', array_fill(0, count($requiredTables), '?'));
$statement = $pdo->prepare(
    "select table_name from information_schema.tables where table_schema = ? and table_name in ({$placeholders})"
);
$statement->execute(array_merge([(string) $schema], $requiredTables));
$existing = $statement->fetchAll(PDO::FETCH_COLUMN);
$missing = array_values(array_diff($requiredTables, array_map('strval', $existing)));

if ($missing !== []) {
    $fail('Missing required test tables after migrations: ' . implode(', ', $missing));
}

fwrite(STDOUT, "Test database schema verified.\n");
