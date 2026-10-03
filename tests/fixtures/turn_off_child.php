<?php

declare(strict_types=1);

// Turns a capability off (ActivationStore::supersede), pausing right after its check that no row
// exists — it prints `absence-checked` and waits for a line on stdin, holding the workspace-seed lock.
// Prints `superseded=<new generation>` (0 when there was no row).
use Thallo\Core\Capabilities\Activation\ActivationStore;

$container = require __DIR__ . '/thallo_child_boot.php';
[, $capability] = $argv;
putenv('THALLO_TEST_PAUSE_IN_SUPERSEDE=after-absence-check');
$generation = $container->get(ActivationStore::class)->supersede($capability, 'turn-off-child');
echo "superseded={$generation}\n";
