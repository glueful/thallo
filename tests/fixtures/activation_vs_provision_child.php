<?php

declare(strict_types=1);

// `activation <slug>`: adds that permission, then runs a whole Commerce activation (the engine is
// already prepared in the testing environment). `provision <slug>`: adds that permission, then
// runs InstallRoleGrants::apply() as provision does. Prints `done`.
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Thallo\Core\Capabilities\Activation\ActivationRunner;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Setup\InstallRoleGrants;

$container = require __DIR__ . '/thallo_child_boot.php';
$context = $container->get(Glueful\Bootstrap\ApplicationContext::class);
[, $mode, $slug] = $argv;
(new PermissionRepository(null, $context))->create([
    'name' => $slug, 'slug' => $slug, 'description' => 'race test', 'category' => 'Test', 'is_system' => true,
]);

if ($mode === 'activation') {
    $generation = $container->get(ActivationStore::class)->startOrJoin('thallo.commerce', 'test')->generation;
    $runner = $container->get(ActivationRunner::class);
    $runner->run('thallo.commerce', $generation, freshBoot: false);
    $runner->run('thallo.commerce', $generation, freshBoot: true);
} else {
    $container->get(InstallRoleGrants::class)->apply();
}
echo "done\n";
