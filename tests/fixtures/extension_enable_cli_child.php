<?php

declare(strict_types=1);

// `cli <package>`: enables the package through the schema executor, as `extensions:enable` does,
// printing `migration-locks-held` and waiting for a line on stdin once it holds the migration locks
// (and not the extension-state lock). Prints the operation status.
// `engine`: prepares acme/bookings' engine as an activation does (migrate, then write the enabled list
// under the extension-state lock), printing `holding` and waiting for a line inside that lock.
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Thallo\Contracts\Extensions\ExtensionStateCoordinator;
use Thallo\Core\Capabilities\Activation\EngineActivation;

$container = require __DIR__ . '/thallo_child_boot.php';
$context = $container->get(Glueful\Bootstrap\ApplicationContext::class);
$mode = $argv[1] ?? '';

$wait = static function (string $marker): void {
    fwrite(STDOUT, $marker . "\n");
    fflush(STDOUT);
    fgets(STDIN);
};

if ($mode === 'cli') {
    ExtensionSchemaExecutor::$afterMigrationLocks = static fn () => $wait('migration-locks-held');
    $operation = $container->get(ExtensionSchemaExecutor::class)->enable((string) $argv[2], 'cli');
    echo 'status=' . $operation->status . "\n";
} elseif ($mode === 'engine') {
    $engine = new EngineActivation(
        $context,
        $container->get(ExtensionStateCoordinator::class),
        null,
        static fn (): bool => true,
        static function () use ($wait, $container): void {
            $wait('holding');                                      // inside the lock, list written
            $container->get(ExtensionManager::class)->writeCacheNow();
        },
    );
    $result = $engine->prepare('acme/bookings', 'Acme\\Bookings\\BookingsServiceProvider', 'test');
    echo 'status=' . $result['status'] . "\n";
}
