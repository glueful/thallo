<?php

// The bounded-allocation probe (block typeface plan, Task 1). Runs one decode in its own process so
// a runaway decoder can be killed, and reports its own peak memory so "allocate everything, then
// refuse" is caught.
// Usage: php -d memory_limit=256M decode-probe.php <pure|unbounded> <file> <maxOutput>
// Prints one JSON line: {"result":"REFUSED:<reason>"|"DECODED:<bytes>","baseline":<bytes>,"peak":<bytes>}

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

[, $which, $file, $max] = $argv;
$input = (string) file_get_contents($file);
$decoder = $which === 'unbounded'
    ? new \Thallo\Core\Tests\Support\Fonts\UnboundedBrotliDecoder()
    : new \Thallo\Core\Content\Fonts\Brotli\PurePhpBrotliDecoder();
gc_collect_cycles();
memory_reset_peak_usage();
$baseline = memory_get_usage(true);
try {
    $decoded = $decoder->decode($input, (int) $max);   // evaluated before anything is printed
    $result = 'DECODED:' . strlen($decoded);
} catch (\Thallo\Core\Content\Fonts\UnreadableFont $e) {
    $result = 'REFUSED:' . $e->reason;
}
echo json_encode(['result' => $result, 'baseline' => $baseline, 'peak' => memory_get_peak_usage(true)]);
