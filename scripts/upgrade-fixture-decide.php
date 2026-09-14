<?php

/**
 * Decisions for the upgrade rehearsal (visual builder spec §7.3, plan A4.7): read a dry-run
 * report and record one decision per unmappable diagnostic by a stated policy — a raw hex
 * colour becomes the accent token, a pixel width the container width token, a pixel height is
 * discarded — keyed by the diagnostic identity and pinned to its document hash and converter
 * version. Usage: php scripts/upgrade-fixture-decide.php <report.jsonl> <decisions.json>
 */

declare(strict_types=1);

use Thallo\Core\Content\Style\Conversion\DecisionsFile;

require dirname(__DIR__) . '/vendor/autoload.php';

[$reportPath, $decisionsPath] = [$argv[1] ?? null, $argv[2] ?? null];
if ($reportPath === null || $decisionsPath === null || !is_file($reportPath)) {
    fwrite(STDERR, "usage: upgrade-fixture-decide.php <report.jsonl> <decisions.json>\n");
    exit(1);
}
$decisions = DecisionsFile::load(is_file($decisionsPath) ? $decisionsPath : null);
$recorded = 0;
foreach (array_filter(explode("\n", (string) file_get_contents($reportPath))) as $line) {
    $d = json_decode($line, true);
    if (!is_array($d) || ($d['status'] ?? null) !== 'unmappable') {
        continue;
    }
    $field = (string) $d['field'];
    $decision = match (true) {
        str_ends_with($field, 'color') => ['action' => 'token', 'value' => 'color.accent'],
        $field === 'width' => ['action' => 'token', 'value' => 'width.container'],
        $field === 'height' => ['action' => 'discard'],
        default => null,
    };
    if ($decision === null) {
        fwrite(STDERR, "no policy for {$d['source_type']}:{$d['block_id']}.{$field}\n");
        exit(1);
    }
    $decisions->record(
        DecisionsFile::key($d['source_type'], $d['source_id'], $d['source_revision'], $d['block_id'], $field),
        $decision + ['document_hash' => $d['document_hash'], 'converter_version' => (int) $d['converter_version']],
    );
    $recorded++;
}
$decisions->write($decisionsPath);
echo "decisions: {$recorded} recorded -> {$decisionsPath}\n";
