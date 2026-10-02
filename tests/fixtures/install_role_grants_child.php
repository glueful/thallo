<?php

declare(strict_types=1);

// Runs InstallRoleGrants::apply() in its own process. --add-permission=<slug> first creates that
// permission row; --pause-after-ledger-read pauses inside apply() right after the ledger read
// (prints `ledger-read`, waits for a line on stdin).
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Thallo\Core\Setup\InstallRoleGrants;

$container = require __DIR__ . '/thallo_child_boot.php';
$context = $container->get(Glueful\Bootstrap\ApplicationContext::class);
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--add-permission=')) {
        $slug = substr($arg, strlen('--add-permission='));
        (new PermissionRepository(null, $context))->create([
            'name' => $slug, 'slug' => $slug, 'description' => 'race test', 'category' => 'Test', 'is_system' => true,
        ]);
    }
    if ($arg === '--pause-after-ledger-read') {
        putenv('THALLO_TEST_PAUSE_AFTER_LEDGER_READ=1');
    }
}
$container->get(InstallRoleGrants::class)->apply();
echo "applied\n";
