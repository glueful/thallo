<?php

declare(strict_types=1);

// The finalization step's lock shape in its own process: take the lease, then in one fenced
// transaction re-seed the capability's blocks and publish it on, then record FINALIZE.
use Thallo\Core\Capabilities\Activation\ActivationStep;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\CapabilityBlockSeeder;
use Thallo\Core\Capabilities\CapabilityStateStore;

$container = require __DIR__ . '/thallo_child_boot.php';
[, $capability] = $argv;
$store = $container->get(ActivationStore::class);
$generation = $store->find($capability)->generation;
// Taking the lease already locks the activation row, so this is where it waits on a workspace seed.
echo "waiting-for-row\n";
$lease = $store->acquire($capability, $generation);
if ($lease === null) {
    echo "no-lease\n";
    exit(1);
}
$attempts = 0;
$store->withinFenced($lease, function () use ($container, $capability, &$attempts): void {
    $attempts++;
    $container->get(CapabilityBlockSeeder::class)->seedCurrent($capability);
    $container->get(CapabilityStateStore::class)->put($capability, true);
});
// The transaction retries on a deadlock; more than one attempt means there was one.
echo "attempts={$attempts}\n";
echo $store->completeStep($lease, ActivationStep::FINALIZE)->status, "\n";
