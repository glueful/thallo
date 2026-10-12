<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;

/** The shipped single files are current, lint clean, and use nothing outside PHP. */
final class BuildUpdaterTest extends TestCase
{
    public function testTheShippedFilesAreCurrent(): void
    {
        $root = dirname(__DIR__, 3);
        exec('php ' . escapeshellarg($root . '/scripts/build-updater') . ' --check 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }

    public function testTheUpdaterClassesImportNothing(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/core/src/Updater/*.php') as $file) {
            $src = (string) file_get_contents($file);
            $name = basename($file);
            self::assertDoesNotMatchRegularExpression('/^use (?!function |const )/m', $src, "{$name} imports a class");
            $helpers = '/\b(config|env|app|container)\(/';
            self::assertDoesNotMatchRegularExpression($helpers, $src, "{$name} calls a framework helper");
        }
    }
}
