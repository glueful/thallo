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

/** The shipped groups' legacy fields (the conversion tables' retired fields). */
$legacy = [
    'heading' => ['align', 'color'],
    'button' => ['align', 'shape'],
    'animated_text' => [],
    'image' => ['size', 'width', 'height'],
    'carousel' => ['transition_duration'],
    'container' => [
        'background_color', 'bg_repeat', 'overlay_color', 'max_width', 'min_height_px', 'padding_preset',
        'padding', 'margin', 'radius', 'border_style', 'border_width', 'border_color', 'shadow',
    ],
    'style' => ['padding', 'margin', 'shadow', 'shadow_color', 'shadow_opacity', 'class_hook'],
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
        $stamped = is_array($stamp) && in_array('presentation-group-2', $stamp['conversions'] ?? [], true);
        $legacyLeft = $hasLegacy($blocksOf($fields));
        if ($stamped && $legacyLeft) {
            fwrite(STDOUT, "FAIL {$name}: stamped but still carries a retired field\n");
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
if ($mode === '--legacy-edit') {
    // The sequential scenario (plan A5.4): after group one shipped, an editor adds a draft with a
    // legacy container and edits an existing entry's container. A new draft carries no stamp; the
    // edited draft keeps its group-one stamp with a group-two field changed underneath it.
    $id = static fn (): string => substr(bin2hex(random_bytes(9)), 0, 12);
    $legacyContainer = ['id' => $id(), 'type' => 'container', 'data' => [
        'padding_preset' => 'medium',
        'padding' => ['top' => 9, 'right' => 9, 'bottom' => 9, 'left' => 9],
        'margin' => ['top' => 4, 'bottom' => 4],
        'radius' => ['top' => 6, 'right' => 6, 'bottom' => 6, 'left' => 6],
        'border_style' => 'dotted', 'border_width' => 1, 'border_color' => '#654321',
        'shadow' => 'sm', 'background_color' => '#fafafa',
        'overlay_color' => '#ffffff', 'overlay_opacity' => 70,
        'max_width' => 640, 'min_height' => 'auto', 'min_height_px' => 120, 'gap' => 8,
        'content' => [
            ['id' => $id(), 'type' => 'style', 'data' => [
                'shadow' => 'xs', 'shadow_color' => '#654321', 'shadow_opacity' => 80,
                'padding' => 'large', 'margin' => 'none', 'class_hook' => 'later',
                'content' => [
                    ['id' => $id(), 'type' => 'heading', 'data' => ['text' => 'Sequential', 'level' => 'h3']],
                ],
            ]],
        ],
    ]];
    $first = $pdo->query('SELECT d.entry_uuid, d.locale, d.fields, d.schema_version, d.updated_by, e.content_type_uuid,'
        . ' e.created_by FROM entry_drafts d JOIN entries e ON e.uuid = d.entry_uuid ORDER BY d.entry_uuid LIMIT 1')
        ->fetch(PDO::FETCH_ASSOC);
    if (!is_array($first)) {
        fwrite(STDERR, "no draft to build on\n");
        exit(1);
    }
    $now = gmdate('Y-m-d H:i:s');
    $entryUuid = $id();
    $pdo->prepare('INSERT INTO entries (uuid, content_type_uuid, status, created_by, created_at, updated_at)'
        . ' VALUES (:u, :c, :s, :b, :t, :t)')
        ->execute(['u' => $entryUuid, 'c' => $first['content_type_uuid'], 's' => 'active',
            'b' => $first['created_by'], 't' => $now]);
    $pdo->prepare('INSERT INTO entry_drafts (entry_uuid, locale, fields, schema_version, lock_version, updated_by,'
        . ' updated_at) VALUES (:e, :l, :f, :v, 0, :b, :t)')
        ->execute(['e' => $entryUuid, 'l' => $first['locale'],
            'f' => json_encode(['title' => 'Sequential draft', 'body' => [$legacyContainer]]),
            'v' => $first['schema_version'], 'b' => $first['updated_by'], 't' => $now]);
    fwrite(STDOUT, "added draft {$entryUuid} ({$first['locale']}) with a legacy container\n");

    // The existing entry's container: its overlay opacity changes after its group-one conversion.
    $fields = json_decode((string) $first['fields'], true);
    $edited = false;
    foreach ($fields['body'] ?? [] as $i => $block) {
        if (($block['type'] ?? '') === 'container' && isset($block['data']['overlay_opacity'])) {
            $fields['body'][$i]['data']['overlay_opacity'] = 60;
            $edited = true;
            break;
        }
    }
    if (!$edited) {
        fwrite(STDERR, "the first draft carries no container with a legacy overlay\n");
        exit(1);
    }
    $pdo->prepare('UPDATE entry_drafts SET fields = :f, lock_version = lock_version + 1'
        . ' WHERE entry_uuid = :e AND locale = :l')
        ->execute(['f' => json_encode($fields), 'e' => $first['entry_uuid'], 'l' => $first['locale']]);
    fwrite(STDOUT, "edited draft {$first['entry_uuid']} ({$first['locale']}): container overlay_opacity -> 60\n");
    exit(0);
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
        // Converted content renders through settings: utilities, never a legacy modifier or an
        // inline style (the container's padding preset and the style block's hook included).
        $ok = !str_contains($body, 'thallo-block-heading--') && !str_contains($body, ' style="')
            && str_contains($body, 't-fg-accent') && str_contains($body, 't-pt-3xl')
            && str_contains($body, 't-pt-lg') && str_contains($body, ' promo');
    }
    $note = $ok ? '' : ' (unexpected body)';
    fwrite(STDOUT, sprintf("%s %s -> %d%s\n", $ok ? 'ok  ' : 'FAIL', $path, $status, $note));
    if (!$ok) {
        $failures++;
    }
}
exit($failures === 0 ? 0 : 1);
