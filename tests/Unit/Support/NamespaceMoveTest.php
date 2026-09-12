<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/** After the move nothing in the product or its tests lives in Thallo\Core\ — that namespace is the operator's. */
final class NamespaceMoveTest extends TestCase
{
    public function testNoProductCodeRemainsInTheAppNamespace(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];
        foreach (['app', 'core/src', 'routes', 'core/routes', 'config', 'database', 'core/database', 'tests'] as $dir) {
            if (!is_dir("$root/$dir")) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir"));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents((string) $file);
                // The needle is concatenated so this file does not match itself.
                if (preg_match('/(^|[^\\w])' . 'App' . '\\\\/m', $src) === 1) {
                    $offenders[] = substr((string) $file, strlen($root) + 1);
                }
            }
        }
        self::assertSame([], $offenders, 'Thallo\\Core\\ is the operator\'s namespace now');
        self::assertTrue(class_exists(\Thallo\Core\Providers\CoreServiceProvider::class));
    }
}
