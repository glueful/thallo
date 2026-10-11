<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Updater;

use PHPUnit\Framework\TestCase;
use Thallo\Core\Updater\ComposerShape;

final class ComposerShapeTest extends TestCase
{
    public function testTheSkeletonShapeIsSupported(): void
    {
        $skeleton = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/skeleton/composer.json'), true);
        self::assertSame([], ComposerShape::problems($skeleton));
        self::assertSame(['app'], ComposerShape::autoloadRoots($skeleton));
    }

    /** @return iterable<string, array{array, string}> */
    public static function unsupported(): iterable
    {
        yield 'path repository' => [['repositories' => [['type' => 'path', 'url' => '../pkg']]], 'repositories'];
        yield 'relative artifact' => [['repositories' => [['type' => 'artifact', 'url' => 'zips/']]], 'repositories'];
        $installerPaths = ['extra' => ['installer-paths' => ['web/{$name}' => ['type:x']]]];
        yield 'installer paths' => [$installerPaths, 'installer-paths'];
        yield 'vendor dir' => [['config' => ['vendor-dir' => 'lib/vendor']], 'vendor-dir'];
        yield 'all plugins' => [['config' => ['allow-plugins' => true]], 'allow-plugins'];
        yield 'unknown plugin' => [['config' => ['allow-plugins' => ['acme/rewriter' => true]]], 'acme/rewriter'];
        yield 'autoload outside' => [['autoload' => ['psr-4' => ['App\\' => '../shared/']]], 'autoload'];
        yield 'autoload in vendor' => [['autoload' => ['classmap' => ['vendor/x/']]], 'autoload'];
        yield 'absolute autoload' => [['autoload' => ['files' => ['/etc/x.php']]], 'autoload'];
    }

    /** @dataProvider unsupported */
    public function testUnsupportedShapesAreNamed(array $json, string $mentions): void
    {
        $problems = ComposerShape::problems($json);
        self::assertNotSame([], $problems);
        self::assertStringContainsString($mentions, implode(' ', $problems));
    }

    public function testADisabledPluginIsFine(): void
    {
        self::assertSame([], ComposerShape::problems(['config' => ['allow-plugins' => ['acme/rewriter' => false]]]));
    }

    public function testEveryAutoloadSectionContributesItsRoot(): void
    {
        $json = [
            'autoload' => [
                'psr-4' => ['App\\' => 'app/', 'Lib\\' => ['src/lib/']],
                'classmap' => ['legacy/'],
                'files' => ['helpers.php'],
            ],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        ];
        self::assertSame(['app', 'helpers.php', 'legacy', 'src', 'tests'], ComposerShape::autoloadRoots($json));
    }
}
