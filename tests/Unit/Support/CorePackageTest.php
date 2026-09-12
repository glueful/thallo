<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/** core/ is a Composer package the dev root installs from a path repository. */
final class CorePackageTest extends TestCase
{
    /** @return array<string,mixed> */
    private function json(string $rel): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/' . $rel),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function testCoreIsAPackageWithAManifest(): void
    {
        $core = $this->json('core/composer.json');
        self::assertSame('glueful/thallo-core', $core['name']);
        self::assertSame('library', $core['type']);
        self::assertSame(['Thallo\\Core\\' => 'src/'], $core['autoload']['psr-4']);
        self::assertArrayNotHasKey('version', $core, 'Packagist derives versions from tags');
        self::assertSame('Thallo\\Core\\Providers\\CoreServiceProvider', $core['extra']['glueful']['provider']);

        $lanes = array_column($core['extra']['glueful']['migrations'], null, 'id');
        self::assertSame(['app'], $lanes['default']['previous_sources']);
        self::assertSame('database/migrations', $lanes['default']['path']);
        self::assertSame(['app:dependent'], $lanes['dependent']['previous_sources']);
        self::assertSame('dependent', $lanes['dependent']['priority']);
        self::assertSame('database/dependent-migrations', $lanes['dependent']['path']);
    }

    public function testCoreRequiresEveryPackAtItsOwnVersion(): void
    {
        $core = $this->json('core/composer.json');
        foreach (glob(dirname(__DIR__, 3) . '/packages/thallo-*') as $dir) {
            $name = $this->json('packages/' . basename($dir) . '/composer.json')['name'];
            self::assertSame('self.version', $core['require'][$name] ?? null, "$name must be pinned to core's version");
        }
    }

    public function testTheDevRootInstallsCoreFromThePathRepository(): void
    {
        $root = $this->json('composer.json');
        self::assertSame('glueful/thallo-dev', $root['name'], 'the dev repo is not what create-project installs');
        $repos = array_column($root['repositories'], null, 'url');
        self::assertArrayHasKey('core', $repos);
        self::assertArrayHasKey('packages/*', $repos);
        foreach (['core', 'packages/*'] as $url) {
            // Pinned to one constant dev version with no git reference: `self.version` resolves,
            // and composer.lock does not churn with every commit of the monorepo.
            self::assertSame('none', $repos[$url]['options']['reference']);
            self::assertSame('1.0.0', $repos[$url]['options']['versions']['glueful/thallo-core']);
            self::assertSame('1.0.0', $repos[$url]['options']['versions']['glueful/thallo-commerce']);
        }
        self::assertSame('*', $root['require']['glueful/thallo-core']);
        self::assertArrayNotHasKey('glueful/thallo-commerce', $root['require'], 'packs arrive through core');
        self::assertSame('stable', $root['minimum-stability']);
        self::assertTrue($root['prefer-stable']);
        self::assertFileExists(
            dirname(__DIR__, 3) . '/vendor/glueful/thallo-core/composer.json',
            'run composer update',
        );
    }

    public function testNoPublishedPackageCarriesAVersionField(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/packages/thallo-*/composer.json') as $file) {
            $pack = json_decode((string) file_get_contents($file), true);
            self::assertArrayNotHasKey('version', $pack, $file);
            foreach ($pack['require'] ?? [] as $dep => $constraint) {
                if (str_starts_with($dep, 'glueful/thallo-')) {
                    self::assertSame('self.version', $constraint, "$file: $dep");
                }
            }
        }
    }
}
