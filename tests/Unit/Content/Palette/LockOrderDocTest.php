<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content\Palette;

use PHPUnit\Framework\TestCase;

/**
 * Custom palette spec §4.6: docs/internal/palette-lock-order.md names every class that writes a
 * block-bearing table, so a new writer cannot appear without being placed in the lock order and
 * marked fenced or justified.
 */
final class LockOrderDocTest extends TestCase
{
    private const TABLES = 'entry_drafts|entry_versions|entry_publications|regions|layouts|saved_sections'
        . '|style_classes';

    public function testEveryDocumentWriterIsInTheInventory(): void
    {
        $root = dirname(__DIR__, 4);
        $doc = (string) file_get_contents($root . '/docs/internal/palette-lock-order.md');
        $missing = [];
        foreach (['core/src', 'packages'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($path, '/tests/')) {
                    continue;
                }
                $src = (string) file_get_contents($path);
                $pattern = "/(?:table\\('(?:" . self::TABLES . ")'\\)[^;]*->(?:update|insert)\\("
                    . "|(?:UPDATE|INSERT INTO)\\s+(?:" . self::TABLES . ")\\b)/s";
                if (preg_match($pattern, $src) !== 1) {
                    continue;
                }
                $class = basename($path, '.php');
                if (!str_contains($doc, $class)) {
                    $missing[] = $class;
                }
            }
        }
        sort($missing);
        self::assertSame([], $missing, 'add each writer of block-bearing tables to '
            . 'docs/internal/palette-lock-order.md');
    }
}
