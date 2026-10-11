<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;

/** The site-owned entry points include the guard before anything loads vendor/ (spec §7). */
final class GuardIncludeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function entries(): iterable
    {
        $root = dirname(__DIR__, 3);
        yield 'skeleton index' => [$root . '/skeleton/public/index.php', "__DIR__ . '/../bootstrap/update-guard.php'"];
        yield 'skeleton console' => [$root . '/skeleton/glueful', "__DIR__ . '/bootstrap/update-guard.php'"];
        yield 'root index' => [$root . '/public/index.php', "__DIR__ . '/../bootstrap/update-guard.php'"];
        yield 'root console' => [$root . '/glueful', "__DIR__ . '/bootstrap/update-guard.php'"];
    }

    /** @dataProvider entries */
    public function testTheGuardComesBeforeTheApplication(string $file, string $guardPath): void
    {
        $src = (string) file_get_contents($file);
        $guard = strpos($src, $guardPath);
        $app = strpos($src, 'bootstrap/app.php');
        self::assertNotFalse($guard, "{$file} includes the guard");
        self::assertLessThan($app, $guard, "{$file} includes it before bootstrap/app.php");
    }
}
