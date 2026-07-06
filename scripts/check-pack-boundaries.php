<?php

/**
 * Fails if any first-party pack under packages/ declares a Composer dependency on
 * glueful/thallo (the engine app). Packs may depend on glueful/thallo-contracts,
 * glueful/framework, and pack-specific deps — never on the engine package.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$violations = [];
foreach (glob($root . '/packages/*/composer.json') ?: [] as $manifest) {
    $json = json_decode((string) file_get_contents($manifest), true);
    $name = $json['name'] ?? $manifest;
    if (($json['name'] ?? '') === 'glueful/thallo-contracts') {
        continue; // the contracts package itself is exempt
    }
    $deps = array_merge($json['require'] ?? [], $json['require-dev'] ?? []);
    if (array_key_exists('glueful/thallo', $deps)) {
        $violations[] = "{$name} depends on glueful/thallo (forbidden — use glueful/thallo-contracts)";
    }
}
// Source-level boundary: no first-party pack (except the contracts package) may reference App\*.
foreach (glob($root . '/packages/*', GLOB_ONLYDIR) ?: [] as $pkgDir) {
    if (basename($pkgDir) === 'thallo-contracts') {
        continue;
    }
    // Pack PHP lives under src/ (classes) and routes/ (route definition files); both must be
    // App-free. thallo-contracts is skipped above.
    foreach (['src', 'routes'] as $sub) {
        if (!is_dir($pkgDir . '/' . $sub)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pkgDir . '/' . $sub, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            // Matches `use App\...`, `\App\...`, or a bare `App\` namespace reference.
            if (preg_match('/(^|[^\\w])App\\\\/m', $src) === 1) {
                $violations[] = basename($pkgDir) . '/' . $sub . '/' . $file->getFilename()
                    . ' references App\\ (packs must use contracts, not the app)';
            }
        }
    }
}
if ($violations !== []) {
    fwrite(STDERR, "Pack boundary violations:\n - " . implode("\n - ", $violations) . "\n");
    exit(1);
}
echo "Pack boundaries OK (" . count(glob($root . '/packages/*/composer.json') ?: []) . " package(s) checked)\n";
exit(0);
