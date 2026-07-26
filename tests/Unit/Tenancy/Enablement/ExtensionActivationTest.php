<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Enablement;

use App\Tests\Support\AppTestCase;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Container\Container;
use Glueful\Extensions\ExtensionManager;
use Thallo\Tenancy\Enablement\ExtensionActivation;

final class ExtensionActivationTest extends AppTestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            $this->removeTree($root);
        }
        parent::tearDown();
    }

    public function testInstalledDevelopmentCandidateIsDetectedButNotActivated(): void
    {
        $activation = $this->container()->get(ExtensionActivation::class);

        self::assertTrue($activation->isInstalled());
        self::assertFalse($activation->isActivated());
    }

    public function testInstallOfAlreadyInstalledPackageIsANonBlockingSkip(): void
    {
        $result = $this->container()->get(ExtensionActivation::class)->install();

        self::assertSame('installed', $result['status']);
        self::assertFalse($result['blocked']);
        self::assertNull($result['reason']);
    }

    public function testActivateRepairsStaleCacheWhenProviderIsAlreadyListed(): void
    {
        [$activation, $cache] = $this->isolatedActivation([ExtensionActivation::PROVIDER]);
        file_put_contents($cache, "<?php\nreturn [];\n");

        $activation->activate();

        self::assertContains(ExtensionActivation::PROVIDER, require $cache);
    }

    public function testDeactivateRepairsStaleCacheWhenProviderIsAlreadyAbsent(): void
    {
        [$activation, $cache] = $this->isolatedActivation([]);
        file_put_contents(
            $cache,
            "<?php\nreturn ['" . addslashes(ExtensionActivation::PROVIDER) . "'];\n",
        );

        $activation->deactivate();

        self::assertNotContains(ExtensionActivation::PROVIDER, require $cache);
    }

    public function testActivateCacheCarriesAppModulesAlongsideTheExtension(): void
    {
        // Modules-not-extensions regression guard: the cache write must be the NO-ARG
        // writeCacheNow() (full resolved list), never the extension-only resolution — an
        // extension-only cache would boot production without the always-on thallo modules.
        [$activation, $cache] = $this->isolatedActivation(
            [],
            ['Thallo\\Render\\RenderServiceProvider'],
        );

        $activation->activate();

        $cached = require $cache;
        self::assertContains(ExtensionActivation::PROVIDER, $cached);
        self::assertContains(
            'Thallo\\Render\\RenderServiceProvider',
            $cached,
            'the activation cache must include app modules from config/serviceproviders.php',
        );
    }

    public function testDeactivateCacheKeepsAppModules(): void
    {
        [$activation, $cache] = $this->isolatedActivation(
            [ExtensionActivation::PROVIDER],
            ['Thallo\\Render\\RenderServiceProvider'],
        );

        $activation->deactivate();

        $cached = require $cache;
        self::assertNotContains(ExtensionActivation::PROVIDER, $cached);
        self::assertContains(
            'Thallo\\Render\\RenderServiceProvider',
            $cached,
            'deactivation must never drop app modules from the cache',
        );
    }

    /** @param list<string> $enabled
     * @param list<string> $modules App-module providers for the isolated config/serviceproviders.php
     * @return array{ExtensionActivation,string}
     */
    private function isolatedActivation(array $enabled, array $modules = []): array
    {
        $root = sys_get_temp_dir() . '/thallo-extension-activation-' . bin2hex(random_bytes(6));
        $this->temporaryRoots[] = $root;
        mkdir($root . '/config', 0777, true);
        mkdir($root . '/bootstrap/cache', 0777, true);
        symlink($this->appContext()->getBasePath() . '/vendor', $root . '/vendor');

        file_put_contents(
            $root . '/config/extensions.php',
            "<?php\nreturn [\n    'enabled' => [\n" . $this->providerLines($enabled) . "\n    ],\n];\n",
        );
        file_put_contents(
            $root . '/config/serviceproviders.php',
            "<?php\nreturn [\n    'enabled' => [\n" . $this->providerLines($modules) . "\n    ],\n];\n",
        );

        $context = new ApplicationContext($root, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($root, 'testing', $root . '/config'));
        $container = new Container([ApplicationContext::class => $context]);
        $container->load([ExtensionManager::class => new ExtensionManager($container)]);
        $context->setContainer($container);

        return [new ExtensionActivation($context), $root . '/bootstrap/cache/extensions.php'];
    }

    /** @param list<string> $providers */
    private function providerLines(array $providers): string
    {
        return implode("\n", array_map(
            static fn (string $provider): string => "        '" . addslashes($provider) . "',",
            $providers,
        ));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
