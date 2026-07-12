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

    /** @param list<string> $enabled
     * @return array{ExtensionActivation,string}
     */
    private function isolatedActivation(array $enabled): array
    {
        $root = sys_get_temp_dir() . '/thallo-extension-activation-' . bin2hex(random_bytes(6));
        $this->temporaryRoots[] = $root;
        mkdir($root . '/config', 0777, true);
        mkdir($root . '/bootstrap/cache', 0777, true);
        symlink($this->appContext()->getBasePath() . '/vendor', $root . '/vendor');

        $entries = implode(",\n", array_map(
            static fn (string $provider): string => "        '" . addslashes($provider) . "',",
            $enabled,
        ));
        file_put_contents(
            $root . '/config/extensions.php',
            "<?php\nreturn [\n    'enabled' => [\n{$entries}\n    ],\n];\n",
        );

        $context = new ApplicationContext($root, 'testing');
        $context->setConfigLoader(new ConfigurationLoader($root, 'testing', $root . '/config'));
        $container = new Container([ApplicationContext::class => $context]);
        $container->load([ExtensionManager::class => new ExtensionManager($container)]);
        $context->setContainer($container);

        return [new ExtensionActivation($context), $root . '/bootstrap/cache/extensions.php'];
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
