<?php

/**
 * A content checksum for the upgrade rehearsal (visual builder plan A4.7): every block-bearing
 * document's fields — drafts, retained versions, regions with their schema stamp — in a stable
 * order, so two runs that should end in the same state can be compared, and a restore from
 * backup can be proven to return the pre-state. Prints the sha1 and the document count.
 */

declare(strict_types=1);

use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';
foreach (getenv() as $key => $value) {
    $_ENV[$key] ??= $value;
}
$_ENV['DB_POOLING_ENABLED'] = 'false';
$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}
if (($_ENV['APP_ENV'] ?? getenv('APP_ENV')) !== 'testing') {
    fwrite(STDERR, "Refusing to run the rehearsal outside APP_ENV=testing.\n");
    exit(1);
}
$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $_ENV['DB_PGSQL_HOST'] ?? '127.0.0.1',
    $_ENV['DB_PGSQL_PORT'] ?? '5432',
    getenv('DB_PGSQL_DATABASE') ?: ($_ENV['DB_PGSQL_DATABASE'] ?? 'app_test'),
);
$pdo = new PDO($dsn, $_ENV['DB_PGSQL_USERNAME'] ?? null, $_ENV['DB_PGSQL_PASSWORD'] ?? null);
$rows = [];
foreach ($pdo->query('SELECT entry_uuid, locale, fields FROM entry_drafts ORDER BY entry_uuid, locale') as $r) {
    $rows[] = ['draft', $r['entry_uuid'], $r['locale'], json_decode((string) $r['fields'], true)];
}
foreach ($pdo->query('SELECT uuid, fields FROM entry_versions ORDER BY uuid') as $r) {
    $rows[] = ['version', $r['uuid'], json_decode((string) $r['fields'], true)];
}
foreach ($pdo->query('SELECT slug, blocks, schema_stamp FROM regions ORDER BY slug') as $r) {
    $stamp = json_decode((string) ($r['schema_stamp'] ?? 'null'), true);
    $rows[] = ['region', $r['slug'], json_decode((string) $r['blocks'], true), $stamp];
}
$canon = static function (mixed $v) use (&$canon): mixed {
    if (!is_array($v)) {
        return $v;
    }
    if (!array_is_list($v)) {
        ksort($v);
    }
    foreach ($v as $k => $x) {
        $v[$k] = $canon($x);
    }
    return $v;
};
echo sha1((string) json_encode($canon($rows))), ' ', count($rows), "\n";
