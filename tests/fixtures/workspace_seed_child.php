<?php

declare(strict_types=1);

// A workspace seed in its own process, through the production ordering
// (CapabilityBlockSeeder::withinWorkspaceSeed): its "kinds" write a product-grid row, then it prints
// `kinds-written` and waits for a line on stdin before the capability seeding and the commit.
use Glueful\Database\Connection;
use Thallo\Core\Capabilities\Activation\BlockInsert;
use Thallo\Core\Capabilities\Activation\CapabilityBlockSeeder;

$container = require __DIR__ . '/thallo_child_boot.php';
$db = $container->get(Connection::class);
$seeder = $container->get(CapabilityBlockSeeder::class);
$attempts = 0;
$db->transaction(function () use ($db, $seeder, &$attempts): void {
    $attempts++;
    $seeder->withinWorkspaceSeed('single', function () use ($db, $attempts): void {
        BlockInsert::ifAbsent($db, static function () use ($db): void {
            $db->getPDO()->prepare(
                'INSERT INTO block_types (uuid, slug, label, schema, active)'
                . " VALUES (?, 'product-grid', 'Grid', '[]', true)"
            )->execute([substr(bin2hex(random_bytes(8)), 0, 12)]);
        });
        if ($attempts === 1) {
            echo "kinds-written\n";
            fgets(STDIN);
        }
    });
});
// The transaction retries on a deadlock; more than one attempt means there was one.
echo "attempts={$attempts}\n";
echo "committed\n";
