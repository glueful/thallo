<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Every published artifact (core, the skeleton, the 13 packs) is a read-only mirror on GitHub,
 * so each README must say so and send contributors to the development repository — a mirror's
 * `main` is overwritten on every release, and a pull request there can never land.
 */
final class MirrorReadmeTest extends TestCase
{
    public const NOTE = 'read-only mirror';

    public function testEveryArtifactReadmeCarriesTheContributingNote(): void
    {
        $root = dirname(__DIR__, 3);
        $dirs = array_merge(['core', 'skeleton'], glob("$root/packages/thallo-*") ?: []);

        foreach ($dirs as $dir) {
            $path = str_starts_with($dir, '/') ? "$dir/README.md" : "$root/$dir/README.md";
            self::assertFileExists($path, basename($dir) . ' ships a README');
            $readme = (string) file_get_contents($path);
            self::assertStringContainsString('## Contributing', $readme, basename($dir));
            self::assertStringContainsString(self::NOTE, $readme, basename($dir));
            self::assertStringContainsString('https://github.com/glueful/thallo', $readme, basename($dir));
        }
    }
}
