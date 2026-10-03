<?php

declare(strict_types=1);

namespace Thallo\Core\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Extensions\ExtensionStateCoordinator;
use Thallo\Core\Capabilities\Activation\ActivationRunner;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\CapabilityBlockSeeder;
use Thallo\Core\Capabilities\Activation\EngineActivation;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\FeatureManagementPolicy;
use Thallo\Core\Setup\InstallRoleGrants;

/**
 * Activation runners for tests: every engine writes a temporary enabled list (Commerce listed by
 * default: already prepared) and never the application's own extension cache. Removes its temp
 * files in removeActivationTempFiles().
 */
trait ActivationRunners
{
    protected const COMMERCE_PROVIDER = 'Glueful\\Extensions\\Commerce\\CommerceServiceProvider';

    /** @var list<string> */
    private array $activationTempFiles = [];

    /** @param list<string> $enabled */
    protected function tempExtensionsConfig(array $enabled = []): string
    {
        $path = sys_get_temp_dir() . '/thallo-activation-extensions-' . bin2hex(random_bytes(6)) . '.php';
        $items = implode('', array_map(
            static fn (string $p): string => "        '" . str_replace('\\', '\\\\', $p) . "',\n",
            $enabled,
        ));
        file_put_contents($path, "<?php\nreturn [\n    'enabled' => [\n{$items}    ],\n];\n");
        $this->activationTempFiles[] = $path;
        return $path;
    }

    /** @param list<string> $enabled */
    protected function engine(
        array $enabled = [self::COMMERCE_PROVIDER],
        bool $writable = true,
        ?\Closure $writeCache = null,
        ?ContainerInterface $container = null,
    ): EngineActivation {
        $container ??= $this->container();
        return new EngineActivation(
            $container->get(ApplicationContext::class),
            $container->get(ExtensionStateCoordinator::class),
            $this->tempExtensionsConfig($enabled),
            static fn (): bool => $writable,
            $writeCache ?? static function (): void {
            },
        );
    }

    protected function runner(?EngineActivation $engine = null, ?ContainerInterface $container = null): ActivationRunner
    {
        $container ??= $this->container();
        return new ActivationRunner(
            $container->get(ActivationStore::class),
            $container->get(CapabilityStateStore::class),
            $container->get(FeatureManagementPolicy::class),
            $container->get(CapabilityBlockSeeder::class),
            $container->get(InstallRoleGrants::class),
            $engine ?? $this->engine(container: $container),
            $container,
        );
    }

    protected function removeActivationTempFiles(): void
    {
        foreach ($this->activationTempFiles as $file) {
            @unlink($file);
        }
        $this->activationTempFiles = [];
    }
}
