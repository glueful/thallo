<?php

declare(strict_types=1);

// A runner that takes the lease on the current generation, prints `acquired`, waits for a line on
// stdin, then tries to record a step. Prints `superseded` when its fenced write is refused.
use Thallo\Core\Capabilities\Activation\ActivationStep;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\ActivationSuperseded;

$container = require __DIR__ . '/thallo_child_boot.php';
[, $capability] = $argv;
$store = $container->get(ActivationStore::class);
$lease = $store->acquire($capability, $store->find($capability)->generation);
if ($lease === null) {
    echo "no-lease\n";
    exit(0);
}
echo "acquired\n";
fgets(STDIN);
try {
    $store->completeStep($lease, ActivationStep::ENABLE_ENGINE);
    echo "completed\n";
} catch (ActivationSuperseded) {
    echo "superseded\n";
}
