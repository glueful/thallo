<?php

declare(strict_types=1);

// Captures upgrade-adoption eligibility (CapabilityAdoption::capture) against the test database, as
// provision does before its migrations, and prints `state=<state> eligible=<json>`. With
// --pause-before-insert it prints `evaluated` after evaluating and waits for a line on stdin before
// writing its decision.
use Glueful\Installer\DatabaseConfig;
use Thallo\Core\Setup\CapabilityAdoption;

$container = require __DIR__ . '/thallo_child_boot.php';
if (in_array('--pause-before-insert', $argv, true)) {
    putenv('THALLO_TEST_PAUSE_IN_CAPTURE=before-insert');
}
$record = $container->get(CapabilityAdoption::class)->capture(new DatabaseConfig(
    'pgsql',
    (string) getenv('DB_PGSQL_HOST') ?: '127.0.0.1',
    (int) (getenv('DB_PGSQL_PORT') ?: 5432),
    (string) getenv('DB_PGSQL_DATABASE'),
    (string) getenv('DB_PGSQL_USERNAME'),
    (string) getenv('DB_PGSQL_PASSWORD'),
    (string) (getenv('DB_PGSQL_SCHEMA') ?: 'public'),
));
echo 'state=', $record['state'], ' eligible=', json_encode($record['eligible']), "\n";
