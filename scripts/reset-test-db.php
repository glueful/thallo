<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// Mirror the process env into $_ENV (the framework's env() reads $_ENV only; CI's
// variables_order / Dotenv immutable-skip can leave it empty), then force pooling off.
// Before the .env load so createImmutable keeps these values.
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

$quoteIdentifier = static function (string $identifier): string {
    return '"' . str_replace('"', '""', $identifier) . '"';
};

$appEnv = $envValue('APP_ENV', 'development');
if ($appEnv !== 'testing') {
    fwrite(STDERR, "Refusing to reset database outside APP_ENV=testing.\n");
    exit(1);
}

$driver = $envValue('DB_DRIVER', 'pgsql');
if ($driver !== 'pgsql') {
    fwrite(STDERR, "Test database reset currently supports pgsql only.\n");
    exit(1);
}

$database = $envValue('DB_PGSQL_DATABASE', '');
if ($database !== 'app_test' && !str_ends_with((string) $database, '_test')) {
    fwrite(STDERR, "Refusing to reset non-test database '{$database}'.\n");
    exit(1);
}

$schema = $envValue('DB_PGSQL_SCHEMA', 'public');
if ($schema === null || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
    fwrite(STDERR, "Invalid PostgreSQL schema name for reset.\n");
    exit(1);
}

$host = $envValue('DB_PGSQL_HOST', '127.0.0.1');
$port = $envValue('DB_PGSQL_PORT', '5432');
$username = $envValue('DB_PGSQL_USERNAME', 'postgres');
$password = $envValue('DB_PGSQL_PASSWORD', '');

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database);
$pdo = new PDO($dsn, (string) $username, (string) $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$quotedSchema = $quoteIdentifier($schema);
$pdo->exec("DROP SCHEMA IF EXISTS {$quotedSchema} CASCADE");
$pdo->exec("CREATE SCHEMA {$quotedSchema}");

if ($username !== null && $username !== '') {
    $pdo->exec("GRANT ALL ON SCHEMA {$quotedSchema} TO " . $quoteIdentifier($username));
}

fwrite(STDOUT, "Reset PostgreSQL test schema {$schema} in {$database}.\n");
