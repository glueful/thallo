<?php

declare(strict_types=1);

namespace App\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;

/**
 * The pack half of the schema manifest contract (schema policy spec B1/B2): every Thallo library
 * pack with an extra.glueful block declares descriptors or explicit "none"; schema-owning packs
 * are core-mode with their exact legacy ledger alias, a conforming effect verifier with full
 * recursive basename coverage, and a provider that registers nothing.
 */
final class PackManifestsTest extends TestCase
{
    private const SCHEMA_OWNING = [
        // pack => [priority, verifier FQCN]
        'thallo-analytics' => ['dependent', \Thallo\Analytics\Schema\AnalyticsSchemaVerifier::class],
        'thallo-collections' => ['dependent', \Thallo\Collections\Schema\CollectionsSchemaVerifier::class],
        'thallo-commerce' => ['dependent', \Thallo\Commerce\Schema\CommerceLinkSchemaVerifier::class],
        'thallo-navigation' => ['dependent', \Thallo\Navigation\Schema\NavigationSchemaVerifier::class],
        'thallo-render' => ['dependent', \Thallo\Render\Schema\RenderSchemaVerifier::class],
        'thallo-seo' => ['dependent', \Thallo\Seo\Schema\SeoSchemaVerifier::class],
        'thallo-tenancy' => ['dependent', \Thallo\Tenancy\Schema\TenancySchemaVerifier::class],
        'thallo-workflow' => ['dependent', \Thallo\Workflow\Schema\WorkflowSchemaVerifier::class],
    ];

    private const SCHEMA_FREE = ['thallo-account', 'thallo-importers', 'thallo-search', 'thallo-subscriptions'];

    /** @return array<string, mixed> */
    private function glueful(string $pack): array
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . "/packages/{$pack}/composer.json"),
            true
        );
        return $composer['extra']['glueful'];
    }

    public function testSchemaOwningPacksDeclareCoreDescriptors(): void
    {
        foreach (self::SCHEMA_OWNING as $pack => [$priority, $verifier]) {
            $migrations = $this->glueful($pack)['migrations'];
            self::assertCount(1, $migrations, $pack);
            self::assertSame('default', $migrations[0]['id'], $pack);
            self::assertSame('migrations', $migrations[0]['path'], $pack);
            self::assertSame('core', $migrations[0]['mode'], "{$pack}: libraries have no enable event");
            self::assertSame($priority, $migrations[0]['priority'], $pack);
            self::assertArrayNotHasKey(
                'legacyAliases',
                $migrations[0],
                "{$pack}: the alias machinery is gone — receipts are canonical from provision"
            );
            self::assertSame($verifier, $migrations[0]['verifier'], $pack);
            self::assertSame('>=1.79.0', $this->glueful($pack)['requires']['glueful'], $pack);
            self::assertSame([], $this->glueful($pack)['requires']['extensions'], $pack);
        }
    }

    public function testSchemaFreePacksDeclareNoneExplicitly(): void
    {
        foreach (self::SCHEMA_FREE as $pack) {
            self::assertSame('none', $this->glueful($pack)['migrations'], $pack);
            self::assertSame([], $this->glueful($pack)['requires']['extensions'], $pack);
        }
    }

    public function testVerifiersConformToTheManifestContract(): void
    {
        foreach (self::SCHEMA_OWNING as $pack => [, $class]) {
            self::assertTrue(class_exists($class), $pack);
            self::assertTrue(
                is_subclass_of($class, \Glueful\Extensions\Schema\StructuralVerifierInterface::class),
                $pack
            );
            $constructor = (new \ReflectionClass($class))->getConstructor();
            self::assertTrue(
                $constructor === null || $constructor->getNumberOfRequiredParameters() === 0,
                $pack
            );
            self::assertSame("glueful/{$pack}", (new $class())->source(), $pack);
        }
    }

    public function testVerifiersCoverEveryRecursivelyDiscoveredMigration(): void
    {
        foreach (self::SCHEMA_OWNING as $pack => [, $class]) {
            $mapped = (new $class())->migrationBasenames();
            $root = dirname(__DIR__, 3) . "/packages/{$pack}/migrations";
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            $shipped = [];
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $shipped[] = $file->getBasename();
                }
            }
            sort($shipped);
            sort($mapped);
            self::assertSame($shipped, $mapped, "{$pack}: every migration file needs a verifier proof");
        }
    }

    public function testNoPackProviderRegistersMigrationsAnymore(): void
    {
        $matches = [];
        foreach (glob(dirname(__DIR__, 3) . '/packages/*/src/*Provider.php') as $file) {
            if (str_contains((string) file_get_contents($file), 'loadMigrationsFrom(')) {
                $matches[] = basename($file);
            }
        }
        self::assertSame([], $matches, 'the manifest is the sole pack migration inventory');
    }
}
