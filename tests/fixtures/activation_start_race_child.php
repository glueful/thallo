<?php

declare(strict_types=1);

// Starts (or joins) an activation and prints the generation it got.
$container = require __DIR__ . '/thallo_child_boot.php';
[, $capability] = $argv;
echo $container->get(Thallo\Core\Capabilities\Activation\ActivationStore::class)
    ->startOrJoin($capability, 'race-child')->generation, "\n";
