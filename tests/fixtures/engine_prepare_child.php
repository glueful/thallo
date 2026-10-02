<?php

declare(strict_types=1);

// Prepares one engine (EngineActivation::prepare) against a shared temp enabled list, with a
// cache rebuild that never touches the application's cache. --pause-in-lock pauses inside the
// extension-state lock after the list write (prints `in-lock`, waits for a line on stdin).
// Prints the outcome's status.
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Extensions\ExtensionStateCoordinator;
use Thallo\Core\Capabilities\Activation\EngineActivation;
use Thallo\Core\Capabilities\FeatureManagementPolicy;

$container = require __DIR__ . '/thallo_child_boot.php';
[, $package, $config] = $argv;
$pause = in_array('--pause-in-lock', $argv, true);

$provider = null;
foreach ((new FeatureManagementPolicy())->activationCapabilities() as $capability) {
    $engine = (new FeatureManagementPolicy())->engineOf($capability);
    if ($engine !== null && $engine['package'] === $package) {
        $provider = $engine['provider'];
    }
}

$activation = new EngineActivation(
    $container->get(ApplicationContext::class),
    $container->get(ExtensionStateCoordinator::class),
    $config,
    static fn (): bool => true,
    static function () use ($pause): void {
        if ($pause) {
            echo "in-lock\n";
            fflush(STDOUT);
            fgets(STDIN);
        }
    },
);
$result = $activation->prepare($package, (string) $provider, 'test');
echo $result['status'], "\n";
