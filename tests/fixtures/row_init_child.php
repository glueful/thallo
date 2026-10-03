<?php

declare(strict_types=1);

// Initializes a capability's activation row (ActivationStore::initializeRow) and prints
// `initialized`; with --start it then starts the activation and prints `generation=<n>`.
use Thallo\Core\Capabilities\Activation\ActivationStore;

$container = require __DIR__ . '/thallo_child_boot.php';
[, $capability] = $argv;
$store = $container->get(ActivationStore::class);
$store->initializeRow($capability);
echo "initialized\n";
if (in_array('--start', $argv, true)) {
    $generation = $store->startOrJoin($capability, 'row-init-child')->generation;
    echo "generation={$generation}\n";
}
