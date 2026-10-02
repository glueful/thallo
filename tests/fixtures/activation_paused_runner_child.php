<?php

declare(strict_types=1);

// A runner that takes the lease on the current generation, prints `acquired`, waits for a line on
// stdin, then tries to record a step. Prints `superseded` when its fenced write is refused.
// (With --pause-before=<step>, see below.)
use Thallo\Core\Capabilities\Activation\ActivationStep;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\ActivationSuperseded;

$container = require __DIR__ . '/thallo_child_boot.php';
[, $capability] = $argv;
$store = $container->get(ActivationStore::class);

// --pause-before=<step>: run the activation from its next step in a fresh boot instead, pausing
// just before <step> (the runner prints `paused` and waits for a line on stdin).
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--pause-before=')) {
        putenv('THALLO_TEST_PAUSE_BEFORE_STEP=' . substr($arg, strlen('--pause-before=')));
        try {
            $container->get(Thallo\Core\Capabilities\Activation\ActivationRunner::class)
                ->run($capability, $store->find($capability)->generation, freshBoot: true);
            echo "finished\n";
        } catch (ActivationSuperseded) {
            echo "superseded\n";
        }
        exit(0);
    }
}

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
