<?php

/**
 * Rehearsal assertions (visual builder plan A1.6, A4.7) against the restored fixture database:
 *   (default)      every manifest route still renders with its published title;
 *   --converted    every block-bearing document is stamped for group one and carries no
 *                  group-one legacy field; the routes still render;
 *   --consistent   stamps and trees agree everywhere (a stamped document has no legacy
 *                  field, an unstamped one is untouched) — the interrupted-run check;
 *   --touch-one    edit one document that carries an unmappable value, so a recorded
 *                  decision about it is stale.
 */

declare(strict_types=1);

use Dotenv\Dotenv;
use Glueful\Framework;
use Symfony\Component\HttpFoundation\Request;

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
$mode = $argv[1] ?? '';
$manifest = json_decode((string) file_get_contents($root . '/tests/fixtures/upgrade/beta28.manifest.json'), true);
$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    $_ENV['DB_PGSQL_HOST'] ?? '127.0.0.1',
    $_ENV['DB_PGSQL_PORT'] ?? '5432',
    getenv('DB_PGSQL_DATABASE') ?: ($_ENV['DB_PGSQL_DATABASE'] ?? 'app_test'),
);
$pdo = new PDO($dsn, $_ENV['DB_PGSQL_USERNAME'] ?? null, $_ENV['DB_PGSQL_PASSWORD'] ?? null);

/** Group one's legacy fields (the conversion table's retired fields). */
$legacy = [
    'heading' => ['align', 'color'],
    'button' => ['align', 'shape'],
    'animated_text' => [],
    'image' => ['size', 'width', 'height'],
    'carousel' => ['transition_duration'],
];
$hasLegacy = static function (array $list) use (&$hasLegacy, $legacy): bool {
    foreach ($list as $block) {
        if (!is_array($block)) {
            continue;
        }
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];
        foreach ($legacy[$block['type'] ?? ''] ?? [] as $field) {
            if (array_key_exists($field, $data)) {
                return true;
            }
        }
        if (($block['type'] ?? '') === 'animated_text') {
            foreach (['prefix_color', 'rotate_color', 'suffix_color'] as $f) {
                if (is_string($data[$f] ?? null)) {
                    return true; // a hex string: converted colours are typed tokens
                }
            }
        }
        foreach ($data as $value) {
            if (is_array($value) && array_is_list($value) && $hasLegacy($value)) {
                return true;
            }
        }
    }
    return false;
};
$documents = [];
foreach ($pdo->query('SELECT entry_uuid, locale, fields FROM entry_drafts ORDER BY entry_uuid, locale') as $r) {
    $fields = json_decode((string) $r['fields'], true) ?: [];
    $documents[] = ['draft ' . $r['entry_uuid'], $fields, $fields['_schema'] ?? null];
}
foreach ($pdo->query('SELECT uuid, fields FROM entry_versions ORDER BY uuid') as $r) {
    $fields = json_decode((string) $r['fields'], true) ?: [];
    $documents[] = ['version ' . $r['uuid'], $fields, $fields['_schema'] ?? null];
}
foreach ($pdo->query('SELECT slug, blocks, schema_stamp FROM regions ORDER BY slug') as $r) {
    $fields = ['blocks' => json_decode((string) $r['blocks'], true) ?: []];
    $documents[] = ['region ' . $r['slug'], $fields, json_decode((string) ($r['schema_stamp'] ?? 'null'), true)];
}
$blocksOf = static function (array $fields): array {
    $lists = [];
    foreach ($fields as $key => $value) {
        if ($key !== '_schema' && $key !== '_presentation' && is_array($value) && array_is_list($value)) {
            $lists[] = $value;
        }
    }
    return array_merge([], ...$lists);
};

$failures = 0;
if ($mode === '--consistent' || $mode === '--converted') {
    foreach ($documents as [$name, $fields, $stamp]) {
        $stamped = is_array($stamp) && in_array('presentation-group-1', $stamp['conversions'] ?? [], true);
        $legacyLeft = $hasLegacy($blocksOf($fields));
        if ($stamped && $legacyLeft) {
            fwrite(STDOUT, "FAIL {$name}: stamped but still carries a group-one field\n");
            $failures++;
        } elseif ($mode === '--converted' && !$stamped) {
            fwrite(STDOUT, "FAIL {$name}: not stamped after the live run\n");
            $failures++;
        } else {
            fwrite(STDOUT, sprintf("ok   %s (%s)\n", $name, $stamped ? 'converted' : 'untouched'));
        }
    }
    if ($mode === '--consistent') {
        exit($failures === 0 ? 0 : 1);
    }
}
if ($mode === '--touch-one') {
    $drafts = $pdo->query('SELECT entry_uuid, locale, fields, lock_version FROM entry_drafts ORDER BY entry_uuid');
    $hasHex = static function (mixed $node) use (&$hasHex): bool {
        if (!is_array($node)) {
            return false;
        }
        if (is_string($node['color'] ?? null) && str_starts_with($node['color'], '#')) {
            return true;
        }
        foreach ($node as $child) {
            if ($hasHex($child)) {
                return true;
            }
        }
        return false;
    };
    foreach ($drafts as $r) {
        $fields = json_decode((string) $r['fields'], true);
        if (!is_array($fields) || !$hasHex($fields)) {
            continue;
        }
        $fields['title'] = ($fields['title'] ?? '') . ' (edited after review)';
        $stmt = $pdo->prepare('UPDATE entry_drafts SET fields = :f WHERE entry_uuid = :e AND locale = :l');
        $stmt->execute(['f' => json_encode($fields), 'e' => $r['entry_uuid'], 'l' => $r['locale']]);
        fwrite(STDOUT, "touched draft {$r['entry_uuid']} ({$r['locale']})\n");
        exit(0);
    }
    fwrite(STDERR, "no draft with a hex colour to touch\n");
    exit(1);
}

$app = Framework::create($root)->withConfigDir($root . '/config')->withEnvironment('testing')->boot();
foreach ($manifest['routes'] as $slug) {
    $path = '/' . ($manifest['type'] ?? 'page') . '/' . $slug;
    $response = $app->handle(Request::create($path, 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']));
    $status = $response->getStatusCode();
    $body = (string) $response->getContent();
    $ok = $status === 200 && str_contains($body, 'Published');
    if ($ok && $mode === '--converted') {
        // Converted content renders through settings: utilities, never a legacy modifier or inline colour.
        $ok = !str_contains($body, 'thallo-block-heading--') && !str_contains($body, 'style="color:')
            && str_contains($body, 't-fg-accent');
    }
    $note = $ok ? '' : ' (unexpected body)';
    fwrite(STDOUT, sprintf("%s %s -> %d%s\n", $ok ? 'ok  ' : 'FAIL', $path, $status, $note));
    if (!$ok) {
        $failures++;
    }
}
exit($failures === 0 ? 0 : 1);
