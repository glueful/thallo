<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;

/**
 * Every code path that writes the `regions` table goes through RegionWriteLock (regions-stage
 * spec §4.5, the writer inventory). A new direct writer fails here with its file, so it cannot
 * slip past the serialization a region save relies on.
 */
final class RegionWriterInventoryTest extends TestCase
{
    private const ALLOWED = [
        'core/src/Content/Regions/RegionRepository.php',
        'core/src/Content/Blocks/Sources/RegionsSource.php',
        'core/src/Content/Starter/Kinds/RegionKind.php',
    ];

    public function testEveryRegionsWriteGoesThroughTheLock(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        foreach (array_merge(glob($root . '/core/src') ?: [], glob($root . '/packages/*/src') ?: []) as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $writers = [];
        foreach ($files as $path) {
            $code = (string) file_get_contents($path);
            $writes = "~->table\\('regions'\\)[^;]*->(update|insert|delete|forceDelete|upsert)\\(~s";
            $direct = preg_match($writes, $code) === 1;
            $raw = preg_match('~\b(UPDATE\s+regions|INSERT\s+INTO\s+regions|DELETE\s+FROM\s+regions)\b~i', $code) === 1;
            if ($direct || $raw) {
                $writers[] = substr($path, strlen($root) + 1);
            }
        }

        sort($writers);
        foreach ($writers as $writer) {
            self::assertContains(
                $writer,
                self::ALLOWED,
                "{$writer} writes the regions table directly; route it through RegionWriteLock",
            );
            $code = (string) file_get_contents($root . '/' . $writer);
            self::assertMatchesRegularExpression(
                '~->within\(|RegionWriteLock~',
                $code,
                "{$writer} writes regions without the lock",
            );
        }
        self::assertNotSame([], $writers, 'the scan found no region writers at all — the pattern is broken');
    }
}
