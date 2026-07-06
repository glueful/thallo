<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use PHPUnit\Framework\TestCase;

/**
 * Proves that the thallo-collections pack source never references the App\ namespace.
 *
 * Packs must depend only on glueful/framework + glueful/thallo-contracts (and any
 * pack-specific deps). Any reference to App\ would couple the pack to the host
 * application and break the composer-install / removability guarantee.
 *
 * The regex `/(^|[^\w])App\\/m` is identical to scripts/check-pack-boundaries.php
 * so that this test and the CI guard agree on what constitutes a violation.
 */
final class NoAppReferencesTest extends TestCase
{
    /**
     * Every .php file under packages/thallo-collections/src must be free of App\ references.
     * On failure, the assertion message lists every offending file:line pair.
     */
    public function testNoAppReferencesInPackSource(): void
    {
        $packRoot = dirname(__DIR__, 3) . '/packages/thallo-collections';

        self::assertDirectoryExists($packRoot . '/src', 'thallo-collections src directory must exist');

        $violations = [];

        // Pack PHP lives under src/ (classes) and routes/ (route files); both must be App-free.
        foreach (['src', 'routes'] as $sub) {
            $dir = $packRoot . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = (string) file_get_contents($file->getPathname());

                // Quick whole-file check with the same regex as the boundary guard script.
                // The /m flag makes ^ anchor to the start of each line.
                if (preg_match('/(^|[^\\w])App\\\\/m', $content) !== 1) {
                    continue;
                }

                // Slow path: find the exact line numbers for the failure message.
                $relativePath = ltrim(str_replace($packRoot, '', $file->getPathname()), '/\\');
                foreach (explode("\n", $content) as $lineIndex => $line) {
                    if (preg_match('/(^|[^\\w])App\\\\/', $line) === 1) {
                        $violations[] = $relativePath . ':' . ($lineIndex + 1) . ' — ' . trim($line);
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'thallo-collections src/ and routes/ must not reference App\\ namespace (pack boundary violation):'
                . "\n  " . implode("\n  ", $violations),
        );
    }
}
