<?php

declare(strict_types=1);

// Helpers for the update proof's artifact builder (build-artifacts.php): copy, zip, edit and
// mutate a tree. Declares functions only.

/** Copy a tree (no VCS, no node_modules). */
function copyTree(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($it as $item) {
        $rel = substr($item->getPathname(), strlen($from) + 1);
        if (preg_match('#(^|/)(\.git|node_modules)(/|$)#', $rel)) {
            continue;
        }
        $dest = $to . '/' . $rel;
        $item->isDir() ? @mkdir($dest, 0777, true) : copy($item->getPathname(), $dest);
    }
}

function zipTree(string $dir, string $zipPath): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("cannot write {$zipPath}");
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($dir) + 1));
        }
    }
    $zip->close();
}

/** @param callable(array): array $edit */
function editJson(string $file, callable $edit): void
{
    $json = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($file, json_encode($edit($json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function mutate(string $file, string $anchor, string $replacement, string $name): void
{
    $src = (string) file_get_contents($file);
    if (!str_contains($src, $anchor)) {
        fwrite(STDERR, "update-proof: anchor '{$name}' is gone from {$file}; update build-artifacts.php\n");
        exit(1);
    }
    file_put_contents($file, str_replace($anchor, $replacement, $src));
}

function versioned(string $src, string $version, string $tmp): string
{
    $dir = $tmp . '/' . basename($src) . '-' . $version . '-' . bin2hex(random_bytes(3));
    copyTree($src, $dir);
    editJson($dir . '/composer.json', static fn (array $j): array => ['version' => $version] + $j);
    return $dir;
}
